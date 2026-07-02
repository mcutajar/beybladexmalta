<?php

namespace App\Command;

use App\Entity\Player;
use App\Entity\Tournament;
use App\Entity\TournamentResult;
use App\Entity\Season;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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
class ImportTournamentCommand extends Command
{
    private const array F1_MATRIX = [
        1 => 25, 2 => 20, 3 => 15, 4 => 12, 5 => 10,
        6 => 8,  7 => 6,  8 => 4,  9 => 2,  10 => 1,
    ];

    private const int KNOCKOUT_WINNER_BONUS = 10;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('title', InputArgument::REQUIRED, 'The title of the tournament')
            ->addArgument('date', InputArgument::REQUIRED, 'The date of the tournament (YYYY-MM-DD)')
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the text/csv file with player names')
            ->addOption('challonge', null, InputOption::VALUE_OPTIONAL, 'Optional Challonge bracket URL')
            ->addOption('season', 's', InputOption::VALUE_REQUIRED, 'The target season slug this tournament belongs to')
            ->addOption('knockout', 'k', InputOption::VALUE_OPTIONAL, 'The name of the player who won the overall knockout bracket');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $title = $input->getArgument('title');
        $dateStr = $input->getArgument('date');
        $filePath = $input->getArgument('file');
        $challongeUrl = $input->getOption('challonge');
        $seasonSlug = $input->getOption('season');
        $knockoutWinnerName = $input->getOption('knockout'); // Capture the knockout option flag

        if (!file_exists($filePath) || !is_readable($filePath)) {
            $io->error(sprintf('File path "%s" is unreadable or does not exist.', $filePath));
            return Command::FAILURE;
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            $io->error('Failed to open file stream sequence handles.');
            return Command::FAILURE;
        }

        try {
            $date = new \DateTimeImmutable($dateStr);
        } catch (\Exception $e) {
            $io->error('Invalid date format provided. Please use YYYY-MM-DD.');
            fclose($handle);
            return Command::FAILURE;
        }

        $seasons = $this->entityManager->getRepository(Season::class)->findAll();

        if (null === $seasonSlug) {
            if (empty($seasons)) {
                $io->error('No seasons found in the database. Please specify a new season via the --season flag to auto-create it.');
                fclose($handle);
                return Command::FAILURE;
            }

            $seasonChoices = [];
            foreach ($seasons as $s) {
                $seasonChoices[$s->getSlug()] = $s->getName();
            }

            $io->section('Season Selection Context');
            $selectedName = $io->choice(
                'This tournament must belong to a season. Please select from the available options:',
                array_values($seasonChoices)
            );

            $seasonSlug = array_search($selectedName, $seasonChoices, true);
        }

        $this->entityManager->beginTransaction();
        try {
            $season = $this->entityManager->getRepository(Season::class)->findOneBy(['slug' => $seasonSlug]);

            if (!$season) {
                $inferredName = ucwords(str_replace(['-', '_'], ' ', $seasonSlug));

                $io->section(sprintf('New Season Generation: "%s"', $seasonSlug));
                $confirm = $io->confirm(
                    sprintf('The season context "%s" does not exist. Would you like to create it automatically now?', $inferredName),
                    true
                );

                if (!$confirm) {
                    $io->warning('Tournament import cancelled by user due to missing season context.');
                    $this->entityManager->rollback();
                    fclose($handle);
                    return Command::INVALID;
                }

                $season = new Season();
                $season->setSlug($seasonSlug);
                $season->setName($inferredName);

                $this->entityManager->persist($season);
                $this->entityManager->flush();

                $io->info(sprintf('Created new seasonal registry: %s', $inferredName));
            }

            $this->logger->info('Initializing database generation execution layout block maps.');

            $tournament = new Tournament();
            $tournament->setTitle($title);
            $tournament->setHeldOn($date);
            $tournament->setChallongeUrl($challongeUrl);
            $tournament->setSeason($season);

            $this->entityManager->persist($tournament);

            $rank = 1;
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                $parts = explode(',', $line);
                $playerName = trim($parts[0]);

                // Keep file-level bonus parsing fallback if you still use it ("Player, 3")
                $bonusPoints = isset($parts[1]) ? (int) trim($parts[1]) : 0;

                // Check if this specific line matches the explicit command-line --knockout flag option
                if (null !== $knockoutWinnerName && strcasecmp($playerName, trim($knockoutWinnerName)) === 0) {
                    $bonusPoints += self::KNOCKOUT_WINNER_BONUS;
                }

                $player = $this->entityManager->getRepository(Player::class)->findOneBy(['name' => $playerName]);
                if (!$player) {
                    $player = new Player();
                    $player->setName($playerName);
                    $this->entityManager->persist($player);
                    $this->logger->notice(sprintf('Implicit auto-generation of new player record proxy: "%s".', $playerName));
                }

                $f1Points = self::F1_MATRIX[$rank] ?? 0;

                $result = new TournamentResult();
                $result->setTournament($tournament);
                $result->setPlayer($player);
                $result->setRank($rank);
                $result->setF1Points($f1Points);
                $result->setBonusPoints($bonusPoints);

                $this->entityManager->persist($result);

                $this->logger->info(sprintf('Persisted result for row element: #%d.', $rank), [
                    'player' => $player->getName(),
                    'f1' => $f1Points,
                    'bonus' => $bonusPoints,
                    'total_calculated' => $result->getTotalPoints()
                ]);

                ++$rank;
            }
            fclose($handle);

            $this->logger->debug('Flushing transactions tracking maps into processing container pipeline.');
            $this->entityManager->flush();

            $this->entityManager->commit();
            $io->success(sprintf('Successfully imported "%s" into %s. Logged %d player placements.', $title, $season->getName(), $rank - 1));
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->logger->critical('Fatal validation breakdown halted deployment loop execution wrapper.', [
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            if (is_resource($handle)) {
                fclose($handle);
            }

            $io->error('Transaction aborted: '.$e->getMessage());
            return Command::FAILURE;
        }
    }
}
