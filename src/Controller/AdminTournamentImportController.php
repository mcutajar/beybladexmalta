<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ImportTournamentData;
use App\Exception\ImportFileWriteException;
use App\Exception\LedgerWriteException;
use App\Form\BracketImportType;
use App\Form\ImportTournamentType;
use App\Service\AdminPassphraseVerifier;
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
     * One name is a tournament; nought is a mistake.
     *
     * This used to insist on exactly ten, on the grounds that the F1 matrix
     * scores a top ten — but the matrix pays nothing below tenth rather than
     * refusing to be asked, and the league has already held a seven-entrant
     * round robin that this rule would have rejected. A short list scores
     * every place it has and stops.
     */
    private const int FEWEST_PLACEMENTS = 1;

    public function __construct(
        private readonly TournamentImportService $importService,
        private readonly PlacementListParser $placementListParser,
        private readonly AdminPassphraseVerifier $passphrases,
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
                'bracket_form' => $this->createForm(BracketImportType::class),
            ]);
        }

        /** @var ImportTournamentData $data */
        $data = $form->getData();

        if (!$this->passphrases->matches($adminPassphrase, $data->passphrase)) {
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

        if ($placementCount < self::FEWEST_PLACEMENTS) {
            $this->logger->warning(
                'Import rejected: the placement list names nobody.',
                [
                    'count_provided' => $placementCount,
                ],
            );

            $this->addFlash(
                'error',
                'Validation Error: The player list must name at least one blader, in finishing order.',
            );

            return $this->redirectToRoute('admin_tournament_import');
        }

        try {
            $outcome = $this->importService->importWithTournament(
                title: $data->title,
                heldOn: $data->date,
                seasonSlug: $seasonSlug,
                placements: $placements,
                challongeUrl: $data->challongeUrl,
                knockoutWinner: $data->knockoutWinner,
            );

            $this->addResultFlash($outcome->result, $data->title, $placementCount);

            if (TournamentImportResult::Imported === $outcome->result && null !== $outcome->tournament) {
                return $this->redirectToRoute('tournament_details', [
                    'slug' => $outcome->tournament->getSeason()->getSlug(),
                    'id' => $outcome->tournament->getId(),
                ]);
            }
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
        int $placementCount,
    ): void {
        [$type, $message] = match ($result) {
            TournamentImportResult::Imported => [
                'success',
                sprintf(
                    'Successfully imported "%s" with %d player ranks.',
                    $title,
                    $placementCount,
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
