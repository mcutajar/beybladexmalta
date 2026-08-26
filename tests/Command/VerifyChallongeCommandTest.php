<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeUrl;
use App\Service\ChallongeFetcher;
use App\Service\ChallongeSnapshotReader;
use App\Service\ChallongeSnapshotWriter;
use App\Tests\Support\ConsoleTestCase;
use App\Tests\Support\FakeChallonge;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The check that a bracket is still what we captured, against the fixture
 * Challonge in `config/services_test.yaml` — so the real fetcher, parser and
 * normaliser all run and nothing reaches the network.
 */
final class VerifyChallongeCommandTest extends ConsoleTestCase
{
    /**
     * The one thing that always differs between a capture and a re-fetch, and
     * the reason `git diff` cannot answer this question.
     */
    public function testAnUnchangedBracketHasNothingToReport(): void
    {
        $this->capture();

        $tester = $this->verify(FakeChallonge::SLUG);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'fixture1 is what was captured on');
        self::assertCommandSaid($tester, '7 matches across 2 stages, unchanged.');
    }

    public function testItNamesWhatTheBracketHasChanged(): void
    {
        $this->capture();
        $this->editTheCapture(static function (array $snapshot): array {
            $snapshot['stages'][0]['participants'][0]['name'] = 'Somebody Else';

            return $snapshot;
        });

        $tester = $this->verify(FakeChallonge::SLUG);

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'fixture1 has changed in 1 place since it was captured on');
        self::assertCommandSaid($tester, 'stages[0].participants[0].name: "Somebody Else" is now');
    }

    /**
     * Refreshing the record is still `app:fetch-challonge`, deliberately:
     * overwriting a capture is a decision somebody makes after reading this.
     */
    public function testItWritesNothingEitherWay(): void
    {
        $this->capture();
        $captured = (string) file_get_contents($this->snapshotPath());

        $this->verify(FakeChallonge::SLUG);

        self::assertSame($captured, file_get_contents($this->snapshotPath()));
        self::assertLedgerIsEmpty();
    }

    public function testItTakesAUrlAsWellAsASlug(): void
    {
        $this->capture();

        self::assertCommandExited($this->verify('https://challonge.com/vi/fixture1'), Command::SUCCESS);
    }

    /**
     * There is nothing to verify a bracket against until it has been
     * captured once.
     */
    public function testItSaysWhenTheBracketWasNeverCaptured(): void
    {
        $tester = $this->verify(FakeChallonge::UNKNOWN_SLUG);

        self::assertCommandExited($tester, Command::INVALID);
        self::assertCommandSaid($tester, 'Capture the bracket first with app:fetch-challonge.');
    }

    /**
     * A bracket that has been taken down is a change worth shouting about,
     * and it is not one this command can describe field by field.
     */
    public function testABracketThatIsGoneIsAFailure(): void
    {
        $this->capture(FakeChallonge::UNREACHABLE_SLUG);

        $tester = $this->verify(FakeChallonge::UNREACHABLE_SLUG);

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'Could not reach');
    }

    #[\Override]
    protected static function commandName(): string
    {
        return 'app:verify-challonge';
    }

    /**
     * `var/data/challonge/` is tracked by git, so a snapshot a test captures
     * has to go again afterwards.
     *
     * @return list<string>
     */
    #[\Override]
    protected function artifactPaths(): array
    {
        return [
            ...parent::artifactPaths(),
            $this->snapshotPath(),
            $this->snapshotPath(FakeChallonge::UNREACHABLE_SLUG),
        ];
    }

    /**
     * Captures the fixture bracket the way `app:fetch-challonge` would.
     *
     * The unreachable slug is captured under the fixture's contents on
     * purpose: a snapshot has to exist for the command to get as far as
     * asking Challonge for it.
     */
    private function capture(string $slug = FakeChallonge::SLUG): void
    {
        $snapshot = $this->service(ChallongeFetcher::class)
            ->fetch(ChallongeUrl::fromString('https://challonge.com/'.FakeChallonge::SLUG));

        $this->service(ChallongeSnapshotWriter::class)->write(
            FakeChallonge::SLUG === $slug ? $snapshot : $this->renamed($snapshot->toArray(), $slug),
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function renamed(array $snapshot, string $slug): ChallongeSnapshot
    {
        $snapshot['slug'] = $slug;
        $snapshot['source_url'] = sprintf('https://challonge.com/%s/module?show_standings=1', $slug);

        return $this->service(ChallongeSnapshotReader::class)->fromArray($snapshot, 'a test');
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $edit
     */
    private function editTheCapture(callable $edit): void
    {
        $snapshot = json_decode((string) file_get_contents($this->snapshotPath()), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($snapshot);

        file_put_contents(
            $this->snapshotPath(),
            json_encode($edit($snapshot), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT),
        );
    }

    private function snapshotPath(string $slug = FakeChallonge::SLUG): string
    {
        return sprintf('%s/var/data/challonge/%s.json', self::projectDir(), $slug);
    }

    private function verify(string $bracket): CommandTester
    {
        return $this->executeCommand(['bracket' => $bracket]);
    }
}
