<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\PerformanceBladeLane;
use App\Dto\PerformanceMatchData;
use App\Dto\PerformanceResultValue;
use App\Dto\PerformanceTrackerData;
use App\Dto\ScoreEntryData;
use App\Entity\PerformanceMatch;
use App\Entity\PerformanceTracker;
use App\Form\PerformanceMatchType;
use App\Form\PerformanceTrackerType;
use App\Form\ScoreEntryType;
use App\Repository\PerformanceTrackerRepository;
use App\Service\PerformanceTrackerCsvExporter;
use App\Service\PerformanceTrackerPresenter;
use App\Service\PerformanceTrackerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PerformanceTrackerController extends AbstractController
{
    public function __construct(
        private PerformanceTrackerRepository $trackers,
        private PerformanceTrackerService $trackerService,
        private PerformanceTrackerPresenter $presenter,
        private PerformanceTrackerCsvExporter $csvExporter,
        private FormFactoryInterface $forms,
    ) {
    }

    #[Route('/tracker', name: 'performance_tracker_setup', methods: ['GET', 'POST'])]
    public function setup(Request $request): Response
    {
        $form = $this->createForm(PerformanceTrackerType::class, new PerformanceTrackerData());
        $form->handleRequest($request);
        $error = null;

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                /** @var PerformanceTrackerData $data */
                $data = $form->getData();
                $created = $this->trackerService->create($data);

                return $this->redirectToRoute('performance_tracker_edit', ['token' => $created->editToken]);
            } catch (\DomainException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->render('tracker/setup.html.twig', [
            'tracker_form' => $form,
            'error' => $error,
        ]);
    }

    #[Route('/tracker/edit/{token}', name: 'performance_tracker_edit', methods: ['GET', 'POST'], requirements: ['token' => '[A-Za-z0-9_-]{40,}'])]
    public function edit(Request $request, string $token): Response
    {
        $tracker = $this->owned($token);
        $settings = $this->createForm(PerformanceTrackerType::class, PerformanceTrackerData::fromTracker($tracker), [
            'action' => $this->generateUrl('performance_tracker_edit', ['token' => $token]),
            'method' => 'POST',
        ]);
        $settings->handleRequest($request);

        if ($settings->isSubmitted() && $settings->isValid()) {
            try {
                /** @var PerformanceTrackerData $data */
                $data = $settings->getData();
                $this->trackerService->update($tracker, $data);
                $this->addFlash('success', 'Tracker setup updated. Existing results were kept.');

                return $this->redirectToRoute('performance_tracker_edit', ['token' => $token]);
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->renderEdit($request, $tracker, $token, $settings);
    }

    #[Route('/tracker/edit/{token}/match/{sequence}/blade/{lane}', name: 'performance_tracker_score', methods: ['POST'], requirements: ['sequence' => '\\d+', 'lane' => 'A|B|C'])]
    public function score(Request $request, string $token, int $sequence, string $lane): Response
    {
        $tracker = $this->owned($token);
        $bladeLane = PerformanceBladeLane::from($lane);
        $result = ($tracker->match($sequence) ?? throw $this->createNotFoundException())->resultFor($tracker->blade($bladeLane));
        $data = new ScoreEntryData($result->getValue()->value);
        $form = $this->scoreForm($token, $sequence, $bladeLane, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['message' => 'Choose one of the listed results.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $this->addFlash('error', 'Choose one of the listed results.');

            return $this->redirectToRoute('performance_tracker_edit', ['token' => $token, 'match' => $sequence]);
        }

        /** @var ScoreEntryData $entry */
        $entry = $form->getData();
        $value = PerformanceResultValue::from($entry->result);
        $update = $this->trackerService->record($tracker, $sequence, $bladeLane, $value);
        $summary = $this->presenter->summarise($tracker);
        $blade = $summary->forLane($bladeLane);
        $announcement = sprintf('%s, match %d, saved as %s. New total %s.', $blade->blade->getDisplayName(), $sequence, $value->label(), self::signed($blade->total));

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'message' => $announcement,
                'value' => $value->value,
                'label' => $value->label(),
                'lane' => $lane,
                'match' => $sequence,
                'bladeTotal' => self::signed($blade->total),
                'bladeAverage' => self::average($blade->average()),
                'bladeAppearances' => $blade->appearances,
                'bladeBalance' => sprintf('%d · %d · %d', $blade->positive, $blade->negative, $blade->zero),
                'bladeUnused' => $blade->unused,
                'bladeBest' => null === $blade->best ? '—' : self::signed($blade->best),
                'overallTotal' => self::signed($summary->total),
                'overallAverage' => self::average($summary->average()),
                'reload' => $update->stationChanged,
                'redirect' => $this->generateUrl('performance_tracker_edit', ['token' => $token]),
            ]);
        }

        $this->addFlash('success', $announcement);

        $routeParameters = ['token' => $token];
        if (!$update->stationChanged) {
            $routeParameters['match'] = $sequence;
        }

        return $this->redirectToRoute('performance_tracker_edit', $routeParameters);
    }

    #[Route('/tracker/edit/{token}/match/{sequence}', name: 'performance_tracker_match_update', methods: ['POST'], requirements: ['sequence' => '\\d+'])]
    public function updateMatch(Request $request, string $token, int $sequence): Response
    {
        $tracker = $this->owned($token);
        $match = $tracker->match($sequence) ?? throw $this->createNotFoundException();
        $form = $this->matchForm($token, $match);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var PerformanceMatchData $data */
            $data = $form->getData();
            $this->trackerService->updateMatch($tracker, $sequence, $data);
            $this->addFlash('success', sprintf('Match %d details saved.', $sequence));
        } else {
            $this->addFlash('error', 'The match details could not be saved.');
        }

        return $this->redirectToRoute('performance_tracker_edit', ['token' => $token, 'match' => $sequence]);
    }

    #[Route('/tracker/edit/{token}/matches', name: 'performance_tracker_match_add', methods: ['POST'])]
    public function addMatch(Request $request, string $token): Response
    {
        $tracker = $this->owned($token);
        if (!$this->isCsrfTokenValid('add-performance-match', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $match = $this->trackerService->addMatch($tracker);

        return $this->redirectToRoute('performance_tracker_edit', ['token' => $token, 'match' => $match->getSequence()]);
    }

    #[Route('/tracker/edit/{token}/match/{sequence}/remove', name: 'performance_tracker_match_remove', methods: ['POST'], requirements: ['sequence' => '\\d+'])]
    public function removeMatch(Request $request, string $token, int $sequence): Response
    {
        $tracker = $this->owned($token);
        if (!$this->isCsrfTokenValid('remove-performance-match-'.$sequence, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->trackerService->removeMatch($tracker, $sequence);
            $this->addFlash('success', sprintf('Match %d removed. Other match numbers were kept.', $sequence));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('performance_tracker_edit', ['token' => $token]);
    }

    #[Route('/tracker/edit/{token}/reset', name: 'performance_tracker_reset', methods: ['GET', 'POST'])]
    public function reset(Request $request, string $token): Response
    {
        $tracker = $this->owned($token);
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('reset-performance-tracker', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $this->trackerService->reset($tracker);
            $this->addFlash('success', 'Every match result was reset. Your tracker setup was kept.');

            return $this->redirectToRoute('performance_tracker_edit', ['token' => $token]);
        }

        return $this->render('tracker/reset.html.twig', ['tracker' => $tracker, 'edit_token' => $token]);
    }

    #[Route('/tracker/edit/{token}/duplicate', name: 'performance_tracker_duplicate', methods: ['POST'])]
    public function duplicate(Request $request, string $token): Response
    {
        $tracker = $this->owned($token);
        if (!$this->isCsrfTokenValid('duplicate-performance-tracker', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $copy = $this->trackerService->duplicate($tracker);
        $this->addFlash('success', 'Fresh tracker created. No match results were copied.');

        return $this->redirectToRoute('performance_tracker_edit', ['token' => $copy->editToken]);
    }

    #[Route('/tracker/edit/{token}/export.csv', name: 'performance_tracker_export', methods: ['GET'])]
    public function export(string $token): Response
    {
        return $this->csv($this->owned($token));
    }

    #[Route('/tracker/share/{shareId}', name: 'performance_tracker_share', methods: ['GET'], requirements: ['shareId' => '[A-Za-z0-9_-]{24,}'])]
    public function share(string $shareId): Response
    {
        $tracker = $this->shared($shareId);

        return $this->render('tracker/share.html.twig', [
            'tracker' => $tracker,
            'summary' => $this->presenter->summarise($tracker),
        ]);
    }

    #[Route('/tracker/share/{shareId}/export.csv', name: 'performance_tracker_share_export', methods: ['GET'], requirements: ['shareId' => '[A-Za-z0-9_-]{24,}'])]
    public function shareExport(string $shareId): Response
    {
        return $this->csv($this->shared($shareId));
    }

    /** @param FormInterface<PerformanceTrackerData> $settings */
    private function renderEdit(Request $request, PerformanceTracker $tracker, string $token, FormInterface $settings): Response
    {
        $selected = $this->selectedMatch($request, $tracker);
        $scoreForms = [];
        foreach ($tracker->getBlades() as $blade) {
            $value = $selected->resultFor($blade)->getValue();
            $scoreForms[$blade->getLane()->value] = $this->scoreForm(
                $token,
                $selected->getSequence(),
                $blade->getLane(),
                new ScoreEntryData($value->value),
            )->createView();
        }

        return $this->render('tracker/edit.html.twig', [
            'tracker' => $tracker,
            'summary' => $this->presenter->summarise($tracker),
            'selected_match' => $selected,
            'score_forms' => $scoreForms,
            'match_form' => $this->matchForm($token, $selected)->createView(),
            'settings_form' => $settings->createView(),
            'edit_token' => $token,
        ]);
    }

    /** @return FormInterface<ScoreEntryData> */
    private function scoreForm(string $token, int $sequence, PerformanceBladeLane $lane, ScoreEntryData $data): FormInterface
    {
        return $this->forms->createNamed(
            sprintf('score_%d_%s', $sequence, $lane->value),
            ScoreEntryType::class,
            $data,
            [
                'action' => $this->generateUrl('performance_tracker_score', ['token' => $token, 'sequence' => $sequence, 'lane' => $lane->value]),
                'method' => 'POST',
            ],
        );
    }

    /** @return FormInterface<PerformanceMatchData> */
    private function matchForm(string $token, PerformanceMatch $match): FormInterface
    {
        return $this->forms->createNamed(
            'match_metadata_'.$match->getSequence(),
            PerformanceMatchType::class,
            PerformanceMatchData::fromMatch($match),
            [
                'action' => $this->generateUrl('performance_tracker_match_update', ['token' => $token, 'sequence' => $match->getSequence()]),
                'method' => 'POST',
            ],
        );
    }

    private function selectedMatch(Request $request, PerformanceTracker $tracker): PerformanceMatch
    {
        $requested = $request->query->getInt('match');
        if (0 < $requested && null !== ($match = $tracker->match($requested))) {
            return $match;
        }

        $matches = $tracker->getMatches()->toArray();
        for ($index = count($matches) - 1; 0 <= $index; --$index) {
            if (!$matches[$index]->isComplete()) {
                return $matches[$index];
            }
        }

        return $matches[array_key_last($matches)];
    }

    private function owned(string $token): PerformanceTracker
    {
        return $this->trackers->findByEditToken($token) ?? throw $this->createNotFoundException('Tracker not found.');
    }

    private function shared(string $shareId): PerformanceTracker
    {
        return $this->trackers->findByShareId($shareId) ?? throw $this->createNotFoundException('Shared tracker not found.');
    }

    private function csv(PerformanceTracker $tracker): Response
    {
        return new Response($this->csvExporter->export($tracker), Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="performance-tracker.csv"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private static function signed(int $value): string
    {
        return 0 < $value ? '+'.$value : (string) $value;
    }

    private static function average(?float $average): string
    {
        return null === $average ? '—' : number_format($average, 2);
    }
}
