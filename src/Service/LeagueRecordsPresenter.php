<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Player;
use App\Entity\TournamentMatch;
use App\Entity\TournamentParticipant;

/**
 * Turns a slice of the archive into the records board.
 *
 * One walk over every match in scope, tallying both sides of each, and then a
 * pass over the tallies to rank the top three for each record. Nothing here reads
 * `TournamentResult`: **no league points appear on this page in either scope**,
 * because points are season-specific and a record is not. Every figure below
 * is a Beyblade point — the scoreline of a match — or a count of matches.
 *
 * The scope is applied by the query that fetches the matches, not here, so the
 * same code answers Overall and one season and the eligibility threshold is
 * evaluated against whatever it was handed. A career total cannot therefore
 * qualify anybody for a season record.
 *
 * Counting rules are `ArchivedMatchReader`'s and are shared with the player
 * profile. Two more are this page's own:
 *
 * - **Nothing is counted from the losing side.** There is no "most times shut
 *   out" and no one-point-margin statistic. A shutout is a win credited to the
 *   winner and that is the only direction it is read in.
 * - **A record with no holder is stated as empty rather than awarded to a
 *   zero.** A scope where nobody has scored a nine says so; it does not hand
 *   the record to whoever sorts first.
 *
 * @phpstan-type Tally array{player: Player, name: string, matches: int, wins: int, losses: int, draws: int, points_for: int, points_against: int, nines: int, strong: int, shutouts: int, events: array<int, true>, streak: int, streak_events: int, running: int, running_events: array<int, true>}
 * @phpstan-type RecordLeader array{name: string, player: ?Player, value: string}
 * @phpstan-type BoardRecord array{key: string, label: string, name: ?string, player: ?Player, value: ?string, note: ?string, tone: string, leaders: list<RecordLeader>}
 * @phpstan-type Rivalry array{leader: ?Player, leader_name: string, trailer: ?Player, trailer_name: string, wins: int, losses: int, draws: int, met: int}
 * @phpstan-type Board array{archived: bool, matches: int, events: int, bladers: int, points: int, minimum_matches: int, records: list<BoardRecord>}
 */
final class LeagueRecordsPresenter
{
    /**
     * How many matches a blader plays before their win rate is a record.
     *
     * The proposal drew this at twenty, which is right for the overall board
     * and wrong for a short season: Preseason 1 is four events and 197
     * matches, and exactly three bladers reach twenty inside it. Fifteen keeps
     * a rate that has settled down while leaving every scope in the archive a
     * field rather than a podium.
     */
    public const MINIMUM_MATCHES = 15;

    /** A pair has to have met this often before the board calls it a rivalry. */
    private const MINIMUM_MEETINGS = 3;

    public function __construct(private readonly ArchivedMatchReader $reader)
    {
    }

    /**
     * @param list<TournamentMatch> $matches every archived match in scope, oldest first
     *
     * @return Board
     */
    public function present(array $matches): array
    {
        /** @var array<int, Tally> $tallies keyed by blader id */
        $tallies = [];
        /** @var array<string, Rivalry> $pairs */
        $pairs = [];
        /** @var array<int, true> $events */
        $events = [];
        $played = 0;
        $points = 0;

        foreach ($matches as $match) {
            $events[(int) $match->getTournament()->getId()] = true;
            ++$played;

            foreach ([$match->getPlayer1(), $match->getPlayer2()] as $side) {
                if (null === $side) {
                    continue;
                }

                $own = $this->reader->scoreFor($match, $side);
                $points += $own ?? 0;

                $this->count($tallies, $match, $side);
            }

            $this->pair($pairs, $match);
        }

        $board = array_values($tallies);
        $rivalries = $this->rivalries($pairs);

        return [
            'archived' => [] !== $matches,
            'matches' => $played,
            'events' => count($events),
            'bladers' => count($board),
            'points' => $points,
            'minimum_matches' => self::MINIMUM_MATCHES,
            'records' => $this->records($board, $rivalries),
        ];
    }

