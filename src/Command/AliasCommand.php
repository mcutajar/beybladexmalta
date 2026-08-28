<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\AliasResolution;
use App\Dto\AliasSuggestion;
use App\Entity\PlayerAlias;
use App\Entity\PlayerAliasSource;
use App\Exception\LedgerWriteException;
use App\Service\AddAliasResult;
use App\Service\AliasRejectionService;
use App\Service\AliasService;
use App\Service\RejectAliasSuggestionResult;
use App\Service\RemoveAliasResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The alias table, from the shell.
 *
 * One command with three actions rather than three commands, because the
 * three are read together: you list to see what is there, add the one that is
 * missing, and remove the one that was wrong. `add` is the only one that
 * writes a ledger line worth replaying, and it is written under the blader's
 * stored name so a rebuilt database files it against the same person.
 */
#[AsCommand(
    name: 'app:alias',
    description: 'Records which Challonge spellings belong to which blader.',
)]
final class AliasCommand extends Command
{
    private const string ADD = 'add';

    private const string LIST = 'list';

    private const string REJECT = 'reject';

    private const string ALLOW = 'allow';

    private const string REMOVE = 'remove';

    public function __construct(
        private readonly AliasService $aliases,
        private readonly AliasRejectionService $rejections,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                sprintf('One of: %s, %s, %s, %s, %s', self::ADD, self::LIST, self::REMOVE, self::REJECT, self::ALLOW),
            )
            ->addArgument(
                'names',
                InputArgument::IS_ARRAY,
                'add/reject/allow: the blader, then the spelling. remove: the spelling. list: nothing, or one blader.',
            )
            ->addOption(
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Where the alias came from: %s', implode(', ', PlayerAliasSource::names())),
                PlayerAliasSource::Manual->value,
            )
            ->addUsage("add 'Il-Karm' 'KARM'")
            ->addUsage('list')
            ->addUsage("list 'Il-Karm'")
            ->addUsage("remove 'KARM'")
            ->addUsage("reject 'Steve' 'Steve V.'")
            ->addUsage("allow 'Steve' 'Steve V.'")
            ->setHelp(<<<'HELP'
                Over two hundred Challonge display names belong to about seventy-six
                bladers. Case, punctuation and the "(invitation pending)" suffix are
                folded away automatically; everything else is a row in this table,
                because <info>Obelix</info> and <info>Obelisk</info> are two letters apart and are two people.

                An alias never creates a blader. If nobody is called what you named,
                the command says so and stops.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $names */
        $names = array_values(array_map(strval(...), (array) $input->getArgument('names')));

        try {
            return match ((string) $input->getArgument('action')) {
                self::ADD => $this->add($io, $names, (string) $input->getOption('source')),
                self::LIST => $this->list($io, $names),
                self::REMOVE => $this->remove($io, $names),
                self::REJECT => $this->reject($io, $names),
                self::ALLOW => $this->allow($io, $names),
                default => $this->unknownAction($io, (string) $input->getArgument('action')),
            };
        } catch (LedgerWriteException $exception) {
            $io->error('The alias table was left alone because the recovery ledger could not be updated.');

            if ($io->isVerbose()) {
                $io->writeln($exception->getMessage());
            }

            return Command::FAILURE;
        }
    }

