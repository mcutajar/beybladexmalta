<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\LedgerWriteException;
use App\Service\PlayerRegistrationService;
use App\Service\RegisterSeasonPaymentResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:register-payment',
    description: 'Marks a player as paid for a specific competitive season.',
)]
final class RegisterPlayerPaymentCommand extends Command
{
    public function __construct(
        private readonly PlayerRegistrationService $registrationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'season',
                InputArgument::REQUIRED,
                'The slug of the target season, for example "season-1".',
            )
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
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

        return $this->registerPayment(
            seasonSlug: (string) $seasonArgument,
            playerName: (string) $nameArgument,
            io: $io,
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
