<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\InvalidChallongeSlugException;
use App\Service\ChallongeSnapshotFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class ChallongeSnapshotFilesTest extends TestCase
{
    private ChallongeSnapshotFiles $files;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn('/app');

        $this->files = new ChallongeSnapshotFiles($kernel);
    }

    public function testASnapshotIsFiledUnderItsSlug(): void
    {
        self::assertSame('/app/var/data/challonge/9yuqg2pi.json', $this->files->pathFor('9yuqg2pi'));
    }

    /**
     * The slug becomes a path here and nowhere else, so this is where it stops
     * being trusted. `ChallongeUrl` guarantees the shape of one read from a
     * link, but a reader takes a bare string, and by phase 2 that string
     * plausibly comes from a request.
     */
    public function testItRefusesASlugThatWouldLeaveTheDirectory(): void
    {
        $this->expectException(InvalidChallongeSlugException::class);
        $this->expectExceptionMessage('"../../../etc/passwd" is not a bracket slug.');

        $this->files->pathFor('../../../etc/passwd');
    }

    public function testItRefusesAnEmptySlug(): void
    {
        $this->expectException(InvalidChallongeSlugException::class);

        $this->files->pathFor('');
    }
}
