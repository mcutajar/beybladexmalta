<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Decides whether a submitted admin passphrase is the configured one.
 *
 * The comparison itself is one line; the reason this is a service is the case
 * hash_equals() gets wrong. An unconfigured passphrase is the empty string, and
 * hash_equals('', '') is true -- so a deployment that never received
 * PAYMENTS_ADMIN_PASSPHRASE would admit anyone who submitted an empty form
 * field. That used to be unreachable because the passphrases were baked into
 * the production image at build time. They are supplied at run time now, which
 * makes "the variable is missing" a state the app can actually be in.
 */
class AdminPassphraseVerifier
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function matches(string $configured, string $submitted): bool
    {
        if ('' === $configured) {
            $this->logger->critical(
                'An admin passphrase is not configured, so every attempt is refused.',
            );

            return false;
        }

        return hash_equals($configured, $submitted);
    }
}
