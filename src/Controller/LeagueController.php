<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Season;
use App\Repository\PlayerMergeRedirectRepository;
use App\Repository\PlayerRepository;
use App\Repository\SeasonRegistrationRepository;
use App\Repository\SeasonRepository;
use App\Repository\TournamentRepository;
use App\Repository\TournamentResultRepository;
use App\Repository\TournamentStageRepository;
use App\Repository\TournamentTeamRepository;
use App\Service\LeagueRecordsPresenter;
use App\Service\PlayerCareerPresenter;
use App\Service\SeasonIndexPresenter;
use App\Service\TournamentArchivePresenter;
use App\Service\TournamentShelfPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LeagueController extends AbstractController
{
    /**
     * Every season the league has held — option 6C.
     *
     * Switching season is navigation rather than a control, which is what
     * lets the leaderboard carry no scope chrome at all. The cost, accepted:
     * two taps every time, permanently.
     *
     * **No total across seasons anywhere on it.** League points are
     * season-specific and are never summed, so this page carries a card per
     * season and links out to the two all-time views that are match-derived.
     *
     * Declared first, and `/seasons/{slug}` has lost the default that used to
     * make a bare `/seasons` fall through to the preseason leaderboard. Both
     * matter: Symfony matches in declaration order, and a placeholder with a
     * default matches the shorter path too, so leaving either alone would have
     * kept `/seasons` pointing at one season's table.
     */
    #[Route('/seasons', name: 'seasons_index', methods: ['GET'])]
    public function seasonsIndex(SeasonRepository $seasonRepository, TournamentRepository $tournaments, SeasonIndexPresenter $index): Response
    {
        return $this->render('league/seasons.html.twig', [
            'cards' => $index->present($seasonRepository->ordered(), $tournaments->archiveIndex()),
        ]);
    }

    #[Route('/season/{slug}', name: 'season_leaderboard', defaults: ['slug' => 'preseason-1'], methods: ['GET'])]
    #[Route('/seasons/{slug}', name: 'season_leaderboard_2', methods: ['GET'])]
    #[Route('/preseason', name: 'season_leaderboard_legacy', defaults: ['slug' => 'preseason-1'], methods: ['GET'])]
    public function seasonLeaderboard(string $slug, PlayerRepository $playerRepository, SeasonRepository $seasonRepository): Response
    {
        $season = $seasonRepository->findOneBy(['slug' => $slug]);
        if (!$season) {
            throw $this->createNotFoundException('Season context not found.');
        }

        $leaderboardData = $playerRepository->getLeagueLeaderboard($slug);

        foreach ($leaderboardData as $index => &$row) {
            $row['rank'] = $index + 1;
        }

        return $this->render('league/leaderboard.html.twig', [
            'leaderboard_data' => $leaderboardData,
            'current_season' => $season,
        ]);
    }

    /**
     * A blader's profile, at their canonical URL.
     *
     * Player identity is independent of seasons, so the route is too. The slug
     * is persisted rather than derived from the current display name on every
     * request: a harmless name correction would otherwise silently break a
     * public URL somebody has shared.
     *
     * `?season=` narrows the page, sharing the scope contract with the archive
     * and the records board. What that does and does not touch is the point of
     * the whole ticket:
     *
     * - **Career figures are match-derived**, so they answer at either scope —
     *   and they include unranked events, which have matches and no points.
     * - **Points are grouped by season and never totalled across them.** Best
     *   14 is a season's cap; applying fourteen to a career would be a number
     *   nobody agreed to, and summing across seasons is what the contract
     *   forbids. So Overall shows one block per season with its own subtotal
     *   and no grand total anywhere on the page.
     */
    #[Route('/player/{slug}', name: 'player_page', methods: ['GET'])]
    public function playerPage(string $slug, Request $request, PlayerRepository $playerRepository, SeasonRepository $seasonRepository, PlayerMergeRedirectRepository $redirects, TournamentStageRepository $stages, TournamentResultRepository $results, PlayerCareerPresenter $careerPresenter): Response
    {
        $player = $playerRepository->findOneBy(['slug' => $slug]);

        if (!$player) {
            /*
             * A merged-away blader keeps their URL. The row is gone, so the
             * redirect table is the only thing that remembers the slug — and
             * the season, which was never part of the blader's identity,
             * travels across as the query parameter it now is.
             */
            $survivor = $redirects->survivorForSlug($slug);

            if (null !== $survivor) {
                return $this->redirectToRoute(
                    'player_page',
                    array_filter(['slug' => $survivor->getSlug(), 'season' => $request->query->getString('season')]),
                    Response::HTTP_MOVED_PERMANENTLY,
                );
            }

            throw $this->createNotFoundException('No blader answers to that name.');
        }

        $scope = $request->query->getString('season');
        $season = null;

        if ('' !== $scope) {
            $season = $seasonRepository->findBySlug($scope);

            if (!$season) {
                throw $this->createNotFoundException('No season answers to that name.');
            }
        }

        $id = (int) $player->getId();

        return $this->render('league/player_details.html.twig', [
            'player' => $player,
            'career' => $careerPresenter->present(
                $player,
                $stages->careerOf($player, $season),
                $results->careerOf($player, $season),
            ),
            /*
             * One shape for both scopes, so the template has one table to
             * render rather than two. A season scope is that season's block on
             * its own; Overall is every season's, each with its own best-14
             * subtotal and no total across them.
             */
            'points' => $this->pointsBySeason($playerRepository->getPlayerContributionsBySeason($id), $season),
            'seasons' => $seasonRepository->ordered(),
            'current_season' => $season,
        ]);
    }

    /**
     * The season-scoped player URLs, kept alive as permanent redirects.
     *
     * The season was never part of a blader's identity, so it moves from the
     * path to the query string rather than being dropped: an old link that
     * named one still opens that season's view.
     */
    #[Route('/season/{slug}/player/{id}', name: 'player_season_details', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[Route('/seasons/{slug}/player/{id}', name: 'player_season_details_2', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[Route('/preseason/player/{id}', name: 'player_season_details_legacy', defaults: ['slug' => 'preseason-1'], requirements: ['id' => '\d+'], methods: ['GET'])]
    public function playerDetails(string $slug, int $id, PlayerRepository $playerRepository, PlayerMergeRedirectRepository $redirects): Response
    {
        $player = $playerRepository->find($id) ?? $redirects->survivorFor($id);

        if (null === $player) {
            throw $this->createNotFoundException('Requested contextual profiles do not exist.');
        }

        return $this->redirectToRoute(
            'player_page',
            ['slug' => $player->getSlug(), 'season' => $slug],
            Response::HTTP_MOVED_PERMANENTLY,
        );
    }

    /**
     * One blader's scoring events, filed under the season each scored in.
     *
     * **No total across seasons, here or anywhere the page can reach.** The
     * subtotal belongs to its season and travels with it, which is also why it
     * is rendered on the same line as the season's heading — a figure orphaned
     * from its season is exactly the cross-season total this forbids.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{slug: string, name: string, total: int, events: list<array<string, mixed>>}>
     */
    private function pointsBySeason(array $rows, ?Season $scope): array
    {
        $blocks = [];

        foreach ($rows as $row) {
            $slug = (string) $row['season_slug'];

            if (null !== $scope && $slug !== $scope->getSlug()) {
                continue;
            }

            $blocks[$slug] ??= [
                'slug' => $slug,
                'name' => (string) $row['season_name'],
                'total' => 0,
                'events' => [],
            ];

            $blocks[$slug]['total'] += (int) $row['total_points'];
            $blocks[$slug]['events'][] = $row;
        }

        return array_values($blocks);
    }

    /**
     * One event, whether or not it belongs to a season.
     *
     * Canonical and season-independent, because an unranked tournament has no
     * season to route through and every previous way into a tournament page
     * went through one. The three season-scoped forms below redirect here and
     * stay valid: both shapes are links people have, and redirecting keeps one
     * page rather than growing a second.
     */
    #[Route('/tournament/{id}', name: 'tournament_page', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function tournamentPage(int $id, TournamentRepository $tournamentRepository, TournamentTeamRepository $teams, TournamentStageRepository $stages, TournamentArchivePresenter $archivePresenter): Response
    {
        $tournament = $tournamentRepository->find($id);

        if (!$tournament) {
            throw $this->createNotFoundException('The requested tournament does not exist.');
        }

        /*
         * Empty for every event but the two 2v2s, where it is what the bracket
         * actually ranked — including the teams nobody has claimed, which have
         * no placement of their own and would otherwise leave no trace on the
         * page at all.
         *
         * The standings are read for both kinds and are simply empty for an
         * unranked event, which has no `TournamentResult` row at all. The
         * template does not render the League points card for one — absent
         * rather than empty or zeroed.
         */
        return $this->render('league/tournament_details.html.twig', [
            'tournament' => $tournament,
            'standings' => $tournament->isRanked() ? $tournamentRepository->getTournamentStandings($id) : [],
            'teams' => $teams->forTournament($tournament),
            'archive' => $archivePresenter->present($stages->forTournament($tournament)),
            'current_season' => $tournament->getSeason(),
        ]);
    }

    /**
     * The season-scoped tournament URLs, kept alive as permanent redirects.
     *
     * The season in the path never identified anything — the id did — so it is
     * dropped rather than validated. A URL naming the wrong season used to
     * 404; it now lands on the event it named, which is what somebody
     * following an old link wanted.
     */
    #[Route('/preseason/tournament/{id}', name: 'tournament_details_legacy', defaults: ['slug' => 'preseason-1'], requirements: ['id' => '\d+'], methods: ['GET'])]
    #[Route('/season/{slug}/tournament/{id}', name: 'tournament_details', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[Route('/seasons/{slug}/tournament/{id}', name: 'tournament_details_2', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function tournamentDetails(int $id): Response
    {
        return $this->redirectToRoute('tournament_page', ['id' => $id], Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * The tournament archive — every event the league has held, filed under
     * the season it scores in.
     *
     * Option 2B, the season shelf. The final group holds the events that score
     * nowhere, headed **Unranked tournaments**. Grouping rather than badging,
     * so the invariant is the page's structure: there is no synthetic "Other"
     * season and nothing here invents one — the last group is exactly the rows
     * whose relation is null.
     *
     * `?season=` narrows to one season, sharing the scope contract with the
     * records board: a season scope holds only the tournaments assigned to that
     * season, and an unknown season is a 404 rather than a quiet fall back to
     * Overall.
     *
     * Without this page an unranked event is unreachable the moment you leave
     * the post-import redirect.
     */
    #[Route('/tournaments', name: 'tournament_archive', methods: ['GET'])]
    public function tournamentArchive(Request $request, SeasonRepository $seasonRepository, TournamentRepository $tournaments, PlayerRepository $players, TournamentShelfPresenter $shelves): Response
    {
        $slug = $request->query->getString('season');
        $season = null;

        if ('' !== $slug) {
            $season = $seasonRepository->findBySlug($slug);

            if (!$season) {
                throw $this->createNotFoundException('No season answers to that name.');
            }
        }

        $seasons = $seasonRepository->ordered();

        return $this->render('league/tournaments.html.twig', [
            'archive' => $shelves->present(
                $tournaments->archiveIndex(),
                $seasons,
                $players->archivedBladerCount(),
                $season,
            ),
            'seasons' => $seasons,
            'current_season' => $season,
        ]);
    }

    /**
     * The records board, over the whole archive or over one season.
     *
     * `/records` is the overall historical record and includes every archived
     * tournament, ranked or not; `/records?season=1` is the same board with
     * the tournament season filtered. The scope is applied to the matches the
     * board is built from and to nothing else, so the record values, the
     * ordering and the eligibility threshold all move together and a career
     * total cannot win a season record.
     *
     * An unknown season is a 404 rather than a quiet fall back to Overall: a
     * page that answers a wrong URL with a different scope's numbers is a page
     * nobody can cite.
     *
     * Nothing links here yet. #94 owns the navigation that will.
     */
    #[Route('/records', name: 'records_board', methods: ['GET'])]
    public function records(Request $request, SeasonRepository $seasonRepository, TournamentStageRepository $stages, LeagueRecordsPresenter $recordsPresenter): Response
    {
        $slug = $request->query->getString('season');
        $season = null;

        if ('' !== $slug) {
            $season = $seasonRepository->findBySlug($slug);
            if (!$season) {
                throw $this->createNotFoundException('No season answers to that name.');
            }
        }

        $seasons = $seasonRepository->ordered();

        /*
         * A blader's profile is reached by their own slug now, so an Overall
         * board links to an Overall profile and a season-scoped one carries
         * its season across as the query parameter. Nothing has to invent a
         * season to link through, which is what this used to do.
         */
        return $this->render('league/records.html.twig', [
            'board' => $recordsPresenter->present($stages->acrossTheLeague($season)),
            'seasons' => $seasons,
            'current_season' => $season,
            'profile_season_slug' => $season?->getSlug(),
        ]);
    }

    #[Route('/registrations', name: 'league_registrations', methods: ['GET'])]
    public function registrations(SeasonRegistrationRepository $registrationRepository): Response
    {
        $payments = $registrationRepository->getAllSeasonalPayments();

        // Group the data by season name for an elegant UI layout presentation
        $groupedPayments = [];
        foreach ($payments as $payment) {
            $groupedPayments[$payment['season_name']][] = $payment;
        }

        return $this->render('league/registrations.html.twig', [
            'grouped_payments' => $groupedPayments,
        ]);
    }

    #[Route('/', name: 'app_league_proposal_v2')]
    #[Route('/v2', name: 'app_league_proposal_v2_alias')]
    public function v2(): Response
    {
        return $this->render('league/proposal-v2.html.twig');
    }

    #[Route('/v1', name: 'app_league_proposal_v1')]
    public function v1(): Response
    {
        return $this->render('league/proposal-v1.html.twig');
    }

    #[Route('/v0', name: 'app_league_proposal_v0')]
    public function v0(): Response
    {
        return $this->render('league/proposal-v0.html.twig');
    }
}
