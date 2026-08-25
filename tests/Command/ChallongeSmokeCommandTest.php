<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Tests\Support\ConsoleTestCase;
use App\Tests\Support\FakeChallonge;
use Symfony\Component\Console\Command\Command;

/**
 * The smoke check as somebody runs it: against the fixture Challonge in
 * config/services_test.yaml, and against a page on disk.
 *
 * Nothing here reaches the network, which is the point — a change to the
 * parser that broke an import is caught by CI rather than by an event.
 */
final class ChallongeSmokeCommandTest extends ConsoleTestCase
{
    public function testAGoodBracketPassesEveryExpectation(): void
    {
        $tester = $this->executeCommand(['url' => 'https://challonge.com/'.FakeChallonge::SLUG]);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'still reads the way the importer expects');
        self::assertCommandSaid($tester, 'a tournament store that decodes as JSON');
        self::assertCommandSaid($tester, 'a standings table for the stage that orders the event');
    }

    /**
     * The whole checklist, not just the verdict: on the day this fails, the
     * expectations either side of the failure are what say how much of the
     * page is still the page we knew.
     */
    public function testItListsEveryExpectationItChecked(): void
    {
        $tester = $this->executeCommand(['url' => 'https://challonge.com/'.FakeChallonge::SLUG]);

        foreach ([
            'an HTML page',
            'a tournament with an id and a format',
            'at least one round',
            'at least one match',
            'matches carrying an id, two named entrants, the scores and a winner',
        ] as $expectation) {
            self::assertCommandSaid($tester, $expectation);
        }
    }

    public function testABracketThatRendersNoStandingsFails(): void
    {
        $tester = $this->executeCommand(['url' => 'https://challonge.com/'.FakeChallonge::SLUG_WITHOUT_STANDINGS]);

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'Expected a standings table for the stage that orders the event');
    }

    public function testItChecksAPageAlreadyOnDisk(): void
    {
        $tester = $this->executeCommand(['--file' => $this->fixturePath('module-page.html')]);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'still reads the way the importer expects');
    }

    public function testItSaysSoWhenThereIsNoPageAtThePathItWasGiven(): void
    {
        $tester = $this->executeCommand(['--file' => $this->fixturePath('no-such-page.html')]);

        self::assertCommandExited($tester, Command::INVALID);
        self::assertCommandSaid($tester, 'There is no readable page at');
    }

    /**
     * The form the scheduled run takes. It names its own bracket so that the
     * cron entry is the command and nothing else.
     */
    public function testWithNoUrlItChecksAKnownBracket(): void
    {
        $tester = $this->executeCommand([]);

        self::assertCommandSaid($tester, 'Reading https://challonge.com/nppk0890/module?show_standings=1');
    }

    public function testItRejectsSomethingThatIsNotABracketUrl(): void
    {
        $tester = $this->executeCommand(['url' => 'https://example.com/9yuqg2pi']);

        self::assertCommandExited($tester, Command::INVALID);
        self::assertCommandSaid($tester, 'is not a Challonge URL');
    }

    public function testAnUnreachableChallongeIsReportedAsSuch(): void
    {
        $tester = $this->executeCommand(['url' => 'https://challonge.com/'.FakeChallonge::UNREACHABLE_SLUG]);

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'Could not reach');
    }

    /**
     * Reading a page must not write one. The check runs on a schedule and in
     * front of an import, and neither wants a snapshot as a side effect.
     */
    public function testItCapturesNothing(): void
    {
        $this->executeCommand(['url' => 'https://challonge.com/'.FakeChallonge::SLUG]);

        self::assertFileDoesNotExist(sprintf('%s/var/data/challonge/%s.json', self::projectDir(), FakeChallonge::SLUG));
        self::assertLedgerIsEmpty();
    }

    #[\Override]
    protected static function commandName(): string
    {
        return 'app:challonge-smoke';
    }

    private function fixturePath(string $file): string
    {
        return sprintf('%s/tests/Fixtures/challonge/%s', self::projectDir(), $file);
    }
}
