<?php

namespace App\Controller;

use App\Repository\PlayerRepository;
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
