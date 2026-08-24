<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChallongeMatch;
use App\Dto\ChallongeParticipant;
use App\Dto\ChallongeRound;
use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeStage;
use App\Dto\ChallongeStageKind;
use App\Dto\ChallongeStanding;
use App\Exception\ChallongeSnapshotReadException;

/**
 * Reads a captured bracket back out of `var/data/challonge/<slug>.json`.
 *
 * The other half of the fetch: the snapshot is written to be read, and this is
 * where a tracked file becomes objects again. Everything downstream — the
 * import, the tournament page, the records board — comes through here and
 * never through Challonge, which is what lets `repeat.sh` replay offline.
 *
 * It reads what the fetch wrote and nothing else. A file from a future version
 * of the format, a field that has changed type, a stage kind we have never
 * heard of: all of those refuse by name rather than being coerced into
 * something plausible. A snapshot is the record, so a reader that guessed
 * would be inventing history.
 *
 * Constructing the objects lives here rather than in a `fromArray()` on each
 * DTO on purpose. Writing one is total — every field is already the right type
 * — while reading one is all validation, and validation is a service's job in
 * this codebase.
 */
class ChallongeSnapshotReader
{
    public function __construct(
        private ChallongeSnapshotFiles $files,
    ) {
    }

    public function read(string $slug): ChallongeSnapshot
    {
        return $this->readFile($this->files->pathFor($slug));
    }

    public function readFile(string $path): ChallongeSnapshot
    {
        if (!is_file($path)) {
            throw new ChallongeSnapshotReadException(sprintf('There is no snapshot at "%s". Capture the bracket first with app:fetch-challonge.', $path));
        }

        $contents = @file_get_contents($path);

        if (false === $contents) {
            throw new ChallongeSnapshotReadException(sprintf('The snapshot at "%s" could not be read.', $path));
        }

        try {
            $decoded = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ChallongeSnapshotReadException(sprintf('The snapshot at "%s" is not valid JSON: %s', $path, $exception->getMessage()), previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new ChallongeSnapshotReadException(sprintf('The snapshot at "%s" holds %s where an object was expected.', $path, get_debug_type($decoded)));
        }

        return $this->fromArray($decoded, $path);
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param string               $source   what to name in a message — the file it came from
     */
    public function fromArray(array $snapshot, string $source): ChallongeSnapshot
    {
        $fields = $this->fieldsFor($source);

        $version = $fields->requiredIntAt($snapshot, 'version');

        /*
         * A snapshot written by a later version of the app may say things this
         * one would read wrongly, so the version is checked before anything
         * else is touched.
         */
        if (ChallongeSnapshot::VERSION !== $version) {
            throw new ChallongeSnapshotReadException(sprintf('The snapshot at "%s" is version %d, and this application reads version %d.', $source, $version, ChallongeSnapshot::VERSION));
        }

        $tournament = $fields->arrayAt($snapshot, 'tournament');

        return new ChallongeSnapshot(
            slug: $fields->requiredStringAt($snapshot, 'slug'),
            sourceUrl: $fields->requiredStringAt($snapshot, 'source_url'),
            fetchedAt: $this->fetchedAt($fields->requiredStringAt($snapshot, 'fetched_at'), $fields),
            tournamentId: $fields->requiredIntAt($tournament, 'id'),
            tournamentType: $fields->requiredStringAt($tournament, 'type'),
            tournamentState: $fields->requiredStringAt($tournament, 'state'),
            isTeamTournament: $fields->boolAt($tournament, 'is_team'),
            stages: array_map(
                fn (array $stage): ChallongeStage => $this->stage($stage, $fields),
                $fields->arrayListAt($snapshot, 'stages'),
            ),
        );
    }

    private function fetchedAt(string $timestamp, ChallongeFields $fields): \DateTimeImmutable
    {
        $fetchedAt = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $timestamp);

        if (false === $fetchedAt) {
            throw $fields->refuse(sprintf('"%s" is not a moment in time.', $timestamp));
        }

        return $fetchedAt;
    }

    /**
     * @param array<string, mixed> $stage
     */
    private function stage(array $stage, ChallongeFields $fields): ChallongeStage
    {
        $kind = $fields->requiredStringAt($stage, 'kind');

        return new ChallongeStage(
            kind: ChallongeStageKind::tryFrom($kind) ?? throw $fields->refuse(sprintf('"%s" is not a kind of stage. The kinds are: %s.', $kind, implode(', ', array_map(static fn (ChallongeStageKind $known): string => $known->value, ChallongeStageKind::cases())))),
            name: $fields->nonEmptyStringAt($stage, 'name'),
            format: $fields->requiredStringAt($stage, 'format'),
            rounds: array_map(
                static fn (array $round): ChallongeRound => new ChallongeRound(
                    number: $fields->requiredIntAt($round, 'number'),
                    title: $fields->nonEmptyStringAt($round, 'title'),
                ),
                $fields->arrayListAt($stage, 'rounds'),
            ),
            participants: array_map(
                static fn (array $participant): ChallongeParticipant => new ChallongeParticipant(
                    id: $fields->requiredIntAt($participant, 'id'),
                    participantId: $fields->intAt($participant, 'participant_id'),
                    seed: $fields->intAt($participant, 'seed'),
                    name: $fields->requiredStringAt($participant, 'name'),
                ),
                $fields->arrayListAt($stage, 'participants'),
            ),
            matches: array_map(
                fn (array $match): ChallongeMatch => $this->match($match, $fields),
                $fields->arrayListAt($stage, 'matches'),
            ),
            standings: array_map(
                static fn (array $standing): ChallongeStanding => new ChallongeStanding(
                    rank: $fields->requiredIntAt($standing, 'rank'),
                    name: $fields->nonEmptyStringAt($standing, 'name'),
                    challongeUser: $fields->nonEmptyStringAt($standing, 'challonge_user'),
                    labels: $fields->stringListAt($standing, 'labels'),
                    matchIds: $fields->integerListAt($standing, 'match_ids'),
                    columns: $fields->stringMapAt($standing, 'columns'),
                ),
                $fields->arrayListAt($stage, 'standings'),
            ),
        );
    }

    /**
     * @param array<string, mixed> $match
     */
    private function match(array $match, ChallongeFields $fields): ChallongeMatch
    {
        return new ChallongeMatch(
            id: $fields->requiredIntAt($match, 'id'),
            round: $fields->requiredIntAt($match, 'round'),
            identifier: $fields->nonEmptyStringAt($match, 'identifier'),
            state: $fields->requiredStringAt($match, 'state'),
            player1Id: $fields->intAt($match, 'player1'),
            player2Id: $fields->intAt($match, 'player2'),
            games: array_map(
                static fn (array $game): array => $fields->integersIn($game, 'games'),
                $fields->arrayListAt($match, 'games'),
            ),
            score: $fields->integerListAt($match, 'score'),
            winnerId: $fields->intAt($match, 'winner'),
            loserId: $fields->intAt($match, 'loser'),
            forfeited: $fields->boolAt($match, 'forfeited'),
            consolation: $fields->boolAt($match, 'consolation'),
        );
    }

    private function fieldsFor(string $source): ChallongeFields
    {
        return new ChallongeFields(
            'snapshot field',
            static fn (string $problem): \Throwable => new ChallongeSnapshotReadException(sprintf('%s The snapshot at "%s" cannot be read.', $problem, $source)),
        );
    }
}
