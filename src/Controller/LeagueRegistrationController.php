<?php

namespace App\Controller;

use App\Entity\Player;
use App\Entity\Season;
use App\Entity\SeasonRegistration;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

class LeagueRegistrationController extends AbstractController
{
    #[Route('/admin/payments', name: 'admin_register_payment', methods: ['GET', 'POST'])]
    public function registerPayment(
        Request $request,
        EntityManagerInterface $entityManager,
        KernelInterface $kernel,
        LoggerInterface $logger,
        #[Autowire(env: 'PAYMENTS_ADMIN_PASSPHRASE')]
        string $adminPassphrase,
    ): Response {
        $seasons = $entityManager->getRepository(Season::class)->findAll();
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
            $logger->info('Registration attempt initiated', ['player' => $data['playerName']]);

            if ($data['passphrase'] !== $adminPassphrase) {
                $logger->warning('Registration failed: Incorrect passphrase');
                $this->addFlash('error', 'Authentication failed.');

                return $this->redirectToRoute('admin_register_payment');
            }

            $season = $entityManager->getRepository(Season::class)->findOneBy(['slug' => $data['season']]);
            if (!$season) {
                $logger->error('Season not found', ['slug' => $data['season']]);
                $this->addFlash('error', 'The requested season context does not exist.');

                return $this->redirectToRoute('admin_register_payment');
            }

            $player = $entityManager->getRepository(Player::class)->createQueryBuilder('p')
                ->where('LOWER(p.name) = LOWER(:name)')
                ->setParameter('name', trim($data['playerName']))
                ->getQuery()
                ->getOneOrNullResult();

            if (!$player) {
                $player = new Player();
                $player->setName(trim($data['playerName']));
                $entityManager->persist($player);
                $entityManager->flush();
                $logger->info('New player record generated', ['name' => $player->getName()]);
            }

            $registration = $entityManager->getRepository(SeasonRegistration::class)->findOneBy([
                'player' => $player,
                'season' => $season,
            ]);

            if (!$registration) {
                $registration = new SeasonRegistration();
                $registration->setPlayer($player);
                $registration->setSeason($season);
            }

            if ($registration->isPaid()) {
                $logger->info('Payment already registered', ['player' => $player->getName()]);
                $this->addFlash('warning', 'Blader has already cleared their balance.');
            } else {
                try {
                    $registration->setPaid(true);
                    $entityManager->persist($registration);
                    $entityManager->flush();

                    // Ledger write attempt
                    $logFilePath = $kernel->getProjectDir().'/var/log/command_ledger.sh';
                    $commandLine = sprintf("php bin/console app:register-payment %s %s\n", escapeshellarg($season->getSlug()), escapeshellarg($player->getName()));

                    if (false === @file_put_contents($logFilePath, $commandLine, FILE_APPEND | LOCK_EX)) {
                        throw new \Exception('Failed to write to ledger file: '.$logFilePath);
                    }

                    $logger->info('Registration successful & ledger updated', ['player' => $player->getName()]);
                    $this->addFlash('success', 'Successfully processed transaction.');
                } catch (\Exception $e) {
                    $logger->critical('Ledger or DB write failure', ['message' => $e->getMessage()]);
                    $this->addFlash('error', 'Critical failure: '.$e->getMessage());
                }
            }

            return $this->redirectToRoute('admin_register_payment');
        }

        return $this->render('admin/register_payment.html.twig', [
            'registration_form' => $form->createView(),
        ]);
    }
}
