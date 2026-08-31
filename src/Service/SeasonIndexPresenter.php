<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Season;
use App\Repository\PlayerRepository;

/**
 * Every season the league has held, as the cards `/seasons` lists.
 *
 * Option 6C: switching season is navigation rather than a control. The
 * accepted cost is that it takes two taps, every time, permanently. What it
 * buys is that nothing has to change later — an index improves as seasons
 * accumulate, where the stepper 6A proposed would have needed a companion the
 * moment they outgrew it — and it keeps the leaderboard completely free of
 * scope chrome, which is what 5C was arguing for.
 *
 * The winner is read from the season's own leaderboard rather than from a
 * stored champion, because that is the same query the season page answers with
 * and a second source would eventually disagree with it. It is the current
 * leader of a season still running, which is why the card says so.
 *
 * @phpstan-type SeasonCard array{season: Season, events: int, matches: int, from: ?\DateTimeImmutable, to: ?\DateTimeImmutable, winner: ?string, points: ?int, current: bool}
 */
final class SeasonIndexPresenter
{
    public function __construct(
        private readonly PlayerRepository $players,
    ) {
    }

    /**
     * @param list<Season>               $seasons oldest first, as `SeasonRepository::ordered()` returns them
     * @param list<array<string, mixed>> $rows    as `TournamentRepository::archiveIndex()` returns them
     *
     * @return list<SeasonCard>
     */
    public function present(array $seasons, array $rows): array
    {
        $cards = [];
        $newest = [] === $seasons ? null : $seasons[count($seasons) - 1];

        foreach ($seasons as $season) {
            $events = 0;
            $matches = 0;
            $from = null;
            $to = null;

            foreach ($rows as $row) {
                if ($row['season_slug'] !== $season->getSlug()) {
                    continue;
                }

                ++$events;
                $matches += (int) $row['matches'];

                // The rows arrive oldest first within a season, so the ends of
                // the run are the range.
                $heldOn = new \DateTimeImmutable((string) $row['held_on']);
                $from ??= $heldOn;
                $to = $heldOn;
            }

            [$winner, $points] = $this->leader($season);

            $cards[] = [
                'season' => $season,
                'events' => $events,
                'matches' => $matches,
                'from' => $from,
                'to' => $to,
                'winner' => $winner,
                'points' => $points,
                'current' => $season === $newest,
            ];
        }

        /*
         * Newest first. The index is read to get somewhere, and the season
         * somebody wants is nearly always the one running — the ordering the
         * repository hands back is oldest first because that is what a scope
         * selector needs.
         */
        return array_reverse($cards);
    }

    /**
     * Who is top of that season's table, and on what.
     *
     * The leaderboard's own query, capped at best-14 and payment-gated exactly
     * as the season page is, so the card cannot name somebody the page does
     * not. A season nobody has scored in yet has no leader rather than a
     * zero-point one.
     *
     * @return array{?string, ?int}
     */
    private function leader(Season $season): array
    {
        $standings = $this->players->getLeagueLeaderboard($season->getSlug());

        foreach ($standings as $row) {
            $total = (int) $row['total'];

            return $total > 0 ? [(string) $row['name'], $total] : [null, null];
        }

        return [null, null];
    }
}
