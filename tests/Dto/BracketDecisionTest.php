<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\AliasSuggestion;
use App\Dto\AliasSuggestionReason;
use App\Dto\BracketAnswers;
use App\Dto\BracketDecision;
use App\Entity\Player;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Support\ServiceTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class BracketDecisionTest extends ServiceTestCase
{
    public function testCreatingRejectsEveryDisplayedCandidate(): void
    {
        $first = PlayerFactory::createOne(['name' => 'Blader A']);
        $second = PlayerFactory::createOne(['name' => 'Blader J']);

        self::assertSame(
            ['Blader A', 'Blader J'],
            $this->rejectedNames($this->decision([$first, $second], BracketAnswers::CREATE)),
        );
    }

    public function testChoosingSomebodyRejectsEveryDisplayedCandidateExceptThem(): void
    {
        $first = PlayerFactory::createOne(['name' => 'Blader A']);
        $chosen = PlayerFactory::createOne(['name' => 'Blader J']);
        $third = PlayerFactory::createOne(['name' => 'Blader T']);

        self::assertSame(
            ['Blader A', 'Blader T'],
            $this->rejectedNames($this->decision([$first, $chosen, $third], 'blader:'.$chosen->getId())),
        );
    }

    public function testDroppingAnEntrantRejectsNobody(): void
    {
        $candidate = PlayerFactory::createOne(['name' => 'Blader A']);

        self::assertSame([], $this->decision([$candidate], BracketAnswers::DROP)->rejectedSuggestions());
    }

    /** @param list<Player> $players */
    private function decision(array $players, string $answer): BracketDecision
    {
        return new BracketDecision(
            key: 'bladerx',
            name: 'Blader X',
            isCollision: false,
            problem: '',
            suggestions: array_map(
                static fn (Player $player): AliasSuggestion => new AliasSuggestion(
                    $player,
                    strtolower($player->getName()),
                    AliasSuggestionReason::Spelling,
                    1,
                ),
                $players,
            ),
            rank: 11,
            matches: 5,
            answer: $answer,
        );
    }

    /** @return list<string> */
    private function rejectedNames(BracketDecision $decision): array
    {
        return array_map(
            static fn (AliasSuggestion $suggestion): string => $suggestion->player->getName(),
            $decision->rejectedSuggestions(),
        );
    }
}
