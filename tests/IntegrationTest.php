<?php

namespace React\Tests\Socket;

use React\Dns\Resolver\Factory as ResolverFactory;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\DnsConnector;
use React\Socket\SecureConnector;
use React\Socket\TcpConnector;
use function React\Async\await;
use function React\Promise\Timer\sleep;
use function React\Promise\Timer\timeout;

/** @group internet */
class IntegrationTest extends TestCase
{
    const TIMEOUT = 5.0;

    /** @test */
    public function gettingStuffFromGoogleShouldWork()
    {
        $connector = new Connector([]);

        $conn = await($connector->connect('google.com:80'));
        assert($conn instanceof ConnectionInterface);

        $this->assertStringContainsString(':80', $conn->getRemoteAddress());
        $this->assertNotEquals('google.com:80', $conn->getRemoteAddress());

        $conn->write("GET / HTTP/1.0\r\n\r\n");

        $response = $this->buffer($conn, self::TIMEOUT);
        assert(!$conn->isReadable());

        $this->assertStringMatchesFormat('HTTP/1.0%a', $response);
    }

    /** @test */
    public function gettingEncryptedStuffFromGoogleShouldWork()
    {
        $secureConnector = new Connector([]);

        $conn = await($secureConnector->connect('tls://google.com:443'));
        assert($conn instanceof ConnectionInterface);

        $conn->write("GET / HTTP/1.0\r\n\r\n");

        $response = $this->buffer($conn, self::TIMEOUT);
        assert(!$conn->isReadable());

        $this->assertStringMatchesFormat('HTTP/1.0%a', $response);
    }

    /** @test */
    public function gettingEncryptedStuffFromGoogleShouldWorkIfHostIsResolvedFirst()
    {
        $factory = new ResolverFactory();
        $dns = $factory->create('8.8.8.8');

        $connector = new DnsConnector(
            new SecureConnector(
                new TcpConnector()
            ),
            $dns
        );

        $conn = await($connector->connect('google.com:443'));
        assert($conn instanceof ConnectionInterface);

        $conn->write("GET / HTTP/1.0\r\n\r\n");

        $response = $this->buffer($conn, self::TIMEOUT);
        assert(!$conn->isReadable());

        $this->assertStringMatchesFormat('HTTP/1.0%a', $response);
    }

    /** @test */
    public function gettingPlaintextStuffFromEncryptedGoogleShouldNotWork()
    {
        $connector = new Connector([]);

        $conn = await($connector->connect('google.com:443'));
        assert($conn instanceof ConnectionInterface);

        $this->assertStringContainsString(':443', $conn->getRemoteAddress());
        $this->assertNotEquals('google.com:443', $conn->getRemoteAddress());

        $conn->write("GET / HTTP/1.0\r\n\r\n");

        $response = $this->buffer($conn, self::TIMEOUT);
        assert(!$conn->isReadable());

        $this->assertStringNotMatchesFormat('HTTP/1.0%a', $response);
    }

    public function testConnectingFailsIfConnectorUsesInvalidDnsResolverAddress()
    {
        if (PHP_OS === 'Darwin') {
            $this->markTestSkipped('Skipped on macOS due to a bug in reactphp/dns (solved in reactphp/dns#171)');
        }

        $factory = new ResolverFactory();
        $dns = $factory->create('255.255.255.255');

        $connector = new Connector([
            'dns' => $dns
        ]);

        $this->expectException(\RuntimeException::class);
        await(timeout($connector->connect('google.com:80'), self::TIMEOUT));
    }

    public function testCancellingPendingConnectionWithoutTimeoutShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $connector = new Connector(['timeout' => false]);

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $promise = $connector->connect('8.8.8.8:80');
        $promise->cancel();
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testCancellingPendingConnectionShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $connector = new Connector([]);

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $promise = $connector->connect('8.8.8.8:80');
        $promise->cancel();
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testWaitingForRejectedConnectionShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        // let loop tick for reactphp/async v4 to clean up any remaining stream resources
        // @link https://github.com/reactphp/async/pull/65 reported upstream // TODO remove me once merged
        if (function_exists('React\Async\async')) {
            await(sleep(0));
            Loop::run();
        }

