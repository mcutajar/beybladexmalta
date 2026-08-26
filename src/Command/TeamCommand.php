<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\ClaimTeamOutcome;
use App\Entity\Player;
use App\Entity\TournamentTeam;
use App\Exception\LedgerWriteException;
use App\Repository\TournamentRepository;
use App\Service\ClaimTeamResult;
use App\Service\TournamentTeamService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The teams of the 2v2 events, from the shell.
 *
 * Two actions rather than two commands, because they are read together: you
 * list an event to see which entrants nobody has claimed, and then you claim
 * one. Only `claim` writes, and it writes a ledger line, because attaching
 * bladers to a team a month after its event changes a historical standing and
 * awards that rank's points.
 */
#[AsCommand(
    name: 'app:team',
    description: 'Lists the entrants of a team event, and records who was in one.',
)]
final class TeamCommand extends Command
{
    private const string CLAIM = 'claim';

    private const string LIST = 'list';

    public function __construct(
        private readonly TournamentTeamService $teams,
        private readonly TournamentRepository $tournaments,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                sprintf('One of: %s, %s', self::CLAIM, self::LIST),
            )
            ->addArgument(
                'names',
                InputArgument::IS_ARRAY,
                'claim: the event, the team, then the bladers in it. list: nothing, or one event.',
            )
            ->addUsage('list')
            ->addUsage("list '11 July Gamebreaker 2v2'")
            ->addUsage("claim '11 July Gamebreaker 2v2' 'JG' 'Kane' 'Steve'")
            ->setHelp(<<<'HELP'
                A 2v2 entrant is a team name and the bladers who were in it, and the
                pairing belongs to the event rather than to the league: Sk3lli was in
                <info>legion</info> on 11 July and <info>Lopez</info> on 19 July.

                A team nobody has identified is <info>unclaimed</info>. It is a record, not a
                gap — the team existed and finished where it finished — so it keeps
                its rank, scores nothing, and waits here. Claiming it writes its
                members' placements and awards that rank's points.

                A claim never creates a blader and never creates a team. If nobody is
                called what you typed, or the event has no entrant spelled that way,
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
                self::CLAIM => $this->claim($io, $names),
                self::LIST => $this->list($io, $names),
                default => $this->unknownAction($io, (string) $input->getArgument('action')),
            };
        } catch (LedgerWriteException $exception) {
            $io->error('The team was left alone because the recovery ledger could not be updated.');

            if ($io->isVerbose()) {
                $io->writeln($exception->getMessage());
            }

            return Command::FAILURE;
        }
    }

    /**
     * @param list<string> $names
     */
    private function claim(SymfonyStyle $io, array $names): int
    {
        if (count($names) < 3) {
            $io->error("Claiming a team takes the event, the team and then the bladers in it: app:team claim '11 July Gamebreaker 2v2' 'JG' 'Kane' 'Steve'.");

            return Command::INVALID;
        }

        [$event, $teamName] = $names;
        $bladers = array_slice($names, 2);

        $outcome = $this->teams->claim($event, $teamName, $bladers);

        return match ($outcome->result) {
            ClaimTeamResult::Claimed => $this->say($io, sprintf(
                '%s finished %s at "%s", and now so %s %s.',
                $teamName,
                $this->ordinal($outcome),
                $event,
                1 === count($outcome->attached) ? 'does' : 'do',
                $this->listed($outcome->attached),
            )),
            ClaimTeamResult::AlreadyRecorded => $this->note($io, sprintf(
                '%s was already down as %s. Nothing was recorded.',
                $teamName,
                $this->listed($this->bladersOf($outcome)),
            )),
            ClaimTeamResult::NoBladers => $this->refuse(
                $io,
                'A claim says who was in the team, so it needs at least one blader.',
            ),
            ClaimTeamResult::TournamentNotFound => $this->refuse($io, sprintf(
                'There is no event called "%s".',
                $event,
            )),
            ClaimTeamResult::TournamentIsAmbiguous => $this->refuse($io, sprintf(
                'More than one event is called "%s", so the name picks neither.',
                $event,
            )),
            ClaimTeamResult::TeamNotFound => $this->refuse($io, sprintf(
                '"%s" had no entrant called "%s". A claim never creates the team — it says who was in one the bracket recorded.',
                $event,
                $teamName,
            )),
            ClaimTeamResult::BladerNotFound => $this->refuse($io, sprintf(
                'There is no blader called "%s", and a claim never creates one.',
                (string) $outcome->blader,
            )),
            ClaimTeamResult::BladerIsAmbiguous => $this->refuse($io, sprintf(
                '"%s" is how more than one blader is already spelled, so it names nobody in particular.',
                (string) $outcome->blader,
            )),
            ClaimTeamResult::BladerAlreadyPlaced => $this->refuse($io, sprintf(
                '%s already finished at "%s" for another team. Nobody places twice in one event.',
                (string) $outcome->blader,
                $event,
            )),
        };
    }

    /**
     * @param list<string> $names
     */
    private function list(SymfonyStyle $io, array $names): int
    {
        if (count($names) > 1) {
            $io->error("Listing takes one event, or none at all: app:team list '11 July Gamebreaker 2v2'.");

            return Command::INVALID;
        }

        if ([] === $names) {
            return $this->show($io, $this->teams->all(), 'No team events have been imported.');
        }

        $events = $this->tournaments->findByTitle($names[0]);

        if (1 !== count($events)) {
            return $this->refuse($io, [] === $events
                ? sprintf('There is no event called "%s".', $names[0])
                : sprintf('More than one event is called "%s", so the name picks neither.', $names[0]));
        }

        return $this->show(
            $io,
            $this->teams->forTournament($events[0]),
            sprintf('"%s" is not a team event.', $events[0]->getTitle()),
        );
    }

    /**
     * @param list<TournamentTeam> $teams
     */
    private function show(SymfonyStyle $io, array $teams, string $whenEmpty): int
    {
        if ([] === $teams) {
            $io->warning($whenEmpty);

            return Command::SUCCESS;
        }

        $io->table(
            ['Event', 'Rank', 'Team', 'Bladers'],
            array_map(
                fn (TournamentTeam $team): array => [
                    $team->getTournament()->getTitle(),
                    $team->getRank(),
                    $team->getName(),
                    $team->isClaimed() ? $this->listed($this->named($team)) : 'unclaimed',
                ],
                $teams,
            ),
        );

        $unclaimed = count(array_filter(
            $teams,
            static fn (TournamentTeam $team): bool => !$team->isClaimed(),
        ));

        $io->text(sprintf(
            '%d %s, %s unclaimed.',
            count($teams),
            1 === count($teams) ? 'team' : 'teams',
            0 === $unclaimed ? 'none' : (string) $unclaimed,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function named(TournamentTeam $team): array
    {
        return array_map(
            static fn (Player $blader): string => $blader->getName(),
            $team->getBladers(),
        );
    }

    /**
     * @return list<string>
     */
    private function bladersOf(ClaimTeamOutcome $outcome): array
    {
        return null === $outcome->team ? [] : $this->named($outcome->team);
    }

    /**
     * @param list<string> $names
     */
    private function listed(array $names): string
    {
        if (count($names) < 2) {
            return implode('', $names);
        }

        return implode(' and ', [
            implode(', ', array_slice($names, 0, -1)),
            $names[count($names) - 1],
        ]);
    }

    private function ordinal(ClaimTeamOutcome $outcome): string
    {
        $rank = $outcome->team?->getRank() ?? 0;

        $suffix = match (true) {
            $rank % 100 >= 11 && $rank % 100 <= 13 => 'th',
            1 === $rank % 10 => 'st',
            2 === $rank % 10 => 'nd',
            3 === $rank % 10 => 'rd',
            default => 'th',
        };

        return $rank.$suffix;
    }

    private function say(SymfonyStyle $io, string $message): int
    {
        $io->success($message);

        return Command::SUCCESS;
    }

    /**
     * Nothing changed, and nothing was wrong either — a replayed line.
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
            '"%s" is not something this does. The actions are: %s, %s.',
            $action,
            self::CLAIM,
            self::LIST,
        ));

        return Command::INVALID;
    }
}
