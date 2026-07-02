<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Hyperf\Command;

use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\DbConnection\Db;

use function Hyperf\Config\config;

#[Command]
final class ActivateKeyCommand extends HyperfCommand
{
    protected ?string $signature = 'req-res-crypto:keys:activate {key_id? : The key ID to activate}';

    protected string $description = 'Activate a pre-issued key (oldest by default)';

    public function handle(): int
    {
        $table = config('req-res-crypto.database.table');
        $keyId = $this->input->getArgument('key_id');
        $now = date('Y-m-d H:i:s');

        if ($keyId !== null) {
            $row = Db::table($table)->where('key_id', $keyId)->first();
            if ($row === null) {
                $this->error("KeyID '{$keyId}' not found.");

                return self::FAILURE;
            }
            if ($row->status !== 'pre_issued') {
                $this->error("KeyID '{$keyId}' is not in pre_issued status.");

                return self::FAILURE;
            }
        } else {
            $row = Db::table($table)
                ->where('status', 'pre_issued')
                ->where(function ($q) use ($now) {
                    $q->whereNull('activated_at')
                        ->orWhere('activated_at', '<=', $now);
                })
                ->orderBy('issued_at')
                ->first();

            if ($row === null) {
                $this->info('No pre_issued key ready for activation.');

                return self::SUCCESS;
            }
        }

        Db::transaction(static function () use ($table, $row, $now) {
            Db::table($table)
                ->where('status', 'current')
                ->update([
                    'status' => 'expired',
                    'expired_at' => $now,
                    'updated_at' => $now,
                ]);

            Db::table($table)
                ->where('id', $row->id)
                ->update([
                    'status' => 'current',
                    'activated_at' => $now,
                    'updated_at' => $now,
                ]);
        });

        $this->info("Activated KeyID: {$row->key_id}");

        return self::SUCCESS;
    }
}
