<?php

namespace App\Controller;

use App\Repository\PlayerRepository;
use App\Repository\SeasonRegistrationRepository;
use App\Repository\SeasonRepository;
use App\Repository\TournamentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LeagueController extends AbstractController
{
    #[Route('/season/{slug}', name: 'season_leaderboard', defaults: ['slug' => 'preseason-1'], methods: ['GET'])]
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
            'current_season'   => $season,
        ]);
    }

    #[Route('/season/{slug}/player/{id}', name: 'player_season_details', methods: ['GET'])]
    #[Route('/preseason/player/{id}', name: 'player_season_details_legacy', defaults: ['slug' => 'preseason-1'], methods: ['GET'])]
    public function playerDetails(string $slug, int $id, PlayerRepository $playerRepository, SeasonRepository $seasonRepository): Response
    {
        $season = $seasonRepository->findOneBy(['slug' => $slug]);
        $player = $playerRepository->find($id);

        if (!$season || !$player) {
            throw $this->createNotFoundException('Requested contextual profiles do not exist.');
        }

        $contributions = $playerRepository->getPlayerContributingTournaments($id, $slug);

        return $this->render('league/player_details.html.twig', [
            'player'        => $player,
            'contributions' => $contributions,
            'current_season'=> $season,
        ]);
    }

    #[Route('/preseason/tournament/{id}', name: 'tournament_details_legacy', defaults: ['slug' => 'preseason-1'], methods: ['GET'])]
    #[Route('/season/{slug}/tournament/{id}', name: 'tournament_details', methods: ['GET'])]
    public function tournamentDetails(string $slug, int $id, TournamentRepository $tournamentRepository, SeasonRepository $seasonRepository): Response
    {
        // 1. Fetch and validate the active season context
        $season = $seasonRepository->findOneBy(['slug' => $slug]);
        if (!$season) {
            throw $this->createNotFoundException('Season context not found.');
        }

        // 2. Fetch the tournament metadata
        $tournament = $tournamentRepository->find($id);
        if (!$tournament || $tournament->getSeason() !== $season) {
            throw $this->createNotFoundException('The requested tournament does not exist under this season.');
        }

        // 3. Fetch all placement results for this specific tournament
        $standings = $tournamentRepository->getTournamentStandings($id);

        return $this->render('league/tournament_details.html.twig', [
            'tournament'     => $tournament,
            'standings'      => $standings,
            'current_season' => $season,
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

    #[Route(['/', '/v2'], name: 'app_league_proposal_v2')]
    public function v2(): Response
    {
        return $this->render('league/proposal-v2.html.twig');
    }

    #[Route(['/v1'], name: 'app_league_proposal_v1')]
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