    /**
     * Adds one side of one match to that blader's running tally.
     *
     * An entrant nobody resolved to a blader is skipped rather than tallied
     * under their bracket spelling: two spellings of the same person would
     * hold two records between them, which is the failure the alias table
     * exists to prevent. Their matches still count towards the league totals
     * and the page totals, because those are counted per match.
     *
     * @param array<int, Tally> $tallies
     */
    private function count(array &$tallies, TournamentMatch $match, TournamentParticipant $side): void
    {
        $player = $side->getPlayer();
        if (null === $player || null === $player->getId()) {
            return;
        }

        $id = $player->getId();
        $tallies[$id] ??= [
            'player' => $player,
            'name' => $player->getName(),
            'matches' => 0,
            'wins' => 0,
            'losses' => 0,
            'draws' => 0,
            'points_for' => 0,
            'points_against' => 0,
            'nines' => 0,
            'strong' => 0,
            'shutouts' => 0,
            'events' => [],
            'streak' => 0,
            'streak_events' => 0,
            'running' => 0,
            'running_events' => [],
        ];

        $event = (int) $match->getTournament()->getId();
        $tallies[$id]['events'][$event] = true;
        ++$tallies[$id]['matches'];

        $outcome = $match->outcomeFor($side);
        match ($outcome) {
            'W' => ++$tallies[$id]['wins'],
            'L' => ++$tallies[$id]['losses'],
            default => ++$tallies[$id]['draws'],
        };

        // The matches arrive oldest first, so the run is extended in place
        // rather than reconstructed afterwards. A loss ends it and so does the
        // draw, which is neither half a win nor half a loss.
        if ('W' === $outcome) {
            ++$tallies[$id]['running'];
            $tallies[$id]['running_events'][$event] = true;

            if ($tallies[$id]['running'] > $tallies[$id]['streak']) {
                $tallies[$id]['streak'] = $tallies[$id]['running'];
                $tallies[$id]['streak_events'] = count($tallies[$id]['running_events']);
            }
        } else {
            $tallies[$id]['running'] = 0;
            $tallies[$id]['running_events'] = [];
        }

        $own = $this->reader->scoreFor($match, $side);
        $against = $this->reader->scoreAgainst($match, $side);
        if (null === $own || null === $against) {
            return;
        }

        $tallies[$id]['points_for'] += $own;
        $tallies[$id]['points_against'] += $against;

        if ('W' !== $outcome) {
            return;
        }

        if ($own >= 8) {
            ++$tallies[$id]['strong'];
        }

        if ($own >= 9) {
            ++$tallies[$id]['nines'];
        }

        if (0 === $against) {
            ++$tallies[$id]['shutouts'];
        }
    }

    /**
     * Adds one match to the record between the two bladers who played it.
     *
     * Keyed on the pair rather than on either blader, and always stored with
     * the lower id first, so the two directions of the same rivalry are one
     * row. Which way it runs is read off the tally afterwards.
     *
     * @param array<string, Rivalry> $pairs
     */
    private function pair(array &$pairs, TournamentMatch $match): void
    {
        $one = $match->getPlayer1()?->getPlayer();
        $two = $match->getPlayer2()?->getPlayer();
        if (null === $one || null === $two || null === $one->getId() || null === $two->getId() || $one === $two) {
            return;
        }

        [$left, $right] = $one->getId() < $two->getId() ? [$one, $two] : [$two, $one];
        $key = $left->getId().':'.$right->getId();

        $pairs[$key] ??= [
            'leader' => $left,
            'leader_name' => $left->getName(),
            'trailer' => $right,
            'trailer_name' => $right->getName(),
            'wins' => 0,
            'losses' => 0,
            'draws' => 0,
            'met' => 0,
        ];

        ++$pairs[$key]['met'];

        $side = $this->reader->sideOf($left, $match);
        if (null === $side) {
            return;
        }

        match ($match->outcomeFor($side)) {
            'W' => ++$pairs[$key]['wins'],
            'L' => ++$pairs[$key]['losses'],
            default => ++$pairs[$key]['draws'],
        };
    }

