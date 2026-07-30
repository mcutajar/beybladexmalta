<?php

declare(strict_types=1);

namespace App\Service;

interface FlusherInterface
{
    public function flush(): void;
}
