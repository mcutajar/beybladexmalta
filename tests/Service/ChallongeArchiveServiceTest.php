<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ChallongeMatch;
use App\Dto\ChallongeParticipant;
use App\Dto\ChallongeRound;
use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeStage;
use App\Dto\ChallongeStageKind;
use App\Dto\ChallongeStanding;
use App\Entity\MatchGame;
use App\Entity\Tournament;
use App\Entity\TournamentMatch;
use App\Entity\TournamentParticipant;
use App\Entity\TournamentStage;
use App\Repository\PlayerRepository;
use App\Repository\TournamentStageRepository;
use App\Service\ChallongeArchiveResult;
use App\Service\ChallongeArchiveService;
use App\Tests\Factory\PlayerAliasFactory;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentResultFactory;
use App\Tests\Factory\TournamentTeamFactory;
use App\Tests\Story\SeasonStory;
use App\Tests\Support\ServiceTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Attribute\WithStory;

/**
 * The archive, against brackets built here rather than captured ones.
 *
 * `ArchivedBracketsTest` puts it to the eighteen the league actually played;
 * this is where the shapes that corpus does not contain live — a best-of-three
 * that is not a team match, a bracket edited after it was archived, an entrant
 * called `bye` in a solo event.
 */
#[ResetDatabase]
#[WithStory(SeasonStory::class)]
final class ChallongeArchiveServiceTest extends ServiceTestCase
{
    private const SLUG = 'co5nncw8';

