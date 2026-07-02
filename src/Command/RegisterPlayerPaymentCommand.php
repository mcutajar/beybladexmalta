<?php

namespace App\Command;

use App\Entity\Player;
use App\Entity\Season;
use App\Entity\SeasonRegistration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:register-payment',
    description: 'Marks a player as paid for a specific competitive season context.',
)]
class RegisterPlayerPaymentCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('season', InputArgument::OPTIONAL, 'The slug of the target season (e.g., season-1)')
            ->addArgument('name', InputArgument::OPTIONAL, 'The name of the blader settling dues');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Fetch arguments passed explicitly via CLI flags if any
        $seasonSlug = $input->getArgument('season');
        $playerName = $input->getArgument('name');

        while (true) {
            // 1. Interactive Selection for Season Context (if not already provided)
            if (null === $seasonSlug) {
                $seasons = $this->entityManager->getRepository(Season::class)->findAll();

                if (empty($seasons)) {
                    $io->error('No seasons found in the database. Please create a season first.');
                    return Command::FAILURE;
                }

                $seasonChoices = [];
                foreach ($seasons as $s) {
                    $seasonChoices[$s->getSlug()] = $s->getName();
                }

                $question = new ChoiceQuestion(
                    'Please select the active competitive season context',
                    array_values($seasonChoices)
                );
                $question->setErrorMessage('Season %s is invalid.');

                $selectedName = $io->askQuestion($question);
                $seasonSlug = array_search($selectedName, $seasonChoices, true);
            }

            // Verify target season exists
            $season = $this->entityManager->getRepository(Season::class)->findOneBy(['slug' => $seasonSlug]);
            if (!$season) {
                $io->error(sprintf('Season context "%s" does not exist in the system database maps.', $seasonSlug));
                return Command::FAILURE;
            }

            // 2. Interactive Autocomplete Prompt for Player Identity (if not already provided)
            if (null === $playerName) {
                $players = $this->entityManager->getRepository(Player::class)->findAll();
                $playerNames = array_map(static fn(Player $p) => $p->getName(), $players);

                $question = new Question('Enter the name of the Blader settling registration dues');
                $question->setAutocompleterValues($playerNames);

                $question->setValidator(function ($answer) {
                    if (empty(trim($answer))) {
                        throw new \RuntimeException('The player name identity value cannot be left blank.');
                    }
                    return trim($answer);
                });

                $playerName = $io->askQuestion($question);
            }

            $playerName = trim($playerName);

            // 3. Perform case-insensitive check for the blader name
            $playerRepository = $this->entityManager->getRepository(Player::class);
            $player = $playerRepository->createQueryBuilder('p')
                ->where('LOWER(p.name) = LOWER(:name)')
                ->setParameter('name', $playerName)
                ->getQuery()
                ->getOneOrNullResult();

            // 4. Prompt confirmation option if player profile does not exist
            if (!$player) {
                $io->section(sprintf('Identity Not Discovered: "%s"', $playerName));
                $confirm = $io->confirm(
                    sprintf('The blader identity "%s" does not exist in the database. Would you like to register them fresh right now?', $playerName),
                    true
                );

                if (!$confirm) {
                    $io->warning('Payment processing aborted for this player.');
                    goto check_loop_continue;
                }

                $player = new Player();
                $player->setName($playerName);
                $this->entityManager->persist($player);
                $this->entityManager->flush();

                $io->info(sprintf('Created new player profile token: %s', $playerName));
            }

            // 5. Update Seasonal Registration
            $registrationRepository = $this->entityManager->getRepository(SeasonRegistration::class);
            $registration = $registrationRepository->findOneBy([
                'player' => $player,
                'season' => $season
            ]);

            if (!$registration) {
                $registration = new SeasonRegistration();
                $registration->setPlayer($player);
                $registration->setSeason($season);
            }

            if ($registration->isPaid()) {
                $io->warning(sprintf('Blader "%s" has already cleared their entry balance sheets for %s.', $player->getName(), $season->getName()));
            } else {
                $registration->setPaid(true);
                $this->entityManager->persist($registration);
                $this->entityManager->flush();

                $io->success(sprintf('Successfully processed cleared entry transaction! "%s" is marked PAID for %s.', $player->getName(), $season->getName()));
            }

            // Label fallback target to handle internal early exit scenarios cleanly
            check_loop_continue:

            // Ask if user wants to run another player entry
            $continue = $io->confirm('Would you like to register another player payment?', true);
            if (!$continue) {
                break;
            }

            // Clear the player name variable so the prompt runs fresh on the next loop iteration.
            // We keep the $seasonSlug cached so you don't have to re-select the season over and over.
            $playerName = null;
            $io->newLine();
        }

        $io->success('All seasonal entry updates finalized. Exiting ledger module.');
        return Command::SUCCESS;
    }
}
