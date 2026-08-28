<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Dto\ChallongeStageKind;
use App\Entity\PlayerMergeRedirect;
use App\Entity\TournamentParticipant;
use App\Entity\TournamentStage;
use App\Repository\PlayerRepository;
use App\Tests\Factory\PlayerAliasFactory;
use App\Tests\Factory\PlayerAliasRejectionFactory;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\SeasonFactory;
use App\Tests\Factory\SeasonRegistrationFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentResultFactory;
use App\Tests\Factory\TournamentTeamFactory;
use App\Tests\Factory\TournamentTeamMemberFactory;
use App\Tests\Support\ConsoleTestCase;
use Symfony\Component\Console\Command\Command;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class MergePlayerCommandTest extends ConsoleTestCase
{
    #[\Override]
    protected static function commandName(): string
    {
        return 'app:merge-player';
    }

    public function testDryRunReportsEverythingAndWritesNothing(): void
    {
        [$from, $into] = $this->players();
        $tournament = TournamentFactory::createOne(['title' => 'A distinct tournament']);
        TournamentResultFactory::createOne(['player' => $from, 'tournament' => $tournament]);
        $season = SeasonFactory::createOne(['name' => 'Season Alpha']);
        SeasonRegistrationFactory::createOne(['player' => $from, 'season' => $season]);
        $team = TournamentTeamFactory::createOne(['name' => 'The Pair']);
        TournamentTeamMemberFactory::createOne(['player' => $from, 'team' => $team]);
        PlayerAliasFactory::createOne(['player' => $from, 'alias' => 'Oldie']);
        PlayerAliasRejectionFactory::createOne(['player' => $from, 'spelling' => 'Wrong oldie']);

        $tester = $this->executeCommand(['from' => 'Old Name', 'into' => 'Survivor']);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'A distinct tournament');
        self::assertCommandSaid($tester, 'Season Alpha');
        self::assertCommandSaid($tester, 'The Pair');
        self::assertCommandSaid($tester, 'Oldie');
        self::assertCommandSaid($tester, 'Wrong oldie');
        self::assertCommandSaid($tester, sprintf('/season/{slug}/player/%d', $from->getId()));
        PlayerFactory::assert()->count(2);
        self::assertLedgerIsEmpty();
    }

    public function testItMovesHistoryAliasesAndRejectionsAndRecordsTheRedirect(): void
    {
        [$from, $into] = $this->players();
        $tournament = TournamentFactory::createOne();
        TournamentResultFactory::createOne(['player' => $from, 'tournament' => $tournament, 'f1Points' => 15, 'bonusPoints' => 3]);
        $season = SeasonFactory::createOne();
        SeasonRegistrationFactory::createOne(['player' => $from, 'season' => $season, 'paid' => true]);
        $team = TournamentTeamFactory::createOne();
        TournamentTeamMemberFactory::createOne(['player' => $from, 'team' => $team]);
        $stage = new TournamentStage($tournament, 0, ChallongeStageKind::Single);
        $participant = new TournamentParticipant($stage, 42, 'Old Name');
        $participant->isBlader($from);
        $manager = self::getContainer()->get('doctrine')->getManager();
        $manager->persist($stage);
        $manager->flush();
        PlayerAliasFactory::createOne(['player' => $from, 'alias' => 'Oldie']);
        PlayerAliasRejectionFactory::createOne(['player' => $from, 'spelling' => 'Nope']);
        PlayerAliasRejectionFactory::createOne(['player' => $into, 'spelling' => 'NOPE']);
        PlayerAliasRejectionFactory::createOne(['player' => $into, 'spelling' => 'Oldie']);
        $oldId = $from->getId();

        $tester = $this->executeCommand(['from' => 'Old Name', 'into' => 'Survivor', '--force' => true]);

        self::assertCommandExited($tester, Command::SUCCESS);
        PlayerFactory::assert()->count(1);
        TournamentResultFactory::assert()->exists(['player' => $into, 'tournament' => $tournament]);
        SeasonRegistrationFactory::assert()->exists(['player' => $into, 'season' => $season]);
        TournamentTeamMemberFactory::assert()->exists(['player' => $into, 'team' => $team]);
        self::assertSame($into->getId(), $manager->getRepository(TournamentParticipant::class)->find($participant->getId())?->getPlayer()?->getId());
        PlayerAliasFactory::assert()->exists(['player' => $into, 'normalised' => 'oldie']);
        PlayerAliasFactory::assert()->exists(['player' => $into, 'normalised' => 'oldname']);
        PlayerAliasRejectionFactory::assert()->count(1);
        self::assertSame($into->getId(), self::getContainer()->get('doctrine')->getRepository(PlayerMergeRedirect::class)->find($oldId)?->getSurvivor()->getId());
        self::assertLedgerRecordsPlayerMerge('Old Name', 'Survivor');
    }

    public function testSameTournamentCollisionNamesTheTournamentAndWritesNothing(): void
    {
        [$from, $into] = $this->players();
        $tournament = TournamentFactory::createOne(['title' => 'Gamesplus Collision']);
        TournamentResultFactory::createOne(['player' => $from, 'tournament' => $tournament]);
        TournamentResultFactory::createOne(['player' => $into, 'tournament' => $tournament]);

        $tester = $this->executeCommand(['from' => 'Old Name', 'into' => 'Survivor', '--force' => true]);

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'Gamesplus Collision');
        PlayerFactory::assert()->count(2);
        self::assertLedgerIsEmpty();
    }

    public function testSameSeasonRegistrationCollisionIsRefusedBeforeWriting(): void
    {
        [$from, $into] = $this->players();
        $season = SeasonFactory::createOne(['name' => 'Shared Season']);
        SeasonRegistrationFactory::createOne(['player' => $from, 'season' => $season]);
        SeasonRegistrationFactory::createOne(['player' => $into, 'season' => $season]);

        $tester = $this->executeCommand(['from' => 'Old Name', 'into' => 'Survivor', '--force' => true]);

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'Shared Season');
        self::assertLedgerIsEmpty();
    }

    public function testSameTeamCollisionIsRefusedBeforeWriting(): void
    {
        [$from, $into] = $this->players();
        $team = TournamentTeamFactory::createOne(['name' => 'Shared Team', 'tournament' => TournamentFactory::createOne(['title' => 'Team Night'])]);
        TournamentTeamMemberFactory::createOne(['player' => $from, 'team' => $team]);
        TournamentTeamMemberFactory::createOne(['player' => $into, 'team' => $team]);

        $tester = $this->executeCommand(['from' => 'Old Name', 'into' => 'Survivor', '--force' => true]);

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'Shared Team');
        self::assertLedgerIsEmpty();
    }

    public function testSuccessfulMergeCombinesLeaderboardHistory(): void
    {
        [$from, $into] = $this->players();
        $season = SeasonFactory::createOne(['slug' => 'combined', 'requiresPayment' => false]);
        TournamentResultFactory::createOne([
            'player' => $from,
            'tournament' => TournamentFactory::createOne(['season' => $season]),
            'f1Points' => 15,
            'bonusPoints' => 2,
        ]);
        TournamentResultFactory::createOne([
            'player' => $into,
            'tournament' => TournamentFactory::createOne(['season' => $season]),
            'f1Points' => 25,
            'bonusPoints' => 3,
        ]);

        $this->executeCommand(['from' => 'Old Name', 'into' => 'Survivor', '--force' => true]);

        $leaderboard = $this->service(PlayerRepository::class)->getLeagueLeaderboard('combined');
        self::assertSame('45', (string) $leaderboard[0]['total']);
    }

    public function testLedgerFailureRollsTheWholeMergeBack(): void
    {
        [$from, $into] = $this->players();
        TournamentResultFactory::createOne(['player' => $from]);
        self::blockLedgerWrites();

        $tester = $this->executeCommand(['from' => 'Old Name', 'into' => 'Survivor', '--force' => true]);

        self::assertCommandExited($tester, Command::FAILURE);
        self::getContainer()->get('doctrine')->resetManager();
        PlayerFactory::assert()->count(2);
        $old = PlayerFactory::repository()->find(['name' => 'Old Name']);
        TournamentResultFactory::assert()->exists(['player' => $old]);
    }

    public function testReplayingAnAppliedMergeIsASafeNoOp(): void
    {
        $this->players();
        $this->executeCommand(['from' => 'Old Name', 'into' => 'Survivor', '--force' => true]);
        self::removePath(self::ledgerPath());

        $tester = $this->executeCommand(['from' => 'Old Name', 'into' => 'Survivor', '--force' => true]);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'already in place');
        self::assertLedgerIsEmpty();
    }

    public function testOlderProfileRedirectsFollowASecondMerge(): void
    {
        $old = PlayerFactory::createOne(['name' => 'Old Name']);
        $middle = PlayerFactory::createOne(['name' => 'Middle']);
        $survivor = PlayerFactory::createOne(['name' => 'Survivor']);
        $oldId = $old->getId();
        $middleId = $middle->getId();

        $this->executeCommand(['from' => 'Old Name', 'into' => 'Middle', '--force' => true]);
        $this->executeCommand(['from' => 'Middle', 'into' => 'Survivor', '--force' => true]);

        $repository = self::getContainer()->get('doctrine')->getRepository(PlayerMergeRedirect::class);
        self::assertSame($survivor->getId(), $repository->find($oldId)?->getSurvivor()->getId());
        self::assertSame($survivor->getId(), $repository->find($middleId)?->getSurvivor()->getId());
    }

    /** @return array{\App\Entity\Player, \App\Entity\Player} */
    private function players(): array
    {
        return [
            PlayerFactory::createOne(['name' => 'Old Name']),
            PlayerFactory::createOne(['name' => 'Survivor']),
        ];
    }
}
