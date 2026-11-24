# Flush only your application's keys from a shared Redis

[![Latest Version on Packagist](https://img.shields.io/packagist/v/keepsuit/laravel-redis-prefix-flush.svg?style=flat-square)](https://packagist.org/packages/keepsuit/laravel-redis-prefix-flush)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/keepsuit/laravel-redis-prefix-flush/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/keepsuit/laravel-redis-prefix-flush/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/keepsuit/laravel-redis-prefix-flush/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/keepsuit/laravel-redis-prefix-flush/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/keepsuit/laravel-redis-prefix-flush.svg?style=flat-square)](https://packagist.org/packages/keepsuit/laravel-redis-prefix-flush)

Laravel's Redis cache store flushes with `FLUSHDB`. When several applications share one Redis or Valkey database and are separated only by key prefix, `php artisan cache:clear` in one application empties the cache of every application, and `cache:clear --locks` empties the whole lock database, queues and sessions included with the default configuration. With per-application ACL users that are denied `FLUSHDB`, both commands fail.

This package replaces the `redis` cache driver with a store that deletes only the keys of the application's cache.

## Installation

```bash
composer require keepsuit/laravel-redis-prefix-flush
```

No configuration is needed: every cache store with `'driver' => 'redis'` uses the new store. It is built exactly like Laravel's own Redis store (`connection`, `lock_connection`, `prefix`, `serializable_classes`) and differs only in `flush()` and `flushLocks()`. Stores with other drivers are not affected.

The package registers its store under the `redis` driver name. If another package also extends the `redis` driver, the extension registered last wins.

## Flush scope

`flush()` deletes, on the cache connection, every key that starts with:

```
<Redis connection prefix><cache prefix>
```

With Laravel's default configuration (`APP_NAME=Shop`), that is `shop-database-shop-cache-*`. Keys of the same application outside the cache prefix (queues, sessions, Horizon) and keys of other applications are not touched.

- `flushLocks()` does the same on the lock connection. As in Laravel, it requires the lock connection to differ from the cache connection.
- With an empty cache prefix, the scope is the connection prefix.
- With both prefixes empty, every key of the database is deleted, one batch at a time. The result is the same as `FLUSHDB`, but the command is never sent.

Keys are found with `SCAN ... MATCH` in batches of 1000 and deleted with `UNLINK`, which frees memory in the background (with Predis before 3.5, `DEL`). `FLUSHDB`, `FLUSHALL` and `KEYS` are never sent. Glob characters in the prefixes are escaped, so they cannot widen the scope.

Unlike `FLUSHDB`, the flush is not atomic: a key written while the scan is running may survive it.

`cache:clear`, `optimize:clear`, `Cache::flush()`, `Cache::clear()`, `Cache::store('redis')->flush()` and `Cache::tags(...)->flush()` keep working and emit the same events as before.

## Supported setups

- Laravel 12.53+ and 13.
- phpredis and Predis.
- A single node and Redis Cluster. On a cluster every master node is scanned, and keys are deleted without multi-key commands that span hash slots.
- Predis replication (master and replicas): keys are scanned on the master, so a replica that lags behind cannot hide keys from the flush.
- Redis 4.0+ and Valkey.

`cache:clear --locks` and `flushLocks()` on the cache repository exist from Laravel 13. On Laravel 12 the store still provides `flushLocks()`, but Laravel offers no command to call it.

## Valkey and ACL

Each application can connect with its own ACL user, limited to its prefix and denied the commands that act on the whole database:

```
ACL SETUSER shop on >password ~shop-database-* &* +@all -flushdb -flushall -keys
```

```php
// config/database.php
'redis' => [
    'cache' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_CACHE_DB', '1'),
    ],
],
```

On a cluster the ACL user must exist on every node. On Laravel 12, phpredis clusters ignore `username`: pass the credentials as `'password' => [username, password]` in the cluster options.

## Testing

The tests run against Valkey, a single node on port 6379, a node on port 6390 that acts as a lagging replica, and a three-master cluster on ports 6380-6382, and need the `redis` PHP extension:

```bash
docker compose up -d --wait
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Credits

- [Fabio Capucci](https://github.com/keepsuit)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
