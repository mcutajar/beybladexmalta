<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChallongeMatch;
use App\Dto\ChallongeScoreCorrection;
use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeStage;
use App\Dto\ChallongeStanding;
use App\Exception\ChallongeCorrectionReadException;

/**
 * Applies facts the organiser confirmed but the league cannot fix upstream.
 *
 * A snapshot is still a transcription: `var/data/challonge/<slug>.json`
 * records exactly what Challonge served. A correction lives beside it in the
 * `corrections/` directory and is applied only to the in-memory interpretation
 * used by the preview and archive. Both the mistaken and corrected values are
 * recorded, so a later, different edit on Challonge fails loudly instead of
 * being mistaken for the old typo.
 */
final class ChallongeCorrections
{
    /** @var array<string, list<ChallongeScoreCorrection>> */
    private array $loaded = [];

    /** @var array<string, true> */
    private array $read = [];

    public function __construct(
        private ChallongeSnapshotFiles $files,
    ) {
    }

    public function apply(ChallongeSnapshot $snapshot): ChallongeSnapshot
    {
        $corrections = $this->forSlug($snapshot->slug);

        if ([] === $corrections) {
            return $snapshot;
        }

        $stages = $snapshot->stages;

        foreach ($corrections as $correction) {
            $stage = $stages[$correction->stage] ?? throw $this->problem($snapshot->slug, sprintf('names stage %d, but that stage does not exist', $correction->stage));

            $stages[$correction->stage] = $this->correctStage($snapshot->slug, $stage, $correction);
        }

        return new ChallongeSnapshot(
            slug: $snapshot->slug,
            sourceUrl: $snapshot->sourceUrl,
            fetchedAt: $snapshot->fetchedAt,
            tournamentId: $snapshot->tournamentId,
            tournamentType: $snapshot->tournamentType,
            tournamentState: $snapshot->tournamentState,
            isTeamTournament: $snapshot->isTeamTournament,
            stages: $stages,
        );
    }

    private function correctStage(
        string $slug,
        ChallongeStage $stage,
        ChallongeScoreCorrection $correction,
    ): ChallongeStage {
        $matches = [];
        $foundMatch = false;

        foreach ($stage->matches as $match) {
            if ($match->id !== $correction->matchId) {
                $matches[] = $match;

                continue;
            }

            $matches[] = $this->correctMatch($slug, $match, $correction);
            $foundMatch = true;
        }

        if (!$foundMatch) {
            throw $this->problem($slug, sprintf('names match %d, but that stage does not contain it', $correction->matchId));
        }

        $standings = $stage->standings;

        foreach ($correction->standings as $change) {
            $standings = $this->correctStanding($slug, $standings, $change);
        }

        return new ChallongeStage(
            kind: $stage->kind,
            name: $stage->name,
            format: $stage->format,
            rounds: $stage->rounds,
            participants: $stage->participants,
            matches: $matches,
            standings: $standings,
        );
    }

    private function correctMatch(
        string $slug,
        ChallongeMatch $match,
        ChallongeScoreCorrection $correction,
    ): ChallongeMatch {
        $alreadyCorrect = $match->games === $correction->toGames && $match->score === $correction->toScore;
        $isTheRecordedMistake = $match->games === $correction->fromGames && $match->score === $correction->fromScore;

        if (!$alreadyCorrect && !$isTheRecordedMistake) {
            throw $this->problem($slug, sprintf('expected match %d to say %s, but it says %s', $match->id, $this->scoreline($correction->fromScore), $this->scoreline($match->score)));
        }

        return new ChallongeMatch(
            id: $match->id,
            round: $match->round,
            identifier: $match->identifier,
            state: $match->state,
            player1Id: $match->player1Id,
            player2Id: $match->player2Id,
            games: $correction->toGames,
            score: $correction->toScore,
            winnerId: $match->winnerId,
            loserId: $match->loserId,
            forfeited: $match->forfeited,
            consolation: $match->consolation,
        );
    }

