<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\BracketAnswers;
use App\Dto\BracketChoices;
use App\Dto\BracketConfirmData;
use App\Dto\BracketDraft;
use App\Dto\BracketImportData;
use App\Dto\BracketImportOutcome;
use App\Dto\BracketPreview;
use App\Dto\ChallongeUrl;
use App\Exception\ChallongeFetchException;
use App\Exception\ImportFileWriteException;
use App\Exception\InvalidChallongeUrlException;
use App\Exception\LedgerWriteException;
use App\Form\BracketConfirmType;
use App\Form\BracketImportType;
use App\Repository\PlayerRepositoryInterface;
use App\Service\AdminPassphraseVerifier;
use App\Service\BracketDraftStore;
use App\Service\BracketImportResult;
use App\Service\BracketImportService;
use App\Service\BracketPreviewer;
use App\Service\ChallongeFetcher;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The second way into `/admin/import`: paste a bracket, look at what it says,
 * and approve it.
 *
 * The textarea path is untouched and stays. This one exists because a bracket
 * already publishes everything the ten typed names throw away — five rounds of
 * Swiss, a top cut, and a per-point scoreline for every match — and because
 * matching each name against the blader list by hand is the part that goes
 * wrong.
 *
 * Three requests, and only the last one writes:
 *
 * 1. the entry form on `/admin/import` posts a URL here
 * 2. the bracket is fetched and previewed; the snapshot is kept in the session
 *    and nothing else happens
 * 3. the confirm posts back the decisions, the order and the passphrase
 *
 * **The passphrase is checked on the confirm, not on the fetch.** Reading a
 * public page writes nothing, and gating it would be asking to authorise
 * something that has not happened yet.
 */
