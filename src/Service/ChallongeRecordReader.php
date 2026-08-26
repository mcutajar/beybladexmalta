<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChallongeRecord;
use App\Dto\ChallongeStanding;

/**
 * Reads the statistics out of a standings row's columns.
 *
 * This is the interpretation the snapshot refuses to make. A captured bracket
 * keeps every cell under the header label Challonge printed above it, verbatim
 * and untyped, because a tracked file must not carry a conclusion that can go
 * out of date. Turning `Match W-L-T (wins +1.0, ties +0.5) => "5 - 0 - 0"`
 * into three integers happens here, when the file is read, where being wrong
 * costs a re-parse rather than a re-fetch of a bracket that may be gone.
 *
 * Labels are matched with their parenthetical stripped, which is the whole
 * trick: Challonge writes the scoring rule into the header, so the same column
 * is `Match W-L-T` in a round robin and `Match W-L-T (wins +1.0, ties +0.5)`
 * in a Swiss stage, and `Byes` only ever appears as `Byes (+1.0)`.
 *
 * A cell that does not parse reads as absent rather than as zero, and so does
 * a column that is not there at all. The standings of a cut carry no columns
 * whatsoever — eight rows of a rank and a match history — and a zero would be
 * a claim the bracket never made.
 *
 * Three columns are read by nothing and stay in the snapshot: `Set Wins`,
 * `Set Ties` and `Pts`, which appear in the league's one round-robin stage.
 * `Pts` is that stage's Beyblade-points total and is emphatically not the
 * `Score` of a Swiss table, which counts match wins — mapping one onto the
 * other would be the archive stating something no bracket said. The snapshot
 * still holds all three, so the day they are worth a column they are a
 * re-archive away.
 */
class ChallongeRecordReader
{
    private const string WINS_LOSSES_TIES = 'match w-l-t';

    private const string BYES = 'byes';

    private const string SCORE = 'score';

    private const string BUCHHOLZ = 'buchholz';

    private const string TIE_BREAK = 'tb';

    private const string POINTS_DIFFERENTIAL = 'pts diff';

    public function read(ChallongeStanding $standing): ChallongeRecord
    {
        $columns = [];

        foreach ($standing->columns as $label => $value) {
            $columns[$this->label($label)] = $value;
        }

        [$wins, $losses, $ties] = $this->winsLossesTies($columns[self::WINS_LOSSES_TIES] ?? null);

        return new ChallongeRecord(
            wins: $wins,
            losses: $losses,
            ties: $ties,
            byes: $this->integer($columns[self::BYES] ?? null),
            score: $this->decimal($columns[self::SCORE] ?? null),
            buchholz: $this->decimal($columns[self::BUCHHOLZ] ?? null),
            tieBreak: $this->decimal($columns[self::TIE_BREAK] ?? null),
            pointsDifferential: $this->integer($columns[self::POINTS_DIFFERENTIAL] ?? null),
        );
    }

    /**
     * The header label, with the scoring rule Challonge appends taken off.
     */
    private function label(string $label): string
    {
        $bracket = mb_strpos($label, '(');

        if (false !== $bracket) {
            $label = mb_substr($label, 0, $bracket);
        }

        return mb_strtolower(trim($label));
    }

    /**
     * @return array{?int, ?int, ?int}
     */
    private function winsLossesTies(?string $value): array
    {
        if (null === $value || 1 !== preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*-\s*(\d+)\s*$/', $value, $matches)) {
            return [null, null, null];
        }

        return [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
    }

    private function integer(?string $value): ?int
    {
        $value = null === $value ? '' : trim($value);

        return is_numeric($value) ? (int) $value : null;
    }

    private function decimal(?string $value): ?float
    {
        $value = null === $value ? '' : trim($value);

        return is_numeric($value) ? (float) $value : null;
    }
}