    /**
     * @param list<ChallongeStanding>                                       $standings
     * @param array{name: string, column: string, from: string, to: string} $change
     *
     * @return list<ChallongeStanding>
     */
    private function correctStanding(string $slug, array $standings, array $change): array
    {
        $corrected = [];
        $found = false;

        foreach ($standings as $standing) {
            if ($standing->name !== $change['name']) {
                $corrected[] = $standing;

                continue;
            }

            $current = $standing->columns[$change['column']] ?? null;

            if ($current !== $change['from'] && $current !== $change['to']) {
                throw $this->problem($slug, sprintf('expected %s\'s %s standing to say %s, but it says %s', $change['name'], $change['column'], $change['from'], null === $current ? 'nothing' : $current));
            }

            $columns = $standing->columns;
            $columns[$change['column']] = $change['to'];

            $corrected[] = new ChallongeStanding(
                rank: $standing->rank,
                name: $standing->name,
                challongeUser: $standing->challongeUser,
                labels: $standing->labels,
                matchIds: $standing->matchIds,
                columns: $columns,
            );
            $found = true;
        }

        if (!$found) {
            throw $this->problem($slug, sprintf('names a standing for %s, but that stage does not contain it', $change['name']));
        }

        return $corrected;
    }

    /** @return list<ChallongeScoreCorrection> */
    private function forSlug(string $slug): array
    {
        if (isset($this->read[$slug])) {
            return $this->loaded[$slug] ?? [];
        }

        $this->read[$slug] = true;
        $path = sprintf('%s/corrections/%s.json', $this->files->directory(), $slug);

        if (!is_file($path)) {
            return [];
        }

        $json = @file_get_contents($path);

        if (false === $json) {
            throw $this->problem($slug, sprintf('could not be read from "%s"', $path));
        }

        try {
            $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw $this->problem($slug, 'is not valid JSON: '.$exception->getMessage(), $exception);
        }

        if (!is_array($decoded)) {
            throw $this->problem($slug, 'does not contain a JSON object');
        }

        $fields = new ChallongeFields(
            'correction field',
            fn (string $message): ChallongeCorrectionReadException => $this->problem($slug, $message),
        );

        if ($fields->requiredStringAt($decoded, 'bracket') !== $slug) {
            throw $this->problem($slug, 'names a different bracket');
        }

        $corrections = [];

        foreach ($fields->arrayListAt($decoded, 'corrections') as $number => $record) {
            $corrections[] = $this->readCorrection($slug, $number + 1, $record, $fields);
        }

        return $this->loaded[$slug] = $corrections;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function readCorrection(
        string $slug,
        int $number,
        array $record,
        ChallongeFields $fields,
    ): ChallongeScoreCorrection {
        $from = $fields->arrayAt($record, 'from');
        $to = $fields->arrayAt($record, 'to');
        $standings = [];

        foreach ($fields->arrayListAt($record, 'standings') as $standing) {
            $standings[] = [
                'name' => $fields->requiredStringAt($standing, 'name'),
                'column' => $fields->requiredStringAt($standing, 'column'),
                'from' => $fields->requiredStringAt($standing, 'from'),
                'to' => $fields->requiredStringAt($standing, 'to'),
            ];
        }

        $correction = new ChallongeScoreCorrection(
            stage: $fields->requiredIntAt($record, 'stage'),
            matchId: $fields->requiredIntAt($record, 'match'),
            fromGames: $this->games($fields, $from, 'from.games'),
            fromScore: $this->score($fields, $from, 'from.score'),
            toGames: $this->games($fields, $to, 'to.games'),
            toScore: $this->score($fields, $to, 'to.score'),
            standings: $standings,
            reason: $fields->requiredStringAt($record, 'reason'),
        );

        if ([] === $correction->standings) {
            throw $this->problem($slug, sprintf('correction %d names no derived standings cells', $number));
        }

        return $correction;
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return list<list<int>>
     */
    private function games(ChallongeFields $fields, array $source, string $key): array
    {
        $games = [];

        foreach ($fields->arrayListAt($source, 'games') as $game) {
            $games[] = $this->pair($fields->integersIn($game, $key), $fields, $key);
        }

        return $games;
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return list<int>
     */
    private function score(ChallongeFields $fields, array $source, string $key): array
    {
        return $this->pair($fields->integerListAt($source, 'score'), $fields, $key);
    }

    /**
     * @param list<int> $values
     *
     * @return list<int>
     */
    private function pair(array $values, ChallongeFields $fields, string $key): array
    {
        if (2 !== count($values)) {
            throw $fields->refuse(sprintf('The correction field "%s" must hold exactly two scores.', $key));
        }

        return $values;
    }

    /** @param list<int> $score */
    private function scoreline(array $score): string
    {
        return [] === $score ? 'no score' : implode('-', $score);
    }

    private function problem(
        string $slug,
        string $problem,
        ?\Throwable $previous = null,
    ): ChallongeCorrectionReadException {
        return new ChallongeCorrectionReadException(
            sprintf('The score correction for "%s" %s.', $slug, rtrim($problem, '.')),
            previous: $previous,
        );
    }
}
