<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Hyperf\NonceStore;

use Psr\SimpleCache\CacheInterface;
use Redis;
use Throwable;
use Wenbo\ReqResCrypto\Core\NonceStoreInterface;

final readonly class CacheNonceStore implements NonceStoreInterface
{
    /**
     * @param  Redis|null  $redis  可选 Redis 连接，提供 SET NX 原子写入。
     *                             未提供时降级为 PSR-16 has+set（存在理论竞态窗口）。
     */
    public function __construct(
        private CacheInterface $cache,
        private ?Redis $redis = null,
    ) {}

    public function exists(string $nonce): bool
    {
        try {
            return $this->cache->has('req_res_nonce:'.$nonce);
        } catch (Throwable) {
            // 缓存不可用时降级：视为 nonce 不存在，不阻断请求
            return false;
        }
    }

    public function store(string $nonce, int $ttlSeconds): bool
    {
        try {
            $key = 'req_res_nonce:'.$nonce;

            // 优先使用 Redis SET NX（真正原子的"不存在则写入"）
            if ($this->redis !== null) {
                return $this->redis->set($key, '1', ['nx', 'ex' => max($ttlSeconds, 1)]) === true;
            }

            // PSR-16 降级：先检查再写入（存在理论竞态窗口，高并发建议搭配 Redis）
            if ($this->cache->has($key)) {
                return false;
            }
            $this->cache->set($key, 1, $ttlSeconds);

            return true;
        } catch (Throwable) {
            // 缓存不可用时降级：视为首次写入，不阻断请求
            return true;
        }
    }
}
