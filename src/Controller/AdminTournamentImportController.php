<?php

namespace App\Controller;

use App\Entity\Player;
use App\Entity\Season;
use App\Entity\Tournament;
use App\Entity\TournamentResult;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

class AdminTournamentImportController extends AbstractController
{
    private const array F1_MATRIX = [
        1 => 25, 2 => 20, 3 => 15, 4 => 12, 5 => 10,
        6 => 8,  7 => 6,  8 => 4,  9 => 2,  10 => 1,
    ];

    private const int KNOCKOUT_WINNER_BONUS = 10;

    #[Route('/admin/import', name: 'admin_tournament_import', methods: ['GET', 'POST'])]
    public function import(
        Request $request,
        EntityManagerInterface $entityManager,
        KernelInterface $kernel,
        LoggerInterface $logger,
        #[Autowire(env: 'TOURNAMENTS_ADMIN_PASSPHRASE')] string $adminPassphrase,
    ): Response {
        $seasons = $entityManager->getRepository(Season::class)->findAll();
        $seasonChoices = [];
        foreach ($seasons as $s) {
            $seasonChoices[$s->getName()] = $s->getSlug();
        }

        $form = $this->createFormBuilder()
            ->add('title', TextType::class, [
                'attr' => ['placeholder' => 'e.g., Stage 1 Ranked', 'class' => 'w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-slate-100 focus:border-cyan-500 outline-none'],
            ])
            ->add('date', TextType::class, [
                'attr' => ['placeholder' => 'YYYY-MM-DD', 'class' => 'w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-slate-100 focus:border-cyan-500 outline-none'],
            ])
            ->add('season', ChoiceType::class, [
                'choices' => $seasonChoices,
                'placeholder' => 'Select Season Context',
                'attr' => ['class' => 'w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-slate-100 focus:border-cyan-500 outline-none'],
            ])
            ->add('challongeUrl', UrlType::class, [
                'required' => false,
                'attr' => ['placeholder' => 'Challonge Link (Optional)', 'class' => 'w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-slate-100 focus:border-cyan-500 outline-none'],
            ])
            ->add('knockoutWinner', TextType::class, [
                'required' => false,
                'attr' => ['placeholder' => 'Knockout Winner Name (Optional)', 'class' => 'w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-slate-100 focus:border-cyan-500 outline-none', 'autocomplete' => 'off'],
            ])
            ->add('playerList', TextareaType::class, [
                'attr' => [
                    'placeholder' => "Blader1\nBlader2\nBlader3\nBlader4\nBlader5\nBlader6\nBlader7\nBlader8\nBlader9\nBlader10",
                    'rows' => 11,
                    'class' => 'w-full font-mono bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-slate-100 focus:border-cyan-500 outline-none',
                ],
            ])
            ->add('passphrase', PasswordType::class, [
                'attr' => ['placeholder' => 'Enter Admin Passphrase', 'class' => 'w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-slate-100 focus:border-cyan-500 outline-none'],
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            if ($data['passphrase'] !== $adminPassphrase) {
                $logger->warning('Tournament web import failed: Unauthorized passphrase input.');
                $this->addFlash('error', 'Authentication failed. The administrative passphrase is incorrect.');

                return $this->redirectToRoute('admin_tournament_import');
            }

            // Clean up lines and parse count arrays
            $lines = array_filter(array_map('trim', explode("\n", $data['playerList'])));
            $lineCount = count($lines);

            if (10 !== $lineCount) {
                $logger->warning('Import rejected: List must contain exactly 10 players.', ['count_provided' => $lineCount]);
                $this->addFlash('error', sprintf('Validation Error: The player list must contain exactly 10 players matching the F1 points system structure. You provided %d.', $lineCount));

                return $this->redirectToRoute('admin_tournament_import');
            }

            // Enforce explicit date formatting patterns
            $dateStr = trim($data['date']);
            try {
                $dateTime = new \DateTimeImmutable($dateStr);
                if ($dateTime->format('Y-m-d') !== $dateStr) {
                    throw new \InvalidArgumentException();
                }
            } catch (\Exception $e) {
                $logger->warning('Import rejected: Invalid date formatting pattern used.', ['provided' => $dateStr]);
                $this->addFlash('error', 'Validation Error: Please use the strict format structure YYYY-MM-DD for the date field.');

                return $this->redirectToRoute('admin_tournament_import');
            }

            $entityManager->beginTransaction();
            try {
                $season = $entityManager->getRepository(Season::class)->findOneBy(['slug' => $data['season']]);
                if (!$season) {
                    throw new \Exception('Target season context variant not found.');
                }

                $tournament = new Tournament();
                $tournament->setTitle($data['title']);
                $tournament->setHeldOn($dateTime);
                $tournament->setChallongeUrl($data['challongeUrl']);
                $tournament->setSeason($season);
                $entityManager->persist($tournament);

                $sanitizedSlug = preg_replace('/[^a-z0-9_-]/', '', strtolower(str_replace(' ', '-', $data['title'])));
                $dateString = $dateTime->format('Y-m-d');

                $dataDir = $kernel->getProjectDir().'/var/data/imports';
                if (!is_dir($dataDir)) {
                    mkdir($dataDir, 0775, true);
                }
                $generatedImportFilePath = sprintf('%s/%s-%s.txt', $dataDir, $dateString, $sanitizedSlug);
                $rawTextAccumulator = '';
                $rank = 1;

                foreach ($lines as $line) {
                    $playerName = trim($line);
                    $rawTextAccumulator .= sprintf("%s\n", $playerName);

                    $bonusPoints = 0;
                    if ($data['knockoutWinner'] && 0 === strcasecmp($playerName, trim($data['knockoutWinner']))) {
                        $bonusPoints = self::KNOCKOUT_WINNER_BONUS;
                    }

                    // Case-insensitive lookups via Query Builder elements
                    $player = $entityManager->getRepository(Player::class)->createQueryBuilder('p')
                        ->where('LOWER(p.name) = LOWER(:name)')
                        ->setParameter('name', $playerName)
                        ->getQuery()
                        ->getOneOrNullResult();

                    if (!$player) {
                        $player = new Player();
                        $player->setName($playerName);
                        $entityManager->persist($player);
                        $logger->notice(sprintf('Implicit auto-generation of player profile proxy: "%s".', $playerName));
                    }

                    $f1Points = self::F1_MATRIX[$rank] ?? 0;

                    $result = new TournamentResult();
                    $result->setTournament($tournament);
                    $result->setPlayer($player);
                    $result->setRank($rank);
                    $result->setF1Points($f1Points);
                    $result->setBonusPoints($bonusPoints);

                    $entityManager->persist($result);
                    ++$rank;
                }

                file_put_contents($generatedImportFilePath, trim($rawTextAccumulator)."\n", LOCK_EX);

                $entityManager->flush();
                $entityManager->commit();

                // --- APPEND STANDALONE REPLAY LEDGER STRING ---
                $logFilePath = $kernel->getProjectDir().'/var/log/command_ledger.sh';
                $commandString = sprintf(
                    'php bin/console app:import-tournament %s %s %s --season=%s',
                    escapeshellarg($data['title']),
                    escapeshellarg($dateString),
                    escapeshellarg($generatedImportFilePath),
                    escapeshellarg($season->getSlug())
                );

                if ($data['challongeUrl']) {
                    $commandString .= sprintf(' --challonge=%s', escapeshellarg($data['challongeUrl']));
                }
                if ($data['knockoutWinner']) {
                    $commandString .= sprintf(' --knockout=%s', escapeshellarg($data['knockoutWinner']));
                }
                $commandString .= "\n";

                file_put_contents($logFilePath, $commandString, FILE_APPEND | LOCK_EX);

                $this->addFlash('success', sprintf('Successfully imported "%s" with 10 player ranks.', $data['title']));

                return $this->redirectToRoute('admin_tournament_import');
            } catch (\Exception $e) {
                if ($entityManager->getConnection()->isTransactionActive()) {
                    $entityManager->rollback();
                }
                $logger->critical('Tournament web import failed.', ['msg' => $e->getMessage()]);
                $this->addFlash('error', 'Import aborted: '.$e->getMessage());
            }
        }

        return $this->render('admin/import_tournament.html.twig', [
            'import_form' => $form->createView(),
        ]);
    }
}
