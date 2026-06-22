<?php

namespace App\Command;

use App\Entity\Player;
use App\Entity\Tournament;
use App\Entity\TournamentResult;
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
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the ordered names CSV file')
            ->addArgument('challonge_url', InputArgument::OPTIONAL, 'The Challonge URL of the tournament bracket')
            ->addArgument('winner',  InputArgument::OPTIONAL, 'The name of the Blader who won the Knockout Stage top-cut');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $title = $input->getArgument('title');
        $dateStr = $input->getArgument('date');
        $filePath = $input->getArgument('file');
        $challongeUrl = $input->getArgument('challonge_url');
        $koWinnerName = $input->getArgument('winner');

        $this->logger->info('Starting tournament import sequence.', [
            'title' => $title,
            'date' => $dateStr,
            'file' => $filePath,
            'winner_option' => $koWinnerName,
            'challonge_url' => $challongeUrl
        ]);

        if (!file_exists($filePath) || !is_readable($filePath)) {
            $this->logger->error('Target CSV file path is missing or unreadable.', ['path' => $filePath]);
            $io->error(sprintf('File "%s" does not exist or is not readable.', $filePath));
            return Command::FAILURE;
        }

        try {
            $date = new \DateTime($dateStr);
        } catch (\Exception $e) {
            $this->logger->error('Failed parsing execution date string constraint.', ['input_date' => $dateStr, 'exception' => $e->getMessage()]);
            $io->error('Invalid date format provided. Please use YYYY-MM-DD.');
            return Command::INVALID;
        }

        if (($handle = fopen($filePath, 'r')) === false) {
            $this->logger->error('Low-level filesystem handle execution failed on open operation.', ['path' => $filePath]);
            $io->error('Failed to open the target CSV file.');
            return Command::FAILURE;
        }

        $this->entityManager->beginTransaction();
        $this->logger->debug('Database relational transaction scope opened.');

        try {
            $tournament = new Tournament();
            $tournament->setTitle($title);
            $tournament->setHeldOn($date);
            $tournament->setChallongeUrl($challongeUrl);
            $this->entityManager->persist($tournament);

            $rank = 1;
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $playerName = isset($row[0]) ? trim($row[0]) : '';

                if (empty($playerName)) {
                    $this->logger->debug('Skipping empty row segment line entry inside target file.');
                    continue;
                }

                if ($rank > 10) {
                    $this->logger->warning('CSV loop exceeded maximum top-10 processing bounds. Cap invoked.', ['rank_reached' => $rank, 'next_name' => $playerName]);
                    break;
                }

                $f1Points = self::F1_MATRIX[$rank] ?? 0;

                // Debug string values exactly as they are captured before comparison evaluation
                $cleanPlayer = strtolower($playerName);
                $cleanWinner = $koWinnerName ? strtolower(trim($koWinnerName)) : null;

                $this->logger->debug(sprintf('Evaluating row match credentials for rank #%d.', $rank), [
                    'raw_csv_name' => $playerName,
                    'lowered_csv_name' => $cleanPlayer,
                    'raw_winner_option' => $koWinnerName,
                    'lowered_winner_option' => $cleanWinner
                ]);

                $bonusPoints = 0;
                if ($cleanWinner && $cleanPlayer === $cleanWinner) {
                    $bonusPoints = 10;
                    $this->logger->info('Matching criterion successfully satisfied. Injecting +10 bonus units.', [
                        'player' => $playerName,
                        'assigned_rank' => $rank
                    ]);
                }

                // CASE-INSENSITIVE PLAYER LOOKUP
                $this->logger->debug('Dispatching DB query criteria lookup.', ['search_term' => $playerName]);

                $player = $this->entityManager->getRepository(Player::class)
                    ->createQueryBuilder('p')
                    ->where('LOWER(p.name) = LOWER(:name)')
                    ->setParameter('name', $playerName)
                    ->getQuery()
                    ->getOneOrNullResult();

                if (!$player) {
                    $this->logger->notice('Identity record missed database constraints mapping. Allocating new profile.', ['name' => $playerName]);
                    $player = new Player();
                    $player->setName($playerName);
                    $this->entityManager->persist($player);
                } else {
                    $this->logger->debug('Located pre-existing identity entry record.', ['id' => $player->getId(), 'db_stored_name' => $player->getName()]);
                }

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
            $this->logger->info('Database sequence safely locked and committed permanently.');

            $io->success(sprintf('Successfully imported "%s". Logged %d player placements.', $title, $rank - 1));
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->logger->critical('Fatal validation breakdown halted deployment loop execution wrapper.', [
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->entityManager->rollback();
            fclose($handle);
            $io->error('Transaction aborted: '.$e->getMessage());
            return Command::FAILURE;
        }
    }
}
