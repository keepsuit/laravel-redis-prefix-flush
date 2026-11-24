<?php

namespace Keepsuit\RedisPrefixFlush;

use Illuminate\Cache\CacheManager;
use Illuminate\Foundation\Application;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class RedisPrefixFlushServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('laravel-redis-prefix-flush');
    }

    public function packageRegistered(): void
    {
        $this->callAfterResolving('cache', function (CacheManager $cache) {
            // Bound to the CacheManager: keep in sync with CacheManager::createRedisDriver().
            $cache->extend('redis', function (Application $app, array $config) {
                $connection = $config['connection'] ?? 'default';

                $store = new PrefixScopedRedisStore(
                    $app['redis'],
                    $this->getPrefix($config),
                    $connection,
                    $this->getSerializableClasses($config),
                );

                return $this->repository(
                    $store->setLockConnection($config['lock_connection'] ?? $connection),
                    $config
                );
            });
        });
    }
}
