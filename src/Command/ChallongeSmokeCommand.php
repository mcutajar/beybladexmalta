<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\ChallongeSmokeFinding;
use App\Dto\ChallongeSmokeReport;
use App\Dto\ChallongeUrl;
use App\Exception\ChallongeFetchException;
use App\Exception\InvalidChallongeUrlException;
use App\Service\ChallongeFetcher;
use App\Service\ChallongeSmokeCheck;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The smoke check on its own, against a live bracket or a page saved from one.
 *
 * The same check runs at the top of every fetch, so this command exists for
 * the two occasions the fetch cannot serve: a scheduled run, which wants to
 * know days before an event that Challonge has changed something, and a
 * diagnosis, which wants the whole checklist for a page it must not capture.
 *
 * It writes nothing, anywhere, either way.
 */
#[AsCommand(
    name: 'app:challonge-smoke',
    description: 'Checks that a Challonge module page is still the page the importer reads.',
)]
final class ChallongeSmokeCommand extends Command
{
    /**
     * What the scheduled run checks when it is given no URL.
     *
     * A finished league event, so nothing about its shape can move except
     * Challonge moving it — which is the entire signal this is watching for.
     *
     * The day this bracket is deleted or made private the fetch fails before
     * the check runs, which the workflow files under "could not reach
     * Challonge" rather than as a route change. That is the right answer, and
     * the fix is to point this constant at another finished event.
     */
    private const KNOWN_BRACKET = 'https://challonge.com/nppk0890';

    public function __construct(
        private readonly ChallongeFetcher $fetcher,
        private readonly ChallongeSmokeCheck $smokeCheck,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'url',
                InputArgument::OPTIONAL,
                'The bracket to check',
                self::KNOWN_BRACKET,
            )
            ->addOption(
                'file',
                null,
                InputOption::VALUE_REQUIRED,
                'Check a module page already on disk instead of fetching one',
            );
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $io = new SymfonyStyle($input, $output);

        $file = $input->getOption('file');

        if (is_string($file) && '' !== $file) {
            if (!is_file($file) || !is_readable($file)) {
                $io->error(sprintf('There is no readable page at %s.', $file));

                return Command::INVALID;
            }

            return $this->report($io, $this->smokeCheck->check((string) file_get_contents($file), $file));
        }

        try {
            $url = ChallongeUrl::fromString((string) $input->getArgument('url'));
        } catch (InvalidChallongeUrlException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $io->text(sprintf('Reading %s', $url->moduleUrl()));

        try {
            $html = $this->fetcher->fetchPage($url);
        } catch (ChallongeFetchException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        return $this->report($io, $this->smokeCheck->check($html, $url->moduleUrl()));
    }

    /**
     * The whole checklist, passes included. A run that only printed the
     * failure would say what broke and nothing about how much of the page
     * still stands, which on the day it matters is half the answer.
     */
    private function report(SymfonyStyle $io, ChallongeSmokeReport $report): int
    {
        $io->table(
            ['', 'Expected', 'What came back'],
            array_map(
                static fn (ChallongeSmokeFinding $finding): array => [
                    $finding->outcome->value,
                    $finding->expectation,
                    $finding->detail,
                ],
                $report->findings,
            ),
        );

        if (!$report->passed()) {
            $io->error($report->problem());

            return Command::FAILURE;
        }

        $io->success(sprintf('%s still reads the way the importer expects.', $report->source));

        return Command::SUCCESS;
    }
}
