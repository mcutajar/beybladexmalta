<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\RegisterPaymentData;
use App\Exception\LedgerWriteException;
use App\Form\RegisterPaymentType;
use App\Service\PlayerRegistrationService;
use App\Service\RegisterSeasonPaymentResult;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LeagueRegistrationController extends AbstractController
{
    public function __construct(
        private readonly PlayerRegistrationService $registrationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        '/admin/payments',
        name: 'admin_register_payment',
        methods: ['GET', 'POST'],
    )]
    public function registerPayment(
        Request $request,
        #[Autowire(env: 'PAYMENTS_ADMIN_PASSPHRASE')]
        string $adminPassphrase,
    ): Response {
        $form = $this->createForm(RegisterPaymentType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('admin/register_payment.html.twig', [
                'registration_form' => $form,
            ]);
        }

        /** @var RegisterPaymentData $data */
        $data = $form->getData();

        if (!hash_equals($adminPassphrase, $data->passphrase)) {
            $this->logger->warning(
                'Payment registration authentication failed',
                [
                    'playerName' => $data->playerName,
                ],
            );

            $this->addFlash('error', 'Authentication failed.');

            return $this->redirectToRoute('admin_register_payment');
        }

        try {
            $result = $this->registrationService->register(
                playerName: $data->playerName,
                seasonSlug: $data->season->getSlug(),
            );

            $this->addResultFlash($result);
        } catch (LedgerWriteException $exception) {
            $this->logger->critical(
                'Payment registered but ledger write failed',
                [
                    'exception' => $exception,
                    'playerName' => $data->playerName,
                    'season' => $data->season,
                ],
            );

            $this->addFlash(
                'error',
                'Critical failure: Failed to write to ledger file, update cancelled.'
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Payment registration failed',
                [
                    'exception' => $exception,
                    'playerName' => $data->playerName,
                    'season' => $data->season,
                ],
            );

            $this->addFlash(
                'error',
                'A critical failure occurred while processing the transaction.',
            );
        }

        return $this->redirectToRoute('admin_register_payment');
    }

    private function addResultFlash(
        RegisterSeasonPaymentResult $result,
    ): void {
        [$type, $message] = match ($result) {
            RegisterSeasonPaymentResult::Registered => [
                'success',
                'Successfully processed transaction.',
            ],
            RegisterSeasonPaymentResult::AlreadyPaid => [
                'warning',
                'Blader has already cleared their balance.',
            ],
            RegisterSeasonPaymentResult::SeasonNotFound => [
                'error',
                'The requested season context does not exist.',
            ],
        };

        $this->addFlash($type, $message);
    }
}
