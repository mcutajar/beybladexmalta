<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LeagueController extends AbstractController
{
    #[Route('/', name: 'app_league_proposal')]
    public function index(): Response
    {
        return $this->render('league/proposal.html.twig');
    }
}
