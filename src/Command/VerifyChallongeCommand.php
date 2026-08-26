<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeUrl;
use App\Dto\SnapshotDifference;
use App\Exception\ChallongeFetchException;
use App\Exception\ChallongeSnapshotReadException;
use App\Exception\InvalidChallongeSlugException;
use App\Exception\InvalidChallongeUrlException;
use App\Service\ChallongeFetcher;
use App\Service\ChallongeSnapshotDiffer;
use App\Service\ChallongeSnapshotReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-fetches a bracket and says what it has changed since it was captured.
 *
 * A snapshot is the record, and that cuts both ways: nothing downstream can be
 * changed by an edit made on Challonge, and nothing downstream finds out about
 * one either. An evening corrected a week after the import — a scoreline
 * fixed, an entrant renamed, a match added — is invisible until somebody
 * looks. This is the looking.
 *
 * It compares everything except `fetched_at`, which is the field a fetch
 * rewrites on every run. That is exactly why "re-fetch it and read the git
 * diff" does not answer the question: an unchanged bracket produces one line
 * of diff that way, so a real change and no change at all look much the same
 * at a glance. Everything else in the file is byte-stable — re-fetching
 * `nppk0890` moved that line and nothing else — so here, an unchanged bracket
 * has nothing to say.
 *
 * It writes nothing: no snapshot, no rows, no ledger line. Refreshing a
 * capture is still `app:fetch-challonge`, deliberately, because overwriting
 * the record is a decision somebody makes after reading this.
 */
#[AsCommand(
    name: 'app:verify-challonge',
    description: 'Re-fetches a bracket and diffs it against the captured snapshot.',
)]
final class VerifyChallongeCommand extends Command
{
    /**
     * How many differences to print before the list stops being a list.
     */
    private const int LEGIBLE = 20;

    public function __construct(
        private readonly ChallongeSnapshotReader $snapshots,
        private readonly ChallongeFetcher $fetcher,
        private readonly ChallongeSnapshotDiffer $differ,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'bracket',
                InputArgument::REQUIRED,
                'The bracket slug, or any URL that names it',
            )
            ->addUsage('nppk0890')
            ->addUsage('https://challonge.com/nppk0890')
            ->setHelp(<<<'HELP'
                Fetches the bracket again and compares it, field by field, against
                <info>var/data/challonge/&lt;slug&gt;.json</info>. Everything is compared except
                <info>fetched_at</info>, which every fetch rewrites.

                The bracket is fetched from the URL the snapshot recorded, so a
                capture made through an invite link or a subdomain is re-read the same
                way it was read the first time.

                It exits non-zero when the bracket has changed, so a cron can shout.
                Nothing is written either way: if the change is one to keep, capture it
                with <info>app:fetch-challonge</info> and archive it again.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $stored = $this->snapshots->read($this->slug((string) $input->getArgument('bracket')));
        } catch (InvalidChallongeUrlException|InvalidChallongeSlugException|ChallongeSnapshotReadException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $io->text(sprintf('Re-fetching %s', $stored->sourceUrl));

        try {
            $fetched = $this->fetcher->fetch(ChallongeUrl::fromString($stored->sourceUrl));
        } catch (ChallongeFetchException|InvalidChallongeUrlException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        return $this->report($io, $stored, $this->differ->compare($stored, $fetched));
    }

    private function slug(string $bracket): string
    {
        $bracket = trim($bracket);

        return ChallongeUrl::isSlug($bracket)
            ? $bracket
            : ChallongeUrl::fromString($bracket)->slug;
    }

    /**
     * @param list<SnapshotDifference> $differences
     */
    private function report(SymfonyStyle $io, ChallongeSnapshot $stored, array $differences): int
    {
        if ([] === $differences) {
            $io->success(sprintf(
                '%s is what was captured on %s: %d matches across %d %s, unchanged.',
                $stored->slug,
                $stored->fetchedAt->format('j F Y'),
                $stored->matchCount(),
                count($stored->stages),
                1 === count($stored->stages) ? 'stage' : 'stages',
            ));

            return Command::SUCCESS;
        }

        $io->warning(sprintf(
            '%s has changed in %d %s since it was captured on %s.',
            $stored->slug,
            count($differences),
            1 === count($differences) ? 'place' : 'places',
            $stored->fetchedAt->format('j F Y'),
        ));

        foreach (array_slice($differences, 0, self::LEGIBLE) as $difference) {
            $io->text(' - '.$difference->describe());
        }

        if (count($differences) > self::LEGIBLE) {
            $io->text(sprintf(' … and %d more.', count($differences) - self::LEGIBLE));
        }

        $io->newLine();
        $io->text('Nothing has been written. Capture the change with app:fetch-challonge, then archive it again with app:archive-challonge.');

        return Command::FAILURE;
    }
}
