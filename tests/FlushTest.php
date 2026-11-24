<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Events\CacheFlushed;
use Illuminate\Cache\Events\CacheFlushing;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Keepsuit\RedisPrefixFlush\PrefixScopedRedisStore;

it('denies FLUSHDB to the app users', function (string $client, string $topology) {
    $this->useRedis($client, $topology);

    expect(fn () => Redis::connection('app_a')->flushdb())->toThrow(Exception::class);
})->with('redis');

it('flushes only the cache keys of the app', function (string $client, string $topology) {
    $this->useRedis($client, $topology);

    foreach (range(1, 50) as $i) {
        Cache::put("key-$i", $i);
        Cache::store('app_b')->put("key-$i", $i);
    }
    Redis::connection('app_a')->set('queues:default', 'job');
    Redis::connection('app_a')->set('horizon:supervisors', 'x');
    Redis::connection('app_a')->set('session-id', 'x');
    Redis::connection('app_b')->set('queues:default', 'job');

    $appBKeys = array_values(array_filter($this->keys(), fn (string $key) => str_starts_with($key, 'app_b_')));

    expect(Cache::flush())->toBeTrue();

    expect($this->keys())->toBe([
        'app_a_horizon:supervisors',
        'app_a_queues:default',
        'app_a_session-id',
        ...$appBKeys,
    ]);
    expect($appBKeys)->toHaveCount(51);
})->with('redis');

it('scopes the flush to the connection prefix when the cache prefix is empty', function (string $client, string $topology) {
    $this->useRedis($client, $topology);
    config()->set('cache.prefix', '');

    Cache::put('key', 'value');
    Cache::store('app_b')->put('key', 'value');

    Cache::flush();

    expect($this->keys())->toBe(['app_b_key']);
})->with('redis');

it('does not widen the scope on glob characters in the prefix', function (string $client, string $topology) {
    $this->useRedis($client, $topology);
    config()->set('cache.prefix', 'x*');

    Cache::put('key', 'value');
    Redis::connection('app_a')->set('xy', 'value');

    Cache::flush();

    expect($this->keys())->toBe(['app_a_xy']);
})->with('redis');

it('flushes through the cache commands', function (string $client, string $topology, string $command) {
    $this->useRedis($client, $topology);
    Event::fake(['cache:clearing', 'cache:cleared']);

    Cache::put('key', 'value');

    $this->artisan($command)->assertSuccessful();

    expect($this->keys())->toBe([]);
    Event::assertDispatched('cache:cleared');
})->with('redis')->with(['cache:clear', 'optimize:clear']);

it('emits the flush events on clear', function (string $client, string $topology) {
    $this->useRedis($client, $topology);
    Event::fake([CacheFlushing::class, CacheFlushed::class]);

    Cache::put('key', 'value');

    expect(Cache::clear())->toBeTrue()
        ->and(Cache::store('redis')->flush())->toBeTrue()
        ->and($this->keys())->toBe([]);
    Event::assertDispatched(CacheFlushing::class, fn (CacheFlushing $event) => $event->storeName === 'redis');
    Event::assertDispatched(CacheFlushed::class, fn (CacheFlushed $event) => $event->storeName === 'redis');
})->with('redis');

it('flushes only the lock keys of the app', function (string $client, string $topology) {
    $this->useRedis($client, $topology);

    Cache::lock('lock-a')->get();
    Cache::store('app_b')->lock('lock-b')->get();
    Cache::put('key', 'value');
    Redis::connection('app_a_locks')->set('queues:default', 'job');

    $this->artisan('cache:clear', ['--locks' => true])->assertSuccessful();

    expect($this->keys())->toBe([
        'app_a_cache_key',
        'app_a_locks_queues:default',
        'app_b_locks_cache_lock-b',
    ]);
})->with('redis')->skip(! method_exists(Repository::class, 'flushLocks'), 'Flushing locks requires Laravel 13');

it('refuses to flush locks stored with the cache', function () {
    $this->useRedis('phpredis', 'single');
    config()->set('cache.stores.redis.lock_connection', 'app_a');

    expect(fn () => Cache::store('redis')->getStore()->flushLocks())->toThrow(RuntimeException::class);
});

it('keeps tagged cache flush working', function (string $client, string $topology) {
    $this->useRedis($client, $topology);

    Cache::tags(['tag'])->put('tagged', 'value');
    Cache::put('untagged', 'value');

    expect(Cache::tags(['tag'])->flush())->toBeTrue();

    expect(Cache::tags(['tag'])->get('tagged'))->toBeNull()
        ->and(Cache::get('untagged'))->toBe('value');
})->with('redis');

it('replaces only the redis driver', function () {
    $this->useRedis('phpredis', 'single');
    config()->set('cache.serializable_classes', [stdClass::class]);

    $store = Cache::store('redis')->getStore();

    expect($store)->toBeInstanceOf(PrefixScopedRedisStore::class)
        ->and($store->getPrefix())->toBe('cache_')
        ->and($store->connection()->getName())->toBe('app_a')
        ->and($store->lockConnection()->getName())->toBe('app_a_locks')
        ->and((fn () => $this->serializableClasses)->call($store))->toBe([stdClass::class])
        ->and(Cache::store('array')->getStore())->toBeInstanceOf(ArrayStore::class);
});

it('flushes every node from a fresh connection', function (string $client, string $topology) {
    $this->useRedis($client, $topology);

    foreach (range(1, 50) as $i) {
        Cache::put("key-$i", $i);
    }

    Redis::purge();
    Cache::forgetDriver('redis');

    Cache::flush();

    expect($this->keys())->toBe([]);
})->with('redis');

it('deletes every key when both prefixes are empty', function (string $client, string $topology) {
    $this->useRedis($client, $topology, appAPrefix: '');
    config()->set('cache.prefix', '');

    Cache::put('key', 'value');
    Cache::store('app_b')->put('key', 'value');

    Cache::flush();

    expect($this->keys())->toBe([]);
})->with('redis');
