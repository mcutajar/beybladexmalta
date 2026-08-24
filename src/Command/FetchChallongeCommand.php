<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\ChallongeUrl;
use App\Exception\ChallongeFetchException;
use App\Exception\ChallongeSnapshotWriteException;
use App\Exception\InvalidChallongeUrlException;
use App\Service\ChallongeFetcher;
use App\Service\ChallongeSnapshotWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The fetch step, usable before any of the import pipeline exists.
 *
 * Deliberately writes no ledger line. The ledger is replayed offline against
 * an empty database, so a line that fetches a URL would make a replay depend
 * on Challonge still serving the same bracket a year from now. The snapshot
 * this writes is the thing the ledger will point at.
 */
#[AsCommand(
    name: 'app:fetch-challonge',
    description: 'Captures a Challonge bracket as var/data/challonge/<slug>.json.',
)]
final class FetchChallongeCommand extends Command
{
    public function __construct(
        private readonly ChallongeFetcher $fetcher,
        private readonly ChallongeSnapshotWriter $snapshotWriter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'url',
            InputArgument::REQUIRED,
            'The bracket URL, in any shape Challonge hands out',
        );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);

        try {
            $url = ChallongeUrl::fromString((string) $input->getArgument('url'));
        } catch (InvalidChallongeUrlException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $replacing = is_file($this->snapshotWriter->pathFor($url->slug));

        $io->text(sprintf('Fetching %s', $url->moduleUrl()));

        try {
            $snapshot = $this->fetcher->fetch($url);
            $filePath = $this->snapshotWriter->write($snapshot);
        } catch (ChallongeFetchException|ChallongeSnapshotWriteException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if (!$snapshot->hasStandings()) {
            $io->warning(
                'The page carried no standings table. A bracket that has one only renders it when show_standings=1 is sent.',
            );
        }

        $io->success(sprintf(
            '%s %s as %s — %d matches across %d %s (%d KB).',
            $replacing ? 'Refreshed' : 'Captured',
            $snapshot->slug,
            $filePath,
            $snapshot->matchCount(),
            count($snapshot->stages),
            1 === count($snapshot->stages) ? 'stage' : 'stages',
            (int) ceil(((int) @filesize($filePath)) / 1024),
        ));

        return Command::SUCCESS;
    }
}
