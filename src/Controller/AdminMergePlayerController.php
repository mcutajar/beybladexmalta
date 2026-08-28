<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\MergePlayerData;
use App\Exception\LedgerWriteException;
use App\Form\MergePlayerType;
use App\Service\AdminPassphraseVerifier;
use App\Service\PlayerMergeService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminMergePlayerController extends AbstractController
{
    public function __construct(
        private readonly PlayerMergeService $merger,
        private readonly AdminPassphraseVerifier $passphrases,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/admin/merge-player', name: 'admin_merge_player', methods: ['GET', 'POST'])]
    public function merge(Request $request, #[Autowire(env: 'TOURNAMENTS_ADMIN_PASSPHRASE')] string $adminPassphrase): Response
    {
        $form = $this->createForm(MergePlayerType::class);
        $form->handleRequest($request);
        $plan = null;

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var MergePlayerData $data */
            $data = $form->getData();
            if (!$this->passphrases->matches($adminPassphrase, $data->passphrase)) {
                $this->logger->warning('Player merge failed: Unauthorized passphrase input.');
                $this->addFlash('error', 'Authentication failed. The administrative passphrase is incorrect.');

                return $this->redirectToRoute('admin_merge_player');
            }

            if (null !== $data->from && null !== $data->into) {
                $plan = $this->merger->plan($data->from, $data->into);
                if (!$plan->isReady()) {
                    $this->addFlash('error', $plan->detail ?? 'The merge cannot be planned.');
                } elseif ($data->confirm) {
                    try {
                        $this->merger->merge($plan);
                        $this->addFlash('success', sprintf('Merged "%s" into "%s". The old profile URL now redirects.', $data->from->getName(), $data->into->getName()));
                    } catch (LedgerWriteException $exception) {
                        $this->logger->critical('Player merge cancelled: ledger write failed.', ['exception' => $exception]);
                        $this->addFlash('error', 'Critical failure: Failed to write the recovery ledger, merge cancelled.');
                    }

                    return $this->redirectToRoute('admin_merge_player');
                }
            }
        }

        return $this->render('admin/merge_player.html.twig', ['merge_form' => $form, 'plan' => $plan]);
    }
}
