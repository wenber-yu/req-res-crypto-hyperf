<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Hyperf\KeyProvider;

use Hyperf\DbConnection\Db;
use Wenbo\ReqResCrypto\Core\Exceptions\KeyException;
use Wenbo\ReqResCrypto\Core\ServerKey;
use Wenbo\ReqResCrypto\Core\ServerKeyProviderInterface;

/**
 * 数据库密钥提供者，直接从密钥表查询，供 Artisan 命令使用。
 */
final readonly class DatabaseKeyProvider implements ServerKeyProviderInterface
{
    public function __construct(
        private string $table,
    ) {}

    public function getCurrentKey(): ?ServerKey
    {
        return $this->fetchKeyByStatus('current');
    }

    public function getPreIssuedKey(): ?ServerKey
    {
        return $this->fetchKeyByStatus('pre_issued');
    }

    private function fetchKeyByStatus(string $status): ?ServerKey
    {
        try {
            $row = Db::table($this->table)
                ->where('status', $status)
                ->first();
        } catch (\PDOException $e) {
            throw KeyException::databaseError($e->getMessage());
        } catch (\Throwable $e) {
            throw KeyException::notFound($status);
        }

        if ($row === null) {
            return null;
        }

        return new ServerKey(
            keyId: (string) $row->key_id,
            signSecretKey: hex2bin((string) $row->sign_secret_key) ?: '',
            signPublicKey: hex2bin((string) $row->sign_public_key) ?: '',
            exchangeSecretKey: hex2bin((string) $row->exchange_secret_key) ?: '',
            exchangePublicKey: hex2bin((string) $row->exchange_public_key) ?: '',
        );
    }
}
