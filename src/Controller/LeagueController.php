<?php

namespace App\Controller;

use App\Repository\PlayerRepository;
use App\Repository\TournamentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LeagueController extends AbstractController
{
    #[Route('/preseason', name: 'preseason')]
    public function preseason(PlayerRepository $playerRepository): Response
    {
        // Fetches your calculated real-time top-14 leaderboard array
        $leaderboardData = $playerRepository->getLeagueLeaderboard();

        // Inject rank indices programmatically for Twig loop alignment
        foreach ($leaderboardData as $index => &$row) {
            $row['rank'] = $index + 1;
        }

        return $this->render('league/preseason.html.twig', [
            'leaderboard_data' => $leaderboardData,
        ]);
    }

    #[Route('/preseason/player/{id}', name: 'player_preseason_details', methods: ['GET'])]
    public function playerDetails(int $id, PlayerRepository $playerRepository): Response
    {
        // 1. Fetch player metadata or 404 if not found
        $player = $playerRepository->find($id);
        if (!$player) {
            throw $this->createNotFoundException('The requested blader could not be found.');
        }

        // 2. Fetch the raw or top-14 contributing tournament breakdown
        $contributions = $playerRepository->getPlayerContributingTournaments($id);

        return $this->render('league/player_details.html.twig', [
            'player' => $player,
            'contributions' => $contributions,
        ]);
    }

    #[Route('/preseason/tournament/{id}', name: 'tournament_details', methods: ['GET'])]
    public function tournamentDetails(int $id, TournamentRepository $tournamentRepository): Response
    {
        // 1. Fetch the tournament metadata
        $tournament = $tournamentRepository->find($id);
        if (!$tournament) {
            throw $this->createNotFoundException('The requested tournament could not be found.');
        }

        // 2. Fetch all placement results for this specific tournament
        $standings = $tournamentRepository->getTournamentStandings($id);

        return $this->render('league/tournament_details.html.twig', [
            'tournament' => $tournament,
            'standings' => $standings,
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
