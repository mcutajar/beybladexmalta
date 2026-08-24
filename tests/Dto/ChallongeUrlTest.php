<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\ChallongeUrl;
use App\Exception\InvalidChallongeUrlException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChallongeUrlTest extends TestCase
{
    /**
     * Every shape below is either one that repeat.sh already holds or one
     * somebody can plausibly paste into the import form.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function bracketUrls(): iterable
    {
        yield 'a plain bracket' => [
            'https://challonge.com/9yuqg2pi',
            'https://challonge.com/9yuqg2pi/module?show_standings=1',
        ];

        yield 'an invite link' => [
            'https://challonge.com/vi/cd0ljdsv',
            'https://challonge.com/cd0ljdsv/module?show_standings=1',
        ];

        yield 'a bracket on a subdomain' => [
            'https://worldbeyblade.challonge.com/co5nncw8',
            'https://worldbeyblade.challonge.com/co5nncw8/module?show_standings=1',
        ];

        yield 'a trailing slash' => [
            'https://challonge.com/9yuqg2pi/',
            'https://challonge.com/9yuqg2pi/module?show_standings=1',
        ];

        yield 'a deeper page' => [
            'https://challonge.com/9yuqg2pi/standings',
            'https://challonge.com/9yuqg2pi/module?show_standings=1',
        ];

        yield 'the module page itself' => [
            'https://challonge.com/9yuqg2pi/module',
            'https://challonge.com/9yuqg2pi/module?show_standings=1',
        ];

        yield 'no scheme at all' => [
            'challonge.com/9yuqg2pi',
            'https://challonge.com/9yuqg2pi/module?show_standings=1',
        ];

        yield 'plain http' => [
            'http://challonge.com/9yuqg2pi',
            'https://challonge.com/9yuqg2pi/module?show_standings=1',
        ];

        yield 'the www host' => [
            'https://www.challonge.com/9yuqg2pi',
            'https://challonge.com/9yuqg2pi/module?show_standings=1',
        ];

        yield 'a shouted host' => [
            'https://CHALLONGE.COM/9yuqg2pi',
            'https://challonge.com/9yuqg2pi/module?show_standings=1',
        ];

        yield 'tracking junk on the end' => [
            'https://challonge.com/9yuqg2pi?utm_source=whatsapp#standings',
            'https://challonge.com/9yuqg2pi/module?show_standings=1',
        ];

        yield 'whitespace around a pasted link' => [
            "  https://challonge.com/9yuqg2pi\n",
            'https://challonge.com/9yuqg2pi/module?show_standings=1',
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedUrls(): iterable
    {
        yield 'nothing' => [''];
        yield 'whitespace' => ['   '];
        yield 'another site' => ['https://example.com/9yuqg2pi'];
        yield 'a host that only looks like Challonge' => ['https://challonge.com.example.com/9yuqg2pi'];
        yield 'Challonge without a bracket' => ['https://challonge.com/'];
        yield 'an invite link without a bracket' => ['https://challonge.com/vi/'];
        yield 'a slug with a slash in it' => ['https://challonge.com/9yu qg2pi'];
    }

    #[DataProvider('bracketUrls')]
    public function testItNormalisesEveryShapeOntoTheModuleRoute(
        string $pasted,
        string $expected,
    ): void {
        self::assertSame($expected, ChallongeUrl::fromString($pasted)->moduleUrl());
    }

    #[DataProvider('rejectedUrls')]
    public function testItRejectsWhatIsNotABracket(string $pasted): void
    {
        $this->expectException(InvalidChallongeUrlException::class);

        ChallongeUrl::fromString($pasted);
    }

    public function testItKeepsTheSubdomainThatOwnsTheBracket(): void
    {
        $url = ChallongeUrl::fromString('https://worldbeyblade.challonge.com/co5nncw8');

        self::assertSame('worldbeyblade', $url->subdomain);
        self::assertSame('co5nncw8', $url->slug);
    }

    public function testTheSlugIsTheSnapshotNameWhateverTheUrlLookedLike(): void
    {
        self::assertSame('cd0ljdsv', ChallongeUrl::fromString('https://challonge.com/vi/cd0ljdsv')->slug);
    }

    public function testItOffersTheBracketAsAPersonWouldOpenIt(): void
    {
        self::assertSame(
            'https://worldbeyblade.challonge.com/co5nncw8',
            ChallongeUrl::fromString('https://worldbeyblade.challonge.com/co5nncw8/module')->bracketUrl(),
        );
    }
}
