<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\ChallongeArchiveOutcome;
use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeUrl;
use App\Entity\Tournament;
use App\Exception\ChallongeSnapshotReadException;
use App\Exception\InvalidChallongeSlugException;
use App\Exception\InvalidChallongeUrlException;
use App\Exception\LedgerWriteException;
use App\Repository\TournamentRepository;
use App\Service\ChallongeArchiveResult;
use App\Service\ChallongeArchiveService;
use App\Service\ChallongeSnapshotReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes a captured bracket's stages, entrants, matches and games against the
 * event that was imported from it.
 *
 * Offline, and that is the point: it reads `var/data/challonge/<slug>.json`,
 * which is tracked by git, and never Challonge. So it writes a ledger line
 * like every other admin action, and a replay of `repeat.sh` rebuilds all
 * nine hundred and fifty-one matches without asking whether the brackets are
 * still there.
 *
 * The bracket names the event rather than the other way round. Every import
 * records its `--challonge` URL, so the slug is enough to find the tournament
 * it produced — and an event that recorded no bracket cannot be archived,
 * because the replay line would have nothing to find it by.
 *
 * Running it again is safe by construction. Every row is looked up by its
 * natural key before it is written, so a second run repairs and a bracket
 * corrected upstream is re-read rather than duplicated.
 */
#[AsCommand(
    name: 'app:archive-challonge',
    description: 'Archives a captured bracket against the event it was imported into.',
)]
final class ArchiveChallongeCommand extends Command
{
    public function __construct(
        private readonly ChallongeSnapshotReader $snapshots,
        private readonly ChallongeArchiveService $archive,
        private readonly TournamentRepository $tournaments,
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
                Reads the snapshot in <info>var/data/challonge/</info> and writes every stage,
                entrant, match and game it holds against the event that was imported
                from that bracket. Capture the bracket first with
                <info>app:fetch-challonge</info>; nothing here touches the network.

                It scores nothing. The finishing order and the points are the import's,
                and archiving an event leaves the leaderboard exactly as it was.

                A 2v2 event archives its entrants and nothing else. A team match records
                only the aggregate of its individual matchups, so there is no
                blader-level match to write, and the teams are already on record.

                An entrant nobody is called is archived under the spelling the bracket
                used, attached to nobody. File the alias with <info>app:alias add</info> and run
                this again.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $snapshot = $this->snapshots->read($this->slug((string) $input->getArgument('bracket')));
        } catch (InvalidChallongeUrlException|InvalidChallongeSlugException|ChallongeSnapshotReadException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $events = $this->eventsFrom($snapshot);

        if (1 !== count($events)) {
            return $this->cannotName($io, $snapshot, $events);
        }

        $tournament = $events[0];

        try {
            $outcome = $this->archive->archive($tournament, $snapshot);
        } catch (LedgerWriteException $exception) {
            $io->error('The bracket was left unarchived because the recovery ledger could not be updated.');

            if ($io->isVerbose()) {
                $io->writeln($exception->getMessage());
            }

            return Command::FAILURE;
        }

        return $this->report($io, $tournament, $snapshot, $outcome);
    }

    /**
     * Accepts a slug or any URL Challonge hands out, because both are lying
     * around: the snapshots are named by slug and `repeat.sh` is full of URLs.
     */
    private function slug(string $bracket): string
    {
        $bracket = trim($bracket);

        return ChallongeUrl::isSlug($bracket)
            ? $bracket
            : ChallongeUrl::fromString($bracket)->slug;
    }

    /**
     * The events imported from this bracket.
     *
     * @return list<Tournament>
     */
    private function eventsFrom(ChallongeSnapshot $snapshot): array
    {
        $events = [];

        foreach ($this->tournaments->everyEventWithABracket() as $tournament) {
            try {
                $slug = ChallongeUrl::fromString((string) $tournament->getChallongeUrl())->slug;
            } catch (InvalidChallongeUrlException) {
                continue;
            }

            if ($slug === $snapshot->slug) {
                $events[] = $tournament;
            }
        }

        return $events;
    }

    /**
     * @param list<Tournament> $events
     */
    private function cannotName(SymfonyStyle $io, ChallongeSnapshot $snapshot, array $events): int
    {
        if ([] === $events) {
            $io->error(sprintf('No event on record was imported from "%s".', $snapshot->slug));
            $io->text(sprintf(
                'An archive is written against the event the bracket produced, so import it first: app:import-tournament ... --challonge=%s',
                $snapshot->sourceUrl,
            ));

            return Command::FAILURE;
        }

        $io->error(sprintf('%d events name "%s" as their bracket, and only one of them can be the one it was.', count($events), $snapshot->slug));

        foreach ($events as $event) {
            $io->text(sprintf(' - %s (%s)', $event->getTitle(), $event->getHeldOn()->format('Y-m-d')));
        }

        return Command::FAILURE;
    }

