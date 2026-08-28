<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\LedgerWriteException;
use App\Service\AliasRejectionService;
use App\Service\RejectAliasSuggestionResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:alias-rejection',
    description: 'Records which alias suggestions have been considered and rejected.',
)]
final class AliasRejectionCommand extends Command
{
    private const string REJECT = 'reject';

    private const string ALLOW = 'allow';

    public function __construct(private readonly AliasRejectionService $rejections)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, sprintf('One of: %s, %s', self::REJECT, self::ALLOW))
            ->addArgument('names', InputArgument::IS_ARRAY, 'The proposed blader, then the spelling.')
            ->addUsage("reject 'Steve' 'Steve V.'")
            ->addUsage("allow 'Steve' 'Steve V.'");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $names */
        $names = array_values(array_map(strval(...), (array) $input->getArgument('names')));
        $action = (string) $input->getArgument('action');

        if (!in_array($action, [self::REJECT, self::ALLOW], true)) {
            $io->error(sprintf('"%s" is not something this does. The actions are: %s, %s.', $action, self::REJECT, self::ALLOW));

            return Command::INVALID;
        }

        if (2 !== count($names)) {
            $io->error(sprintf("%s a suggestion takes the proposed blader and then the spelling: app:alias-rejection %s 'Steve' 'Steve V.'.", ucfirst($action).'ing', $action));

            return Command::INVALID;
        }

        try {
            return self::REJECT === $action
                ? $this->reject($io, $names[0], $names[1])
                : $this->allow($io, $names[0], $names[1]);
        } catch (LedgerWriteException $exception) {
            $io->error('The rejection table was left alone because the recovery ledger could not be updated.');

            if ($io->isVerbose()) {
                $io->writeln($exception->getMessage());
            }

            return Command::FAILURE;
        }
    }

    private function reject(SymfonyStyle $io, string $blader, string $spelling): int
    {
        return match ($this->rejections->reject($blader, $spelling)) {
            RejectAliasSuggestionResult::Rejected => $this->success($io, sprintf('%s will no longer be suggested for "%s".', $blader, $spelling)),
            RejectAliasSuggestionResult::AlreadyRejected => $this->note($io, sprintf('%s was already rejected for "%s".', $blader, $spelling)),
            RejectAliasSuggestionResult::BladerNotFound => $this->failure($io, sprintf('There is no blader called "%s".', $blader)),
            RejectAliasSuggestionResult::BladerIsAmbiguous => $this->failure($io, sprintf('"%s" is how more than one blader is already spelled.', $blader)),
            RejectAliasSuggestionResult::NotAName => $this->failure($io, sprintf('"%s" has no name in it, so no suggestion can be rejected.', $spelling)),
            RejectAliasSuggestionResult::Allowed, RejectAliasSuggestionResult::NotRejected => throw new \LogicException('Unexpected rejection result.'),
        };
    }

    private function allow(SymfonyStyle $io, string $blader, string $spelling): int
    {
        return match ($this->rejections->allow($blader, $spelling)) {
            RejectAliasSuggestionResult::Allowed => $this->success($io, sprintf('%s may be suggested for "%s" again.', $blader, $spelling)),
            RejectAliasSuggestionResult::NotRejected => $this->note($io, sprintf('%s was not rejected for "%s". Nothing was removed.', $blader, $spelling)),
            RejectAliasSuggestionResult::BladerNotFound => $this->failure($io, sprintf('There is no blader called "%s".', $blader)),
            RejectAliasSuggestionResult::BladerIsAmbiguous => $this->failure($io, sprintf('"%s" is how more than one blader is already spelled.', $blader)),
            RejectAliasSuggestionResult::NotAName => $this->failure($io, sprintf('"%s" has no name in it, so it cannot be allowed.', $spelling)),
            RejectAliasSuggestionResult::Rejected, RejectAliasSuggestionResult::AlreadyRejected => throw new \LogicException('Unexpected allowance result.'),
        };
    }

    private function success(SymfonyStyle $io, string $message): int
    {
        $io->success($message);

        return Command::SUCCESS;
    }

    private function note(SymfonyStyle $io, string $message): int
    {
        $io->warning($message);

        return Command::SUCCESS;
    }

    private function failure(SymfonyStyle $io, string $message): int
    {
        $io->error($message);

        return Command::FAILURE;
    }
}
