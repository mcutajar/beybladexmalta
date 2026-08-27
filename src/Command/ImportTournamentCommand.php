<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeUrl;
use App\Dto\TeamPlacement;
use App\Dto\TournamentPlacement;
use App\Entity\Season;
use App\Exception\ImportFileWriteException;
use App\Exception\LedgerWriteException;
use App\Repository\SeasonRepository;
use App\Service\ChallongeFetcher;
use App\Service\ChallongeSnapshotFiles;
use App\Service\ChallongeSnapshotReader;
use App\Service\PlacementListParser;
use App\Service\ReplayTournamentImportService;
use App\Service\TeamListParser;
use App\Service\TournamentImportResult;
use App\Service\TournamentImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-tournament',
    description: 'Imports a tournament by calculating F1 points from an ordered top 10 list of names.',
)]
final class ImportTournamentCommand extends Command
{
    public function __construct(
        private readonly TournamentImportService $importService,
        private readonly PlacementListParser $placementListParser,
        private readonly TeamListParser $teamListParser,
        private readonly SeasonRepository $seasonRepository,
        private readonly ChallongeFetcher $challongeFetcher,
        private readonly ChallongeSnapshotReader $snapshotReader,
        private readonly ChallongeSnapshotFiles $snapshotFiles,
        private readonly ReplayTournamentImportService $replayImportService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'title',
                InputArgument::REQUIRED,
                'The title of the tournament',
            )
            ->addArgument(
                'date',
                InputArgument::REQUIRED,
                'The date of the tournament (YYYY-MM-DD)',
            )
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'Path to the text/csv file with player names',
            )
            ->addOption(
                'challonge',
                null,
                InputOption::VALUE_OPTIONAL,
                'Optional Challonge bracket URL',
            )
            ->addOption(
                'season',
                's',
                InputOption::VALUE_REQUIRED,
                'The target season slug this tournament belongs to',
            )
            ->addOption(
                'snapshot',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to the captured Challonge snapshot to replay',
            )
            ->addOption(
                'knockout',
                'k',
                InputOption::VALUE_OPTIONAL,
                'The name of the player who won the overall knockout bracket',
            )
            ->addOption(
                'team',
                't',
                InputOption::VALUE_NONE,
                'A 2v2 event: the file is a roster, one team per line, and each team is scored for everybody in it',
            )
            ->setHelp(<<<'HELP'
                The file is an ordered list of bladers, best finish first, one per
                line, optionally followed by a comma and any manual bonus points.

                With <info>--team</info> it is a roster instead, one entrant per line, in the
                same finishing order:

                    irmied u gebel: Butcher + Obelix
                    JG:
                    bye

                A 2v2 bracket carries a finishing order and nothing else the league
                can use, so a team event awards the entrant's rank to each blader in
                it and writes no match, game or knockout bonus. A team with nobody
                after the colon is <info>unclaimed</info>: it keeps its rank, scores nothing, and
                is filled in later with <info>app:team claim</info>. Challonge's own <info>bye</info> is
                dropped, and the entrants below it keep the rank the bracket gave
                them.

                A team event is declared here, never detected: nothing in a bracket
                says which events are 2v2, and the rosters have to be supplied by
                hand whatever happens.
                HELP);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);

        $title = (string) $input->getArgument('title');
        $date = (string) $input->getArgument('date');
        $filePath = (string) $input->getArgument('file');
        $challongeUrl = $input->getOption('challonge');
        $snapshotPath = $input->getOption('snapshot');
        $knockoutWinner = $input->getOption('knockout');

        $teamEvent = (bool) $input->getOption('team');

        if ($teamEvent && null !== $knockoutWinner) {
            $io->error(
                'A team event awards no knockout bonus, so --team and --knockout cannot be used together.',
            );

            return Command::INVALID;
        }

        if (null !== $snapshotPath && null === $challongeUrl) {
            $io->error('--snapshot requires --challonge so the replay can verify which bracket it belongs to.');

            return Command::INVALID;
        }

        $contents = $this->readFile($filePath, $io);

        if (null === $contents) {
            return Command::FAILURE;
        }

        $placements = $teamEvent ? [] : $this->placementListParser->parse($contents);
        $teams = $teamEvent ? $this->teamListParser->parse($contents) : [];

        if ([] === $placements && [] === $teams) {
            $io->error(sprintf(
                'The file "%s" holds no %s to import.',
                $filePath,
                $teamEvent ? 'teams' : 'placements',
            ));

            return Command::INVALID;
        }

        $seasonSlug = trim((string) $input->getOption('season'));

        if ('' === $seasonSlug) {
            $io->error('The --season option is required.');

            return Command::INVALID;
        }

        $season = $this->seasonRepository->findBySlug($seasonSlug);

        if (null === $season) {
            $io->error(sprintf('Season "%s" does not exist.', $seasonSlug));

            return Command::FAILURE;
        }

        $challongeUrl = null !== $challongeUrl ? (string) $challongeUrl : null;
        $snapshot = $this->resolveSnapshot($challongeUrl, null !== $snapshotPath ? (string) $snapshotPath : null, $io);

        if (false === $snapshot) {
            return Command::FAILURE;
        }

        $resolvedSnapshotPath = $snapshot instanceof ChallongeSnapshot
            ? ($snapshotPath ?? $this->snapshotFiles->pathFor($snapshot->slug))
            : null;

        return $teamEvent
            ? $this->importTeamEvent(
                title: $title,
                date: $date,
                season: $season,
                teams: $teams,
                filePath: $filePath,
                challongeUrl: $challongeUrl,
                io: $io,
                snapshot: $snapshot instanceof ChallongeSnapshot ? $snapshot : null,
                snapshotPath: $resolvedSnapshotPath,
            )
            : $this->import(
                title: $title,
                date: $date,
                season: $season,
                placements: $placements,
                filePath: $filePath,
                challongeUrl: $challongeUrl,
                knockoutWinner: null !== $knockoutWinner ? (string) $knockoutWinner : null,
                io: $io,
                snapshot: $snapshot instanceof ChallongeSnapshot ? $snapshot : null,
                snapshotPath: $resolvedSnapshotPath,
            );
    }

    /**
     * @param list<TournamentPlacement> $placements
     */
    private function import(
        string $title,
        string $date,
        Season $season,
        array $placements,
        string $filePath,
        ?string $challongeUrl,
        ?string $knockoutWinner,
        SymfonyStyle $io,
        ?ChallongeSnapshot $snapshot,
        ?string $snapshotPath,
    ): int {
        try {
            $result = null !== $snapshot && null !== $snapshotPath && null !== $challongeUrl
                ? $this->replayImportService->import(
                    title: $title,
                    heldOn: $date,
                    seasonSlug: $season->getSlug(),
                    placements: $placements,
                    sourceFilePath: $filePath,
                    snapshot: $snapshot,
                    snapshotPath: $snapshotPath,
                    challongeUrl: $challongeUrl,
                    knockoutWinner: $knockoutWinner,
                )
                : $this->importService->import(
                    title: $title,
                    heldOn: $date,
                    seasonSlug: $season->getSlug(),
                    placements: $placements,
                    challongeUrl: $challongeUrl,
                    knockoutWinner: $knockoutWinner,
                    sourceFilePath: $filePath,
                );
        } catch (LedgerWriteException|ImportFileWriteException $exception) {
            return $this->showLedgerFailure($io, $exception);
        } catch (\Throwable $exception) {
            $io->error('Transaction aborted: '.$exception->getMessage());

            return Command::FAILURE;
        }

        return $this->translate($result, $io, $season, sprintf(
            'Successfully imported "%s" into %s. Logged %d player placements.',
            $title,
            $season->getName(),
            count($placements),
        ));
    }

    /**
     * A 2v2 event, imported as one tournament through its roster.
     *
     * @param list<TeamPlacement> $teams
     */
    private function importTeamEvent(
        string $title,
        string $date,
        Season $season,
        array $teams,
        string $filePath,
        ?string $challongeUrl,
        SymfonyStyle $io,
        ?ChallongeSnapshot $snapshot,
        ?string $snapshotPath,
    ): int {
        try {
            $outcome = null !== $snapshot && null !== $snapshotPath && null !== $challongeUrl
                ? $this->replayImportService->importTeamEvent(
                    title: $title,
                    heldOn: $date,
                    seasonSlug: $season->getSlug(),
                    teams: $teams,
                    sourceFilePath: $filePath,
                    snapshot: $snapshot,
                    snapshotPath: $snapshotPath,
                    challongeUrl: $challongeUrl,
                )
                : $this->importService->importTeamEvent(
                    title: $title,
                    heldOn: $date,
                    seasonSlug: $season->getSlug(),
                    teams: $teams,
                    challongeUrl: $challongeUrl,
                    sourceFilePath: $filePath,
                );
        } catch (LedgerWriteException|ImportFileWriteException $exception) {
            return $this->showLedgerFailure($io, $exception);
        } catch (\Throwable $exception) {
            $io->error('Transaction aborted: '.$exception->getMessage());

            return Command::FAILURE;
        }

        if ([] !== $outcome->unclaimed) {
            $io->note(sprintf(
                '%d of the %d teams %s unclaimed: %s. %s a rank and no points, and can be claimed with app:team claim.',
                count($outcome->unclaimed),
                $outcome->teams,
                1 === count($outcome->unclaimed) ? 'is' : 'are',
                implode(', ', $outcome->unclaimed),
                1 === count($outcome->unclaimed) ? 'It holds' : 'They hold',
            ));
        }

        /*
         * Loud rather than incidental. Nobody is meant to enter twice, and the
         * roster keeps both places on record, so the one thing that must not
         * happen quietly is the decision about which of them was scored.
         */
        if ([] !== $outcome->inTwoTeams) {
            $io->warning(sprintf(
                '%s in more than one team. Every place is on record, but only the better finish is scored.',
                1 === count($outcome->inTwoTeams)
                    ? sprintf('%s is', $outcome->inTwoTeams[0])
                    : sprintf('%s are', implode(', ', $outcome->inTwoTeams)),
            ));
        }

        return $this->translate($outcome->result, $io, $season, sprintf(
            'Successfully imported "%s" into %s as a team event. Logged %d player placements across %d teams.',
            $title,
            $season->getName(),
            $outcome->placements,
            $outcome->teams,
        ));
    }

    /**
     * The outcome, said out loud. Both imports report the same four, and only
     * the sentence for the one that worked differs.
     */
    private function translate(
        TournamentImportResult $result,
        SymfonyStyle $io,
        Season $season,
        string $imported,
    ): int {
        return match ($result) {
            TournamentImportResult::Imported => $this->showImported($io, $imported),

            TournamentImportResult::InvalidDate => $this->showError(
                $io,
                'Invalid date format provided. Please use YYYY-MM-DD.',
            ),

            TournamentImportResult::SeasonNotFound => $this->showError(
                $io,
                sprintf('Season "%s" does not exist.', $season->getSlug()),
            ),

            /*
             * The same answer the command's own emptiness check gives, so a
             * file with nothing in it and a roster of nothing but `bye` do not
             * come back with two different exit codes for one condition.
             */
            TournamentImportResult::NoPlacements => $this->showInvalid(
                $io,
                'There is nothing in that file to import.',
            ),
        };
    }

    /**
     * @return ?string null when the file cannot be read
     */
    private function readFile(string $filePath, SymfonyStyle $io): ?string
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            $io->error(
                sprintf(
                    'File path "%s" is unreadable or does not exist.',
                    $filePath,
                ),
            );

            return null;
        }

        $contents = @file_get_contents($filePath);

        if (false === $contents) {
            $io->error('Failed to open file stream sequence handles.');

            return null;
        }

        return $contents;
    }

    private function resolveSnapshot(?string $challongeUrl, ?string $snapshotPath, SymfonyStyle $io): ChallongeSnapshot|false|null
    {
        if (null === $challongeUrl) {
            return null;
        }

        try {
            $url = ChallongeUrl::fromString($challongeUrl);
            $path = $snapshotPath ?? $this->snapshotFiles->pathFor($url->slug);
            $snapshot = is_file($path)
                ? $this->snapshotReader->readFile($path)
                : $this->challongeFetcher->fetch($url);

            if ($snapshot->slug !== $url->slug) {
                throw new \RuntimeException(sprintf('Snapshot "%s" belongs to "%s", not "%s".', $path, $snapshot->slug, $url->slug));
            }

            return $snapshot;
        } catch (\Throwable $exception) {
            $io->error('The Challonge bracket could not be prepared: '.$exception->getMessage());

            if ($io->isVerbose()) {
                $io->writeln($exception->getTraceAsString());
            }

            return false;
        }
    }

    private function showImported(SymfonyStyle $io, string $message): int
    {
        $io->success($message);

        return Command::SUCCESS;
    }

    private function showLedgerFailure(SymfonyStyle $io, \Throwable $exception): int
    {
        $io->error(
            'The import was cancelled because the recovery ledger could not be updated.',
        );

        if ($io->isVerbose()) {
            $io->writeln($exception->getMessage());
        }

        return Command::FAILURE;
    }

    private function showError(SymfonyStyle $io, string $message): int
    {
        $io->error($message);

        return Command::FAILURE;
    }

    private function showInvalid(SymfonyStyle $io, string $message): int
    {
        $io->error($message);

        return Command::INVALID;
    }
}
