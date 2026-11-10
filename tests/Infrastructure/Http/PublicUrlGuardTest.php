<?php

declare(strict_types=1);

namespace DDD\Tests\Infrastructure\Http;

use DDD\Infrastructure\Http\PublicUrlGuard;
use DDD\Infrastructure\Http\PublicUrlGuardException;
use DDD\Tests\Infrastructure\Http\Fixtures\FakeDnsPublicUrlGuard;
use PHPUnit\Framework\TestCase;

/**
 * The outbound URL guard without network: literal addresses and reserved names (public addresses pass, every internal
 * range, a non-http(s) scheme and a malformed URL are refused) and host names through a scripted resolver.
 */
class PublicUrlGuardTest extends TestCase
{
    protected function setUp(): void
    {
        FakeDnsPublicUrlGuard::reset();
    }

    /** @return array<string, array{string}> */
    public static function providePublicUrls(): array
    {
        return [
            'public IPv4' => ['https://93.184.216.34/path'],
            'public IPv4 over http with port' => ['http://93.184.216.34:8080/'],
            'public IPv6' => ['https://[2606:2800:220:1:248:1893:25c8:1946]/'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function provideRefusedUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1:9200/'],
            'private 10/8' => ['http://10.0.0.5/'],
            'private 172.16/12' => ['http://172.16.3.4/'],
            'private 192.168/16' => ['http://192.168.1.1/'],
            'link-local metadata address' => ['http://169.254.169.254/latest/meta-data/'],
            'carrier-grade NAT' => ['http://100.64.0.1/'],
            'unspecified' => ['http://0.0.0.0/'],
            'IPv6 loopback' => ['http://[::1]/'],
            'IPv6 unique local' => ['http://[fd00::1]/'],
            'IPv6 link-local' => ['http://[fe80::1]/'],
            'IPv4-mapped IPv6 loopback' => ['http://[::ffff:127.0.0.1]/'],
            'localhost' => ['http://localhost/'],
            'localhost with trailing dot' => ['http://localhost./'],
            'a .localhost name' => ['http://api.localhost/'],
            'ftp scheme' => ['ftp://93.184.216.34/'],
            'file scheme' => ['file:///etc/passwd'],
            'no scheme' => ['93.184.216.34/path'],
            'malformed' => ['http://'],
            'empty' => [''],
        ];
    }

    /** @dataProvider providePublicUrls */
    public function testAPublicLiteralAddressPasses(string $url): void
    {
        PublicUrlGuard::assertUrlIsPublic($url);
        $publicIps = PublicUrlGuard::resolvePublicIps($url);
        $this->assertIsArray($publicIps);
        $this->assertCount(1, $publicIps);
        $this->assertTrue(PublicUrlGuard::resolvedHostsAreAllPublic($url));
        $this->assertSame(PublicUrlGuard::HOST_PUBLIC, PublicUrlGuard::classifyHost($url));
    }

    /** @dataProvider provideRefusedUrls */
    public function testAnInternalOrInvalidTargetIsRefused(string $url): void
    {
        $this->assertNull(PublicUrlGuard::resolvePublicIps($url));
        $this->assertFalse(PublicUrlGuard::resolvedHostsAreAllPublic($url));
        $this->expectException(PublicUrlGuardException::class);
        PublicUrlGuard::assertUrlIsPublic($url, 'webhookUrl');
    }

    public function testTheRefusalNamesTheField(): void
    {
        try {
            PublicUrlGuard::assertUrlIsPublic('http://10.0.0.5/', 'webhookUrl');
            $this->fail('an internal address must be refused');
        } catch (PublicUrlGuardException $publicUrlGuardException) {
            $this->assertStringContainsString('webhookUrl', $publicUrlGuardException->getMessage());
        }
    }

    public function testClassifyHostAnswersWithoutThrowing(): void
    {
        $this->assertSame(PublicUrlGuard::HOST_PRIVATE, PublicUrlGuard::classifyHost('http://192.168.1.1/'));
        $this->assertSame(PublicUrlGuard::HOST_PUBLIC, PublicUrlGuard::classifyHost('93.184.216.34'));
        $this->assertSame(PublicUrlGuard::HOST_PRIVATE, PublicUrlGuard::classifyHost('http://[::1]/'));
        $this->assertSame(PublicUrlGuard::HOST_UNKNOWN, PublicUrlGuard::classifyHost(''));
        $this->assertSame(PublicUrlGuard::HOST_UNKNOWN, PublicUrlGuard::classifyHost('http://not a host/'));
    }

    public function testEveryAddressOfAPublicHostIsAnswered(): void
    {
        FakeDnsPublicUrlGuard::answerFor('hooks.example.com', ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']);
        $this->assertSame(
            ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
            FakeDnsPublicUrlGuard::resolvePublicIps('https://hooks.example.com/hook')
        );
        $this->assertSame(1, FakeDnsPublicUrlGuard::$lookupCountByHost['hooks.example.com']);
    }

    /** A name with one public and one internal record is refused: the client could connect to either. */
    public function testAHostWithAPublicAndAnInternalAddressIsRefused(): void
    {
        FakeDnsPublicUrlGuard::answerFor('mixed.example.com', ['93.184.216.34', '10.0.0.7']);
        $this->assertNull(FakeDnsPublicUrlGuard::resolvePublicIps('https://mixed.example.com/'));
        FakeDnsPublicUrlGuard::answerFor('mixed6.example.com', ['93.184.216.34', '::1']);
        $this->assertNull(FakeDnsPublicUrlGuard::resolvePublicIps('https://mixed6.example.com/'));
        FakeDnsPublicUrlGuard::answerFor('metadata.example.com', ['169.254.169.254']);
        $this->assertNull(FakeDnsPublicUrlGuard::resolvePublicIps('https://metadata.example.com/'));
    }

    /** A host that answers public first and internal afterwards: the caller connects to the checked address. */
    public function testAHostThatChangesItsAnswerIsCheckedAtEveryResolution(): void
    {
        FakeDnsPublicUrlGuard::answerFor('rebind.example.com', ['93.184.216.34'], ['127.0.0.1']);
        $this->assertSame(['93.184.216.34'], FakeDnsPublicUrlGuard::resolvePublicIps('https://rebind.example.com/'));
        $this->assertNull(FakeDnsPublicUrlGuard::resolvePublicIps('https://rebind.example.com/'));
    }

    public function testAnUnresolvableHostIsRefusedWithItsOwnText(): void
    {
        FakeDnsPublicUrlGuard::answerFor('gone.example.com', []);
        $this->assertNull(FakeDnsPublicUrlGuard::resolvePublicIps('https://gone.example.com/'));
        try {
            FakeDnsPublicUrlGuard::assertUrlIsPublic('https://gone.example.com/');
            $this->fail('an unresolvable host must be refused');
        } catch (PublicUrlGuardException $publicUrlGuardException) {
            $this->assertSame('url host could not be resolved.', $publicUrlGuardException->getMessage());
        }
    }
}
