<?php

declare(strict_types=1);

namespace App\Dto;

enum PerformanceResultValue: string
{
    case NotRecorded = 'not_recorded';
    case Unused = 'unused';
    case MinusThree = '-3';
    case MinusTwo = '-2';
    case MinusOne = '-1';
    case Zero = '0';
    case PlusOne = '+1';
    case PlusTwo = '+2';
    case PlusThree = '+3';

    public function label(): string
    {
        return match ($this) {
            self::NotRecorded => 'Not recorded',
            self::Unused => '— Unused',
            self::MinusThree => '−3',
            self::MinusTwo => '−2',
            self::MinusOne => '−1',
            self::Zero => '0',
            self::PlusOne => '+1',
            self::PlusTwo => '+2',
            self::PlusThree => '+3',
        };
    }

    public function csvValue(): string
    {
        return match ($this) {
            self::NotRecorded => 'not recorded',
            self::Unused => 'unused',
            default => $this->value,
        };
    }

    public function score(): ?int
    {
        return match ($this) {
            self::MinusThree => -3,
            self::MinusTwo => -2,
            self::MinusOne => -1,
            self::Zero => 0,
            self::PlusOne => 1,
            self::PlusTwo => 2,
            self::PlusThree => 3,
            self::NotRecorded, self::Unused => null,
        };
    }

    public function isAppearance(): bool
    {
        return null !== $this->score();
    }
}
