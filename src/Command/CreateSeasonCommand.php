<?php

namespace App\Command;

use App\Entity\Season;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
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
        private readonly KernelInterface $kernel
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::OPTIONAL, 'The unique URL-safe identifier (e.g., season-x)')
            ->addArgument('name', InputArgument::OPTIONAL, 'The display name of the season (e.g., Season X)');
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
                // Basic slug validation format helper
                if (!preg_match('/^[a-z0-match0-9-_]+$/', trim($answer))) {
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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = $input->getArgument('slug');
        $name = $input->getArgument('name');

        $logFilePath = $this->kernel->getProjectDir() . '/var/log/command_ledger.sh';

        if (!$slug || !$name) {
            $io->error('Both a unique identifier slug and a display name are required.');
            return Command::FAILURE;
        }

        $slug = trim($slug);
        $name = trim($name);

        // Check if the season already exists
        $seasonRepository = $this->entityManager->getRepository(Season::class);
        $existingSeason = $seasonRepository->findOneBy(['slug' => $slug]);

        if ($existingSeason) {
            $io->warning(sprintf('The season context with slug "%s" already exists ("%s").', $slug, $existingSeason->getName()));
            return Command::SUCCESS;
        }

        // Persist the new season row record mapping
        $season = new Season();
        $season->setSlug($slug);
        $season->setName($name);

        $this->entityManager->persist($season);
        $this->entityManager->flush();

        // --- EVENT SOURCING LOGGER ADDITION ---
        $escapedSlug = escapeshellarg($slug);
        $escapedName = escapeshellarg($name);

        // Build the non-interactive variant execution line
        $commandString = sprintf("php bin/console app:create-season %s %s\n", $escapedSlug, $escapedName);

        // Append to the target recovery shell ledger script file
        file_put_contents($logFilePath, $commandString, FILE_APPEND | LOCK_EX);
        // --------------------------------------

        $io->success(sprintf('Successfully initialized season "%s" [%s] and recorded it to the ledger!', $name, $slug));
        return Command::SUCCESS;
    }
}
