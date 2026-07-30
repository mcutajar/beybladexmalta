<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Player;
use App\Exception\LedgerWriteException;
use App\Repository\PlayerRepository;
use App\Repository\SeasonRepository;
use App\Service\PlayerRegistrationService;
use App\Service\RegisterSeasonPaymentResult;
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
    description: 'Marks a player as paid for a specific competitive season.',
)]
final class RegisterPlayerPaymentCommand extends Command
{
    public function __construct(
        private readonly PlayerRegistrationService $registrationService,
        private readonly SeasonRepository $seasonRepository,
        private readonly PlayerRepository $playerRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'season',
                InputArgument::OPTIONAL,
                'The slug of the target season, for example "season-1".',
            )
            ->addArgument(
                'name',
                InputArgument::OPTIONAL,
                'The name of the player settling registration dues.',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);

        $seasonArgument = $input->getArgument('season');
        $nameArgument = $input->getArgument('name');

        /*
         * When both arguments are provided, run once without prompting.
         */
        if (null !== $seasonArgument && null !== $nameArgument) {
            return $this->registerPayment(
                seasonSlug: (string) $seasonArgument,
                playerName: (string) $nameArgument,
                io: $io,
            );
        }

        return $this->runInteractively($io);
    }

    private function runInteractively(SymfonyStyle $io): int
    {
        do {
            $seasonSlug = $this->askForSeason($io);

            if (null === $seasonSlug) {
                return Command::FAILURE;
            }

            $playerName = $this->askForPlayerName($io);

            if (!$this->confirmPlayerCreation($playerName, $io)) {
                $io->warning('Payment processing was aborted for this player.');

                continue;
            }

            $exitCode = $this->registerPayment(
                seasonSlug: $seasonSlug,
                playerName: $playerName,
                io: $io,
            );

            /*
             * A missing season or an unexpected operational failure should
             * terminate the command rather than immediately asking for
             * another payment.
             */
            if (Command::SUCCESS !== $exitCode) {
                return $exitCode;
            }

            $io->newLine();
        } while (
            $io->confirm(
                'Would you like to register another player payment?',
                true,
            )
        );

        $io->success('All seasonal payment updates have been completed.');

        return Command::SUCCESS;
    }

    private function askForSeason(SymfonyStyle $io): ?string
    {
        $seasons = $this->seasonRepository->findAll();

        if ([] === $seasons) {
            $io->error(
                'No seasons were found. Create a season before registering payments.',
            );

            return null;
        }

        /*
         * ChoiceQuestion returns the selected displayed value, so map each
         * displayed season name back to its slug.
         *
         * @var array<string, string> $choices
         */
        $choices = [];

        foreach ($seasons as $season) {
            $choices[$season->getName()] = $season->getSlug();
        }

        $question = new ChoiceQuestion(
            'Select the competitive season',
            array_keys($choices),
        );

        $question->setErrorMessage('Season "%s" is invalid.');

        $selectedName = $io->askQuestion($question);

        if (!is_string($selectedName)) {
            throw new \LogicException('The selected season name must be a string.');
        }

        return $choices[$selectedName];
    }

    private function askForPlayerName(SymfonyStyle $io): string
    {
        $playerNames = array_map(
            static fn (Player $player): string => $player->getName(),
            $this->playerRepository->findAll(),
        );

        $question = new Question(
            'Enter the name of the player settling registration dues',
        );

        $question->setAutocompleterValues($playerNames);

        $question->setValidator(
            static function (mixed $answer): string {
                if (!is_string($answer)) {
                    throw new \RuntimeException('The player name must be text.');
                }

                $playerName = trim($answer);

                if ('' === $playerName) {
                    throw new \RuntimeException('The player name cannot be empty.');
                }

                return $playerName;
            },
        );

        $answer = $io->askQuestion($question);

        if (!is_string($answer)) {
            throw new \LogicException('The validated player name must be a string.');
        }

        return $answer;
    }

    private function confirmPlayerCreation(
        string $playerName,
        SymfonyStyle $io,
    ): bool {
        $player = $this->playerRepository->findByName($playerName);

        if (null !== $player) {
            return true;
        }

        $io->section(
            sprintf(
                'Player not found: "%s"',
                $playerName,
            ),
        );

        return $io->confirm(
            sprintf(
                'Player "%s" does not exist. Would you like to create them?',
                $playerName,
            ),
            true,
        );
    }

    private function registerPayment(
        string $seasonSlug,
        string $playerName,
        SymfonyStyle $io,
    ): int {
        $seasonSlug = trim($seasonSlug);
        $playerName = trim($playerName);

        if ('' === $seasonSlug) {
            $io->error('The season slug cannot be empty.');

            return Command::INVALID;
        }

        if ('' === $playerName) {
            $io->error('The player name cannot be empty.');

            return Command::INVALID;
        }

        try {
            $result = $this->registrationService->register(
                playerName: $playerName,
                seasonSlug: $seasonSlug,
            );
        } catch (LedgerWriteException $exception) {
            $io->error(
                'The payment was registered, but the recovery ledger could not be updated.',
            );

            if ($io->isVerbose()) {
                $io->writeln($exception->getMessage());
            }

            return Command::FAILURE;
        } catch (\Throwable $exception) {
            $io->error(
                sprintf(
                    'Payment registration failed: %s',
                    $exception->getMessage(),
                ),
            );

            return Command::FAILURE;
        }

        return match ($result) {
            RegisterSeasonPaymentResult::Registered => $this->showRegistered(
                io: $io,
                playerName: $playerName,
                seasonSlug: $seasonSlug,
            ),

            RegisterSeasonPaymentResult::AlreadyPaid => $this->showAlreadyPaid(
                io: $io,
                playerName: $playerName,
                seasonSlug: $seasonSlug,
            ),

            RegisterSeasonPaymentResult::SeasonNotFound => $this->showSeasonNotFound(
                io: $io,
                seasonSlug: $seasonSlug,
            ),
        };
    }

    private function showRegistered(
        SymfonyStyle $io,
        string $playerName,
        string $seasonSlug,
    ): int {
        $io->success(
            sprintf(
                '"%s" is now marked as paid for season "%s".',
                $playerName,
                $seasonSlug,
            ),
        );

        return Command::SUCCESS;
    }

    private function showAlreadyPaid(
        SymfonyStyle $io,
        string $playerName,
        string $seasonSlug,
    ): int {
        $io->warning(
            sprintf(
                '"%s" has already paid for season "%s".',
                $playerName,
                $seasonSlug,
            ),
        );

        return Command::SUCCESS;
    }

    private function showSeasonNotFound(
        SymfonyStyle $io,
        string $seasonSlug,
    ): int {
        $io->error(
            sprintf(
                'Season "%s" does not exist.',
                $seasonSlug,
            ),
        );

        return Command::FAILURE;
    }
}