    /**
     * The pairs who have met most, each stated from the side that is ahead.
     *
     * Ordered by meetings rather than by margin, because a 5-0 against
     * somebody met five times says more than a 1-0 against somebody met once —
     * the same ordering the player profile's rivals use.
     *
     * @param array<string, Rivalry> $pairs
     *
     * @return list<Rivalry>
     */
    private function rivalries(array $pairs): array
    {
        $rivalries = [];
        foreach ($pairs as $pair) {
            $rivalries[] = $pair['losses'] > $pair['wins'] ? $this->reversed($pair) : $pair;
        }

        usort($rivalries, static fn (array $left, array $right): int => [$right['met'], $right['wins'] - $right['losses'], $left['leader_name'], $left['trailer_name']]
            <=> [$left['met'], $left['wins'] - $left['losses'], $right['leader_name'], $right['trailer_name']]
        );

        return $rivalries;
    }

    /**
     * @param Rivalry $pair
     *
     * @return Rivalry
     */
    private function reversed(array $pair): array
    {
        return [
            'leader' => $pair['trailer'],
            'leader_name' => $pair['trailer_name'],
            'trailer' => $pair['leader'],
            'trailer_name' => $pair['leader_name'],
            'wins' => $pair['losses'],
            'losses' => $pair['wins'],
            'draws' => $pair['draws'],
            'met' => $pair['met'],
        ];
    }

    /**
     * @param list<Tally>   $board
     * @param list<Rivalry> $rivalries
     *
     * @return list<BoardRecord>
     */
    private function records(array $board, array $rivalries): array
    {
        $rate = $this->leaders($board, static fn (array $tally): ?float => $tally['matches'] >= self::MINIMUM_MATCHES
            ? $tally['wins'] / $tally['matches']
            : null);

        $streak = $this->leaders($board, static fn (array $tally): ?int => $tally['streak'] > 0 ? $tally['streak'] : null);
        $differential = $this->leaders($board, static fn (array $tally): ?int => 0 === $tally['points_for'] && 0 === $tally['points_against']
            ? null
            : $tally['points_for'] - $tally['points_against']);
        $nines = $this->leaders($board, static fn (array $tally): ?int => $tally['nines'] > 0 ? $tally['nines'] : null);
        $strong = $this->leaders($board, static fn (array $tally): ?int => $tally['strong'] > 0 ? $tally['strong'] : null);
        $shutouts = $this->leaders($board, static fn (array $tally): ?int => $tally['shutouts'] > 0 ? $tally['shutouts'] : null);
        $played = $this->leaders($board, static fn (array $tally): ?int => $tally['matches'] > 0 ? $tally['matches'] : null);
        $scored = $this->leaders($board, static fn (array $tally): ?int => $tally['points_for'] > 0 ? $tally['points_for'] : null);

        $ninesEverywhere = array_sum(array_column($board, 'nines'));
        $onesided = $this->onesided($rivalries);
        $oneSidedHolder = $onesided[0] ?? null;

        return [
            $this->record('win-rate', 'Highest win rate', $rate, static fn (array $tally): string => round($tally['wins'] / $tally['matches'] * 100, 1).'%',
                static fn (array $tally): string => sprintf('%d–%d in %d matches', $tally['wins'], $tally['losses'], $tally['matches']), 'brand'),

            $this->record('streak', 'Longest win streak', $streak, static fn (array $tally): string => $tally['streak'].' straight',
                static fn (array $tally): string => 1 === $tally['streak_events'] ? 'inside one event' : sprintf('across %d events', $tally['streak_events'])),

            $this->record('differential', 'Best points differential', $differential, static fn (array $tally): string => sprintf('%+d', $tally['points_for'] - $tally['points_against']),
                static fn (array $tally): string => sprintf('%d scored, %d conceded', $tally['points_for'], $tally['points_against']), 'positive'),

            $this->record('nines', 'Most 9-point finishes', $nines, static fn (array $tally): string => (string) $tally['nines'],
                static fn (array $tally): string => sprintf('of the %d anyone has scored', $ninesEverywhere)),

            $this->record('strong-wins', 'Most wins at 8 or better', $strong, static fn (array $tally): string => (string) $tally['strong'],
                static fn (array $tally): string => sprintf('%d%% of their wins', (int) round($tally['strong'] / max(1, $tally['wins']) * 100)), 'brand'),

            $this->record('shutouts', 'Most shutout wins', $shutouts, static fn (array $tally): string => (string) $tally['shutouts'],
                static fn (array $tally): string => 'opponent left on zero'),

            $this->record('matches', 'Most matches played', $played, static fn (array $tally): string => (string) $tally['matches'],
                static fn (array $tally): string => sprintf('across %d events', count($tally['events'])), 'brand'),

            $this->record('points', 'Most points scored', $scored, static fn (array $tally): string => (string) $tally['points_for'],
                static fn (array $tally): string => sprintf('across %d matches', $tally['matches']), 'positive'),

            [
                'key' => 'one-sided',
                'label' => 'Most one-sided rivalry',
                'name' => null === $oneSidedHolder ? null : $oneSidedHolder['leader_name'].' over '.$oneSidedHolder['trailer_name'],
                'player' => null,
                'value' => null === $oneSidedHolder ? null : $oneSidedHolder['wins'].'–'.$oneSidedHolder['losses'],
                'note' => null === $oneSidedHolder
                    ? null
                    : sprintf('met %d times%s', $oneSidedHolder['met'], 0 === $oneSidedHolder['losses'] ? ', never beaten' : ''),
                'tone' => 'flame',
                'leaders' => array_map(static fn (array $rivalry): array => [
                    'name' => $rivalry['leader_name'].' over '.$rivalry['trailer_name'],
                    'player' => null,
                    'value' => $rivalry['wins'].'–'.$rivalry['losses'],
                ], $onesided),
            ],
        ];
    }

