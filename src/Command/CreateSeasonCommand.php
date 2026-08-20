<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Season;
use App\Exception\LedgerWriteException;
use App\Repository\SeasonRepository;
use App\Service\FlusherInterface;
use App\Service\LedgerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:create-season',
    description: 'Creates a new competitive season context and logs it to the command ledger.',
)]
class CreateSeasonCommand extends Command
{
    public function __construct(
        private readonly SeasonRepository $seasonRepository,
        private readonly LedgerService $ledgerService,
        private readonly FlusherInterface $flusher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::OPTIONAL, 'The unique URL-safe identifier (e.g., season-x)')
            ->addArgument('name', InputArgument::OPTIONAL, 'The display name of the season (e.g., Season X)')
            ->addArgument('requiresPayment', InputArgument::OPTIONAL, 'Does this competitive season context require entry payment validation? (1 for yes, 0 for no)');
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $io = new SymfonyStyle($input, $output);

        // 1. Prompt for Slug if missing
        if (null === $input->getArgument('slug')) {
            $question = new Question('Enter a unique identifier slug for the season (e.g., season-2):');
            $question->setValidator(function ($answer) {
                if (empty(trim($answer))) {
                    throw new \RuntimeException('The season slug cannot be left blank.');
                }
                if (!preg_match('/^[a-z0-9-_]+$/', trim($answer))) {
                    throw new \RuntimeException('The slug must contain only lowercase letters, numbers, hyphens, or underscores.');
                }

                return trim($answer);
            });
            $slug = $io->askQuestion($question);
            $input->setArgument('slug', $slug);
        }

        // 2. Prompt for Name if missing
        if (null === $input->getArgument('name')) {
            $slug = $input->getArgument('slug');
            $defaultName = ucwords(str_replace(['-', '_'], ' ', $slug));

            $question = new Question(sprintf('Enter the display name for the season [%s]:', $defaultName), $defaultName);
            $question->setValidator(function ($answer) {
                if (empty(trim($answer))) {
                    throw new \RuntimeException('The season display name cannot be left blank.');
                }

                return trim($answer);
            });
            $name = $io->askQuestion($question);
            $input->setArgument('name', $name);
        }

        // 3. Prompt for Payment Validation toggle if missing
        if (null === $input->getArgument('requiresPayment')) {
            $question = new ConfirmationQuestion('Does this season require entry dues/payment authorization verification? (yes/no) [no]:', false);
            $requiresPayment = $io->askQuestion($question);
            $input->setArgument('requiresPayment', $requiresPayment ? '1' : '0');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = $input->getArgument('slug');
        $name = $input->getArgument('name');

        // Read raw argument input and securely parse it into a real boolean state
        $rawRequiresPayment = $input->getArgument('requiresPayment');
        $requiresPayment = filter_var($rawRequiresPayment, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

        if (!$slug || !$name) {
            $io->error('Both a unique identifier slug and a display name are required.');

            return Command::FAILURE;
        }

        $slug = trim($slug);
        $name = trim($name);

        $existingSeason = $this->seasonRepository->findBySlug($slug);

        if ($existingSeason) {
            $io->warning(sprintf('The season context with slug "%s" already exists ("%s").', $slug, $existingSeason->getName()));

            return Command::SUCCESS;
        }

        $season = new Season();
        $season->setSlug($slug);
        $season->setName($name);
        $season->setRequiresPayment($requiresPayment);

        $this->seasonRepository->save($season);

        try {
            /*
             * The replay command is written inside the same transaction as
             * the flush, so the ledger can never gain a line for a season the
             * database rejected, and a failed write undoes the season.
             */
            $this->flusher->flushThen(
                fn () => $this->ledgerService->logSeasonCreation(
                    slug: $slug,
                    name: $name,
                    requiresPayment: $requiresPayment,
                ),
            );
        } catch (LedgerWriteException $exception) {
            $io->error('The season was not created because the recovery ledger could not be updated.');

            if ($io->isVerbose()) {
                $io->writeln($exception->getMessage());
            }

            return Command::FAILURE;
        } catch (\Throwable $exception) {
            $io->error('Transaction aborted: '.$exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Successfully initialized season "%s" [%s] (Requires Payment: %s) and updated the ledger!', $name, $slug, $requiresPayment ? 'YES' : 'NO'));

        return Command::SUCCESS;
    }
}
