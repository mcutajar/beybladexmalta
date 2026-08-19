<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ImportTournamentData;
use App\Exception\ImportFileWriteException;
use App\Exception\LedgerWriteException;
use App\Form\ImportTournamentType;
use App\Service\PlacementListParser;
use App\Service\TournamentImportResult;
use App\Service\TournamentImportService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminTournamentImportController extends AbstractController
{
    /**
     * The web form mirrors the F1 points table, which only scores a top ten.
     */
    private const int REQUIRED_PLACEMENT_COUNT = 10;

    public function __construct(
        private readonly TournamentImportService $importService,
        private readonly PlacementListParser $placementListParser,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        '/admin/import',
        name: 'admin_tournament_import',
        methods: ['GET', 'POST'],
    )]
    public function import(
        Request $request,
        #[Autowire(env: 'TOURNAMENTS_ADMIN_PASSPHRASE')]
        string $adminPassphrase,
    ): Response {
        $form = $this->createForm(ImportTournamentType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('admin/import_tournament.html.twig', [
                'import_form' => $form,
            ]);
        }

        /** @var ImportTournamentData $data */
        $data = $form->getData();

        if (!hash_equals($adminPassphrase, $data->passphrase)) {
            $this->logger->warning(
                'Tournament web import failed: Unauthorized passphrase input.',
            );

            $this->addFlash(
                'error',
                'Authentication failed. The administrative passphrase is incorrect.',
            );

            return $this->redirectToRoute('admin_tournament_import');
        }

        $seasonSlug = $data->season?->getSlug() ?? '';
        $placements = $this->placementListParser->parse($data->playerList);
        $placementCount = count($placements);

        if (self::REQUIRED_PLACEMENT_COUNT !== $placementCount) {
            $this->logger->warning(
                'Import rejected: List must contain exactly 10 players.',
                [
                    'count_provided' => $placementCount,
                ],
            );

            $this->addFlash(
                'error',
                sprintf(
                    'Validation Error: The player list must contain exactly 10 players matching the F1 points system structure. You provided %d.',
                    $placementCount,
                ),
            );

            return $this->redirectToRoute('admin_tournament_import');
        }

        try {
            $result = $this->importService->import(
                title: $data->title,
                heldOn: $data->date,
                seasonSlug: $seasonSlug,
                placements: $placements,
                challongeUrl: $data->challongeUrl,
                knockoutWinner: $data->knockoutWinner,
            );

            $this->addResultFlash($result, $data->title);
        } catch (LedgerWriteException|ImportFileWriteException $exception) {
            $this->logger->critical(
                'Tournament import cancelled: recovery artifact write failed',
                [
                    'exception' => $exception,
                    'title' => $data->title,
                    'season' => $seasonSlug,
                ],
            );

            $this->addFlash(
                'error',
                'Critical failure: Failed to write the recovery artifacts, import cancelled.',
            );
        } catch (\Throwable $exception) {
            $this->logger->critical(
                'Tournament web import failed.',
                [
                    'exception' => $exception,
                    'title' => $data->title,
                    'season' => $seasonSlug,
                ],
            );

            $this->addFlash(
                'error',
                'Import aborted: '.$exception->getMessage(),
            );
        }

        return $this->redirectToRoute('admin_tournament_import');
    }

    private function addResultFlash(
        TournamentImportResult $result,
        string $title,
    ): void {
        [$type, $message] = match ($result) {
            TournamentImportResult::Imported => [
                'success',
                sprintf(
                    'Successfully imported "%s" with %d player ranks.',
                    $title,
                    self::REQUIRED_PLACEMENT_COUNT,
                ),
            ],
            TournamentImportResult::InvalidDate => [
                'error',
                'Validation Error: Please use the strict format structure YYYY-MM-DD for the date field.',
            ],
            TournamentImportResult::SeasonNotFound => [
                'error',
                'Target season context variant not found.',
            ],
            TournamentImportResult::NoPlacements => [
                'error',
                'Validation Error: The player list cannot be empty.',
            ],
        };

        $this->addFlash($type, $message);
    }
}
