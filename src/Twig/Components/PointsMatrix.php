<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The F1 points table, shown on the landing pages.
 *
 * The award for each place is passed in because the proposals differ on it;
 * how a place is emphasised is not, because that is the design's business.
 */
#[AsTwigComponent]
final class PointsMatrix
{
    /**
     * The award for each place, first place first.
     *
     * @var list<string>
     */
    public array $awards = [];

    /**
     * Prefix the podium with medals and tint its rows. The first proposal
     * presents the matrix this way; the later ones do not.
     */
    public bool $medals = false;

    /**
     * @return list<array{place: string, award: string, emphasis: string}>
     */
    public function rows(): array
    {
        $rows = [];

        foreach ($this->awards as $index => $award) {
            $place = $index + 1;

            $rows[] = [
                'place' => trim(($this->medals ? self::medalFor($place) : '').' '.self::ordinal($place).' place'),
                'award' => $award,
                'emphasis' => $this->emphasisFor($place),
            ];
        }

        return $rows;
    }

    /**
     * The podium and the final scoring place are called out; the rest are read
     * as a run of numbers.
     */
    private function emphasisFor(int $place): string
    {
        return match (true) {
            1 === $place => 'gold',
            2 === $place => 'silver',
            3 === $place => 'bronze',
            $place === count($this->awards) => 'last',
            default => 'plain',
        };
    }

    private static function medalFor(int $place): string
    {
        return match ($place) {
            1 => '🥇',
            2 => '🥈',
            3 => '🥉',
            default => '',
        };
    }

    private static function ordinal(int $place): string
    {
        $suffix = match (true) {
            $place % 100 >= 11 && $place % 100 <= 13 => 'th',
            1 === $place % 10 => 'st',
            2 === $place % 10 => 'nd',
            3 === $place % 10 => 'rd',
            default => 'th',
        };

        return $place.$suffix;
    }
}
