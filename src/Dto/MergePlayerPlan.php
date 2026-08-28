<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Player;
use App\Entity\PlayerAlias;
use App\Entity\PlayerAliasRejection;
use App\Entity\PlayerMergeRedirect;
use App\Entity\SeasonRegistration;
use App\Entity\TournamentParticipant;
use App\Entity\TournamentResult;
use App\Entity\TournamentTeamMember;
use App\Service\MergePlayerResult;

final readonly class MergePlayerPlan
{
    /**
     * @param list<TournamentResult>      $results
     * @param list<TournamentParticipant> $participants
     * @param list<SeasonRegistration>    $registrations
     * @param list<TournamentTeamMember>  $teamMemberships
     * @param list<PlayerAlias>           $aliases
     * @param list<PlayerAliasRejection>  $rejections
     * @param list<PlayerAliasRejection>  $reconciledRejections
     * @param list<PlayerMergeRedirect>   $existingRedirects
     */
    public function __construct(
        public MergePlayerResult $result,
        public ?Player $from = null,
        public ?Player $into = null,
        public array $results = [],
        public array $participants = [],
        public array $registrations = [],
        public array $teamMemberships = [],
        public array $aliases = [],
        public array $rejections = [],
        public array $reconciledRejections = [],
        public array $existingRedirects = [],
        public bool $addLosingNameAlias = false,
        public ?string $detail = null,
    ) {
    }

    public function isReady(): bool
    {
        return MergePlayerResult::Ready === $this->result;
    }

    public function oldProfilePath(string $seasonSlug = '{slug}'): ?string
    {
        return null === $this->from?->getId() ? null : sprintf('/season/%s/player/%d', $seasonSlug, $this->from->getId());
    }
}
