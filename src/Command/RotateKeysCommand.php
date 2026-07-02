<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Hyperf\Command;

use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\DbConnection\Db;
use Wenbo\ReqResCrypto\Core\KeyPair;

use function Hyperf\Config\config;

#[Command]
final class RotateKeysCommand extends HyperfCommand
{
    protected ?string $signature = 'req-res-crypto:keys:rotate';

    protected string $description = 'Generate a new key pair and insert as pre_issued';

    public function handle(): int
    {
        if (! config('req-res-crypto.key_rotation.enabled', false)) {
            $this->info('Key rotation is disabled.');

            return self::FAILURE;
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

        $this->info("Rotated keys — KeyID: {$keyId}");
        $this->info('  sign_public_key: '.bin2hex($signKeyPair->signPublicKey));
        $this->info('  exchange_public_key: '.bin2hex($exchangeKeyPair->exchangePublicKey));

        return self::SUCCESS;
    }
}
