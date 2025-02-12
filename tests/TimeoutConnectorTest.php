<?php

namespace React\Tests\Socket;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use React\Socket\TimeoutConnector;
use function React\Promise\reject;
use function React\Promise\resolve;

class TimeoutConnectorTest extends TestCase
{
    public function testConstructWithoutLoopAssignsLoopAutomatically()
    {
        $base = $this->createMock(ConnectorInterface::class);

        $connector = new TimeoutConnector($base, 0.01);

        $ref = new \ReflectionProperty($connector, 'loop');
        $ref->setAccessible(true);
        $loop = $ref->getValue($connector);

        $this->assertInstanceOf(LoopInterface::class, $loop);
    }

    public function testRejectsPromiseWithoutStartingTimerWhenWrappedConnectorReturnsRejectedPromise()
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->never())->method('addTimer');
        $loop->expects($this->never())->method('cancelTimer');

        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn(reject(new \RuntimeException('Failed', 42)));

        $timeout = new TimeoutConnector($connector, 5.0, $loop);

        $promise = $timeout->connect('example.com:80');

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertEquals('Failed', $exception->getMessage());
        $this->assertEquals(42, $exception->getCode());
    }

    public function testRejectsPromiseAfterCancellingTimerWhenWrappedConnectorReturnsPendingPromiseThatRejects()
    {
        $timer = $this->createMock(TimerInterface::class);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(5.0, $this->anything())->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $deferred = new Deferred();
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn($deferred->promise());

        $timeout = new TimeoutConnector($connector, 5.0, $loop);

        $promise = $timeout->connect('example.com:80');

        $deferred->reject(new \RuntimeException('Failed', 42));

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertEquals('Failed', $exception->getMessage());
        $this->assertEquals(42, $exception->getCode());
    }

    public function testResolvesPromiseWithoutStartingTimerWhenWrappedConnectorReturnsResolvedPromise()
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->never())->method('addTimer');
        $loop->expects($this->never())->method('cancelTimer');

        $connection = $this->createMock(ConnectionInterface::class);
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn(resolve($connection));

        $timeout = new TimeoutConnector($connector, 5.0, $loop);

        $promise = $timeout->connect('example.com:80');

        $resolved = null;
        $promise->then(function ($value) use (&$resolved) {
            $resolved = $value;
        });

        $this->assertSame($connection, $resolved);
    }

    public function testResolvesPromiseAfterCancellingTimerWhenWrappedConnectorReturnsPendingPromiseThatResolves()
    {
        $timer = $this->createMock(TimerInterface::class);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(5.0, $this->anything())->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $deferred = new Deferred();
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn($deferred->promise());

        $timeout = new TimeoutConnector($connector, 5.0, $loop);

        $promise = $timeout->connect('example.com:80');

        $connection = $this->createMock(ConnectionInterface::class);
        $deferred->resolve($connection);

        $resolved = null;
        $promise->then(function ($value) use (&$resolved) {
            $resolved = $value;
        });

        $this->assertSame($connection, $resolved);
    }

    public function testRejectsPromiseAndCancelsPendingConnectionWhenTimeoutTriggers()
    {
        $timerCallback = null;
        $timer = $this->createMock(TimerInterface::class);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(0.01, $this->callback(function ($callback) use (&$timerCallback) {
            $timerCallback = $callback;
            return true;
        }))->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $cancelled = 0;
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn(new Promise(function () { }, function () use (&$cancelled) {
            ++$cancelled;
            throw new \RuntimeException();
        }));

        $timeout = new TimeoutConnector($connector, 0.01, $loop);

        $promise = $timeout->connect('example.com:80');

        $this->assertEquals(0, $cancelled);

        $this->assertNotNull($timerCallback);
        $timerCallback();

        $this->assertEquals(1, $cancelled);

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertEquals('Connection to example.com:80 timed out after 0.01 seconds (ETIMEDOUT)' , $exception->getMessage());
        $this->assertEquals(\defined('SOCKET_ETIMEDOUT') ? \SOCKET_ETIMEDOUT : 110, $exception->getCode());
    }

    public function testCancellingPromiseWillCancelPendingConnectionAndRejectPromise()
    {
        $timer = $this->createMock(TimerInterface::class);
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addTimer')->with(0.01, $this->anything())->willReturn($timer);
        $loop->expects($this->once())->method('cancelTimer')->with($timer);

        $cancelled = 0;
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn(new Promise(function () { }, function () use (&$cancelled) {
            ++$cancelled;
            throw new \RuntimeException('Cancelled');
        }));

        $timeout = new TimeoutConnector($connector, 0.01, $loop);

        $promise = $timeout->connect('example.com:80');

        $this->assertEquals(0, $cancelled);

        assert(method_exists($promise, 'cancel'));
        $promise->cancel();

        $this->assertEquals(1, $cancelled);

        $exception = null;
        $promise->then(null, function ($reason) use (&$exception) {
            $exception = $reason;
        });

        assert($exception instanceof \RuntimeException);
        $this->assertEquals('Cancelled', $exception->getMessage());
    }

    public function testRejectionDuringConnectionShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $connection = new Deferred();
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn($connection->promise());

        $timeout = new TimeoutConnector($connector, 0.01);

        $promise = $timeout->connect('example.com:80');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        $connection->reject(new \RuntimeException('Connection failed'));
        unset($promise, $connection);

        $this->assertEquals(0, gc_collect_cycles());
    }

    public function testRejectionDueToTimeoutShouldNotCreateAnyGarbageReferences()
    {
        if (class_exists('React\Promise\When')) {
            $this->markTestSkipped('Not supported on legacy Promise v1 API');
        }

        while (gc_collect_cycles()) {
            // collect all garbage cycles
        }

        $connection = new Deferred(function () {
            throw new \RuntimeException('Connection cancelled');
        });
        $connector = $this->createMock(ConnectorInterface::class);
        $connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn($connection->promise());

        $timeout = new TimeoutConnector($connector, 0);

        $promise = $timeout->connect('example.com:80');

        $promise->then(null, $this->expectCallableOnce()); // avoid reporting unhandled rejection

        Loop::run();
        unset($promise, $connection);

        $this->assertEquals(0, gc_collect_cycles());
    }
}
