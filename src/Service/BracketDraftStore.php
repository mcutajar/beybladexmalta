<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\BracketDraft;
use App\Dto\ChallongeSnapshot;
use App\Exception\ChallongeSnapshotReadException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The bracket somebody is looking at, held between the fetch and the confirm.
 *
 * The preview writes nothing, so the snapshot it was built from has nowhere to
 * live: `var/data/challonge/<slug>.json` is tracked by git and is the record,
 * and putting a bracket there before anybody has approved it would make the
 * record a draft. It goes in the session instead, for the length of one
 * decision, and the confirm imports the exact bytes the preview described.
 *
 * That last part is the point rather than an optimisation. Re-fetching on
 * confirm would mean approving one bracket and importing whatever Challonge
 * happened to be serving a minute later — and `/module` is an embed endpoint
 * nobody promised us. A draft that has expired is a fetch to run again, said
 * out loud, which is the honest failure.
 */
class BracketDraftStore
{
    private const string KEY = 'bracket_import_draft';

    public function __construct(
        private RequestStack $requests,
        private ChallongeSnapshotReader $snapshots,
    ) {
    }

    public function remember(
        ChallongeSnapshot $snapshot,
        string $challongeUrl,
        string $title,
        string $heldOn,
        string $seasonSlug,
    ): void {
        $this->requests->getSession()->set(self::KEY, [
            'snapshot' => $snapshot->toArray(),
            'url' => $challongeUrl,
            'title' => $title,
            'heldOn' => $heldOn,
            'season' => $seasonSlug,
        ]);
    }

    /**
     * The draft, if there is one and it is the bracket being confirmed.
     *
     * The slug is checked rather than assumed, so a confirm posted from a
     * stale tab cannot apply one bracket's decisions to another's snapshot.
     */
    public function recall(string $slug): ?BracketDraft
    {
        $draft = $this->requests->getSession()->get(self::KEY);

        if (!is_array($draft) || !is_array($draft['snapshot'] ?? null)) {
            return null;
        }

        try {
            $snapshot = $this->snapshots->fromArray($draft['snapshot'], 'the bracket you fetched');
        } catch (ChallongeSnapshotReadException) {
            return null;
        }

        if ($snapshot->slug !== $slug) {
            return null;
        }

        return new BracketDraft(
            snapshot: $snapshot,
            challongeUrl: (string) ($draft['url'] ?? ''),
            title: (string) ($draft['title'] ?? ''),
            heldOn: (string) ($draft['heldOn'] ?? ''),
            seasonSlug: (string) ($draft['season'] ?? ''),
        );
    }

    public function forget(): void
    {
        $this->requests->getSession()->remove(self::KEY);
    }
}
