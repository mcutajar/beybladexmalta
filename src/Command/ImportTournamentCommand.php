<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\TournamentPlacement;
use App\Entity\Season;
use App\Exception\ImportFileWriteException;
use App\Exception\LedgerWriteException;
use App\Repository\SeasonRepository;
use App\Service\FlusherInterface;
use App\Service\PlacementListParser;
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
            );
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

        $placements = $this->readPlacements($filePath, $io);

        if (null === $placements) {
            return Command::FAILURE;
        }

        if ([] === $placements) {
            $io->error(
                sprintf('The file "%s" holds no placements to import.', $filePath),
            );

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

        return $this->import(
            title: $title,
            date: $date,
            season: $season,
            placements: $placements,
            filePath: $filePath,
            challongeUrl: null !== $challongeUrl ? (string) $challongeUrl : null,
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
                seasonSlug: (string) $season->getSlug(),
                placements: $placements,
                challongeUrl: $challongeUrl,
                knockoutWinner: $knockoutWinner,
                sourceFilePath: $filePath,
            );
        } catch (LedgerWriteException|ImportFileWriteException $exception) {
            $io->error(
                'The import was cancelled because the recovery ledger could not be updated.',
            );

            if ($io->isVerbose()) {
                $io->writeln($exception->getMessage());
            }

            return Command::FAILURE;
        } catch (\Throwable $exception) {
            $io->error('Transaction aborted: '.$exception->getMessage());

            return Command::FAILURE;
        }

        return match ($result) {
            TournamentImportResult::Imported => $this->showImported(
                io: $io,
                title: $title,
                season: $season,
                placementCount: count($placements),
            ),

            TournamentImportResult::InvalidDate => $this->showError(
                $io,
                'Invalid date format provided. Please use YYYY-MM-DD.',
            ),

            TournamentImportResult::SeasonNotFound => $this->showError(
                $io,
                sprintf('Season "%s" does not exist.', $season->getSlug()),
            ),

            TournamentImportResult::NoPlacements => $this->showError(
                $io,
                'The placement list is empty.',
            ),
        };
    }

    /**
     * @return ?list<TournamentPlacement> null when the file cannot be read
     */
    private function readPlacements(string $filePath, SymfonyStyle $io): ?array
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

        return $this->placementListParser->parse($contents);
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
            $choices[(string) $season->getName()] = (string) $season->getSlug();
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

    private function showImported(
        SymfonyStyle $io,
        string $title,
        Season $season,
        int $placementCount,
    ): int {
        $io->success(
            sprintf(
                'Successfully imported "%s" into %s. Logged %d player placements.',
                $title,
                (string) $season->getName(),
                $placementCount,
            ),
        );

        return Command::SUCCESS;
    }

    private function showError(SymfonyStyle $io, string $message): int
    {
        $io->error($message);

        return Command::FAILURE;
    }
}
