<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Hyperf\Tests\NonceStore;

use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Psr\SimpleCache\CacheInterface;
use Wenbo\ReqResCrypto\Hyperf\NonceStore\CacheNonceStore;
use Wenbo\ReqResCrypto\Hyperf\Tests\TestCase;

final class CacheNonceStoreTest extends TestCase
{
    #[Test]
    public function exists_returns_true_when_cache_has_nonce(): void
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('has')
            ->once()
            ->with('req_res_nonce:'.$nonce)
            ->andReturn(true);

        $store = new CacheNonceStore($cache);

        $this->assertTrue($store->exists($nonce));
    }

    #[Test]
    public function exists_returns_false_when_cache_does_not_have_nonce(): void
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('has')->andReturn(false);

        $store = new CacheNonceStore($cache);

        $this->assertFalse($store->exists($nonce));
    }

    #[Test]
    public function store_sets_nonce_in_cache_with_correct_ttl_and_returns_true(): void
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ttl = 300;

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('has')
            ->once()
            ->with('req_res_nonce:'.$nonce)
            ->andReturn(false);
        $cache->shouldReceive('set')
            ->once()
            ->with('req_res_nonce:'.$nonce, 1, $ttl);

        $store = new CacheNonceStore($cache);

        $this->assertTrue($store->store($nonce, $ttl));
    }

    #[Test]
    public function store_returns_false_when_nonce_already_exists(): void
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('has')
            ->once()
            ->with('req_res_nonce:'.$nonce)
            ->andReturn(true);
        $cache->shouldNotReceive('set');

        $store = new CacheNonceStore($cache);

        $this->assertFalse($store->store($nonce, 300));
    }

    #[Test]
    public function exists_returns_true_after_store(): void
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('has')
            ->with('req_res_nonce:'.$nonce)
            ->andReturn(false, true); // store: false, exists: true
        $cache->shouldReceive('set')->andReturn(true);

        $store = new CacheNonceStore($cache);
        $store->store($nonce, 300);

        $this->assertTrue($store->exists($nonce));
    }

    #[Test]
    public function different_nonces_are_stored_independently(): void
    {
        $nonceA = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $nonceB = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $stored = [];

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('set')->andReturnUsing(function (string $key) use (&$stored) {
            $stored[$key] = true;

            return true;
        });
        $cache->shouldReceive('has')->andReturnUsing(function (string $key) use (&$stored): bool {
            return isset($stored[$key]);
        });

        $store = new CacheNonceStore($cache);
        $this->assertTrue($store->store($nonceA, 300));
        $this->assertTrue($store->store($nonceB, 300));
        // 存储后重复写入应返回 false
        $this->assertFalse($store->store($nonceA, 300));

        $this->assertTrue($store->exists($nonceA));
        $this->assertTrue($store->exists($nonceB));
    }

    #[Test]
    public function store_accepts_zero_ttl(): void
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $cache = Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('has')
            ->once()
            ->with('req_res_nonce:'.$nonce)
            ->andReturn(false);
        $cache->shouldReceive('set')
            ->once()
            ->with('req_res_nonce:'.$nonce, 1, 0);

        $store = new CacheNonceStore($cache);

        $this->assertTrue($store->store($nonce, 0));
    }

    #[Test]
    public function store_uses_redis_set_nx_when_redis_is_provided(): void
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ttl = 300;

        $cache = Mockery::mock(CacheInterface::class);
        // cache 方法不应被调用（Redis 优先）
        $cache->shouldNotReceive('has');
        $cache->shouldNotReceive('set');

        $redis = Mockery::mock(\Redis::class);
        $redis->shouldReceive('set')
            ->once()
            ->with('req_res_nonce:'.$nonce, '1', ['nx', 'ex' => $ttl])
            ->andReturn(true);

        $store = new CacheNonceStore($cache, $redis);

        $this->assertTrue($store->store($nonce, $ttl));
    }

    #[Test]
    public function store_returns_false_when_redis_set_nx_fails(): void
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $cache = Mockery::mock(CacheInterface::class);

        $redis = Mockery::mock(\Redis::class);
        $redis->shouldReceive('set')
            ->once()
            ->with('req_res_nonce:'.$nonce, '1', ['nx', 'ex' => 300])
            ->andReturn(false);

        $store = new CacheNonceStore($cache, $redis);

        $this->assertFalse($store->store($nonce, 300));
    }
}
