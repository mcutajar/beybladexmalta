<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeUrl;
use App\Exception\ChallongeFetchException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Captures a Challonge bracket, once, over the network.
 *
 * This is the only class in the pipeline that is allowed to touch the
 * network. Everything downstream reads the snapshot it produces.
 */
class ChallongeFetcher
{
    /**
     * Challonge bounces an anonymous client, so say who we are and leave a
     * way to reach us. There is no vanity in this — it is the difference
     * between a 200 and a 403.
     */
    private const USER_AGENT = 'MaltaBeybladeLeague/1.0 (+https://github.com/mcutajar/beybladexmalta)';

    private const TIMEOUT_SECONDS = 15.0;

    private const MAX_DURATION_SECONDS = 30.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ChallongeModuleParser $parser,
        private ChallongeStoreNormaliser $normaliser,
    ) {
    }

    public function fetch(ChallongeUrl $url): ChallongeSnapshot
    {
        $moduleUrl = $url->moduleUrl();

        try {
            $response = $this->httpClient->request('GET', $moduleUrl, [
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'text/html,application/xhtml+xml',
                ],
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
            ]);

            $statusCode = $response->getStatusCode();
            // Read the body ourselves rather than letting a 4xx throw, so the
            // message below can name the status Challonge actually gave.
            $html = $response->getContent(throw: false);
        } catch (TransportExceptionInterface $exception) {
            throw new ChallongeFetchException(sprintf('Could not reach %s: %s', $moduleUrl, $exception->getMessage()), previous: $exception);
        }

        if (200 !== $statusCode) {
            throw new ChallongeFetchException(sprintf('%s answered %d, expected 200.', $moduleUrl, $statusCode));
        }

        return $this->normaliser->normalise(
            store: $this->parser->readStore($html),
            bodyScorecardHtml: $this->parser->readScorecard($html),
            url: $url,
            fetchedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }
}
