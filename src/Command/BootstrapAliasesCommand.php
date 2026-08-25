<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\AliasBootstrapPlan;
use App\Dto\AliasContradiction;
use App\Dto\AliasProposal;
use App\Dto\SkippedEvent;
use App\Exception\LedgerWriteException;
use App\Service\AliasBootstrapper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The alias table, read out of the events already imported.
 *
 * A one-off, and it prints before it writes. Sixty assertions about who is who
 * derived in one pass is exactly the change that is unpleasant to unpick, so
 * the default is to work the whole thing out, show it, and touch nothing —
 * `--force` is the second answer to a question already asked.
 *
 * What it could not decide is printed with the same prominence as what it
 * could: contradictions, ranks that paired with nothing, and every event it
 * read nothing out of. A seeding pass that showed only its successes would
 * look the same whether it had read sixteen events or two.
 */
#[AsCommand(
    name: 'app:bootstrap-aliases',
    description: 'Reads the alias table out of the tournaments already imported.',
)]
final class BootstrapAliasesCommand extends Command
{
    public function __construct(
        private readonly AliasBootstrapper $bootstrapper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Write the aliases. Without it, nothing is touched.',
            )
            ->addUsage('')
            ->addUsage('--force')
            ->setHelp(<<<'HELP'
                Every tournament already imported is a labelled example. The placement
                list was typed under the league's own names; the bracket ranks the same
                people under whatever they called themselves that night. Rank <info>n</info> of the
                bracket is line <info>n</info> of the list, so the pairing is on record already and
                only needs reading out.

                Nothing two events disagree about is written, nothing already on file is
                touched, and no blader is ever created. Run it once to read the table,
                then again with <info>--force</info> when it looks right.

                The two 2v2 events are left alone: their entrants are teams, and a team
                name belongs to two bladers rather than one.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $plan = $this->bootstrapper->plan();

        $this->report($io, $plan);

        if (!$input->getOption('force')) {
            return $this->rehearsal($io, $plan);
        }

        return $this->write($io, $plan);
    }

    private function report(SymfonyStyle $io, AliasBootstrapPlan $plan): void
    {
        $io->title('The alias table, as the imports already tell it');

        $io->text(sprintf(
            '%d %s read. %d %s paired with a bracket entrant, and %d of those already spell the blader the way the league does.',
            $plan->events,
            1 === $plan->events ? 'event' : 'events',
            $plan->placements,
            1 === $plan->placements ? 'placement' : 'placements',
            $plan->agreed,
        ));

        $this->proposals($io, $plan);
        $this->contradictions($io, $plan->contradictions);
        $this->undecided($io, $plan->undecided);
        $this->skipped($io, $plan->skipped);
    }

    private function proposals(SymfonyStyle $io, AliasBootstrapPlan $plan): void
    {
        if ([] === $plan->proposals) {
            $io->warning('Nothing was learned. Every placement already names its blader the way the league spells them.');

            return;
        }

        $io->section('What the imports say');

        $io->table(
            ['Blader', 'Spelling', 'Resolves as', 'Seen in', 'Status'],
            array_map(
                static fn (AliasProposal $proposal): array => [
                    $proposal->bladerName(),
                    $proposal->spelling,
                    $proposal->normalised,
                    sprintf('%d × %s', $proposal->timesSeen(), implode(', ', $proposal->events)),
                    $proposal->status->value,
                ],
                $plan->proposals,
            ),
        );

        $io->text(sprintf(
            '%d to write, %d already on file, %d that cannot be filed.',
            count($plan->writable()),
            count($plan->alreadyOnFile()),
            count($plan->refused()),
        ));
    }

    /**
     * @param list<AliasContradiction> $contradictions
     */
    private function contradictions(SymfonyStyle $io, array $contradictions): void
    {
        if ([] === $contradictions) {
            return;
        }

        $io->section('Spellings two evenings disagree about');
        $io->text('Not written, and not this command\'s to settle. Two bladers who spell themselves alike look exactly like a list typed against the wrong bracket.');
        $io->listing(array_map(
            static fn (AliasContradiction $contradiction): string => $contradiction->problem(),
            $contradictions,
        ));
    }

    /**
     * @param list<string> $undecided
     */
    private function undecided(SymfonyStyle $io, array $undecided): void
    {
        if ([] === $undecided) {
            return;
        }

        $io->section('Lines that paired with nothing');
        $io->listing($undecided);
    }

    /**
     * @param list<SkippedEvent> $skipped
     */
    private function skipped(SymfonyStyle $io, array $skipped): void
    {
        if ([] === $skipped) {
            return;
        }

        $io->section('Events nothing was read out of');

        $io->table(
            ['Event', 'Bracket', 'Why'],
            array_map(
                static fn (SkippedEvent $event): array => [
                    $event->title,
                    $event->bracket(),
                    $event->reason->explanation(),
                ],
                $skipped,
            ),
        );

        foreach ($skipped as $event) {
            if (null !== $event->detail && $io->isVerbose()) {
                $io->text(sprintf('%s: %s', $event->title, $event->detail));
            }
        }
    }

    /**
     * The default run. It has already printed everything; all that is left is
     * to say plainly that nothing happened and what would.
     */
    private function rehearsal(SymfonyStyle $io, AliasBootstrapPlan $plan): int
    {
        $writable = count($plan->writable());

        if (0 === $writable) {
            $io->success('Nothing to write. The alias table already says everything the imports do.');

            return Command::SUCCESS;
        }

        $io->warning(sprintf(
            'Nothing was written. Run it again with --force to file %d %s.',
            $writable,
            1 === $writable ? 'alias' : 'aliases',
        ));

        return Command::SUCCESS;
    }

    private function write(SymfonyStyle $io, AliasBootstrapPlan $plan): int
    {
        if ([] === $plan->writable()) {
            $io->success('Nothing to write. The alias table already says everything the imports do.');

            return Command::SUCCESS;
        }

        try {
            $outcome = $this->bootstrapper->apply($plan);
        } catch (LedgerWriteException $exception) {
            $io->error('The alias table was left alone because the recovery ledger could not be updated.');

            if ($io->isVerbose()) {
                $io->writeln($exception->getMessage());
            }

            return Command::FAILURE;
        }

        if (!$outcome->wentThrough()) {
            $io->error(sprintf(
                '%d %s filed. These were refused when it came to writing them: %s',
                $outcome->written,
                1 === $outcome->written ? 'alias was' : 'aliases were',
                implode('; ', array_map(
                    static fn (array $refusal): string => sprintf('"%s" → %s (%s)', $refusal['spelling'], $refusal['blader'], $refusal['result']->name),
                    $outcome->refused,
                )),
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%d %s filed. Every one of them is in the ledger, so a rebuilt database gets them back.',
            $outcome->written,
            1 === $outcome->written ? 'alias was' : 'aliases were',
        ));

        if ($plan->needsAPerson()) {
            $io->note('The sections above are what a person still has to look at.');
        }

        return Command::SUCCESS;
    }
}
