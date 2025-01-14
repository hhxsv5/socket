<?php

namespace React\Tests\Socket;

use React\Dns\Model\Message;
use React\Dns\Resolver\ResolverInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Socket\ConnectorInterface;
use React\Socket\HappyEyeBallsConnectionBuilder;
use function React\Promise\reject;
use function React\Promise\resolve;

class HappyEyeBallsConnectionBuilderTest extends TestCase
{
    public function testConnectWillResolveTwiceViaResolver()
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->never())->method('addTimer');

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->never())->method('connect');

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['reactphp.org', Message::TYPE_AAAA],
            ['reactphp.org', Message::TYPE_A]
        )->willReturn(new Promise(function () { }));

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->connect();
    }

    public function testConnectWillRejectWhenBothDnsLookupsReject()
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->never())->method('addTimer');

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->never())->method('connect');

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['reactphp.org', Message::TYPE_AAAA],
            ['reactphp.org', Message::TYPE_A]
        )->willReturn(new Promise(function () {
            throw new \RuntimeException('DNS lookup error');
        }));

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $promise = $builder->connect();

        $exception = null;
        $promise->then(null, function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        assert($exception instanceof \RuntimeException);

        $this->assertEquals('Connection to tcp://reactphp.org:80 failed during DNS lookup: DNS lookup error', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
        $this->assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
    }

    public function testConnectWillRejectWhenBothDnsLookupsRejectWithDifferentMessages()
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->never())->method('addTimer');

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->never())->method('connect');

        $deferred = new Deferred();
        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['reactphp.org', Message::TYPE_AAAA],
            ['reactphp.org', Message::TYPE_A]
        )->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            reject(new \RuntimeException('DNS4 error'))
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $promise = $builder->connect();
        $deferred->reject(new \RuntimeException('DNS6 error'));

        $exception = null;
        $promise->then(null, function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        assert($exception instanceof \RuntimeException);

        $this->assertEquals('Connection to tcp://reactphp.org:80 failed during DNS lookup. Last error for IPv6: DNS6 error. Previous error for IPv4: DNS4 error', $exception->getMessage());
        $this->assertEquals(0, $exception->getCode());
        $this->assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
    }

    public function testConnectWillStartDelayTimerWhenIpv4ResolvesAndIpv6IsPending()
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(0.05, $this->anything());
        $loop->expects($this->never())->method('cancelTimer');

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->never())->method('connect');

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['reactphp.org', Message::TYPE_AAAA],
            ['reactphp.org', Message::TYPE_A]
        )->willReturnOnConsecutiveCalls(
            new Promise(function () { }),
            resolve(['127.0.0.1'])
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->connect();
    }

    public function testConnectWillStartConnectingWithAttemptTimerButWithoutResolutionTimerWhenIpv6ResolvesAndIpv4IsPending()
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(0.1, $this->anything());
        $loop->expects($this->never())->method('cancelTimer');

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('tcp://[::1]:80?hostname=reactphp.org')->willReturn(new Promise(function () { }));

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['reactphp.org', Message::TYPE_AAAA],
            ['reactphp.org', Message::TYPE_A]
        )->willReturnOnConsecutiveCalls(
            resolve(['::1']),
            new Promise(function () { })
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->connect();
    }

    public function testConnectWillStartConnectingAndWillStartNextConnectionWithNewAttemptTimerWhenNextAttemptTimerFiresWithIpv4StillPending()
    {
        $timer = null;
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->exactly(2))->method('addTimer')->with(0.1, $this->callback(function ($cb) use (&$timer) {
            $timer = $cb;
            return true;
        }));
        $loop->expects($this->never())->method('cancelTimer');

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->exactly(2))->method('connect')->willReturn(new Promise(function () { }));

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['reactphp.org', Message::TYPE_AAAA],
            ['reactphp.org', Message::TYPE_A]
        )->willReturnOnConsecutiveCalls(
            resolve(['::1', '::2']),
            new Promise(function () { })
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->connect();

        $this->assertNotNull($timer);
        $timer();
    }

    public function testConnectWillStartConnectingAndWillDoNothingWhenNextAttemptTimerFiresWithNoOtherIps()
    {
        $timer = null;
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(0.1, $this->callback(function ($cb) use (&$timer) {
            $timer = $cb;
            return true;
        }));
        $loop->expects($this->never())->method('cancelTimer');

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('tcp://[::1]:80?hostname=reactphp.org')->willReturn(new Promise(function () { }));

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['reactphp.org', Message::TYPE_AAAA],
            ['reactphp.org', Message::TYPE_A]
        )->willReturnOnConsecutiveCalls(
            resolve(['::1']),
            new Promise(function () { })
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->connect();

        $this->assertNotNull($timer);
        $timer();
    }

    public function testConnectWillStartConnectingWithAttemptTimerButWithoutResolutionTimerWhenIpv6ResolvesAndWillCancelAttemptTimerWhenIpv4Rejects()
    {
        $timer = $this->createMock(TimerInterface::class);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(0.1, $this->anything())->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('tcp://[::1]:80?hostname=reactphp.org')->willReturn(new Promise(function () { }));

        $deferred = new Deferred();
        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['reactphp.org', Message::TYPE_AAAA],
            ['reactphp.org', Message::TYPE_A]
        )->willReturnOnConsecutiveCalls(
            resolve(['::1']),
            $deferred->promise()
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->connect();
        $deferred->reject(new \RuntimeException());
    }

    public function testConnectWillStartConnectingWithAttemptTimerWhenIpv6AndIpv4ResolvesAndWillStartNextConnectionAttemptWithoutAttemptTimerImmediatelyWhenFirstConnectionAttemptFails()
    {
        $timer = $this->createMock(TimerInterface::class);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(0.1, $this->anything())->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $deferred = new Deferred();
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->exactly(2))->method('connect')->withConsecutive(
            ['tcp://[::1]:80?hostname=reactphp.org'],
            ['tcp://127.0.0.1:80?hostname=reactphp.org']
        )->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            new Promise(function () { })
        );

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['reactphp.org', Message::TYPE_AAAA],
            ['reactphp.org', Message::TYPE_A]
        )->willReturnOnConsecutiveCalls(
            resolve(['::1']),
            resolve(['127.0.0.1'])
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->connect();

        $deferred->reject(new \RuntimeException());
    }

    public function testConnectWillStartConnectingWithAlternatingIPv6AndIPv4WhenResolverReturnsMultipleIPAdresses()
    {
        $timer = $this->createMock(TimerInterface::class);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(0.1, $this->anything())->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $deferred = new Deferred();
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->exactly(4))->method('connect')->withConsecutive(
            ['tcp://[::1]:80?hostname=reactphp.org'],
            ['tcp://127.0.0.1:80?hostname=reactphp.org'],
            ['tcp://[::1]:80?hostname=reactphp.org'],
            ['tcp://127.0.0.1:80?hostname=reactphp.org']
        )->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            $deferred->promise(),
            $deferred->promise(),
            new Promise(function () { })
        );

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['reactphp.org', Message::TYPE_AAAA],
            ['reactphp.org', Message::TYPE_A]
        )->willReturnOnConsecutiveCalls(
            resolve(['::1', '::1']),
            resolve(['127.0.0.1', '127.0.0.1'])
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->connect();

        $deferred->reject(new \RuntimeException());
    }

    public function testConnectWillStartConnectingWithAttemptTimerWhenOnlyIpv6ResolvesAndWillStartNextConnectionAttemptWithoutAttemptTimerImmediatelyWhenFirstConnectionAttemptFails()
    {
        $timer = $this->createMock(TimerInterface::class);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(0.1, $this->anything())->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->exactly(2))->method('connect')->withConsecutive(
            ['tcp://[::1]:80?hostname=reactphp.org'],
            ['tcp://[::1]:80?hostname=reactphp.org']
        )->willReturnOnConsecutiveCalls(
            reject(new \RuntimeException()),
            new Promise(function () { })
        );

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['reactphp.org', Message::TYPE_AAAA],
            ['reactphp.org', Message::TYPE_A]
        )->willReturnOnConsecutiveCalls(
            resolve(['::1', '::1']),
            reject(new \RuntimeException())
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->connect();
    }

    public function testConnectWillStartConnectingAndWillStartNextConnectionWithoutNewAttemptTimerWhenNextAttemptTimerFiresAfterIpv4Rejected()
    {
        $timer = null;
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(0.1, $this->callback(function ($cb) use (&$timer) {
            $timer = $cb;
            return true;
        }));
        $loop->expects($this->never())->method('cancelTimer');

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->exactly(2))->method('connect')->willReturn(new Promise(function () { }));

        $deferred = new Deferred();
        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['reactphp.org', Message::TYPE_AAAA],
            ['reactphp.org', Message::TYPE_A]
        )->willReturnOnConsecutiveCalls(
            resolve(['::1', '::2']),
            $deferred->promise()
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->connect();
        $deferred->reject(new \RuntimeException());

        $this->assertNotNull($timer);
        $timer();
    }

    public function testConnectWillStartAndCancelResolutionTimerAndStartAttemptTimerWhenIpv4ResolvesAndIpv6ResolvesAfterwardsAndStartConnectingToIpv6()
    {
        $timerDelay = $this->createMock(TimerInterface::class);
        $timerAttempt = $this->createMock(TimerInterface::class);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->exactly(2))->method('addTimer')->withConsecutive(
            [0.05, $this->anything()],
            [0.1, $this->anything()]
        )->willReturnOnConsecutiveCalls($timerDelay, $timerAttempt);
        $loop->expects($this->once())->method('cancelTimer')->with($timerDelay);

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('tcp://[::1]:80?hostname=reactphp.org')->willReturn(new Promise(function () { }));

        $deferred = new Deferred();
        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['reactphp.org', Message::TYPE_AAAA],
            ['reactphp.org', Message::TYPE_A]
        )->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            resolve(['127.0.0.1'])
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->connect();
        $deferred->resolve(['::1']);
    }

    public function testConnectWillRejectWhenOnlyTcp6ConnectionRejectsAndCancelNextAttemptTimerImmediately()
    {
        $timer = $this->createMock(TimerInterface::class);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(0.1, $this->anything())->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $deferred = new Deferred();
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('tcp://[::1]:80?hostname=reactphp.org')->willReturn($deferred->promise());

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['reactphp.org', Message::TYPE_AAAA],
            ['reactphp.org', Message::TYPE_A]
        )->willReturnOnConsecutiveCalls(
            resolve(['::1']),
            reject(new \RuntimeException('DNS failed'))
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $promise = $builder->connect();
        $deferred->reject(new \RuntimeException(
            'Connection refused (ECONNREFUSED)',
            defined('SOCKET_ECONNREFUSED') ? SOCKET_ECONNREFUSED : 111
        ));

        $exception = null;
        $promise->then(null, function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        assert($exception instanceof \RuntimeException);

        $this->assertEquals('Connection to tcp://reactphp.org:80 failed: Last error for IPv6: Connection refused (ECONNREFUSED). Previous error for IPv4: DNS failed', $exception->getMessage());
        $this->assertEquals(defined('SOCKET_ECONNREFUSED') ? SOCKET_ECONNREFUSED : 111, $exception->getCode());
        $this->assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
    }

    public function testConnectWillRejectWhenOnlyTcp4ConnectionRejectsAndWillNeverStartNextAttemptTimer()
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->never())->method('addTimer');

        $deferred = new Deferred();
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('tcp://127.0.0.1:80?hostname=reactphp.org')->willReturn($deferred->promise());

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['reactphp.org', Message::TYPE_AAAA],
            ['reactphp.org', Message::TYPE_A]
        )->willReturnOnConsecutiveCalls(
            reject(new \RuntimeException('DNS failed')),
            resolve(['127.0.0.1'])
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $promise = $builder->connect();
        $deferred->reject(new \RuntimeException(
            'Connection refused (ECONNREFUSED)',
            defined('SOCKET_ECONNREFUSED') ? SOCKET_ECONNREFUSED : 111
        ));

        $exception = null;
        $promise->then(null, function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        assert($exception instanceof \RuntimeException);

        $this->assertEquals('Connection to tcp://reactphp.org:80 failed: Last error for IPv4: Connection refused (ECONNREFUSED). Previous error for IPv6: DNS failed', $exception->getMessage());
        $this->assertEquals(defined('SOCKET_ECONNREFUSED') ? SOCKET_ECONNREFUSED : 111, $exception->getCode());
        $this->assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
    }

    public function testConnectWillRejectWhenAllConnectionsRejectAndCancelNextAttemptTimerImmediately()
    {
        $timer = $this->createMock(TimerInterface::class);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(0.1, $this->anything())->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $deferred = new Deferred();
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->exactly(2))->method('connect')->willReturn($deferred->promise());

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['reactphp.org', Message::TYPE_AAAA],
            ['reactphp.org', Message::TYPE_A]
        )->willReturnOnConsecutiveCalls(
            resolve(['::1']),
            resolve(['127.0.0.1'])
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $promise = $builder->connect();
        $deferred->reject(new \RuntimeException(
            'Connection refused (ECONNREFUSED)',
            defined('SOCKET_ECONNREFUSED') ? SOCKET_ECONNREFUSED : 111
        ));

        $exception = null;
        $promise->then(null, function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        assert($exception instanceof \RuntimeException);

        $this->assertEquals('Connection to tcp://reactphp.org:80 failed: Connection refused (ECONNREFUSED)', $exception->getMessage());
        $this->assertEquals(defined('SOCKET_ECONNREFUSED') ? SOCKET_ECONNREFUSED : 111, $exception->getCode());
        $this->assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
    }

    public function testConnectWillRejectWithMessageWithoutHostnameWhenAllConnectionsRejectAndCancelNextAttemptTimerImmediately()
    {
        $timer = $this->createMock(TimerInterface::class);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(0.1, $this->anything())->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $deferred = new Deferred();
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->exactly(2))->method('connect')->willReturnOnConsecutiveCalls(
            $deferred->promise(),
            reject(new \RuntimeException(
                'Connection to tcp://127.0.0.1:80?hostname=localhost failed: Connection refused (ECONNREFUSED)',
                defined('SOCKET_ECONNREFUSED') ? SOCKET_ECONNREFUSED : 111
            ))
        );

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['localhost', Message::TYPE_AAAA],
            ['localhost', Message::TYPE_A]
        )->willReturnOnConsecutiveCalls(
            resolve(['::1']),
            resolve(['127.0.0.1'])
        );

        $uri = 'tcp://localhost:80';
        $host = 'localhost';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $promise = $builder->connect();
        $deferred->reject(new \RuntimeException(
            'Connection to tcp://[::1]:80?hostname=localhost failed: Connection refused (ECONNREFUSED)',
            defined('SOCKET_ECONNREFUSED') ? SOCKET_ECONNREFUSED : 111
        ));

        $exception = null;
        $promise->then(null, function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        assert($exception instanceof \RuntimeException);

        $this->assertEquals('Connection to tcp://localhost:80 failed: Last error for IPv4: Connection to tcp://127.0.0.1:80 failed: Connection refused (ECONNREFUSED). Previous error for IPv6: Connection to tcp://[::1]:80 failed: Connection refused (ECONNREFUSED)', $exception->getMessage());
        $this->assertEquals(defined('SOCKET_ECONNREFUSED') ? SOCKET_ECONNREFUSED : 111, $exception->getCode());
        $this->assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
    }

    public function testCancelConnectWillRejectPromiseAndCancelBothDnsLookups()
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->never())->method('addTimer');

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->never())->method('connect');

        $cancelled = 0;
        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['reactphp.org', Message::TYPE_AAAA],
            ['reactphp.org', Message::TYPE_A]
        )->willReturnOnConsecutiveCalls(
            new Promise(function () { }, function () use (&$cancelled) {
                ++$cancelled;
                throw new \RuntimeException();
            }),
            new Promise(function () { }, function () use (&$cancelled) {
                ++$cancelled;
                throw new \RuntimeException();
            })
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $promise = $builder->connect();
        $promise->cancel();

        $this->assertEquals(2, $cancelled);

        $exception = null;
        $promise->then(null, function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        assert($exception instanceof \RuntimeException);

        $this->assertEquals('Connection to tcp://reactphp.org:80 cancelled during DNS lookup (ECONNABORTED)', $exception->getMessage());
        $this->assertEquals(defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103, $exception->getCode());
    }

    public function testCancelConnectWillRejectPromiseAndCancelPendingIpv6LookupAndCancelDelayTimer()
    {
        $timer = $this->createMock(TimerInterface::class);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->never())->method('connect');

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['reactphp.org', Message::TYPE_AAAA],
            ['reactphp.org', Message::TYPE_A]
        )->willReturnOnConsecutiveCalls(
            new Promise(function () { }, function () {
                throw new \RuntimeException('DNS cancelled');
            }),
            resolve(['127.0.0.1'])
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $promise = $builder->connect();
        $promise->cancel();

        $exception = null;
        $promise->then(null, function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        assert($exception instanceof \RuntimeException);

        $this->assertEquals('Connection to tcp://reactphp.org:80 cancelled during DNS lookup (ECONNABORTED)', $exception->getMessage());
        $this->assertEquals(defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103, $exception->getCode());
    }

    public function testCancelConnectWillRejectPromiseAndCancelPendingIpv6ConnectionAttemptAndPendingIpv4LookupAndCancelAttemptTimer()
    {
        $timer = $this->createMock(TimerInterface::class);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(0.1, $this->anything())->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $cancelled = 0;
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('tcp://[::1]:80?hostname=reactphp.org')->willReturn(new Promise(function () { }, function () use (&$cancelled) {
            ++$cancelled;
            throw new \RuntimeException('Ignored message');
        }));

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->exactly(2))->method('resolveAll')->withConsecutive(
            ['reactphp.org', Message::TYPE_AAAA],
            ['reactphp.org', Message::TYPE_A]
        )->willReturnOnConsecutiveCalls(
            resolve(['::1']),
            new Promise(function () { }, $this->expectCallableOnce())
        );

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $promise = $builder->connect();
        $promise->cancel();

        $this->assertEquals(1, $cancelled);

        $exception = null;
        $promise->then(null, function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        assert($exception instanceof \RuntimeException);

        $this->assertEquals('Connection to tcp://reactphp.org:80 cancelled (ECONNABORTED)', $exception->getMessage());
        $this->assertEquals(defined('SOCKET_ECONNABORTED') ? SOCKET_ECONNABORTED : 103, $exception->getCode());
    }

    public function testResolveWillReturnResolvedPromiseWithEmptyListWhenDnsResolverFails()
    {
        $loop = $this->createMock(LoopInterface::class);

        $connector = $this->createMock(ConnectorInterface::class);

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->once())->method('resolveAll')->with('reactphp.org', Message::TYPE_A)->willReturn(reject(new \RuntimeException()));

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $promise = $builder->resolve(Message::TYPE_A, $this->expectCallableNever());

        $this->assertInstanceof(PromiseInterface::class, $promise);
        $promise->then($this->expectCallableOnceWith([]), $this->expectCallableNever());
    }

    public function testAttemptConnectionWillConnectViaConnectorToGivenIpWithPortAndHostnameFromUriParts()
    {
        $loop = $this->createMock(LoopInterface::class);

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('tcp://10.1.1.1:80?hostname=reactphp.org')->willReturn(new Promise(function () { }));

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->never())->method('resolveAll');

        $uri = 'tcp://reactphp.org:80';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->attemptConnection('10.1.1.1');
    }

    public function testAttemptConnectionWillConnectViaConnectorToGivenIpv6WithAllUriParts()
    {
        $loop = $this->createMock(LoopInterface::class);

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('tcp://[::1]:80/path?test=yes&hostname=reactphp.org#start')->willReturn(new Promise(function () { }));

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->never())->method('resolveAll');

        $uri = 'tcp://reactphp.org:80/path?test=yes#start';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $builder->attemptConnection('::1');
    }

    public function testCheckCallsRejectFunctionImmediateWithoutLeavingDanglingPromiseWhenConnectorRejectsImmediately()
    {
        $loop = $this->createMock(LoopInterface::class);

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('tcp://[::1]:80/path?test=yes&hostname=reactphp.org#start')->willReturn(reject(new \RuntimeException()));

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->never())->method('resolveAll');

        $uri = 'tcp://reactphp.org:80/path?test=yes#start';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $ref = new \ReflectionProperty($builder, 'connectQueue');
        $ref->setAccessible(true);
        $ref->setValue($builder, ['::1']);

        $builder->check($this->expectCallableNever(), function () { });

        $ref = new \ReflectionProperty($builder, 'connectionPromises');
        $ref->setAccessible(true);
        $promises = $ref->getValue($builder);

        $this->assertEquals([], $promises);
    }

    public function testCleanUpCancelsAllPendingConnectionAttempts()
    {
        $loop = $this->createMock(LoopInterface::class);

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->exactly(2))->method('connect')->with('tcp://[::1]:80/path?test=yes&hostname=reactphp.org#start')->willReturnOnConsecutiveCalls(
            new Promise(function () { }, $this->expectCallableOnce()),
            new Promise(function () { }, $this->expectCallableOnce())
        );

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->never())->method('resolveAll');

        $uri = 'tcp://reactphp.org:80/path?test=yes#start';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $ref = new \ReflectionProperty($builder, 'connectQueue');
        $ref->setAccessible(true);
        $ref->setValue($builder, ['::1', '::1']);

        $builder->check($this->expectCallableNever(), function () { });
        $builder->check($this->expectCallableNever(), function () { });

        $builder->cleanUp();
    }

    public function testCleanUpCancelsAllPendingConnectionAttemptsWithoutStartingNewAttemptsDueToCancellationRejection()
    {
        $loop = $this->createMock(LoopInterface::class);

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('tcp://[::1]:80/path?test=yes&hostname=reactphp.org#start')->willReturn(new Promise(function () { }, function () {
            throw new \RuntimeException();
        }));

        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->expects($this->never())->method('resolveAll');

        $uri = 'tcp://reactphp.org:80/path?test=yes#start';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);

        $ref = new \ReflectionProperty($builder, 'connectQueue');
        $ref->setAccessible(true);
        $ref->setValue($builder, ['::1', '::1']);

        $builder->check($this->expectCallableNever(), function () { });

        $builder->cleanUp();
    }

    public function testMixIpsIntoConnectQueueSometimesAssignsInOriginalOrder()
    {
        $loop = $this->createMock(LoopInterface::class);
        $connector = $this->createMock(ConnectorInterface::class);
        $resolver = $this->createMock(ResolverInterface::class);

        $uri = 'tcp://reactphp.org:80/path?test=yes#start';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        for ($i = 0; $i < 100; ++$i) {
            $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);
            $builder->mixIpsIntoConnectQueue(['::1', '::2']);

            $ref = new \ReflectionProperty($builder, 'connectQueue');
            $ref->setAccessible(true);
            $value = $ref->getValue($builder);

            if ($value === ['::1', '::2']) {
                break;
            }
        }

        $this->assertEquals(['::1', '::2'], $value);
    }

    public function testMixIpsIntoConnectQueueSometimesAssignsInReverseOrder()
    {
        $loop = $this->createMock(LoopInterface::class);
        $connector = $this->createMock(ConnectorInterface::class);
        $resolver = $this->createMock(ResolverInterface::class);

        $uri = 'tcp://reactphp.org:80/path?test=yes#start';
        $host = 'reactphp.org';
        $parts = parse_url($uri);

        for ($i = 0; $i < 100; ++$i) {
            $builder = new HappyEyeBallsConnectionBuilder($loop, $connector, $resolver, $uri, $host, $parts);
            $builder->mixIpsIntoConnectQueue(['::1', '::2']);

            $ref = new \ReflectionProperty($builder, 'connectQueue');
            $ref->setAccessible(true);
            $value = $ref->getValue($builder);

            if ($value === ['::2', '::1']) {
                break;
            }
        }

        $this->assertEquals(['::2', '::1'], $value);
    }
}
