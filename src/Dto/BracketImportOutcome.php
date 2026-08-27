<?php

declare(strict_types=1);

namespace App\Dto;

use App\Service\BracketImportResult;

/**
 * What confirming a previewed bracket did, and the screen to go back to when
 * it did nothing.
 *
 * The preview travels with the outcome on purpose. Every refusal here is one
 * somebody has to answer — a name still undecided, an alias that could not be
 * filed — and the answer is given on the same screen, so the controller
 * re-renders the preview the service just rebuilt rather than fetching the
 * bracket again.
 */
final readonly class BracketImportOutcome
{
    /**
     * @param int          $scored   placements written as results, which is the top ten at most
     * @param int          $created  bladers this screen invented, being the only thing that does
     * @param int          $seeded   how many of those answers were the screen's rather than
     *                               somebody's — said out loud, because a default that was
     *                               never examined is the one worth being able to find again
     * @param int          $aliased  spellings filed against a blader already on record
     * @param list<string> $problems why an alias was refused, in `AddAliasResult`'s terms
     */
    private function __construct(
        public BracketImportResult $result,
        public BracketPreview $preview,
        public int $scored = 0,
        public int $created = 0,
        public int $seeded = 0,
        public int $aliased = 0,
        public ?ChallongeArchiveOutcome $archive = null,
        public array $problems = [],
    ) {
    }

    public static function imported(
        BracketPreview $preview,
        int $scored,
        int $created,
        int $seeded,
        int $aliased,
        ?ChallongeArchiveOutcome $archive,
    ): self {
        return new self(
            BracketImportResult::Imported,
            $preview,
            $scored,
            $created,
            $seeded,
            $aliased,
            $archive,
        );
    }

    /**
     * @param list<string> $problems
     */
    public static function refused(
        BracketImportResult $result,
        BracketPreview $preview,
        array $problems = [],
    ): self {
        return new self($result, $preview, problems: $problems);
    }

    public function wasImported(): bool
    {
        return BracketImportResult::Imported === $this->result;
    }
}
