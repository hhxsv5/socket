<?php

namespace React\Tests\Socket;

use React\Dns\Resolver\ResolverInterface;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Socket\ConnectorInterface;
use React\Socket\DnsConnector;
use function React\Promise\reject;
use function React\Promise\resolve;

class DnsConnectorTest extends TestCase
{
    private $tcp;
    private $resolver;
    private $connector;

    /**
     * @before
     */
    public function setUpMocks()
    {
        $this->tcp = $this->createMock(ConnectorInterface::class);
        $this->resolver = $this->createMock(ResolverInterface::class);

        $this->connector = new DnsConnector($this->tcp, $this->resolver);
    }

    public function testPassByResolverIfGivenIp()
    {
        $this->resolver->expects($this->never())->method('resolve');
        $this->tcp->expects($this->once())->method('connect')->with('127.0.0.1:80')->willReturn(reject(new \Exception('reject')));

        $promise = $this->connector->connect('127.0.0.1:80');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection
    }

    public function testPassThroughResolverIfGivenHost()
    {
        $this->resolver->expects($this->once())->method('resolve')->with('google.com')->willReturn(resolve('1.2.3.4'));
        $this->tcp->expects($this->once())->method('connect')->with('1.2.3.4:80?hostname=google.com')->willReturn(reject(new \Exception('reject')));

        $promise = $this->connector->connect('google.com:80');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection
    }

    public function testPassThroughResolverIfGivenHostWhichResolvesToIpv6()
    {
        $this->resolver->expects($this->once())->method('resolve')->with('google.com')->willReturn(resolve('::1'));
        $this->tcp->expects($this->once())->method('connect')->with('[::1]:80?hostname=google.com')->willReturn(reject(new \Exception('reject')));

        $promise = $this->connector->connect('google.com:80');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection
    }

    public function testPassByResolverIfGivenCompleteUri()
    {
        $this->resolver->expects($this->never())->method('resolve');
        $this->tcp->expects($this->once())->method('connect')->with('scheme://127.0.0.1:80/path?query#fragment')->willReturn(reject(new \Exception('reject')));

        $promise = $this->connector->connect('scheme://127.0.0.1:80/path?query#fragment');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection
    }

    public function testPassThroughResolverIfGivenCompleteUri()
    {
        $this->resolver->expects($this->once())->method('resolve')->with('google.com')->willReturn(resolve('1.2.3.4'));
        $this->tcp->expects($this->once())->method('connect')->with('scheme://1.2.3.4:80/path?query&hostname=google.com#fragment')->willReturn(reject(new \Exception('reject')));

        $promise = $this->connector->connect('scheme://google.com:80/path?query#fragment');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection
    }

    public function testPassThroughResolverIfGivenExplicitHost()
    {
        $this->resolver->expects($this->once())->method('resolve')->with('google.com')->willReturn(resolve('1.2.3.4'));
        $this->tcp->expects($this->once())->method('connect')->with('scheme://1.2.3.4:80/?hostname=google.de')->willReturn(reject(new \Exception('reject')));

        $promise = $this->connector->connect('scheme://google.com:80/?hostname=google.de');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection
    }

    public function testRejectsImmediatelyIfUriIsInvalid()
    {
        $this->resolver->expects($this->never())->method('resolve');
        $this->tcp->expects($this->never())->method('connect');

        $promise = $this->connector->connect('////');

        $promise->then(null, $this->expectCallableOnceWithException(
            \InvalidArgumentException::class,
            'Given URI "////" is invalid (EINVAL)',
            defined('SOCKET_EINVAL') ? SOCKET_EINVAL : (defined('PCNTL_EINVAL') ? PCNTL_EINVAL : 22)
        ));
    }

