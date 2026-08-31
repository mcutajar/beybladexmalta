<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Season;

/**
 * The tournament archive, filed by the season each event scores in.
 *
 * Option 2B, the season shelf. Events are grouped rather than badged, so the
 * invariant is the page's structure: a season's card holds the events that
 * score in it, and the final group holds the events that score nowhere. There
 * is no synthetic "Other" season and nothing here invents one — the group with
 * no `Season` behind it is exactly the events whose relation is null.
 *
 * **The accepted cost**: an unranked event is filed away from its own date, so
 * somebody who remembers "the one in August" has two places to look. Worth
 * watching. If unranked events become common the group will need a
 * date-ordered view alongside it.
 *
 * Nothing here reads `TournamentResult` except to name a knockout winner, and
 * that column is simply empty for an unranked row — which is what having no
 * result rows looks like from the outside.
 *
 * @phpstan-type ArchiveRow array{id: int, title: string, heldOn: \DateTimeImmutable, entrants: int, matches: int, teams: int, knockoutWinner: ?string, ranked: bool}
 * @phpstan-type Shelf array{season: ?Season, label: string, slug: ?string, ranked: bool, events: list<ArchiveRow>, matches: int, from: ?\DateTimeImmutable, to: ?\DateTimeImmutable}
 */
final class TournamentShelfPresenter
{
    /**
     * The heading the unranked group carries. Decided on review: **not**
     * "Outside the season", which reads as a place events fell out of rather
     * than a kind of event.
     */
    public const string UNRANKED_LABEL = 'Unranked tournaments';

    /**
     * @param list<array<string, mixed>> $rows    as `TournamentRepository::archiveIndex()` returns them
     * @param list<Season>               $seasons every season, oldest first, so one with no events yet
     *                                            still gets a shelf and an empty state rather than
     *                                            disappearing from the archive
     *
     * @return array{totals: array{events: int, matches: int, bladers: int, seasons: int}, shelves: list<Shelf>}
     */
    public function present(array $rows, array $seasons, int $bladers, ?Season $scope = null): array
    {
        /** @var array<string, list<ArchiveRow>> $byShelf */
        $byShelf = [];
        $events = 0;
        $matches = 0;

        foreach ($rows as $row) {
            $slug = null === $row['season_slug'] ? null : (string) $row['season_slug'];

            if (null !== $scope && $slug !== $scope->getSlug()) {
                continue;
            }

            $event = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'heldOn' => new \DateTimeImmutable((string) $row['held_on']),
                'entrants' => (int) $row['entrants'],
                'matches' => (int) $row['matches'],
                'teams' => (int) $row['teams'],
                'knockoutWinner' => null === $row['knockout_winner'] ? null : (string) $row['knockout_winner'],
                'ranked' => null !== $slug,
            ];

            $byShelf[$slug ?? ''][] = $event;
            ++$events;
            $matches += $event['matches'];
        }

        $shelves = [];

        foreach ($seasons as $season) {
            if (null !== $scope && $season !== $scope) {
                continue;
            }

            $shelves[] = $this->shelf($season->getName(), $season->getSlug(), true, $byShelf[$season->getSlug()] ?? [], $season);
        }

        /*
         * Last, and only when it holds something. An empty group headed
         * "Unranked tournaments" on every season-scoped view would be a
         * standing invitation to wonder what is missing, and a season scope
         * cannot hold one by definition.
         */
        if ([] !== ($byShelf[''] ?? [])) {
            $shelves[] = $this->shelf(self::UNRANKED_LABEL, null, false, $byShelf['']);
        }

        return [
            'totals' => [
                'events' => $events,
                'matches' => $matches,
                'bladers' => $bladers,
                'seasons' => count($seasons),
            ],
            'shelves' => $shelves,
        ];
    }

    /**
     * @param list<ArchiveRow> $events
     *
     * @return Shelf
     */
    private function shelf(string $label, ?string $slug, bool $ranked, array $events, ?Season $season = null): array
    {
        $matches = 0;

        foreach ($events as $event) {
            $matches += $event['matches'];
        }

        return [
            'season' => $season,
            'label' => $label,
            'slug' => $slug,
            'ranked' => $ranked,
            'events' => $events,
            'matches' => $matches,
            // The rows arrive oldest first, so the range is the ends of the
            // list rather than a scan of it.
            'from' => $events[0]['heldOn'] ?? null,
            'to' => $events[count($events) - 1]['heldOn'] ?? null,
        ];
    }
}
