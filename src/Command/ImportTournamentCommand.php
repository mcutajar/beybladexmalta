<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\TeamPlacement;
use App\Dto\TournamentPlacement;
use App\Entity\Season;
use App\Exception\ImportFileWriteException;
use App\Exception\LedgerWriteException;
use App\Repository\SeasonRepository;
use App\Service\FlusherInterface;
use App\Service\PlacementListParser;
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
        private readonly FlusherInterface $flusher,
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
        $knockoutWinner = $input->getOption('knockout');

        $teamEvent = (bool) $input->getOption('team');

        if ($teamEvent && null !== $knockoutWinner) {
            $io->error(
                'A team event awards no knockout bonus, so --team and --knockout cannot be used together.',
            );

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

        $seasonSlug = $this->resolveSeasonSlug(
            $input->getOption('season'),
            $io,
        );

        if (null === $seasonSlug) {
            return Command::FAILURE;
        }

        $season = $this->seasonRepository->findBySlug($seasonSlug)
            ?? $this->createSeason($seasonSlug, $io);

        if (null === $season) {
            $io->warning(
                'Tournament import cancelled by user due to missing season context.',
            );

            return Command::INVALID;
        }

        $challongeUrl = null !== $challongeUrl ? (string) $challongeUrl : null;

        return $teamEvent
            ? $this->importTeamEvent(
                title: $title,
                date: $date,
                season: $season,
                teams: $teams,
                filePath: $filePath,
                challongeUrl: $challongeUrl,
                io: $io,
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
    ): int {
        try {
            $result = $this->importService->import(
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
    ): int {
        try {
            $outcome = $this->importService->importTeamEvent(
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

    /**
     * Falls back to an interactive pick when no slug was supplied.
     */
    private function resolveSeasonSlug(
        mixed $seasonSlug,
        SymfonyStyle $io,
    ): ?string {
        if (null !== $seasonSlug && '' !== trim((string) $seasonSlug)) {
            return trim((string) $seasonSlug);
        }

        $seasons = $this->seasonRepository->findAll();

        if ([] === $seasons) {
            $io->error(
                'No seasons found in the database. Please specify a new season via the --season flag to auto-create it.',
            );

            return null;
        }

        /*
         * The choice question returns the displayed name, so map each
         * displayed season name back to its slug.
         *
         * @var array<string, string> $choices
         */
        $choices = [];

        foreach ($seasons as $season) {
            $choices[$season->getName()] = $season->getSlug();
        }

        $io->section('Season Selection Context');

        $selectedName = $io->choice(
            'This tournament must belong to a season. Please select from the available options:',
            array_keys($choices),
        );

        return $choices[$selectedName];
    }

    /**
     * @return ?Season null when the operator declines the creation
     */
    private function createSeason(string $seasonSlug, SymfonyStyle $io): ?Season
    {
        $inferredName = ucwords(str_replace(['-', '_'], ' ', $seasonSlug));

        $io->section(sprintf('New Season Generation: "%s"', $seasonSlug));

        $confirmed = $io->confirm(
            sprintf(
                'The season context "%s" does not exist. Would you like to create it automatically now?',
                $inferredName,
            ),
            true,
        );

        if (!$confirmed) {
            return null;
        }

        $season = new Season();
        $season->setSlug($seasonSlug);
        $season->setName($inferredName);

        $this->seasonRepository->save($season);
        $this->flusher->flush();

        $io->info(sprintf('Created new seasonal registry: %s', $inferredName));

        return $season;
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
