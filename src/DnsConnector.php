<?php

namespace React\Socket;

use React\Dns\Resolver\ResolverInterface;
use React\Promise;
use React\Promise\CancellablePromiseInterface;

final class DnsConnector implements ConnectorInterface
{
    private $connector;
    private $resolver;

    public function __construct(ConnectorInterface $connector, ResolverInterface $resolver)
    {
        $this->connector = $connector;
        $this->resolver = $resolver;
    }

    public function connect($uri)
    {
        $original = $uri;
        if (\strpos($uri, '://') === false) {
            $uri = 'tcp://' . $uri;
            $parts = \parse_url($uri);
            unset($parts['scheme']);
        } else {
            $parts = \parse_url($uri);
        }

        if (!$parts || !isset($parts['host'])) {
            return Promise\reject(new \InvalidArgumentException('Given URI "' . $original . '" is invalid'));
        }

        $host = \trim($parts['host'], '[]');
        $connector = $this->connector;

        // skip DNS lookup / URI manipulation if this URI already contains an IP
        if (false !== \filter_var($host, \FILTER_VALIDATE_IP)) {
            return $connector->connect($original);
        }

        $promise = $this->resolver->resolve($host);
        $resolved = null;

        return new Promise\Promise(
            function ($resolve, $reject) use (&$promise, &$resolved, $uri, $connector, $host, $parts) {
                // resolve/reject with result of DNS lookup
                $promise->then(function ($ip) use (&$promise, &$resolved, $uri, $connector, $host, $parts) {
                    $resolved = $ip;

                    return $promise = $connector->connect(
                        Connector::uri($parts, $host, $ip)
                    )->then(null, function (\Exception $e) use ($uri) {
                        if ($e instanceof \RuntimeException) {
                            $message = \preg_replace('/^(Connection to [^ ]+)[&?]hostname=[^ &]+/', '$1', $e->getMessage());
                            $e = new \RuntimeException(
                                'Connection to ' . $uri . ' failed: ' . $message,
                                $e->getCode(),
                                $e
                            );
                        }

                        throw $e;
                    });
                }, function ($e) use ($uri, $reject) {
                    $reject(new \RuntimeException('Connection to ' . $uri .' failed during DNS lookup: ' . $e->getMessage(), 0, $e));
                })->then($resolve, $reject);
            },
            function ($_, $reject) use (&$promise, &$resolved, $uri) {
                // cancellation should reject connection attempt
                // reject DNS resolution with custom reason, otherwise rely on connection cancellation below
                if ($resolved === null) {
                    $reject(new \RuntimeException('Connection to ' . $uri . ' cancelled during DNS lookup'));
                }

                // (try to) cancel pending DNS lookup / connection attempt
                if ($promise instanceof CancellablePromiseInterface) {
                    // overwrite callback arguments for PHP7+ only, so they do not show
                    // up in the Exception trace and do not cause a possible cyclic reference.
                    $_ = $reject = null;

                    $promise->cancel();
                    $promise = null;
                }
            }
        );
    }
}