    /**
     * @param list<string> $names
     */
    private function add(SymfonyStyle $io, array $names, string $source): int
    {
        if (2 !== count($names)) {
            $io->error("Adding an alias takes the blader and then the spelling: app:alias add 'Il-Karm' 'KARM'.");

            return Command::INVALID;
        }

        $recorded = PlayerAliasSource::tryFrom($source);

        if (null === $recorded) {
            $io->error(sprintf('"%s" is not a source. The sources are: %s.', $source, implode(', ', PlayerAliasSource::names())));

            return Command::INVALID;
        }

        [$bladerName, $spelling] = $names;

        return match ($this->aliases->add($bladerName, $spelling, $recorded)) {
            AddAliasResult::Added => $this->say($io, sprintf(
                '%s answers to "%s".',
                $this->named($bladerName),
                $spelling,
            )),
            AddAliasResult::AlreadyRecorded => $this->note($io, sprintf(
                '"%s" was already on file against %s.',
                $spelling,
                $this->named($bladerName),
            )),
            AddAliasResult::IsTheirOwnName => $this->note($io, sprintf(
                '"%s" is what %s is called, so it already resolves. Nothing was recorded.',
                $spelling,
                $this->named($bladerName),
            )),
            AddAliasResult::IsAnotherBladersName => $this->refuse($io, sprintf(
                '"%s" is %s\'s own name. If they and %s are one person, that is a merge rather than an alias.',
                $spelling,
                $this->named($spelling),
                $this->named($bladerName),
            )),
            AddAliasResult::TakenByAnotherBlader => $this->refuse($io, sprintf(
                '"%s" already points at %s. Remove it first if that is wrong.',
                $spelling,
                $this->named($spelling),
            )),
            AddAliasResult::BladerNotFound => $this->noBlader($io, $bladerName, sprintf(
                'There is no blader called "%s", and an alias never creates one.',
                $bladerName,
            )),
            AddAliasResult::BladerIsAmbiguous => $this->noBlader($io, $bladerName, sprintf(
                '"%s" is how more than one blader is already spelled, so it names nobody in particular.',
                $bladerName,
            )),
            AddAliasResult::NotAName => $this->refuse($io, sprintf(
                '"%s" has no name in it once case, punctuation and "(invitation pending)" are taken out.',
                $spelling,
            )),
        };
    }

