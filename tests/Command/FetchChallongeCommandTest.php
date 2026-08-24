<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Dto\ChallongeSnapshot;
use App\Tests\Support\ConsoleTestCase;
use App\Tests\Support\FakeChallonge;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Drives the fetch end to end against the fixture Challonge in
 * config/services_test.yaml, so the real fetcher, parser and writer all run
 * and nothing reaches the network.
 */
final class FetchChallongeCommandTest extends ConsoleTestCase
{
    public function testItCapturesABracket(): void
    {
        $tester = $this->fetch(FakeChallonge::SLUG);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'Captured fixture1');
        self::assertCommandSaid($tester, '7 matches across 2 stages');
        self::assertFileExists($this->snapshotPath(FakeChallonge::SLUG));
    }

    public function testTheSnapshotHoldsTheBracketAndWhereItCameFrom(): void
    {
        $this->fetch(FakeChallonge::SLUG);

        $snapshot = json_decode(
            (string) file_get_contents($this->snapshotPath(FakeChallonge::SLUG)),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($snapshot);
        self::assertSame('fixture1', $snapshot['slug']);
        self::assertSame('https://challonge.com/fixture1/module?show_standings=1', $snapshot['source_url']);
        self::assertSame(ChallongeSnapshot::VERSION, $snapshot['version']);
        self::assertSame(
            ['id' => 18169778, 'type' => 'single elimination', 'state' => 'complete', 'is_team' => false],
            $snapshot['tournament'],
        );
        self::assertIsArray($snapshot['stages']);
        self::assertCount(2, $snapshot['stages']);
    }

    /**
     * The whole point of the snapshot is that a replay never fetches, so the
     * fetch itself is not a replayable step and writes no ledger line.
     */
    public function testItWritesNoLedgerLine(): void
    {
        $tester = $this->fetch(FakeChallonge::SLUG);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertLedgerIsEmpty();
    }

    /**
     * Nothing Challonge sends only the embed — portraits, checksums, chat
     * flags — survives into the file.
     */
    public function testTheSnapshotHoldsNoneOfTheNoise(): void
    {
        $this->fetch(FakeChallonge::SLUG);

        $written = (string) file_get_contents($this->snapshotPath(FakeChallonge::SLUG));

        foreach (['portrait_url', 'md5', 'has_chat', 'quick_added', 'scorecard_html', 'underway_at'] as $noise) {
            self::assertStringNotContainsString($noise, $written);
        }
    }

    public function testItSaysWhenItIsReplacingAnEarlierCapture(): void
    {
        $this->fetch(FakeChallonge::SLUG);

        self::assertCommandSaid($this->fetch(FakeChallonge::SLUG), 'Refreshed fixture1');
    }

    public function testItTakesTheInviteLinkShapeToo(): void
    {
        $tester = $this->executeCommand(['url' => 'https://challonge.com/vi/fixture1']);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertFileExists($this->snapshotPath(FakeChallonge::SLUG));
    }

    public function testItWarnsAboutABracketThatCarriedNoStandings(): void
    {
        $tester = $this->fetch(FakeChallonge::SLUG_WITHOUT_STANDINGS);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'The page carried no standings table.');
    }

    public function testItRejectsSomethingThatIsNotABracketUrl(): void
    {
        $tester = $this->executeCommand(['url' => 'https://example.com/9yuqg2pi']);

        self::assertCommandExited($tester, Command::INVALID);
        self::assertCommandSaid($tester, 'is not a Challonge URL');
    }

    public function testABouncedRequestLeavesNoSnapshot(): void
    {
        $tester = $this->fetch(FakeChallonge::BOUNCED_SLUG);

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'answered 403, expected 200.');
        self::assertFileDoesNotExist($this->snapshotPath(FakeChallonge::BOUNCED_SLUG));
    }

    public function testAnUnreachableChallongeLeavesNoSnapshot(): void
    {
        $tester = $this->fetch(FakeChallonge::UNREACHABLE_SLUG);

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'Could not reach');
        self::assertFileDoesNotExist($this->snapshotPath(FakeChallonge::UNREACHABLE_SLUG));
    }

    public function testABracketThatIsGoneLeavesNoSnapshot(): void
    {
        $tester = $this->fetch(FakeChallonge::UNKNOWN_SLUG);

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'answered 404, expected 200.');
        self::assertFileDoesNotExist($this->snapshotPath(FakeChallonge::UNKNOWN_SLUG));
    }

    #[\Override]
    protected static function commandName(): string
    {
        return 'app:fetch-challonge';
    }

    /**
     * `var/data/challonge/` is tracked by git, so every snapshot a test writes
     * has to go again afterwards.
     *
     * @return list<string>
     */
    #[\Override]
    protected function artifactPaths(): array
    {
        return [
            ...parent::artifactPaths(),
            ...array_map(
                $this->snapshotPath(...),
                [
                    FakeChallonge::SLUG,
                    FakeChallonge::SLUG_WITHOUT_STANDINGS,
                    FakeChallonge::BOUNCED_SLUG,
                    FakeChallonge::UNREACHABLE_SLUG,
                    FakeChallonge::UNKNOWN_SLUG,
                ],
            ),
        ];
    }

    private function snapshotPath(string $slug): string
    {
        return sprintf('%s/var/data/challonge/%s.json', self::projectDir(), $slug);
    }

    private function fetch(string $slug): CommandTester
    {
        return $this->executeCommand(['url' => 'https://challonge.com/'.$slug]);
    }
}
