<?php

declare(strict_types=1);

namespace DDD\Tests\Domain\Common\Entities\WebContent;

use DDD\Domain\Common\Entities\WebContent\WebsiteHostName;
use PHPUnit\Framework\TestCase;

class WebsiteHostNameTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function provideUrls(): array
    {
        return [
            'full URL' => ['https://www.Example.com/path?x=1', 'example.com'],
            'bare domain' => ['example.com', 'example.com'],
            'bare domain with www and path' => ['WWW.example.com/shop', 'example.com'],
            'subdomain is kept' => ['http://shop.example.com', 'shop.example.com'],
            'port is not part of the host' => ['https://example.com:8443/', 'example.com'],
            'surrounding blanks' => ['  https://example.com  ', 'example.com'],
            'empty' => ['', ''],
            'blank' => ['   ', ''],
        ];
    }

    /** @dataProvider provideUrls */
    public function testHostOfAnAddress(string $url, string $expectedHost): void
    {
        $this->assertSame($expectedHost, WebsiteHostName::fromUrl($url)->host);
    }

    public function testTwoAddressesOfOneSiteShareTheHost(): void
    {
        $this->assertSame(
            WebsiteHostName::fromUrl('https://www.example.com/a')->host,
            WebsiteHostName::fromUrl('example.com/b')->host
        );
    }
}
