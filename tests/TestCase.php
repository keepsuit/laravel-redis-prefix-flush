<?php

namespace Keepsuit\RedisPrefixFlush\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis as RedisFacade;
use Keepsuit\RedisPrefixFlush\RedisPrefixFlushServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Redis;

class TestCase extends Orchestra
{
    protected const PASSWORD = 'secret';

    /** @var list<int> */
    protected array $nodes = [];

    protected function getPackageProviders($app): array
    {
        return [
            RedisPrefixFlushServiceProvider::class,
        ];
    }

    /**
     * Point every connection at the given client and topology, as two apps sharing one database.
     *
     * Each app connects as an ACL user limited to its own prefix and denied FLUSHDB, FLUSHALL and KEYS.
     */
    protected function useRedis(string $client, string $topology, string $appAPrefix = 'app_a_'): void
    {
        $host = (getenv('REDIS_HOST') ?: '127.0.0.1');

        $this->nodes = $topology === 'cluster' ? [6380, 6381, 6382] : [6379];

        // ACL users are per node: a cluster needs them on every master.
        foreach ($this->nodes as $port) {
            $admin = $this->admin($port);
            $admin->flushAll();

            foreach (['app_a' => $appAPrefix, 'app_b' => 'app_b_'] as $user => $prefix) {
                $admin->rawCommand('ACL', 'SETUSER', $user, 'reset', 'on', '>'.self::PASSWORD, "~{$prefix}*", '&*', '+@all', '-flushdb', '-flushall', '-keys');
            }
        }

        $connection = fn (string $user, string $prefix) => $topology === 'cluster'
            ? [
                ['host' => $host, 'port' => 6380, 'username' => $user, 'password' => self::PASSWORD],
                'options' => [
                    'prefix' => $prefix,
                    // Laravel 12 ignores "username" on phpredis clusters; phpredis accepts [user, password].
                    'password' => $client === 'phpredis' ? [$user, self::PASSWORD] : self::PASSWORD,
                    'parameters' => ['username' => $user, 'password' => self::PASSWORD],
                ],
            ]
            : ['host' => $host, 'port' => 6379, 'username' => $user, 'password' => self::PASSWORD, 'options' => ['prefix' => $prefix]];

        $connections = [
            'app_a' => $connection('app_a', $appAPrefix),
            'app_a_locks' => $connection('app_a', 'app_a_locks_'),
            'app_b' => $connection('app_b', 'app_b_'),
            'app_b_locks' => $connection('app_b', 'app_b_locks_'),
        ];

        config()->set('database.redis', [
            'client' => $client,
            'options' => ['cluster' => 'redis'],
            ...($topology === 'cluster' ? ['clusters' => $connections] : $connections),
        ]);

        config()->set('cache.default', 'redis');
        config()->set('cache.prefix', 'cache_');
        config()->set('cache.stores.redis', ['driver' => 'redis', 'connection' => 'app_a', 'lock_connection' => 'app_a_locks']);
        config()->set('cache.stores.app_b', ['driver' => 'redis', 'connection' => 'app_b', 'lock_connection' => 'app_b_locks']);

        RedisFacade::purge();
        Cache::forgetDriver(['redis', 'app_b']);
    }

    protected function admin(int $port): Redis
    {
        $redis = new Redis;
        $redis->connect((getenv('REDIS_HOST') ?: '127.0.0.1'), $port);

        return $redis;
    }

    /**
     * Every key in the database, read as the unrestricted default user.
     *
     * @return list<string>
     */
    protected function keys(): array
    {
        $keys = array_merge(...array_map(fn (int $port) => $this->admin($port)->keys('*'), $this->nodes));

        sort($keys);

        return $keys;
    }
}
