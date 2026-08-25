<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * What the smoke check made of one Challonge module page.
 *
 * Every expectation is listed whether it held or not, because the value of the
 * check on the day Challonge changes something is the whole checklist: the
 * failure says what broke, and the passes either side of it say how much of
 * the page is still the page we knew.
 */
final class ChallongeSmokeReport
{
    /**
     * @param string                      $source   where the page came from — a module URL or a file path
     * @param list<ChallongeSmokeFinding> $findings in the order they were looked for
     */
    public function __construct(
        public readonly string $source,
        public readonly array $findings,
    ) {
    }

    /**
     * The findings so far, with everything still on the list recorded as
     * unread rather than dropped.
     *
     * @param list<ChallongeSmokeFinding> $findings
     * @param list<string>                $expectations every expectation, in order
     */
    public static function stoppedAfter(string $source, array $findings, array $expectations): self
    {
        $reached = array_map(
            static fn (ChallongeSmokeFinding $finding): string => $finding->expectation,
            $findings,
        );

        foreach ($expectations as $expectation) {
            if (!in_array($expectation, $reached, true)) {
                $findings[] = ChallongeSmokeFinding::notRun($expectation);
            }
        }

        return new self($source, $findings);
    }

    public function passed(): bool
    {
        return null === $this->failure();
    }

    public function failure(): ?ChallongeSmokeFinding
    {
        foreach ($this->findings as $finding) {
            if ($finding->isFailure()) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * The abort message: what was expected, and what came back.
     */
    public function problem(): string
    {
        $failure = $this->failure();

        if (null === $failure) {
            return '';
        }

        return sprintf(
            'The Challonge module page at %s is not the page this reads. Expected %s; found %s',
            $this->source,
            $failure->expectation,
            $failure->detail,
        );
    }
}
