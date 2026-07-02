<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Hyperf\Crontab;

use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\DbConnection\Db;
use Wenbo\ReqResCrypto\Core\KeyPair;

use function Hyperf\Config\config;

final class KeyRotationCrontab
{
    public function __construct(
        private readonly StdoutLoggerInterface $logger,
    ) {}

    public function execute(): void
    {
        if (! config('req-res-crypto.key_rotation.enabled', false)) {
            $this->logger->info('req-res-crypto: key rotation is disabled, skipping.');

            return;
        }

        $table = config('req-res-crypto.database.table');

        $signKeyPair = KeyPair::generate();
        $exchangeKeyPair = KeyPair::generate();
        $keyId = bin2hex(random_bytes(4));

        $now = date('Y-m-d H:i:s');

        Db::table($table)->insert([
            'key_id' => $keyId,
            'sign_public_key' => bin2hex($signKeyPair->signPublicKey),
            'sign_secret_key' => bin2hex($signKeyPair->signSecretKey),
            'exchange_public_key' => bin2hex($exchangeKeyPair->exchangePublicKey),
            'exchange_secret_key' => bin2hex($exchangeKeyPair->exchangeSecretKey),
            'status' => 'pre_issued',
            'issued_at' => $now,
            'activated_at' => null,
            'expired_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->logger->info("req-res-crypto: rotated keys — KeyID: {$keyId}");
    }
}
