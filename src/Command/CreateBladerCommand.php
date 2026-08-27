<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\LedgerWriteException;
use App\Service\BladerService;
use App\Service\CreateBladerResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The replay half of a blader created on the import screen.
 *
 * It exists because `var/data/imports/*.txt` stops at ten and most of the
 * bladers that screen creates finished eleventh or worse — archived, unscored,
 * and named nowhere else in `repeat.sh`. Replaying a league without this line
 * would rebuild their matches and attach them to nobody.
 *
 * Nobody needs to type it. It is written by the import screen, it replays
 * before the aliases that spell its blader and the import that scores them,
 * and running it twice is a no-op.
 */
#[AsCommand(
    name: 'app:create-blader',
    description: 'Puts a blader on record, so an import that only archives them can be replayed.',
)]
final class CreateBladerCommand extends Command
{
    public function __construct(
        private readonly BladerService $bladers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'The blader, spelled the way the league will hold them')
            ->addUsage("'Sk3lli'")
            ->setHelp(<<<'HELP'
                Creates a blader and nothing else — no payment, no result, no alias.

                A spelling the league already answers to is reported and left alone, so
                a second replay of <info>repeat.sh</info> writes nothing.

                This never files an alias. Saying that a spelling belongs to somebody
                already on record is <info>app:alias add</info>, and saying it belongs to nobody
                yet is this.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = trim((string) $input->getArgument('name'));

        try {
            $result = $this->bladers->create($name);
        } catch (LedgerWriteException $exception) {
            $io->error('The blader was not created because the recovery ledger could not be updated.');

            if ($io->isVerbose()) {
                $io->writeln($exception->getMessage());
            }

            return Command::FAILURE;
        }

        return match ($result) {
            CreateBladerResult::Created => $this->said($io, sprintf('%s is on record.', $name)),

            CreateBladerResult::AlreadyOnRecord => $this->said($io, sprintf(
                'The league already knows a blader called "%s". Nothing to do.',
                $name,
            )),

            CreateBladerResult::NotAName => $this->refuse($io, 'A blader needs a name.'),
        };
    }

    private function said(SymfonyStyle $io, string $message): int
    {
        $io->success($message);

        return Command::SUCCESS;
    }

    private function refuse(SymfonyStyle $io, string $message): int
    {
        $io->error($message);

        return Command::INVALID;
    }
}
