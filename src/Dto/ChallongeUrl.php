<?php

declare(strict_types=1);

namespace App\Dto;

use App\Exception\InvalidChallongeUrlException;

/**
 * A pasted Challonge bracket URL, reduced to the one route that answers a
 * plain HTTP client.
 *
 * The URLs in repeat.sh come in three shapes — `challonge.com/<slug>`,
 * `<subdomain>.challonge.com/<slug>` and the `challonge.com/vi/<slug>` invite
 * links. Every one of them returns 403 to anything that is not a browser, and
 * `/<slug>/module` returns 200 to everything, so all three normalise to that.
 */
final class ChallongeUrl
{
    private const HOST = 'challonge.com';

    /**
     * Challonge permits letters, digits and underscores in a custom URL; the
     * hyphen is here because a pasted link is not always a custom URL.
     */
    private const SLUG_PATTERN = '/^[A-Za-z0-9_-]+$/';

    /**
     * The invite links carry the slug one segment deeper. `/vi/<slug>` and
     * `/<slug>` are the same tournament — both answer on `/module`.
     */
    private const INVITE_SEGMENT = 'vi';

    private function __construct(
        public readonly string $slug,
        public readonly ?string $subdomain,
    ) {
    }

    public static function fromString(string $url): self
    {
        $candidate = trim($url);

        if ('' === $candidate) {
            throw new InvalidChallongeUrlException('No Challonge bracket URL was given.');
        }

        // People paste "challonge.com/abc123" as often as the full thing.
        if (1 !== preg_match('#^https?://#i', $candidate)) {
            $candidate = 'https://'.$candidate;
        }

        $parts = parse_url($candidate);

        if (false === $parts || !isset($parts['host'])) {
            throw new InvalidChallongeUrlException(sprintf('"%s" is not a URL.', $url));
        }

        return new self(
            slug: self::readSlug($parts['path'] ?? '', $url),
            subdomain: self::readSubdomain(strtolower($parts['host']), $url),
        );
    }

    /**
     * The URL to fetch. `show_standings=1` is part of it rather than something
     * a caller adds, because a Swiss-only bracket renders no standings table
     * without it and the omission is invisible until the parse comes up empty.
     */
    public function moduleUrl(): string
    {
        return sprintf('https://%s/%s/module?show_standings=1', $this->host(), $this->slug);
    }

    /**
     * The bracket as a person would open it.
     */
    public function bracketUrl(): string
    {
        return sprintf('https://%s/%s', $this->host(), $this->slug);
    }

    /**
     * The subdomain is kept rather than resolved: `challonge.com/<slug>` does
     * redirect to the subdomain that owns it, but the 301 drops the query
     * string, so following it would silently cost us the standings.
     */
    private function host(): string
    {
        return null === $this->subdomain
            ? self::HOST
            : $this->subdomain.'.'.self::HOST;
    }

    private static function readSubdomain(string $host, string $url): ?string
    {
        if (self::HOST === $host || 'www.'.self::HOST === $host) {
            return null;
        }

        if (!str_ends_with($host, '.'.self::HOST)) {
            throw new InvalidChallongeUrlException(sprintf('"%s" is not a Challonge URL.', $url));
        }

        return substr($host, 0, -strlen('.'.self::HOST));
    }

    private static function readSlug(string $path, string $url): string
    {
        $segments = array_values(array_filter(
            explode('/', $path),
            static fn (string $segment): bool => '' !== $segment,
        ));

        if (self::INVITE_SEGMENT === ($segments[0] ?? null)) {
            array_shift($segments);
        }

        $slug = $segments[0] ?? '';

        if (1 !== preg_match(self::SLUG_PATTERN, $slug)) {
            throw new InvalidChallongeUrlException(sprintf('"%s" names no Challonge bracket.', $url));
        }

        return $slug;
    }
}
