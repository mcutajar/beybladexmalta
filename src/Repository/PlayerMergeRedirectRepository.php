<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Player;
use App\Entity\PlayerMergeRedirect;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PlayerMergeRedirect> */
final class PlayerMergeRedirectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayerMergeRedirect::class);
    }

    public function save(PlayerMergeRedirect $redirect): void
    {
        $this->getEntityManager()->persist($redirect);
    }

    public function survivorFor(int $oldPlayerId): ?Player
    {
        return $this->find($oldPlayerId)?->getSurvivor();
    }

    /**
     * The blader a merged-away slug now belongs to.
     *
     * The newest row wins. A blader merged into somebody who is then merged
     * again leaves two rows, and `merge()` repoints the older one at the final
     * survivor — but ordering by id makes the answer stable either way.
     */
    public function survivorForSlug(string $slug): ?Player
    {
        return $this->findOneBy(['oldSlug' => $slug], ['oldPlayerId' => 'DESC'])?->getSurvivor();
    }

    /** @return list<PlayerMergeRedirect> */
    public function pointingTo(Player $survivor): array
    {
        return $this->findBy(['survivor' => $survivor]);
    }
}
