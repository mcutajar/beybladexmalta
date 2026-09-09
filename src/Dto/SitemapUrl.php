<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One `<url>` in the sitemap.
 *
 * The path is relative and the sitemap prefixes it with the canonical origin,
 * so nothing here depends on the scheme the request arrived on. Behind the
 * Cloudflare tunnel that matters: the origin is spoken to over plain HTTP, and
 * a URL built from the request context would advertise `http://` to the
 * crawler for every page on the site.
 *
 * `lastModified` is nullable and stays that way for anything the database
 * cannot date. A guessed date is worse than none — Google weighs the signal by
 * how well it has held up, so a sitemap that stamps today on every page trains
 * the crawler to ignore the field everywhere, including on the pages where it
 * was true.
 */
final readonly class SitemapUrl
{
    public function __construct(
        public string $path,
        public ?\DateTimeImmutable $lastModified = null,
    ) {
    }
}
