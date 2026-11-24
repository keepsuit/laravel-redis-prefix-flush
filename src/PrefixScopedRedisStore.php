<?php

namespace Keepsuit\RedisPrefixFlush;

use Generator;
use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Predis\Client as PredisClient;
use Predis\Command\RawCommand;
use Predis\Connection\Replication\ReplicationInterface;
use Predis\Response\ErrorInterface;
use Redis;
use RedisCluster;
use RuntimeException;
use Traversable;

class PrefixScopedRedisStore extends RedisStore
{
    /**
     * Remove every key under the connection prefix and the cache prefix, instead of flushing the database.
     */
    public function flush(): bool
    {
        $this->deleteByPrefix($this->connection());

        return true;
    }

    /**
     * Remove every lock key under the lock connection prefix and the cache prefix.
     */
    public function flushLocks(): bool
    {
        // RedisStore::hasSeparateLockStore() does not exist on Laravel 12.
        if ($this->lockConnection === $this->connection) {
            throw new RuntimeException('Flushing locks is only supported when the lock store is separate from the cache store.');
        }

        $this->deleteByPrefix($this->lockConnection());

        return true;
    }

    protected function deleteByPrefix(Connection $connection): void
    {
        $connectionPrefix = match (true) {
            $connection instanceof PhpRedisConnection => (string) $connection->client()->getOption(Redis::OPT_PREFIX),
            $connection instanceof PredisConnection => (string) $connection->client()->getOptions()->prefix,
            default => throw new RuntimeException('Unsupported Redis connection ['.$connection::class.'].'),
        };

        $pattern = addcslashes($connectionPrefix.$this->prefix, '*?[]\\').'*';

        foreach ($this->scan($connection, $pattern) as $node => $keys) {
            // Scanned keys carry the connection prefix, which the client adds again on DEL.
            $keys = array_map(fn (string $key) => substr($key, strlen($connectionPrefix)), $keys);

            if ($connection instanceof PredisClusterConnection) {
                // Delete on the node that returned the keys: iterating a Predis cluster resets its slot map.
                $replies = $node->pipeline(function ($pipe) use ($keys) {
                    foreach ($keys as $key) {
                        $pipe->del($key);
                    }
                });
            } else {
                $replies = [$connection->del(...$keys)];
            }

            // phpredis on a cluster, and Predis without exceptions, return failed replies instead of throwing.
            foreach ($replies as $reply) {
                if ($reply === false || $reply instanceof ErrorInterface) {
                    $error = $reply instanceof ErrorInterface ? $reply->getMessage() : $connection->client()->getLastError();

                    throw new RuntimeException("Deleting cache keys failed: {$error}");
                }
            }
        }
    }

    /**
     * Scan every master node with raw commands, so no client prefix or scan option alters the pattern.
     *
     * @return Generator<mixed, list<string>> scanned keys, keyed by the node that holds them
     */
    protected function scan(Connection $connection, string $pattern): Generator
    {
        $client = $connection->client();

        $nodes = match (true) {
            $client instanceof RedisCluster => $client->_masters(),
            $client instanceof Redis => [null],
            // Predis 2 cannot iterate a replication client.
            $client->getConnection() instanceof Traversable => $client,
            default => [$client],
        };

        foreach ($nodes as $node) {
            // Predis sends SCAN to a replica, and retries it on one when the master fails: a lagging replica misses keys.
            $predisConnection = $node instanceof PredisClient ? $node->getConnection() : null;
            if ($predisConnection instanceof ReplicationInterface) {
                $predisConnection = $predisConnection->getMaster();
            }

            $cursor = '0';

            do {
                $reply = match (true) {
                    $client instanceof RedisCluster => $client->rawCommand($node, 'SCAN', $cursor, 'MATCH', $pattern, 'COUNT', 1000),
                    $client instanceof Redis => $client->rawCommand('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', 1000),
                    $predisConnection !== null => $predisConnection->executeCommand(new RawCommand('SCAN', [$cursor, 'MATCH', $pattern, 'COUNT', '1000'])),
                    default => throw new RuntimeException('Unsupported Redis client ['.$client::class.'].'),
                };

                if (! is_array($reply)) {
                    $error = $reply instanceof ErrorInterface ? $reply->getMessage() : $client->getLastError();

                    throw new RuntimeException("Scanning cache keys failed: {$error}");
                }

                [$cursor, $keys] = $reply;

                if ($keys !== []) {
                    yield $node => $keys;
                }
            } while ((string) $cursor !== '0');
        }
    }
}