    private function report(
        SymfonyStyle $io,
        Tournament $tournament,
        ChallongeSnapshot $snapshot,
        ChallongeArchiveOutcome $outcome,
    ): int {
        if (!$outcome->wasArchived()) {
            return $this->refused($io, $tournament, $snapshot, $outcome->result);
        }

        $io->success(sprintf(
            'Archived %s into "%s": %s, %s, %s, %s.',
            $snapshot->slug,
            $tournament->getTitle(),
            $this->count($outcome->stages, 'stage'),
            $this->count($outcome->participants, 'entrant'),
            $this->count($outcome->matches, 'match', 'matches'),
            $this->count($outcome->games, 'game'),
        ));

        $io->text(sprintf('%d of the entrants are bladers the league knows.', $outcome->bladers));

        if ($outcome->discarded > 0) {
            $io->text(sprintf('%s dropped: the bracket no longer has them.', $this->count($outcome->discarded, 'row')));
        }

        if ([] !== $outcome->unrecognised) {
            $io->warning(sprintf(
                'Nobody is called %s. Those entrants are archived with their matches and attached to nobody; file the aliases with app:alias add and run this again.',
                implode(', ', array_map(static fn (string $name): string => sprintf('"%s"', $name), $outcome->unrecognised)),
            ));
        }

        /*
         * Said separately from the names nobody answers to, because the answer
         * is different. An alias cannot settle a spelling two bladers already
         * share — AliasService refuses to file one onto a blader's own name —
         * so pointing an operator at app:alias add here would send them to a
         * refusal.
         */
        if ([] !== $outcome->collisions) {
            $io->warning(sprintf(
                "More than one blader already answers to a name this bracket used, so those entrants are attached to nobody:\n\n%s\n\nNo alias can settle that. Two rows for one person is a merge; a blader whose name shadows an alias is the alias to remove.",
                implode("\n", array_map(static fn (string $problem): string => ' - '.$problem, $outcome->collisions)),
            ));
        }

        return Command::SUCCESS;
    }

    private function refused(
        SymfonyStyle $io,
        Tournament $tournament,
        ChallongeSnapshot $snapshot,
        ChallongeArchiveResult $result,
    ): int {
        return match ($result) {
            ChallongeArchiveResult::TeamEvent => $this->said($io, $this->teamEvent($tournament, $snapshot)),

            ChallongeArchiveResult::NoBracketRecorded => $this->refuse($io, sprintf(
                '"%s" does not record which bracket it came from, so an archive of it could never be replayed.',
                $tournament->getTitle(),
            )),

            ChallongeArchiveResult::NotThisBracket => $this->refuse($io, sprintf(
                '"%s" was imported from a different bracket to "%s".',
                $tournament->getTitle(),
                $snapshot->slug,
            )),

            ChallongeArchiveResult::Archived => Command::SUCCESS,
        };
    }

    /**
     * Why a team event archives nothing, said either way round.
     *
     * Normally the event is what knows: a team event is declared at import and
     * its teams are that declaration's trace. A bracket that declares itself
     * one is the other way round, and worth saying differently — the event was
     * imported without `--team`, so it has no teams and there is nothing to
     * point at.
     */
    private function teamEvent(Tournament $tournament, ChallongeSnapshot $snapshot): string
    {
        if (!$tournament->isTeamEvent()) {
            return sprintf(
                '"%s" says it is a team tournament, so none of it is archived: its entrants are teams, and a team match records only the aggregate of its individual matchups. "%s" holds no teams, which means it was imported without --team.',
                $snapshot->slug,
                $tournament->getTitle(),
            );
        }

        return sprintf(
            '"%s" is a 2v2 event, so nothing but its entrants is archived: %s on record. A team match records only the aggregate of its individual matchups, and nothing in it says which half of either team played which.',
            $tournament->getTitle(),
            $this->count($tournament->getTeams()->count(), 'entrant'),
        );
    }

    /**
     * Nothing to do is not a failure. A backfill walks every event, and two of
     * them are team events.
     */
    private function said(SymfonyStyle $io, string $message): int
    {
        $io->success($message);

        return Command::SUCCESS;
    }

    private function refuse(SymfonyStyle $io, string $message): int
    {
        $io->error($message);

        return Command::FAILURE;
    }

    private function count(int $count, string $singular, ?string $plural = null): string
    {
        if (0 === $count) {
            return sprintf('no %s', $plural ?? $singular.'s');
        }

        return sprintf('%d %s', $count, 1 === $count ? $singular : ($plural ?? $singular.'s'));
    }
}
