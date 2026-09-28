<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\PerformanceBladeData;
use App\Dto\PerformanceBladeLane;
use App\Dto\PerformanceResultValue;
use App\Dto\PerformanceTrackerData;
use App\Repository\PerformanceTrackerRepository;
use App\Service\PerformanceTrackerService;
use App\Tests\Support\PageTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PerformanceTrackerControllerTest extends PageTestCase
{
    use ResetDatabase;

    public function testAPlayerCanCreateAndScoreATrackerWithoutJavaScript(): void
    {
        $browser = $this->createBrowser();
        $page = $browser->request('GET', '/tracker');
        $form = $page->selectButton('Create tracker')->form([
            'performance_tracker[tournamentName]' => 'Gamesplus weekly',
            'performance_tracker[heldOn]' => '2026-09-27',
            'performance_tracker[playerName]' => 'Derius',
            'performance_tracker[location]' => 'Mosta',
            'performance_tracker[expectedMatches]' => '2',
            'performance_tracker[blades][0][displayName]' => 'Phoenix Wing',
            'performance_tracker[blades][0][blade]' => 'Phoenix Wing',
            'performance_tracker[blades][0][ratchet]' => '5-60',
            'performance_tracker[blades][0][bit]' => 'Point',
            'performance_tracker[blades][0][colour]' => 'cyan',
            'performance_tracker[blades][1][displayName]' => 'Wizard Rod',
            'performance_tracker[blades][1][blade]' => 'Wizard Rod',
            'performance_tracker[blades][1][ratchet]' => '9-60',
            'performance_tracker[blades][1][bit]' => 'Ball',
            'performance_tracker[blades][1][colour]' => 'amber',
            'performance_tracker[blades][2][displayName]' => 'Shark Edge',
            'performance_tracker[blades][2][blade]' => 'Shark Edge',
            'performance_tracker[blades][2][ratchet]' => '3-60',
            'performance_tracker[blades][2][bit]' => 'Low Flat',
            'performance_tracker[blades][2][colour]' => 'red',
        ]);
        $browser->submit($form);

        self::assertResponseRedirects();
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/tracker/edit/[A-Za-z0-9_-]{40,}$#', $location);

        $page = $browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Match 1', $page->text());
        self::assertCount(3, $page->filter('form[data-score-form]'));
        self::assertCount(27, $page->filter('form[data-score-form] input[type="radio"]'));
        self::assertCount(27, $page->filter('form[data-score-form] input[type="radio"][aria-label]'));
        self::assertCount(27, $page->filter('form[data-score-form] label.min-h-11'));

        $score = $page->filter('form[data-score-form][data-lane="A"]')->form([
            'score_1_A[result]' => '+3',
        ]);
        $browser->submit($score);
        self::assertResponseRedirects();

        $page = $browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Phoenix Wing, match 1, saved as +3', $page->text());

        $token = basename(parse_url($location, PHP_URL_PATH));
        $tracker = $this->repository()->findByEditToken($token);
        self::assertNotNull($tracker);
        self::assertSame(PerformanceResultValue::PlusThree, $tracker->match(1)?->resultFor($tracker->blade(PerformanceBladeLane::A))->getValue());

        $score = $page->filter('form[data-score-form][data-lane="B"]')->form([
            'score_1_B[result]' => 'unused',
        ]);
        $browser->submit($score);
        $page = $browser->followRedirect();

        $score = $page->filter('form[data-score-form][data-lane="C"]')->form([
            'score_1_C[result]' => '0',
        ]);
        $browser->submit($score);
        self::assertResponseRedirects();
        self::assertStringNotContainsString('?match=', (string) $browser->getResponse()->headers->get('Location'));

        $page = $browser->followRedirect();
        self::assertStringContainsString('Match 2', $page->filter('#match-station')->text());
    }

    public function testTheSharePageCannotMutateAndNeverLeaksTheEditCapability(): void
    {
        [$tracker, $token] = $this->createTracker();
        $browser = $this->createBrowser();
        $page = $browser->request('GET', '/tracker/share/'.$tracker->getShareId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('form'));
        self::assertStringNotContainsString($token, (string) $browser->getResponse()->getContent());
        self::assertSame('noindex, nofollow', $page->filter('meta[name="robots"]')->attr('content'));
        self::assertStringEndsWith('/tracker/share/'.$tracker->getShareId(), (string) $page->filter('link[rel="canonical"]')->attr('href'));

        $browser->request('POST', '/tracker/share/'.$tracker->getShareId());
        self::assertResponseStatusCodeSame(405);
    }

    public function testPrivateMetadataContainsNoEditCapability(): void
    {
        [, $token] = $this->createTracker();
        $page = $this->createBrowser()->request('GET', '/tracker/edit/'.$token);

        self::assertResponseIsSuccessful();
        self::assertSame('noindex, nofollow', $page->filter('meta[name="robots"]')->attr('content'));
        self::assertStringEndsWith('/tracker', (string) $page->filter('link[rel="canonical"]')->attr('href'));
        self::assertStringNotContainsString($token, (string) $page->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('no-referrer', $page->filter('meta[name="referrer"]')->attr('content'));
    }

    public function testCsvRepresentsUnusedAndNotRecordedDifferently(): void
    {
        [$tracker, $token] = $this->createTracker();
        $this->trackerService()->record($tracker, 1, PerformanceBladeLane::A, PerformanceResultValue::Unused);
        $browser = $this->createBrowser();
        $browser->request('GET', '/tracker/edit/'.$token.'/export.csv');

        self::assertResponseIsSuccessful();
        self::assertSame('text/csv; charset=UTF-8', $browser->getResponse()->headers->get('Content-Type'));
        $csv = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('unused', $csv);
        self::assertStringContainsString('not recorded', $csv);
    }

    public function testResetNeedsASeparateConfirmationPage(): void
    {
        [$tracker, $token] = $this->createTracker();
        $this->trackerService()->record($tracker, 1, PerformanceBladeLane::A, PerformanceResultValue::PlusThree);
        $browser = $this->createBrowser();

        $page = $browser->request('GET', '/tracker/edit/'.$token.'/reset');
        self::assertResponseIsSuccessful();
        self::assertSame(PerformanceResultValue::PlusThree, $tracker->match(1)?->resultFor($tracker->blade(PerformanceBladeLane::A))->getValue());

        $browser->submit($page->selectButton('Yes, reset all match results')->form());
        self::assertResponseRedirects('/tracker/edit/'.$token);

        $reloaded = $this->repository()->findByEditToken($token);
        self::assertSame(PerformanceResultValue::NotRecorded, $reloaded?->match(1)?->resultFor($reloaded->blade(PerformanceBladeLane::A))->getValue());
    }

    public function testUnknownEditAndShareCapabilitiesAreNotFound(): void
    {
        $browser = $this->createBrowser();
        $browser->request('GET', '/tracker/edit/'.str_repeat('x', 43));
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/tracker/share/'.str_repeat('y', 32));
        self::assertResponseStatusCodeSame(404);
    }

    public function testAForgedScoreOutsideTheVocabularyIsRejected(): void
    {
        [$tracker, $token] = $this->createTracker();
        $browser = $this->createBrowser();
        $page = $browser->request('GET', '/tracker/edit/'.$token);
        $form = $page->filter('form[data-score-form][data-lane="A"]');
        $csrf = (string) $form->filter('input[name="score_1_A[_token]"]')->attr('value');

        $browser->request('POST', '/tracker/edit/'.$token.'/match/1/blade/A', [
            'score_1_A' => ['_token' => $csrf, 'result' => '99'],
        ]);

        self::assertResponseRedirects();
        self::assertSame(PerformanceResultValue::NotRecorded, $tracker->match(1)?->resultFor($tracker->blade(PerformanceBladeLane::A))->getValue());
    }

    /** @return array{\App\Entity\PerformanceTracker, string} */
    private function createTracker(): array
    {
        $created = $this->trackerService()->create(new PerformanceTrackerData(
            'Gamesplus 16-08',
            new \DateTimeImmutable('2026-08-16'),
            'Derius',
            'Gamesplus',
            3,
            [
                new PerformanceBladeData('Phoenix Wing', 'Phoenix Wing', '5-60', 'Point', 'cyan'),
                new PerformanceBladeData('Wizard Rod', 'Wizard Rod', '9-60', 'Ball', 'amber'),
                new PerformanceBladeData('Shark Edge', 'Shark Edge', '3-60', 'Low Flat', 'red'),
            ],
        ));

        return [$created->tracker, $created->editToken];
    }

    private function trackerService(): PerformanceTrackerService
    {
        return self::getContainer()->get(PerformanceTrackerService::class);
    }

    private function repository(): PerformanceTrackerRepository
    {
        return self::getContainer()->get(PerformanceTrackerRepository::class);
    }
}