        $connector = new Connector(['timeout' => false]);

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $wait = true;
        $promise = $connector->connect('127.0.0.1:1')->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
            }
        );

        // run loop for short period to ensure we detect connection refused error
        await(sleep(0.01));
        if ($wait) {
            await(sleep(0.2));
            if ($wait) {
                await(sleep(2.0));
                if ($wait) {
                    $this->fail('Connection attempt did not fail');
                }
            }
        }
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testWaitingForConnectionTimeoutDuringDnsLookupShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $connector = new Connector(['timeout' => 0.001]);

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $wait = true;
        $promise = $connector->connect('google.com:80')->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
            }
        );

        // run loop for short period to ensure we detect a connection timeout error
        await(sleep(0.01));
        if ($wait) {
            await(sleep(0.2));
            if ($wait) {
                $this->fail('Connection attempt did not fail');
            }
        }
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testWaitingForConnectionTimeoutDuringTcpConnectionShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $connector = new Connector(['timeout' => 0.000001]);

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $wait = true;
        $promise = $connector->connect('8.8.8.8:53')->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
            }
        );

        // run loop for short period to ensure we detect a connection timeout error
        await(sleep(0.01));
        if ($wait) {
            await(sleep(0.2));
            if ($wait) {
                $this->fail('Connection attempt did not fail');
            }
        }
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testWaitingForInvalidDnsConnectionShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $connector = new Connector(['timeout' => false]);

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $wait = true;
        $promise = $connector->connect('example.invalid:80')->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
            }
        );

        // run loop for short period to ensure we detect a DNS error
        await(sleep(0.01));
        if ($wait) {
            await(sleep(0.2));
            if ($wait) {
                await(sleep(2.0));
                if ($wait) {
                    $this->fail('Connection attempt did not fail');
                }
            }
        }
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testWaitingForInvalidTlsConnectionShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $connector = new Connector([
            'tls' => [
                'verify_peer' => true
            ]
        ]);

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $wait = true;
        $promise = $connector->connect('tls://self-signed.badssl.com:443')->then(
            null,
            function ($e) use (&$wait) {
                $wait = false;
            }
        );

        // run loop for short period to ensure we detect a TLS error
        await(sleep(0.01));
        if ($wait) {
            await(sleep(0.4));
            if ($wait) {
                await(sleep(self::TIMEOUT - 0.5));
                if ($wait) {
                    $this->fail('Connection attempt did not fail');
                }
            }
        }
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testWaitingForSuccessfullyClosedConnectionShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        $connector = new Connector(['timeout' => false]);

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $promise = $connector->connect('google.com:80')->then(
            function ($conn) {
                $conn->close();
            }
        );
        await(timeout($promise, self::TIMEOUT));
        unset($promise);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testConnectingFailsIfTimeoutIsTooSmall()
    {
        $connector = new Connector([
            'timeout' => 0.001
        ]);

        $this->expectException(\RuntimeException::class);
        await(timeout($connector->connect('google.com:80'), self::TIMEOUT));
    }

    public function testSelfSignedRejectsIfVerificationIsEnabled()
    {
        $connector = new Connector([
            'tls' => [
                'verify_peer' => true
            ]
        ]);

        $this->expectException(\RuntimeException::class);
        await(timeout($connector->connect('tls://self-signed.badssl.com:443'), self::TIMEOUT));
    }

    public function testSelfSignedResolvesIfVerificationIsDisabled()
    {
        $connector = new Connector([
            'tls' => [
                'verify_peer' => false
            ]
        ]);

        $conn = await(timeout($connector->connect('tls://self-signed.badssl.com:443'), self::TIMEOUT));
        assert($conn instanceof ConnectionInterface);
        $conn->close();

        // if we reach this, then everything is good
        $this->assertNull(null);
    }
}