    private ChallongeArchiveService $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archive = $this->service(ChallongeArchiveService::class);
    }

    public function testItArchivesTheStagesTheEntrantsAndTheMatches(): void
    {
        $event = $this->event();

        $outcome = $this->archive->archive($event, $this->bracket());

        self::assertSame(ChallongeArchiveResult::Archived, $outcome->result);
        self::assertSame([1, 3, 2, 0], [$outcome->stages, $outcome->participants, $outcome->matches, $outcome->games]);

        $stage = $this->stages($event)[0];
        self::assertSame(ChallongeStageKind::Group, $stage->getKind());
        self::assertSame('Group A', $stage->getName());
        self::assertSame('swiss', $stage->getFormat());
        self::assertSame(2, $stage->getRounds());
    }

    /**
     * Ranks below eleven pay no league points and are half the matches. A
     * blader's record is wrong without them, which is the whole reason this
     * table is not `TournamentResult` with more columns.
     */
    public function testItArchivesTheEntrantsWhoScoredNothing(): void
    {
        $event = $this->event();

        $this->archive->archive($event, $this->bracket());

        self::assertSame(
            ['legion', 'Obelix', 'Giglio'],
            array_map(
                static fn (TournamentParticipant $entrant): string => $entrant->getName(),
                $this->stages($event)[0]->getParticipants()->toArray(),
            ),
        );
    }

    public function testItArchivesWhatTheStandingsSaidAboutAnEntrant(): void
    {
        $event = $this->event();

        $this->archive->archive($event, $this->bracket());

        $entrant = $this->entrant($event, 'legion');

        self::assertSame(1, $entrant->getStageRank());
        self::assertTrue($entrant->hasAdvanced());
        self::assertSame([2, 0, 0], [$entrant->getWins(), $entrant->getLosses(), $entrant->getTies()]);
        self::assertSame(2.0, $entrant->getScore());
        self::assertSame(3.0, $entrant->getBuchholz());
        self::assertSame(11, $entrant->getPointsDifferential());
        self::assertSame(1, $entrant->getByes());
        self::assertSame('Sanya0207', $entrant->getChallongeUser());
    }

    /**
     * The done-when this whole table is shaped around. `app:import-tournament`
     * has no such guard, which is why a second replay of `repeat.sh` doubles
     * every result it holds.
     */
    public function testArchivingTheSameBracketTwiceLeavesTheSameRows(): void
    {
        $event = $this->event();

        $this->archive->archive($event, $this->bracket());
        $first = $this->rowIds($event);

        $outcome = $this->archive->archive($event, $this->bracket());

        self::assertSame($first, $this->rowIds($event));
        self::assertSame(0, $outcome->discarded);
    }

    /**
     * A bracket corrected upstream is re-read into the row it already has,
     * rather than layered over it.
     */
    public function testItRepairsAScoreThatWasCorrectedUpstream(): void
    {
        $event = $this->event();

        $this->archive->archive($event, $this->bracket());
        $before = $this->rowIds($event);

        $this->archive->archive($event, $this->bracket(score: [7, 5]));

        self::assertSame($before, $this->rowIds($event));
        self::assertSame([7, 5], [$this->match($event, 901)->getPlayer1Score(), $this->match($event, 901)->getPlayer2Score()]);
    }

    public function testItDropsAMatchTheBracketNoLongerHas(): void
    {
        $event = $this->event();

        $this->archive->archive($event, $this->bracket());

        $outcome = $this->archive->archive($event, $this->bracket(matches: 1));

        self::assertSame(1, $outcome->matches);
        self::assertSame(1, $outcome->discarded);
        self::assertCount(1, $this->stages($event)[0]->getMatches());
    }

    /**
     * The rule the empty `match_games` table is evidence of: a single game
     * restates its own match's scoreline and carries nothing new.
     */
    public function testASingleGameIsNotWrittenAsAGame(): void
    {
        $event = $this->event();

        $outcome = $this->archive->archive($event, $this->bracket());

        self::assertSame(0, $outcome->games);
        self::assertCount(0, $this->match($event, 901)->getGames());
        self::assertSame([7, 4], [$this->match($event, 901)->getPlayer1Score(), $this->match($event, 901)->getPlayer2Score()]);
    }

    /**
     * And the reason the table is built anyway. Every multi-game match in the
     * corpus is a team match, and team events are not archived — so the first
     * solo best-of-three the league plays is the first row here.
     */
    public function testItWritesTheGamesOfABestOfThree(): void
    {
        $event = $this->event();

        $outcome = $this->archive->archive($event, $this->bracket(games: [[4, 7], [7, 3], [1, 7]]));

        self::assertSame(3, $outcome->games);
        self::assertSame(
            [[1, 4, 7], [2, 7, 3], [3, 1, 7]],
            array_map(
                static fn (MatchGame $game): array => [$game->getNumber(), $game->getPlayer1Score(), $game->getPlayer2Score()],
                $this->match($event, 901)->getGames()->toArray(),
            ),
        );
    }

    /**
     * A match that has become single-game since it was archived loses the rows
     * it had, rather than keeping a scoreline the bracket no longer states.
     */
    public function testAMatchThatIsNoLongerMultiGameLosesItsGames(): void
    {
        $event = $this->event();

        $this->archive->archive($event, $this->bracket(games: [[4, 7], [7, 3], [1, 7]]));
        $outcome = $this->archive->archive($event, $this->bracket());

        self::assertSame(0, $outcome->games);
        self::assertCount(0, $this->match($event, 901)->getGames());
    }

    /**
     * One of the three ways Challonge says nobody played: complete, decided,
     * and with no scoreline at all. There are four in the corpus.
     */
    public function testItKeepsAForfeitWithNoScoreline(): void
    {
        $event = $this->event();

        $this->archive->archive($event, $this->bracket(forfeited: true));

        $match = $this->match($event, 901);

        self::assertTrue($match->isForfeited());
        self::assertFalse($match->wasPlayed());
        self::assertNull($match->getPlayer1Score());
        self::assertNull($match->getPlayer2Score());
        self::assertSame('legion', $match->getWinner()?->getName());
    }

    /**
     * The third-place playoff is played after the final and would otherwise
     * look like it, which is how the knockout winner becomes the wrong person.
     */
    public function testItKeepsThePlayoffMarkedAsOne(): void
    {
        $event = $this->event();

        $this->archive->archive($event, $this->bracket(consolation: true));

        self::assertTrue($this->match($event, 902)->isConsolation());
        self::assertFalse($this->match($event, 901)->isConsolation());
    }

    /**
     * The third way, and the only one in the corpus — in a team event, so
     * nothing archives it today. A solo bracket with one lands here as an
     * entrant like any other, because a transcription does not tidy, and it
     * reaches nobody without being reported as a missing alias.
     */
    public function testTheByeEntrantIsArchivedAndIsNobody(): void
    {
        $event = $this->event();

        $outcome = $this->archive->archive($event, $this->bracket(entrant: 'bye'));

        self::assertNull($this->entrant($event, 'bye')->getPlayer());
        self::assertNotContains('bye', $outcome->unrecognised);
    }

    public function testItAttachesTheBladerAnAliasNames(): void
    {
        $event = $this->event();
        $sanya = PlayerFactory::createOne(['name' => 'Sanya']);
        PlayerAliasFactory::createOne(['player' => $sanya, 'alias' => 'legion']);

        $outcome = $this->archive->archive($event, $this->bracket());

        self::assertSame('Sanya', $this->entrant($event, 'legion')->getPlayer()?->getName());
        self::assertSame(1, $outcome->bladers);
    }

    /**
     * An entrant nobody is called is archived with their matches and attached
     * to nobody. Nothing invents a blader here, and nothing picks one: the
     * name comes back as a question, and re-archiving after the alias is filed
     * picks them up.
     */
    public function testAnEntrantNobodyIsCalledIsArchivedAndReported(): void
    {
        $event = $this->event();

        $outcome = $this->archive->archive($event, $this->bracket());

        self::assertSame(['Giglio', 'Obelix', 'legion'], $outcome->unrecognised);
        self::assertNull($this->entrant($event, 'legion')->getPlayer());
        self::assertCount(0, PlayerFactory::repository()->findAll());
    }

    /**
     * A team match records only the aggregate of its individual matchups, so
     * there is no blader-level row to write and its entrants are teams. The
     * teams are already on record; nothing else is.
     */
    public function testATeamEventArchivesNothing(): void
    {
        $event = $this->event();
        TournamentTeamFactory::createOne(['tournament' => $event, 'name' => 'legion', 'rank' => 1]);

        $outcome = $this->archive->archive($event, $this->bracket());

        self::assertSame(ChallongeArchiveResult::TeamEvent, $outcome->result);
        self::assertSame([], $this->stages($event));
        self::assertLedgerIsEmpty();
    }

    /**
     * The archive has to be replayable, and the replay line names a bracket.
     * An event that does not say which bracket it came from could be archived
     * once and never again.
     */
    public function testAnEventThatNamesNoBracketIsRefused(): void
    {
        $event = TournamentFactory::createOne(['title' => 'Gamebreaker 20-06', 'season' => SeasonStory::freeSeason()]);

        $outcome = $this->archive->archive($event, $this->bracket());

        self::assertSame(ChallongeArchiveResult::NoBracketRecorded, $outcome->result);
        self::assertSame([], $this->stages($event));
    }

    public function testAnEventImportedFromAnotherBracketIsRefused(): void
    {
        $event = $this->event('https://challonge.com/nppk0890');

        $outcome = $this->archive->archive($event, $this->bracket());

        self::assertSame(ChallongeArchiveResult::NotThisBracket, $outcome->result);
        self::assertSame([], $this->stages($event));
    }

    /**
     * The snapshot is tracked by git, so unlike a fetch this replays offline —
     * and unlike an import, a second line for a bracket already archived costs
     * nothing.
     */
    public function testItWritesItsReplayLine(): void
    {
        $this->archive->archive($this->event(), $this->bracket());

        self::assertLedgerRecordsArchive(self::SLUG);
    }

    /**
     * The archive is additive. `TournamentResult` is untouched, so the
     * leaderboard returns the same rows in the same order, before and after.
     */
    public function testTheLeaderboardIsUnchanged(): void
    {
        $event = $this->event();
        $sanya = PlayerFactory::createOne(['name' => 'Sanya']);
        PlayerAliasFactory::createOne(['player' => $sanya, 'alias' => 'legion']);
        TournamentResultFactory::createOne(['tournament' => $event, 'player' => $sanya, 'rank' => 1, 'f1Points' => 25, 'bonusPoints' => 10]);

        $players = $this->service(PlayerRepository::class);
        $before = $players->getLeagueLeaderboard('free-season');

        $this->archive->archive($event, $this->bracket());

        self::assertSame($before, $players->getLeagueLeaderboard('free-season'));
        self::assertCount(1, TournamentResultFactory::repository()->findAll());
    }

    private function event(string $challongeUrl = 'https://challonge.com/'.self::SLUG): Tournament
    {
        return TournamentFactory::createOne([
            'title' => 'Gamebreaker 20-06',
            'season' => SeasonStory::freeSeason(),
            'challongeUrl' => $challongeUrl,
        ]);
    }

    /**
     * @return list<TournamentStage>
     */
    private function stages(Tournament $event): array
    {
        return $this->service(TournamentStageRepository::class)->forTournament($event);
    }

    private function entrant(Tournament $event, string $name): TournamentParticipant
    {
        foreach ($this->stages($event) as $stage) {
            foreach ($stage->getParticipants() as $entrant) {
                if ($entrant->getName() === $name) {
                    return $entrant;
                }
            }
        }

        self::fail(sprintf('No entrant called "%s" was archived.', $name));
    }

    private function match(Tournament $event, int $challongeId): TournamentMatch
    {
        foreach ($this->stages($event) as $stage) {
            foreach ($stage->getMatches() as $match) {
                if ($match->getChallongeId() === $challongeId) {
                    return $match;
                }
            }
        }

        self::fail(sprintf('Match %d was not archived.', $challongeId));
    }

    /**
     * Every row the archive wrote, by id, so that "the same rows" can mean the
     * same rows rather than the same number of them.
     *
     * @return list<string>
     */
    private function rowIds(Tournament $event): array
    {
        $ids = [];

        foreach ($this->stages($event) as $stage) {
            $ids[] = sprintf('stage %d', (int) $stage->getId());

            foreach ($stage->getParticipants() as $entrant) {
                $ids[] = sprintf('entrant %d', (int) $entrant->getId());
            }

            foreach ($stage->getMatches() as $match) {
                $ids[] = sprintf('match %d', (int) $match->getId());

                foreach ($match->getGames() as $game) {
                    $ids[] = sprintf('game %d', (int) $game->getId());
                }
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * A two-match Swiss stage: `legion` beat `Giglio` and then `Obelix`, and
     * the standings row for `legion` is rendered as the Challonge account they
     * linked rather than as their name — which is the join the archive relies
     * on and the reason the entrant and the row are stored together.
     *
     * @param list<int>        $score
     * @param ?list<list<int>> $games
     */
    private function bracket(
        array $score = [7, 4],
        ?array $games = null,
        int $matches = 2,
        bool $forfeited = false,
        bool $consolation = false,
        string $entrant = 'Obelix',
    ): ChallongeSnapshot {
        $played = [
            new ChallongeMatch(
                id: 901,
                round: 1,
                identifier: 'A',
                state: 'complete',
                player1Id: 1,
                player2Id: 2,
                games: $forfeited ? [] : ($games ?? [$score]),
                score: $forfeited ? [] : $score,
                winnerId: 1,
                loserId: 2,
                forfeited: $forfeited,
                consolation: false,
            ),
            new ChallongeMatch(
                id: 902,
                round: 2,
                identifier: 'B',
                state: 'complete',
                player1Id: 1,
                player2Id: 3,
                games: [[7, 2]],
                score: [7, 2],
                winnerId: 1,
                loserId: 3,
                forfeited: false,
                consolation: $consolation,
            ),
        ];

        return new ChallongeSnapshot(
            slug: self::SLUG,
            sourceUrl: 'https://challonge.com/'.self::SLUG.'/module?show_standings=1',
            fetchedAt: new \DateTimeImmutable('2026-08-24T12:00:00+00:00'),
            tournamentId: 18113372,
            tournamentType: 'swiss',
            tournamentState: 'complete',
            isTeamTournament: false,
            stages: [
                new ChallongeStage(
                    kind: ChallongeStageKind::Group,
                    name: 'Group A',
                    format: 'swiss',
                    rounds: [
                        new ChallongeRound(number: 1, title: 'Round 1'),
                        new ChallongeRound(number: 2, title: 'Round 2'),
                    ],
                    participants: [
                        new ChallongeParticipant(id: 1, participantId: 5001, seed: 1, name: 'legion'),
                        new ChallongeParticipant(id: 2, participantId: 5002, seed: 2, name: $entrant),
                        new ChallongeParticipant(id: 3, participantId: 5003, seed: 3, name: 'Giglio'),
                    ],
                    matches: array_slice($played, 0, $matches),
                    standings: [
                        new ChallongeStanding(
                            rank: 1,
                            name: null,
                            challongeUser: 'Sanya0207',
                            labels: ['Advanced'],
                            matchIds: [901, 902],
                            columns: [
                                'Match W-L-T (wins +1.0, ties +0.5)' => '2 - 0 - 0',
                                'Score' => '2.0',
                                'Buchholz' => '3.0',
                                'TB' => '0',
                                'Pts Diff' => '+11',
                                'Byes (+1.0)' => '1',
                            ],
                        ),
                        new ChallongeStanding(
                            rank: 2,
                            name: $entrant,
                            challongeUser: null,
                            labels: [],
                            matchIds: [901],
                            columns: ['Match W-L-T (wins +1.0, ties +0.5)' => '0 - 1 - 0'],
                        ),
                        new ChallongeStanding(
                            rank: 3,
                            name: 'Giglio',
                            challongeUser: null,
                            labels: [],
                            matchIds: [902],
                            columns: ['Match W-L-T (wins +1.0, ties +0.5)' => '0 - 1 - 0'],
                        ),
                    ],
                ),
            ],
        );
    }
}
