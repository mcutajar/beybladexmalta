<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChallongeStanding;
use App\Exception\ChallongeFetchException;

/**
 * Turns a rendered standings table into rows, without deciding what any of it
 * means.
 *
 * Four columns are the spine of every table Challonge renders and are read by
 * name: the rank, who the row is about, their linked Challonge account, and
 * the match-history cell. Every other column is kept verbatim under its own
 * header label, because the set of them changes with the format — and the
 * `Byes` column appears only in the brackets that had byes, so anything
 * counting columns by position reads the wrong number for the rest.
 */
class ChallongeStandingsParser
{
    private const RANK = 'Rank';

    private const PARTICIPANT = ['Participant', 'Participant Name'];

    private const CHALLONGE_USER = 'Challonge User';

    private const MATCH_HISTORY = 'Match History';

    /**
     * Challonge prints an en dash where a participant has no linked account.
     */
    private const EMPTY_CELL = ['–', '-', '—'];

    /**
     * @return list<ChallongeStanding>
     */
    public function parse(?string $html): array
    {
        if (null === $html || '' === trim($html)) {
            return [];
        }

        $table = $this->tableIn($html);

        if (null === $table) {
            return [];
        }

        $labels = $this->headerOf($table);

        $standings = [];

        foreach ($table->querySelectorAll('tr') as $row) {
            $cells = [];

            foreach ($row->querySelectorAll('td') as $cell) {
                $cells[] = $cell;
            }

            if ([] === $cells) {
                continue;
            }

            $standings[] = $this->readRow($cells, $labels);
        }

        return $standings;
    }

    private function tableIn(string $html): ?\Dom\Element
    {
        try {
            $document = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR);
        } catch (\ValueError $exception) {
            throw new ChallongeFetchException(sprintf('The standings table did not parse as HTML: %s', $exception->getMessage()), previous: $exception);
        }

        return $document->querySelector('table');
    }

    /**
     * @return list<string>
     */
    private function headerOf(\Dom\Element $table): array
    {
        $labels = [];

        foreach ($table->querySelectorAll('th') as $cell) {
            $labels[] = $this->textOf($cell);
        }

        if (!in_array(self::RANK, $labels, true)) {
            throw new ChallongeFetchException(sprintf('The standings table has no "%s" column. Its columns are: %s.', self::RANK, [] === $labels ? '(none)' : '"'.implode('", "', $labels).'"'));
        }

        return $labels;
    }

    /**
     * @param list<\Dom\Element> $cells
     * @param list<string>       $labels
     */
    private function readRow(array $cells, array $labels): ChallongeStanding
    {
        $rank = null;
        $name = null;
        $challongeUser = null;
        $rowLabels = [];
        $matchIds = [];
        $columns = [];

        foreach ($cells as $index => $cell) {
            $label = $labels[$index] ?? '';

            if (self::RANK === $label) {
                $rank = $this->readRank($cell);

                continue;
            }

            if (in_array($label, self::PARTICIPANT, true)) {
                [$rowLabels, $linked, $name] = $this->readParticipant($cell);
                $challongeUser ??= $linked;

                continue;
            }

            if (self::CHALLONGE_USER === $label) {
                $challongeUser = $this->readChallongeUser($cell) ?? $challongeUser;

                continue;
            }

            if (str_starts_with($label, self::MATCH_HISTORY)) {
                $matchIds = $this->readMatchIds($cell);

                continue;
            }

            $columns[$label] = $this->textOf($cell);
        }

        if (null === $rank) {
            throw new ChallongeFetchException('A standings row carries no rank.');
        }

        return new ChallongeStanding(
            rank: $rank,
            name: $name,
            challongeUser: $challongeUser,
            labels: $rowLabels,
            matchIds: $matchIds,
            columns: $columns,
        );
    }

    private function readRank(\Dom\Element $cell): int
    {
        $rank = $this->textOf($cell);

        if (1 !== preg_match('/^\d+$/', $rank)) {
            throw new ChallongeFetchException(sprintf('"%s" is not a rank.', $rank));
        }

        return (int) $rank;
    }

    /**
     * The participant cell is the awkward one. It may carry a badge, and a
     * blader who linked their Challonge account is rendered as that account —
     * sometimes instead of their display name. Each part is lifted out and
     * removed, so what is left over is the name and nothing else.
     *
     * @return array{list<string>, ?string, ?string} the row's badges, the linked account, the name
     */
    private function readParticipant(\Dom\Element $cell): array
    {
        $labels = [];

        foreach ($cell->querySelectorAll('span.label') as $badge) {
            $labels[] = $this->textOf($badge);
            $badge->remove();
        }

        $account = $this->accountLinkIn($cell, remove: true);
        $name = $this->textOf($cell);

        return [$labels, $account, '' === $name ? null : $name];
    }

    /**
     * The dedicated column, which a final-stage table has and a Swiss one does
     * not. Challonge prints an en dash for a participant who never linked one.
     */
    private function readChallongeUser(\Dom\Element $cell): ?string
    {
        $account = $this->accountLinkIn($cell);

        if (null !== $account) {
            return $account;
        }

        $text = $this->textOf($cell);

        return '' === $text || in_array($text, self::EMPTY_CELL, true) ? null : $text;
    }

    private function accountLinkIn(\Dom\Element $cell, bool $remove = false): ?string
    {
        $link = $cell->querySelector('a[href*="/users/"]');

        if (null === $link) {
            return null;
        }

        $account = $this->textOf($link);

        if ($remove) {
            $link->remove();
        }

        return '' === $account ? null : $account;
    }

    /**
     * @return list<int>
     */
    private function readMatchIds(\Dom\Element $cell): array
    {
        $matchIds = [];

        foreach ($cell->querySelectorAll('[data-match-id]') as $link) {
            $matchIds[] = (int) $link->getAttribute('data-match-id');
        }

        return $matchIds;
    }

    /**
     * A `<br>` is a space once the tags are gone — Challonge puts one inside
     * the "Match W-L-T" and "Byes" headers, and without this the label reads
     * "Byes(+1.0)" and no longer matches what the page shows.
     */
    private function textOf(\Dom\Node $node): string
    {
        if ($node instanceof \Dom\Element) {
            foreach ($node->querySelectorAll('br') as $break) {
                $break->replaceWith(' ');
            }
        }

        return trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
    }
}