    /**
     * @param list<string> $names
     */
    private function list(SymfonyStyle $io, array $names): int
    {
        if (count($names) > 1) {
            $io->error("Listing takes one blader, or none at all: app:alias list 'Il-Karm'.");

            return Command::INVALID;
        }

        $blader = null;

        if (1 === count($names)) {
            $resolution = $this->aliases->whoCouldThisBe($names[0]);
            $blader = $resolution->player;

            if (null === $blader) {
                /*
                 * Listing creates nothing, so it does not get the message
                 * about aliases never creating a blader. It says what is
                 * wrong with the name it was handed and offers the shortlist.
                 */
                return $this->noBlader($io, $names[0], $resolution->isAmbiguous()
                    ? sprintf('"%s" is how more than one blader is already spelled.', $names[0])
                    : sprintf('There is no blader called "%s".', $names[0]));
            }
        }

        $aliases = $this->aliases->all($blader);

        if ([] === $aliases) {
            $io->warning(null === $blader
                ? 'No aliases are on file. Every Challonge spelling has to match a blader letter for letter.'
                : sprintf('%s has no aliases on file.', $blader->getName()));

            return Command::SUCCESS;
        }

        $io->table(
            ['Blader', 'Spelling', 'Resolves as', 'Source', 'Recorded'],
            array_map(
                static fn (PlayerAlias $alias): array => [
                    $alias->getPlayer()->getName(),
                    $alias->getAlias(),
                    $alias->getNormalised(),
                    $alias->getSource()->value,
                    $alias->getRecordedAt()->format('Y-m-d'),
                ],
                $aliases,
            ),
        );

        $io->text(sprintf(
            '%d %s on file.',
            count($aliases),
            1 === count($aliases) ? 'alias' : 'aliases',
        ));

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $names
     */
    private function remove(SymfonyStyle $io, array $names): int
    {
        if (1 !== count($names)) {
            $io->error("Removing an alias takes the spelling on file: app:alias remove 'KARM'.");

            return Command::INVALID;
        }

        $spelling = $names[0];

        return match ($this->aliases->remove($spelling)) {
            RemoveAliasResult::Removed => $this->say($io, sprintf('"%s" no longer resolves to anybody.', $spelling)),
            RemoveAliasResult::NotFound => $this->note($io, sprintf('"%s" was not on file. Nothing was removed.', $spelling)),
            RemoveAliasResult::NotAName => $this->refuse($io, sprintf('"%s" has no name in it, so it cannot be on file.', $spelling)),
        };
    }

    /** @param list<string> $names */
    private function reject(SymfonyStyle $io, array $names): int
    {
        if (2 !== count($names)) {
            $io->error("Rejecting a suggestion takes the proposed blader and then the spelling: app:alias reject 'Steve' 'Steve V.'.");

            return Command::INVALID;
        }

        [$blader, $spelling] = $names;

        return match ($this->rejections->reject($blader, $spelling)) {
            RejectAliasSuggestionResult::Rejected => $this->say($io, sprintf('%s will no longer be suggested for "%s".', $this->named($blader), $spelling)),
            RejectAliasSuggestionResult::AlreadyRejected => $this->note($io, sprintf('%s was already rejected for "%s".', $this->named($blader), $spelling)),
            RejectAliasSuggestionResult::BladerNotFound => $this->refuse($io, sprintf('There is no blader called "%s".', $blader)),
            RejectAliasSuggestionResult::BladerIsAmbiguous => $this->refuse($io, sprintf('"%s" is how more than one blader is already spelled.', $blader)),
            RejectAliasSuggestionResult::NotAName => $this->refuse($io, sprintf('"%s" has no name in it, so no suggestion can be rejected.', $spelling)),
            RejectAliasSuggestionResult::Allowed, RejectAliasSuggestionResult::NotRejected => throw new \LogicException('Unexpected rejection result.'),
        };
    }

    /** @param list<string> $names */
    private function allow(SymfonyStyle $io, array $names): int
    {
        if (2 !== count($names)) {
            $io->error("Allowing a suggestion takes the proposed blader and then the spelling: app:alias allow 'Steve' 'Steve V.'.");

            return Command::INVALID;
        }

        [$blader, $spelling] = $names;

        return match ($this->rejections->allow($blader, $spelling)) {
            RejectAliasSuggestionResult::Allowed => $this->say($io, sprintf('%s may be suggested for "%s" again.', $this->named($blader), $spelling)),
            RejectAliasSuggestionResult::NotRejected => $this->note($io, sprintf('%s was not rejected for "%s". Nothing was removed.', $this->named($blader), $spelling)),
            RejectAliasSuggestionResult::BladerNotFound => $this->refuse($io, sprintf('There is no blader called "%s".', $blader)),
            RejectAliasSuggestionResult::BladerIsAmbiguous => $this->refuse($io, sprintf('"%s" is how more than one blader is already spelled.', $blader)),
            RejectAliasSuggestionResult::NotAName => $this->refuse($io, sprintf('"%s" has no name in it, so it cannot be allowed.', $spelling)),
            RejectAliasSuggestionResult::Rejected, RejectAliasSuggestionResult::AlreadyRejected => throw new \LogicException('Unexpected allowance result.'),
        };
    }

    /**
     * The refusal the whole ticket is about, printed with the shortlist the
     * resolver came back with. Never acted on — the person reading it decides,
     * and then types the alias.
     *
     * The sentence is the caller's, because a name that reaches nobody and a
     * name that reaches two people are different problems and only one of them
     * is about the league not knowing somebody.
     */
    private function noBlader(SymfonyStyle $io, string $name, string $problem): int
    {
        $io->error($problem);

        $this->offer($io, $this->aliases->whoCouldThisBe($name));

        return Command::FAILURE;
    }

    private function offer(SymfonyStyle $io, AliasResolution $resolution): void
    {
        if ([] === $resolution->suggestions) {
            return;
        }

        $io->text('Did you mean:');
        $io->listing(array_map(
            static fn (AliasSuggestion $suggestion): string => sprintf(
                '%s — %s',
                $suggestion->player->getName(),
                $suggestion->because(),
            ),
            $resolution->suggestions,
        ));
    }

    /**
     * The blader a spelling reaches, under the name the database holds — or
     * the spelling as typed, when it reaches nobody or more than one. Only
     * ever used to phrase a message, which is why it is allowed to read the
     * tables again rather than thread an index through: it runs once, after
     * the write, on the path a person is watching.
     */
    private function named(string $name): string
    {
        return $this->aliases->whoCouldThisBe($name)->player?->getName() ?? $name;
    }

    private function say(SymfonyStyle $io, string $message): int
    {
        $io->success($message);

        return Command::SUCCESS;
    }

    /**
     * Nothing changed, and nothing was wrong either — a replayed line, or a
     * spelling that already resolved on its own.
     */
    private function note(SymfonyStyle $io, string $message): int
    {
        $io->warning($message);

        return Command::SUCCESS;
    }

    private function refuse(SymfonyStyle $io, string $message): int
    {
        $io->error($message);

        return Command::FAILURE;
    }

    private function unknownAction(SymfonyStyle $io, string $action): int
    {
        $io->error(sprintf(
            '"%s" is not something this does. The actions are: %s, %s, %s, %s, %s.',
            $action,
            self::ADD,
            self::LIST,
            self::REMOVE,
            self::REJECT,
            self::ALLOW,
        ));

        return Command::INVALID;
    }
}
