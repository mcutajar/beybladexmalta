<?php

declare(strict_types=1);

namespace App\Controller;

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
use App\Service\TournamentArchivePresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LeagueController extends AbstractController
{
    #[Route('/season/{slug}', name: 'season_leaderboard', defaults: ['slug' => 'preseason-1'], methods: ['GET'])]
    #[Route('/seasons/{slug}', name: 'season_leaderboard_2', defaults: ['slug' => 'preseason-1'], methods: ['GET'])]
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

    #[Route('/season/{slug}/player/{id}', name: 'player_season_details', methods: ['GET'])]
    #[Route('/seasons/{slug}/player/{id}', name: 'player_season_details_2', methods: ['GET'])]
    #[Route('/preseason/player/{id}', name: 'player_season_details_legacy', defaults: ['slug' => 'preseason-1'], methods: ['GET'])]
    public function playerDetails(string $slug, int $id, PlayerRepository $playerRepository, SeasonRepository $seasonRepository, PlayerMergeRedirectRepository $redirects, TournamentStageRepository $stages, TournamentResultRepository $results, PlayerCareerPresenter $careerPresenter): Response
    {
        $season = $seasonRepository->findOneBy(['slug' => $slug]);
        $player = $playerRepository->find($id);

        if (!$season) {
            throw $this->createNotFoundException('Requested contextual profiles do not exist.');
        }

        if (!$player) {
            $survivor = $redirects->survivorFor($id);
            if (null !== $survivor && null !== $survivor->getId()) {
                return $this->redirectToRoute('player_season_details', ['slug' => $slug, 'id' => $survivor->getId()], Response::HTTP_MOVED_PERMANENTLY);
            }

            throw $this->createNotFoundException('Requested contextual profiles do not exist.');
        }

        $contributions = $playerRepository->getPlayerContributingTournaments($id, $slug);

        /*
         * The career reads every season and the points table below it reads
         * the one in the URL. That is deliberate rather than an oversight: a
         * blader's record is not season-scoped and 35 of them have played in
         * both, so the page states which season each event belonged to instead
         * of hiding half of somebody's matches behind the route.
         */
        return $this->render('league/player_details.html.twig', [
            'player' => $player,
            'contributions' => $contributions,
            'career' => $careerPresenter->present(
                $player,
                $stages->careerOf($player),
                $results->careerOf($player),
            ),
            'current_season' => $season,
        ]);
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
         * A blader's profile still lives under a season, so an Overall board
         * links through the newest one. #95 replaces this with the canonical
         * `/player/{slug}` and the argument disappears; until then the link has
         * to name some season and the current one is the least wrong.
         */
        return $this->render('league/records.html.twig', [
            'board' => $recordsPresenter->present($stages->acrossTheLeague($season)),
            'seasons' => $seasons,
            'current_season' => $season,
            'profile_season_slug' => ($season ?? ([] === $seasons ? null : $seasons[count($seasons) - 1]))?->getSlug(),
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
