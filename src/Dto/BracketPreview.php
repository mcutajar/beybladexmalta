<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Everything the import screen shows, and nothing it writes.
 *
 * The four jobs of the preview, in the order somebody checks them: prove the
 * right bracket was fetched, get every unresolved name decided, let the
 * finishing order be corrected, and say exactly what a confirm will write —
 * down to the lines that will land in `repeat.sh`.
 *
 * It is rebuilt from the snapshot on every request, answers included, so the
 * screen that refuses a confirm is the same screen that renders it. Nothing
 * here is trusted from the browser: the snapshot lives on the server for the
 * length of the draft, and a posted answer is a choice between options this
 * class produced rather than a fact it takes on faith.
 */
final readonly class BracketPreview
{
    /**
     * @param list<BracketDecision>                                   $decisions  every spelling that still needs a person
     * @param list<BracketPlacement>                                  $placements the proposed finishing order, best first
     * @param list<array{label: string, note: string, value: string}> $archive    what the archive will hold
     * @param list<array{verb: string, path: string}>                 $artifacts  the files a confirm writes
     * @param list<string>                                            $ledger     the exact commands a confirm appends
     */
    private function __construct(
        public BracketPreviewResult $result,
        public string $slug,
        public string $bracketUrl,
        public string $shape = '',
        public string $title = '',
        public string $heldOn = '',
        public string $seasonSlug = '',
        public int $participants = 0,
        public int $matches = 0,
        public int $pointsScored = 0,
        public array $decisions = [],
        public array $placements = [],
        public array $archive = [],
        public array $artifacts = [],
        public array $ledger = [],
    ) {
    }

    /**
     * @param list<BracketDecision>                                   $decisions
     * @param list<BracketPlacement>                                  $placements
     * @param list<array{label: string, note: string, value: string}> $archive
     * @param list<array{verb: string, path: string}>                 $artifacts
     * @param list<string>                                            $ledger
     */
    public static function ready(
        string $slug,
        string $bracketUrl,
        string $shape,
        string $title,
        string $heldOn,
        string $seasonSlug,
        int $participants,
        int $matches,
        int $pointsScored,
        array $decisions,
        array $placements,
        array $archive,
        array $artifacts,
        array $ledger,
    ): self {
        return new self(
            BracketPreviewResult::Ready,
            $slug,
            $bracketUrl,
            $shape,
            $title,
            $heldOn,
            $seasonSlug,
            $participants,
            $matches,
            $pointsScored,
            $decisions,
            $placements,
            $archive,
            $artifacts,
            $ledger,
        );
    }

    public static function refused(
        BracketPreviewResult $result,
        string $slug,
        string $bracketUrl,
    ): self {
        return new self($result, $slug, $bracketUrl);
    }

    public function isReady(): bool
    {
        return BracketPreviewResult::Ready === $this->result;
    }

    /**
     * Whether a confirm would be refused.
     *
     * The rule the whole epic turns on: nothing is written until every
     * unresolved name is answered. A collision can never be answered here, so
     * a bracket carrying one is blocked until somebody merges the two bladers.
     */
    public function isBlocked(): bool
    {
        return [] !== $this->outstanding();
    }

    /**
     * @return list<BracketDecision>
     */
    public function outstanding(): array
    {
        return array_values(array_filter(
            $this->decisions,
            static fn (BracketDecision $decision): bool => $decision->isOutstanding(),
        ));
    }

    /**
     * The questions that arrived answered, which is every spelling with
     * nothing close to it.
     *
     * Collapsed on the screen rather than hidden: they are still editable, and
     * on the 23 August bracket they are ten rows of the fourteen — the
     * difference between reviewing a list and working through one.
     *
     * @return list<BracketDecision>
     */
    public function settled(): array
    {
        return array_values(array_filter(
            $this->decisions,
            static fn (BracketDecision $decision): bool => !$decision->isOutstanding(),
        ));
    }

    /**
     * How many answers are the screen's rather than somebody's.
     */
    public function seeded(): int
    {
        return count(array_filter(
            $this->decisions,
            static fn (BracketDecision $decision): bool => $decision->wasSeeded(),
        ));
    }

    /**
     * @return list<BracketDecision>
     */
    public function collisions(): array
    {
        return array_values(array_filter(
            $this->decisions,
            static fn (BracketDecision $decision): bool => $decision->isCollision,
        ));
    }

    /**
     * The placements that will be written as results.
     *
     * @return list<BracketPlacement>
     */
    public function scoring(): array
    {
        return array_values(array_filter(
            $this->placements,
            static fn (BracketPlacement $placement): bool => $placement->scores(),
        ));
    }

    /**
     * How many bladers a confirm would invent.
     *
     * Shown before the button is pressed, because this screen is the only
     * thing in the system that creates one and creating one is a decision
     * rather than a fallback.
     */
    public function bladersToCreate(): int
    {
        return count(array_filter(
            $this->decisions,
            static fn (BracketDecision $decision): bool => BracketAnswers::CREATE === $decision->answer,
        ));
    }

    public function knockoutWinner(): ?BracketPlacement
    {
        foreach ($this->placements as $placement) {
            if ($placement->wonTheKnockout) {
                return $placement;
            }
        }

        return null;
    }

    /**
     * Why this bracket cannot be imported this way, in one sentence.
     */
    public function refusal(): string
    {
        return match ($this->result) {
            BracketPreviewResult::Ready => '',

            BracketPreviewResult::TeamEvent => sprintf(
                '"%s" is a 2v2 bracket. Its entrants are teams and nothing in it says who was in one, so it is imported from a roster instead: app:import-tournament … --team.',
                $this->slug,
            ),

            BracketPreviewResult::AlreadyImported => sprintf(
                'An event on record was already imported from "%s". Importing it again would insert a second copy of every result, so re-read it with app:fetch-challonge and app:archive-challonge instead.',
                $this->slug,
            ),

            BracketPreviewResult::NoStandings => sprintf(
                '"%s" renders no standings table, so it states no finishing order. app:challonge-smoke is how such a bracket is looked at.',
                $this->slug,
            ),
        };
    }
}
