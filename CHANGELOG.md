# Changelog

All notable changes to `laravel-redis-prefix-flush` will be documented in this file.

## 0.1.0 - 2026-11-25

First release.

Replaces Laravel's `redis` cache driver with a store whose `flush()` and `flushLocks()` delete only the keys under the Redis connection prefix and the cache prefix, instead of sending `FLUSHDB`. Applications that share one Redis or Valkey database can run `cache:clear` without emptying each other's cache, and per-application ACL users denied `FLUSHDB` can flush again.

### Features

- Drop-in: every store with `'driver' => 'redis'` uses the new store, no configuration needed.
- Keys are found with `SCAN ... MATCH ... COUNT 1000` and deleted with `DEL`. `FLUSHDB`, `FLUSHALL` and `KEYS` are never sent; glob characters in the prefixes are escaped.
- A failed `SCAN` or `DEL` throws instead of reporting success.
- phpredis and Predis, single node and Redis Cluster (every master is scanned, no cross-slot multi-key commands).
- Predis replication: keys are scanned on the master, so a lagging replica cannot hide keys from the flush.
- Redis and Valkey, Laravel 12.53+ and 13, PHP 8.3+.

### Notes

- Unlike `FLUSHDB`, the flush is not atomic: a key written during the scan may survive it.
- `cache:clear --locks` exists from Laravel 13; on Laravel 12 the store provides `flushLocks()` but Laravel has no command for it.

**Full Changelog**: https://github.com/keepsuit/laravel-redis-prefix-flush/commits/0.1.0
