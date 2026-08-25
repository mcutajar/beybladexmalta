<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One thing the smoke check expected of a Challonge module page, and what it
 * found instead.
 *
 * The expectation is written as a noun phrase — "at least one round" — so that
 * it reads both in the report's table and in the sentence an abort is made of.
 */
final class ChallongeSmokeFinding
{
    private function __construct(
        public readonly string $expectation,
        public readonly ChallongeSmokeOutcome $outcome,
        public readonly string $detail,
    ) {
    }

    /**
     * @param string $detail what was there, so a passing run still says what it read
     */
    public static function passed(string $expectation, string $detail): self
    {
        return new self($expectation, ChallongeSmokeOutcome::Passed, $detail);
    }

    /**
     * @param string $detail what came back instead, naming the missing piece
     */
    public static function failed(string $expectation, string $detail): self
    {
        return new self($expectation, ChallongeSmokeOutcome::Failed, $detail);
    }

    public static function notRun(string $expectation): self
    {
        return new self($expectation, ChallongeSmokeOutcome::NotRun, 'nothing was left to read it from.');
    }

    public function isFailure(): bool
    {
        return ChallongeSmokeOutcome::Failed === $this->outcome;
    }
}