    public function testConnectRejectsIfGivenIpAndTcpConnectorRejectsWithRuntimeException()
    {
        $promise = reject(new \RuntimeException('Connection to tcp://1.2.3.4:80 failed: Connection failed', 42));
        $this->resolver->expects($this->never())->method('resolve');
        $this->tcp->expects($this->once())->method('connect')->with('1.2.3.4:80')->willReturn($promise);

        $promise = $this->connector->connect('1.2.3.4:80');

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('Connection to tcp://1.2.3.4:80 failed: Connection failed', $exception->getMessage());
        $this->assertEquals(42, $exception->getCode());
        $this->assertNull($exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    public function testConnectRejectsIfGivenIpAndTcpConnectorRejectsWithInvalidArgumentException()
    {
        $promise = reject(new \InvalidArgumentException('Invalid', 42));
        $this->resolver->expects($this->never())->method('resolve');
        $this->tcp->expects($this->once())->method('connect')->with('1.2.3.4:80')->willReturn($promise);

        $promise = $this->connector->connect('1.2.3.4:80');

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \InvalidArgumentException);
        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
        $this->assertEquals('Invalid', $exception->getMessage());
        $this->assertEquals(42, $exception->getCode());
        $this->assertNull($exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    public function testConnectRejectsWithOriginalHostnameInMessageAfterResolvingIfTcpConnectorRejectsWithRuntimeException()
    {
        $promise = reject(new \RuntimeException('Connection to tcp://1.2.3.4:80?hostname=example.com failed: Connection failed', 42));
        $this->resolver->expects($this->once())->method('resolve')->with('example.com')->willReturn(resolve('1.2.3.4'));
        $this->tcp->expects($this->once())->method('connect')->with('1.2.3.4:80?hostname=example.com')->willReturn($promise);

        $promise = $this->connector->connect('example.com:80');

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('Connection to tcp://example.com:80 failed: Connection to tcp://1.2.3.4:80 failed: Connection failed', $exception->getMessage());
        $this->assertEquals(42, $exception->getCode());
        $this->assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    public function testConnectRejectsWithOriginalExceptionAfterResolvingIfTcpConnectorRejectsWithInvalidArgumentException()
    {
        $promise = reject(new \InvalidArgumentException('Invalid', 42));
        $this->resolver->expects($this->once())->method('resolve')->with('example.com')->willReturn(resolve('1.2.3.4'));
        $this->tcp->expects($this->once())->method('connect')->with('1.2.3.4:80?hostname=example.com')->willReturn($promise);

        $promise = $this->connector->connect('example.com:80');

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \InvalidArgumentException);
        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
        $this->assertEquals('Invalid', $exception->getMessage());
        $this->assertEquals(42, $exception->getCode());
        $this->assertNull($exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    public function testSkipConnectionIfDnsFails()
    {
        $promise = reject(new \RuntimeException('DNS error'));
        $this->resolver->expects($this->once())->method('resolve')->with('example.invalid')->willReturn($promise);
        $this->tcp->expects($this->never())->method('connect');

        $promise = $this->connector->connect('example.invalid:80');

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('Connection to tcp://example.invalid:80 failed during DNS lookup: DNS error', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
        $this->assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    public function testRejectionExceptionUsesPreviousExceptionIfDnsFails()
    {
        $exception = new \RuntimeException();

        $this->resolver->expects($this->once())->method('resolve')->with('example.invalid')->willReturn(reject($exception));

        $promise = $this->connector->connect('example.invalid:80');

        $promise->then(null, function ($e) {
            throw $e->getPrevious();
        })->then(null, $this->expectCallableOnceWith($this->identicalTo($exception)));
    }

    public function testCancelDuringDnsCancelsDnsAndDoesNotStartTcpConnection()
    {
        $pending = new Promise(function () { }, $this->expectCallableOnce());
        $this->resolver->expects($this->once())->method('resolve')->with('example.com')->willReturn($pending);
        $this->tcp->expects($this->never())->method('connect');

        $promise = $this->connector->connect('example.com:80');
        $promise->cancel();

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('Connection to tcp://example.com:80 cancelled during DNS lookup (ECONNABORTED)', $exception->getMessage());
        $this->assertEquals(defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103, $exception->getCode());
        $this->assertNull($exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    public function testCancelDuringTcpConnectionCancelsTcpConnectionIfGivenIp()
    {
        $pending = new Promise(function () { }, $this->expectCallableOnce());
        $this->resolver->expects($this->never())->method('resolve');
        $this->tcp->expects($this->once())->method('connect')->with('1.2.3.4:80')->willReturn($pending);

        $promise = $this->connector->connect('1.2.3.4:80');
        $promise->cancel();
    }

    public function testCancelDuringTcpConnectionCancelsTcpConnectionAfterDnsIsResolved()
    {
        $pending = new Promise(function () { }, $this->expectCallableOnce());
        $this->resolver->expects($this->once())->method('resolve')->with('example.com')->willReturn(resolve('1.2.3.4'));
        $this->tcp->expects($this->once())->method('connect')->with('1.2.3.4:80?hostname=example.com')->willReturn($pending);

        $promise = $this->connector->connect('example.com:80');
        $promise->cancel();
    }

    public function testCancelDuringTcpConnectionCancelsTcpConnectionWithTcpRejectionAfterDnsIsResolved()
    {
        $first = new Deferred();
        $this->resolver->expects($this->once())->method('resolve')->with('example.com')->willReturn($first->promise());
        $pending = new Promise(function () { }, function () {
            throw new \RuntimeException(
                'Connection cancelled',
                defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103
            );
        });
        $this->tcp->expects($this->once())->method('connect')->with('1.2.3.4:80?hostname=example.com')->willReturn($pending);

        $promise = $this->connector->connect('example.com:80');
        $first->resolve('1.2.3.4');

        $promise->cancel();

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertEquals('Connection to tcp://example.com:80 failed: Connection cancelled', $exception->getMessage());
        $this->assertEquals(defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103, $exception->getCode());
        $this->assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        $this->assertNotEquals('', $exception->getTraceAsString());
    }

    public function testRejectionDuringDnsLookupShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $dns = new Deferred();
        $this->resolver->expects($this->once())->method('resolve')->with('example.com')->willReturn($dns->promise());
        $this->tcp->expects($this->never())->method('connect');

        $promise = $this->connector->connect('example.com:80');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $dns->reject(new \RuntimeException('DNS failed'));
        unset($promise, $dns);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testRejectionAfterDnsLookupShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $dns = new Deferred();
        $this->resolver->expects($this->once())->method('resolve')->with('example.com')->willReturn($dns->promise());

        $tcp = new Deferred();
        $this->tcp->expects($this->once())->method('connect')->with('1.2.3.4:80?hostname=example.com')->willReturn($tcp->promise());

        $promise = $this->connector->connect('example.com:80');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $dns->resolve('1.2.3.4');
        $tcp->reject(new \RuntimeException('Connection failed'));
        unset($promise, $dns, $tcp);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testRejectionAfterDnsLookupShouldNotCreateAnyGarbageReferencesAgain()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $dns = new Deferred();
        $this->resolver->expects($this->once())->method('resolve')->with('example.com')->willReturn($dns->promise());

        $tcp = new Deferred();
        $dns->promise()->then(function () use ($tcp) {
            $tcp->reject(new \RuntimeException('Connection failed'));
        });
        $this->tcp->expects($this->once())->method('connect')->with('1.2.3.4:80?hostname=example.com')->willReturn($tcp->promise());

        $promise = $this->connector->connect('example.com:80');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $dns->resolve('1.2.3.4');

        unset($promise, $dns, $tcp);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testCancelDuringDnsLookupShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $dns = new Deferred(function () {
            throw new \RuntimeException();
        });
        $this->resolver->expects($this->once())->method('resolve')->with('example.com')->willReturn($dns->promise());
        $this->tcp->expects($this->never())->method('connect');

        $promise = $this->connector->connect('example.com:80');

        $promise->cancel();
        unset($promise, $dns);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testCancelDuringTcpConnectionShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $dns = new Deferred();
        $this->resolver->expects($this->once())->method('resolve')->with('example.com')->willReturn($dns->promise());
        $tcp = new Promise(function () { }, function () {
            throw new \RuntimeException('Connection cancelled');
        });
        $this->tcp->expects($this->once())->method('connect')->with('1.2.3.4:80?hostname=example.com')->willReturn($tcp);

        $promise = $this->connector->connect('example.com:80');
        $dns->resolve('1.2.3.4');

        $promise->cancel();
        unset($promise, $dns, $tcp);

        $this->assertEquals(0, gc_collect_cycles());
    }
}
