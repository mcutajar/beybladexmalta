<?php

declare(strict_types=1);

namespace App\Service;

interface FlusherInterface
{
    public function flush(): void;

    /**
     * Flushes the pending changes, then runs $afterFlush inside the same
     * transaction.
     *
     * Either both survive or neither does, so a side effect that records the
     * change cannot outlive a failed write, and a failed side effect undoes
     * the write.
     */
    public function flushThen(callable $afterFlush): void;
}
