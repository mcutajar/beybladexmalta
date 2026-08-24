<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AdminPassphraseVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AdminPassphraseVerifier::class)]
final class AdminPassphraseVerifierTest extends TestCase
{
    public function testItAcceptsTheConfiguredPassphrase(): void
    {
        self::assertTrue($this->verifier()->matches('correct horse', 'correct horse'));
    }

    public function testItRefusesAnythingElse(): void
    {
        self::assertFalse($this->verifier()->matches('correct horse', 'battery staple'));
    }

    /**
     * The reason this class exists. hash_equals('', '') is true, so an admin
     * form on a deployment that never received its passphrase would let an
     * empty field through. The passphrases arrive as run-time environment
     * variables now rather than baked into the image, so a missing one has to
     * lock the door rather than leave it open.
     */
    public function testAnUnconfiguredPassphraseRefusesAnEmptySubmission(): void
    {
        self::assertFalse($this->verifier()->matches('', ''));
    }

    public function testAnUnconfiguredPassphraseRefusesEverythingElseToo(): void
    {
        self::assertFalse($this->verifier()->matches('', 'anything at all'));
    }

    private function verifier(): AdminPassphraseVerifier
    {
        return new AdminPassphraseVerifier(new NullLogger());
    }
}
