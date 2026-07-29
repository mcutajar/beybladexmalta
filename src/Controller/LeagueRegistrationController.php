<?php

namespace App\Controller;

use App\Exception\LedgerWriteException;
use App\Repository\SeasonRepository;
use App\Service\PlayerRegistrationService;
use App\Service\RegisterSeasonPaymentResult;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LeagueRegistrationController extends AbstractController
{
    public function __construct(
        private SeasonRepository $seasonRepository,
        private PlayerRegistrationService $playerRegistrationService,
        private LoggerInterface $logger, )
    {
    }

    #[Route('/admin/payments', name: 'admin_register_payment', methods: ['GET', 'POST'])]
    public function registerPayment(
        Request $request,
        #[Autowire(env: 'PAYMENTS_ADMIN_PASSPHRASE')]
        string $adminPassphrase,
    ): Response {
        $seasons = $this->seasonRepository->findAll();
        $seasonChoices = [];
        foreach ($seasons as $season) {
            $seasonChoices[$season->getName()] = $season->getSlug();
        }

        $form = $this->createFormBuilder()
            ->add('season', ChoiceType::class, [
                'choices' => $seasonChoices,
                'placeholder' => 'Select Target Season Context',
                'attr' => ['class' => 'w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-slate-100 focus:border-cyan-500 outline-none'],
            ])
            ->add('playerName', TextType::class, [
                'attr' => ['placeholder' => 'Enter Blader Name', 'class' => 'w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-slate-100 focus:border-cyan-500 outline-none', 'autocomplete' => 'off'],
            ])
            ->add('passphrase', PasswordType::class, [
                'attr' => ['placeholder' => 'Enter Admin Passphrase', 'class' => 'w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-slate-100 focus:border-cyan-500 outline-none'],
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $this->logger->info('Registration attempt initiated', ['player' => $data['playerName']]);

            if ($data['passphrase'] !== $adminPassphrase) {
                $this->logger->warning('Registration failed: Incorrect passphrase');
                $this->addFlash('error', 'Authentication failed.');

                return $this->redirectToRoute('admin_register_payment');
            }

            try {
                $result = $this->playerRegistrationService->register(
                    $data['playerName'],
                    $data['season'],
                );

                match ($result) {
                    RegisterSeasonPaymentResult::SeasonNotFound => $this->addFlash(
                        'error',
                        'The requested season context does not exist.',
                    ),

                    RegisterSeasonPaymentResult::AlreadyPaid => $this->addFlash(
                        'warning',
                        'Blader has already cleared their balance.',
                    ),

                    RegisterSeasonPaymentResult::Registered => $this->addFlash(
                        'success',
                        'Successfully processed transaction.',
                    ),
                };
            } catch (LedgerWriteException $exception) {
                $this->logger->critical('Critical failure: Failed to write to ledger file, update cancelled.', [
                    'exception' => $exception,
                    'playerName' => $data['playerName'],
                    'season' => $data['season'],
                ]);

                $this->addFlash(
                    'error',
                    'Critical failure: Failed to write to ledger file, update cancelled.',
                );
            } catch (\Throwable $exception) {
                $this->logger->error('Payment registration failed', [
                    'exception' => $exception,
                    'playerName' => $data['playerName'],
                    'season' => $data['season'],
                ]);

                $this->addFlash(
                    'error',
                    'A critical failure occurred while processing the transaction.',
                );
            }

            return $this->redirectToRoute('admin_register_payment');
        }

        return $this->render('admin/register_payment.html.twig', [
            'registration_form' => $form->createView(),
        ]);
    }
}
