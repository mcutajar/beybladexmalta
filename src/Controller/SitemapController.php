<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SitemapBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The XML sitemap.
 *
 * A route rather than a file in `public/`, because the league gains
 * tournaments and bladers twice a week and a checked-in file would be stale by
 * Wednesday. Caddy only rewrites a request to `index.php` when the path is not
 * a real file, so this answers precisely because `public/sitemap.xml` does not
 * exist — do not add one.
 *
 * `SitemapBuilder` decides what belongs in it; this hands back what it built.
 */
final class SitemapController extends AbstractController
{
    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function sitemap(SitemapBuilder $sitemap): Response
    {
        $response = $this->render('sitemap.xml.twig', ['urls' => $sitemap->build()]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $response;
    }
}
