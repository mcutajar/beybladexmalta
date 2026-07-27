<?php

namespace App\Command;

use App\Entity\Season;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'app:create-season',
    description: 'Creates a new competitive season context and logs it to the command ledger.',
)]
class CreateSeasonCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KernelInterface $kernel,
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

        $logFilePath = $this->kernel->getProjectDir().'/var/log/command_ledger.sh';

        if (!$slug || !$name) {
            $io->error('Both a unique identifier slug and a display name are required.');

            return Command::FAILURE;
        }

        $slug = trim($slug);
        $name = trim($name);

        $seasonRepository = $this->entityManager->getRepository(Season::class);
        $existingSeason = $seasonRepository->findOneBy(['slug' => $slug]);

        if ($existingSeason) {
            $io->warning(sprintf('The season context with slug "%s" already exists ("%s").', $slug, $existingSeason->getName()));

            return Command::SUCCESS;
        }

        $season = new Season();
        $season->setSlug($slug);
        $season->setName($name);

        // Assumes your Season Entity contains a setRequiresPayment() or setIsPaidRequired() mapping setter method
        if (method_exists($season, 'setRequiresPayment')) {
            $season->setRequiresPayment($requiresPayment);
        }

        $this->entityManager->persist($season);
        $this->entityManager->flush();

        // --- EVENT SOURCING LEDGER REPLICATION ---
        $escapedSlug = escapeshellarg($slug);
        $escapedName = escapeshellarg($name);
        $paymentFlagValue = $requiresPayment ? '1' : '0';

        // Persist the explicit headless variant string directly down to the ledger
        $commandString = sprintf("php bin/console app:create-season %s %s %s\n", $escapedSlug, $escapedName, $paymentFlagValue);
        file_put_contents($logFilePath, $commandString, FILE_APPEND | LOCK_EX);
        // ------------------------------------------

        $io->success(sprintf('Successfully initialized season "%s" [%s] (Requires Payment: %s) and updated the ledger!', $name, $slug, $requiresPayment ? 'YES' : 'NO'));

        return Command::SUCCESS;
    }
}
