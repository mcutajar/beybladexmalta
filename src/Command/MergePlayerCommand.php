<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\MergePlayerPlan;
use App\Exception\LedgerWriteException;
use App\Service\MergePlayerResult;
use App\Service\PlayerMergeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:merge-player', description: 'Moves one blader and their whole history into another.')]
final class MergePlayerCommand extends Command
{
    public function __construct(private readonly PlayerMergeService $merger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('from', InputArgument::REQUIRED, 'The losing blader name.')
            ->addArgument('into', InputArgument::REQUIRED, 'The surviving blader name.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Apply the merge. Without it, nothing is touched.')
            ->addUsage("'Old name' 'Survivor'")
            ->addUsage("'Old name' 'Survivor' --force");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $plan = $this->merger->planNames((string) $input->getArgument('from'), (string) $input->getArgument('into'));

        if (!$plan->isReady()) {
            if (MergePlayerResult::AlreadyMerged === $plan->result) {
                $io->success('This merge is already in place. Nothing was written.');

                return Command::SUCCESS;
            }
            $io->error($plan->detail ?? 'The merge cannot be planned.');

            return Command::FAILURE;
        }

        $this->report($io, $plan);
        if (!$input->getOption('force')) {
            $io->warning('Nothing was written. Run the same command with --force to apply this merge.');

            return Command::SUCCESS;
        }

        try {
            $this->merger->merge($plan);
        } catch (LedgerWriteException) {
            $io->error('The merge was cancelled because the recovery ledger could not be updated.');

            return Command::FAILURE;
        }
        $io->success(sprintf('Merged "%s" into "%s". The old profile URL now redirects.', $plan->from?->getName(), $plan->into?->getName()));

        return Command::SUCCESS;
    }

    private function report(SymfonyStyle $io, MergePlayerPlan $plan): void
    {
        $io->title(sprintf('Merge %s into %s', $plan->from?->getName(), $plan->into?->getName()));
        $io->table(['History', 'What moves'], [
            ['Tournament results', $this->names($plan->results, static fn ($row): string => $row->getTournament()->getTitle())],
            ['Archived participations', $this->names($plan->participants, static fn ($row): string => $row->getStage()->getTournament()->getTitle())],
            ['Season registrations', $this->names($plan->registrations, static fn ($row): string => $row->getSeason()->getName())],
            ['Team memberships', $this->names($plan->teamMemberships, static fn ($row): string => sprintf('%s — %s', $row->getTeam()->getTournament()->getTitle(), $row->getTeam()->getName()))],
            ['Aliases', $this->names($plan->aliases, static fn ($row): string => $row->getAlias())],
            ['Rejected suggestions', $this->names($plan->rejections, static fn ($row): string => $row->getSpelling())],
            ['Losing name alias', $plan->addLosingNameAlias ? (string) $plan->from?->getName() : 'already on file'],
            ['Old profile redirect', (string) $plan->oldProfilePath()],
        ]);
        if ([] !== $plan->reconciledRejections) {
            $io->note(sprintf('%d rejected suggestion(s) will be reconciled because they duplicate or contradict the survivor\'s identity.', count($plan->reconciledRejections)));
        }
        $io->text(sprintf('%d older profile redirect(s) will also follow the survivor.', count($plan->existingRedirects)));
        $io->text(sprintf('%d archived participation(s) carry the affected match history; matches and games do not point at a player directly.', count($plan->participants)));
    }

    /** @param array<object> $rows */
    private function names(array $rows, callable $name): string
    {
        return [] === $rows ? 'none' : implode(', ', array_map($name, $rows));
    }
}
