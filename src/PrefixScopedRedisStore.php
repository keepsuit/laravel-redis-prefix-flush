<?php

namespace Keepsuit\RedisPrefixFlush;

use Generator;
use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Predis\Client as PredisClient;
use Predis\Connection\Replication\ReplicationInterface;
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

        // Predis registers UNLINK only from 3.5.
        $delete = $connection instanceof PredisConnection && ! $connection->client()->getCommandFactory()->supports('UNLINK')
            ? 'del'
            : 'unlink';

        foreach ($this->scan($connection, $pattern) as $node => $keys) {
            // Scanned keys carry the connection prefix, which the client adds again on delete.
            $keys = array_map(fn (string $key) => substr($key, strlen($connectionPrefix)), $keys);

            if ($connection instanceof PredisClusterConnection) {
                // Delete on the node that returned the keys: iterating a Predis cluster resets its slot map.
                $node->pipeline(function ($pipe) use ($keys, $delete) {
                    foreach ($keys as $key) {
                        $pipe->{$delete}($key);
                    }
                });
            } else {
                $connection->{$delete}(...$keys);
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
            // Predis sends SCAN to a replica, which misses keys not yet replicated.
            if ($node instanceof PredisClient && $node->getConnection() instanceof ReplicationInterface) {
                $node->getConnection()->switchToMaster();
            }

            $cursor = '0';

            do {
                [$cursor, $keys] = match (true) {
                    $client instanceof RedisCluster => $client->rawCommand($node, 'SCAN', $cursor, 'MATCH', $pattern, 'COUNT', 1000),
                    $client instanceof Redis => $client->rawCommand('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', 1000),
                    default => $node->executeRaw(['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '1000']),
                };

                if ($keys !== []) {
                    yield $node => $keys;
                }
            } while ((string) $cursor !== '0');
        }
    }
}