final class AdminBracketImportController extends AbstractController
{
    public function __construct(
        private readonly ChallongeFetcher $fetcher,
        private readonly BracketPreviewer $previewer,
        private readonly BracketImportService $imports,
        private readonly BracketDraftStore $drafts,
        private readonly PlayerRepositoryInterface $players,
        private readonly AdminPassphraseVerifier $passphrases,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        '/admin/import/bracket',
        name: 'admin_bracket_preview',
        methods: ['POST'],
    )]
    public function preview(Request $request): Response
    {
        $form = $this->createForm(BracketImportType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->redirectToRoute('admin_tournament_import');
        }

        /** @var BracketImportData $data */
        $data = $form->getData();

        $title = trim($data->title);
        $heldOn = trim($data->date);
        $seasonSlug = $data->season?->getSlug() ?? '';

        if ('' === $title) {
            return $this->refuse('Validation Error: The event needs a title. Challonge does not carry the name the league calls it.');
        }

        if (!$this->isADate($heldOn)) {
            return $this->refuse('Validation Error: Please use the strict format structure YYYY-MM-DD for the date field.');
        }

        try {
            $url = ChallongeUrl::fromString($data->challongeUrl);
        } catch (InvalidChallongeUrlException $exception) {
            return $this->refuse($exception->getMessage());
        }

        try {
            $snapshot = $this->fetcher->fetch($url);
        } catch (ChallongeFetchException $exception) {
            $this->logger->error('A bracket could not be fetched for preview.', [
                'url' => $url->moduleUrl(),
                'exception' => $exception,
            ]);

            return $this->refuse('The bracket could not be read: '.$exception->getMessage());
        }

        $challongeUrl = trim($data->challongeUrl);

        $this->drafts->remember($snapshot, $challongeUrl, $title, $heldOn, $seasonSlug);

        $preview = $this->previewer->preview($snapshot, $challongeUrl, $title, $heldOn, $seasonSlug);

        return $this->renderPreview($preview, $this->confirmFor($preview));
    }

    #[Route(
        '/admin/import/bracket/confirm',
        name: 'admin_bracket_confirm',
        methods: ['POST'],
    )]
    public function confirm(
        Request $request,
        #[Autowire(env: 'TOURNAMENTS_ADMIN_PASSPHRASE')]
        string $adminPassphrase,
    ): Response {
        if ($request->request->has('cancel')) {
            $this->drafts->forget();

            return $this->redirectToRoute('admin_tournament_import');
        }

        $submitted = $request->request->all('bracket_confirm');
        $draft = $this->drafts->recall(is_string($submitted['slug'] ?? null) ? $submitted['slug'] : '');

        if (null === $draft) {
            return $this->refuse('That bracket is no longer in front of you. Nothing was written — paste the URL and fetch it again.');
        }

        $choices = new BracketChoices(
            answers: $this->answersIn($request),
            order: $this->placesIn($request),
        );

        $preview = $this->rebuild($draft, $choices);
        $form = $this->confirmFor($preview);
        $form->handleRequest($request);

        /** @var BracketConfirmData $confirmation */
        $confirmation = $form->getData();

        if (!$this->passphrases->matches($adminPassphrase, $confirmation->passphrase)) {
            $this->logger->warning('Bracket import failed: Unauthorized passphrase input.');

            $this->addFlash('error', 'Authentication failed. The administrative passphrase is incorrect.');

            return $this->renderPreview($preview, $form);
        }

        try {
            $outcome = $this->imports->apply(
                snapshot: $draft->snapshot,
                challongeUrl: $draft->challongeUrl,
                title: $draft->title,
                heldOn: $draft->heldOn,
                seasonSlug: $draft->seasonSlug,
                choices: new BracketChoices(
                    answers: $choices->answers,
                    order: $choices->order,
                    knockoutWinner: $confirmation->knockoutWinner ?? '',
                ),
            );
        } catch (LedgerWriteException|ImportFileWriteException $exception) {
            $this->logger->critical('Bracket import cancelled: recovery artifact write failed', [
                'exception' => $exception,
                'slug' => $draft->snapshot->slug,
            ]);

            $this->addFlash('error', 'Critical failure: Failed to write the recovery artifacts, import cancelled.');

            return $this->renderPreview($preview, $form);
        } catch (\Throwable $exception) {
            $this->logger->critical('Bracket import failed.', [
                'exception' => $exception,
                'slug' => $draft->snapshot->slug,
            ]);

            $this->addFlash('error', 'Import aborted: '.$exception->getMessage());

            return $this->renderPreview($preview, $form);
        }

        if (!$outcome->wasImported()) {
            /*
             * A bracket that stopped being importable between the fetch and
             * the confirm has nowhere to go back to, so it is said once and
             * the draft is abandoned. Everything else is answered on the
             * screen it was asked on.
             */
            if (!$outcome->preview->isReady()) {
                return $this->refuse($outcome->preview->refusal());
            }

            $this->addFlash('error', $this->why($outcome));

            return $this->renderPreview($outcome->preview, $form);
        }

        $this->drafts->forget();
        $this->addFlash('success', $this->said($outcome));

        return $this->redirectToRoute('admin_tournament_import');
    }

    /**
     * The preview as it stands with the choices somebody has made so far.
     *
     * Named apart from the route because the two do different things: the
     * route fetches a bracket, this only re-derives one already in hand.
     */
    private function rebuild(BracketDraft $draft, BracketChoices $choices): BracketPreview
    {
        return $this->previewer->preview(
            $draft->snapshot,
            $draft->challongeUrl,
            $draft->title,
            $draft->heldOn,
            $draft->seasonSlug,
            $choices,
        );
    }

    /**
     * The confirm bar, offering the bladers this bracket actually produced.
     *
     * Seeded with whoever the preview already has winning the cut — the last
     * final-stage match on the first render, and whatever was chosen on every
     * render after it, because the preview was rebuilt with that choice in it.
     *
     * @return FormInterface<BracketConfirmData>
     */
    private function confirmFor(BracketPreview $preview): FormInterface
    {
        $winners = [];

        foreach ($preview->placements as $placement) {
            if (null !== $placement->bladerName) {
                $winners[$placement->bladerName] = $placement->bladerName;
            }
        }

        return $this->createForm(
            BracketConfirmType::class,
            new BracketConfirmData(
                slug: $preview->slug,
                knockoutWinner: $preview->knockoutWinner()->bladerName ?? null,
            ),
            ['bladers' => $winners],
        );
    }

    /**
     * @param FormInterface<BracketConfirmData> $confirm
     */
    private function renderPreview(BracketPreview $preview, FormInterface $confirm): Response
    {
        if (!$preview->isReady()) {
            return $this->refuse($preview->refusal());
        }

        return $this->render('admin/import_bracket.html.twig', [
            'preview' => $preview,
            'confirm_form' => $confirm,
            'bladers' => $this->everyBlader(),
            'elsewhere' => BracketAnswers::ELSEWHERE,
        ]);
    }

    /**
     * Every blader, so a decision can point at one the suggestions missed.
     *
     * @return array<int, string> id => name, alphabetically
     */
    private function everyBlader(): array
    {
        $bladers = [];

        foreach ($this->players->findAll() as $player) {
            $id = $player->getId();

            if (null !== $id) {
                $bladers[$id] = $player->getName();
            }
        }

        asort($bladers, \SORT_NATURAL | \SORT_FLAG_CASE);

        return $bladers;
    }

    /**
     * What was said about each unreadable name, with the dropdown folded in.
     *
     * A row answers with buttons or with the dropdown behind "someone else",
     * and the two cannot share a field name — a `<select>` posts alongside the
     * radios and, being later in the document, would blank a button somebody
     * had pressed. So the dropdown is its own field and the button that hands
     * over to it says so; here is where the two become one answer again.
     */
    private function answersIn(Request $request): BracketAnswers
    {
        $answers = $this->stringsIn($request, 'decision');
        $elsewhere = $this->stringsIn($request, 'elsewhere');

        foreach ($answers as $key => $answer) {
            if (BracketAnswers::ELSEWHERE === $answer) {
                $answers[$key] = $elsewhere[$key] ?? '';
            }
        }

        return new BracketAnswers($answers);
    }

    /**
     * @return array<string, string>
     */
    private function stringsIn(Request $request, string $key): array
    {
        $values = [];

        foreach ($request->request->all($key) as $name => $value) {
            if (is_string($value)) {
                $values[(string) $name] = $value;
            }
        }

        return $values;
    }

    /**
     * Where each standings row was moved to, ignoring anything that is not a
     * place.
     *
     * @return array<int, int>
     */
    private function placesIn(Request $request): array
    {
        $places = [];

        foreach ($request->request->all('order') as $row => $place) {
            if (is_numeric($row) && is_numeric($place)) {
                $places[(int) $row] = (int) $place;
            }
        }

        return $places;
    }

    private function why(BracketImportOutcome $outcome): string
    {
        return match ($outcome->result) {
            BracketImportResult::Imported => '',

            BracketImportResult::Refused => $outcome->preview->refusal(),

            BracketImportResult::DecisionsOutstanding => sprintf(
                'Nothing was written. %d %s still unanswered, and an import cannot begin on a name the league cannot read.',
                count($outcome->preview->outstanding()),
                1 === count($outcome->preview->outstanding()) ? 'name is' : 'names are',
            ),

            BracketImportResult::AliasRefused => 'The import stopped before the event was opened, so there is no tournament and no results. '.implode(' ', $outcome->problems),

            BracketImportResult::InvalidDate => 'Validation Error: Please use the strict format structure YYYY-MM-DD for the date field.',

            BracketImportResult::SeasonNotFound => 'Target season context variant not found.',

            BracketImportResult::NoPlacements => 'Validation Error: Every entrant was dropped, so there is no finishing order left to score.',
        };
    }

    private function said(BracketImportOutcome $outcome): string
    {
        $message = sprintf(
            'Imported "%s" from %s: %d %s scored',
            $outcome->preview->title,
            $outcome->preview->slug,
            $outcome->scored,
            1 === $outcome->scored ? 'placement' : 'placements',
        );

        if (null !== $outcome->archive && $outcome->archive->wasArchived()) {
            $message .= sprintf(
                ', %d matches and %d entrants archived',
                $outcome->archive->matches,
                $outcome->archive->participants,
            );
        }

        if ($outcome->created > 0) {
            $message .= sprintf(
                ', %d %s created%s',
                $outcome->created,
                1 === $outcome->created ? 'blader' : 'bladers',
                $outcome->seeded > 0 ? sprintf(' (%d by default)', $outcome->seeded) : '',
            );
        }

        if ($outcome->aliased > 0) {
            $message .= sprintf(', %d %s filed', $outcome->aliased, 1 === $outcome->aliased ? 'alias' : 'aliases');
        }

        return $message.'.';
    }

    private function refuse(string $message): Response
    {
        $this->addFlash('error', $message);

        return $this->redirectToRoute('admin_tournament_import');
    }

    private function isADate(string $heldOn): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $heldOn);

        return false !== $date && $date->format('Y-m-d') === $heldOn;
    }
}
