<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LeagueController extends AbstractController
{
    #[Route(['/', '/v1'], name: 'app_league_proposal_v1')]
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
