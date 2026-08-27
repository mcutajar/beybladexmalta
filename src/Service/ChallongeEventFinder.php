<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChallongeUrl;
use App\Entity\Tournament;
use App\Exception\InvalidChallongeUrlException;
use App\Repository\TournamentRepository;

/**
 * Which event, if any, came from a given bracket.
 *
 * The bracket names the event rather than the other way round: every import
 * records its `--challonge` URL, so a slug is enough to find the tournament it
 * produced. That single fact is now asked three times — the archive command
 * finds the event to write against, the import preview refuses a bracket an
 * event already names, and the import that follows a confirm has to find the
 * tournament it just created in order to archive it — so it lives here rather
 * than three times over.
 *
 * It cannot be a repository method, because the same bracket is spelled three
 * ways across `repeat.sh` — `challonge.com/<slug>`, a subdomain, and the
 * `/vi/<slug>` invite links — and normalising a URL to its slug is
 * `ChallongeUrl`'s job rather than SQL's. There are twenty rows.
 */
class ChallongeEventFinder
{
    public function __construct(
        private TournamentRepository $tournaments,
    ) {
    }

    /**
     * @return list<Tournament> normally none or one; two means two events
     *                          record the same bracket, and a caller refuses
     *                          rather than picks
     */
    public function forSlug(string $slug): array
    {
        $events = [];

        foreach ($this->tournaments->everyEventWithABracket() as $tournament) {
            try {
                $recorded = ChallongeUrl::fromString((string) $tournament->getChallongeUrl())->slug;
            } catch (InvalidChallongeUrlException) {
                continue;
            }

            if ($recorded === $slug) {
                $events[] = $tournament;
            }
        }

        return $events;
    }
}
