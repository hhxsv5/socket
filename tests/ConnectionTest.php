<?php

namespace React\Tests\Socket;

use React\EventLoop\LoopInterface;
use React\Socket\Connection;

class ConnectionTest extends TestCase
{
    public function testCloseConnectionWillCloseSocketResource()
    {
        $resource = fopen('php://memory', 'r+');
        $loop = $this->createMock(LoopInterface::class);

        $connection = new Connection($resource, $loop);
        $connection->close();

        $this->assertFalse(is_resource($resource));
    }

    public function testCloseConnectionWillRemoveResourceFromLoopBeforeClosingResource()
    {
        $resource = fopen('php://memory', 'r+');
        $loop = $this->createMock(LoopInterface::class);
        $loop->expects($this->once())->method('addWriteStream')->with($resource);

        $onRemove = null;
        $loop->expects($this->once())->method('removeWriteStream')->with($this->callback(function ($param) use (&$onRemove) {
            $onRemove = is_resource($param);
            return true;
        }));

        $connection = new Connection($resource, $loop);
        $connection->write('test');
        $connection->close();

        $this->assertTrue($onRemove);
        $this->assertFalse(is_resource($resource));
    }
}