    /**
     * The pair furthest apart, among those who have met often enough to mean it.
     *
     * @param list<Rivalry> $rivalries
     *
     * @return list<Rivalry>
     */
    private function onesided(array $rivalries): array
    {
        $standing = array_values(array_filter($rivalries, static fn (array $rivalry): bool => $rivalry['met'] >= self::MINIMUM_MEETINGS));
        if ([] === $standing) {
            return [];
        }

        usort($standing, static fn (array $left, array $right): int => [$right['wins'] - $right['losses'], $right['met'], $left['leader_name']]
            <=> [$left['wins'] - $left['losses'], $left['met'], $right['leader_name']]
        );

        $standing = array_values(array_filter($standing, static fn (array $rivalry): bool => $rivalry['wins'] > $rivalry['losses']));

        return array_slice($standing, 0, 3);
    }

    /**
     * The top three bladers for a record.
     *
     * `$of` returns the figure being ranked, or null for a blader this record
     * cannot be awarded to at all — below the match threshold, or on nothing
     * worth naming. Ties go to the name that sorts first, so the board does
     * not reshuffle between two identical requests.
     *
     * @param list<Tally>                       $board
     * @param callable(Tally): (int|float|null) $of
     *
     * @return list<Tally>
     */
    private function leaders(array $board, callable $of): array
    {
        $eligible = array_values(array_filter($board, static fn (array $tally): bool => null !== $of($tally)));
        usort($eligible, static fn (array $left, array $right): int => [$of($right), $left['name']] <=> [$of($left), $right['name']]);

        return array_slice($eligible, 0, 3);
    }

    /**
     * @param list<Tally>             $leaders
     * @param callable(Tally): string $value
     * @param callable(Tally): string $note
     *
     * @return BoardRecord
     */
    private function record(string $key, string $label, array $leaders, callable $value, callable $note, string $tone = 'flame'): array
    {
        $holder = $leaders[0] ?? null;

        return [
            'key' => $key,
            'label' => $label,
            'name' => $holder['name'] ?? null,
            'player' => $holder['player'] ?? null,
            'value' => null === $holder ? null : $value($holder),
            'note' => null === $holder ? null : $note($holder),
            'tone' => $tone,
            'leaders' => array_map(static fn (array $leader): array => [
                'name' => $leader['name'],
                'player' => $leader['player'],
                'value' => $value($leader),
            ], $leaders),
        ];
    }
}
