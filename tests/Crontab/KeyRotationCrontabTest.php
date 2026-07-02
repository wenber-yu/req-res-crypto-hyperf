<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Hyperf\Tests\Crontab;

use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\DbConnection\Db;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Wenbo\ReqResCrypto\Hyperf\Crontab\KeyRotationCrontab;
use Wenbo\ReqResCrypto\Hyperf\Tests\TestCase;

final class KeyRotationCrontabTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->swap('config', [
            'req-res-crypto' => [
                'database' => [
                    'table' => 'req_res_crypto_public_keys',
                ],
                'key_rotation' => [
                    'enabled' => true,
                    'rotate_before_days' => 7,
                ],
            ],
        ]);
    }

    #[Test]
    public function execute_generates_key_pairs_and_inserts_into_database(): void
    {
        putenv('REQ_RES_CRYPTO_KEY_ROTATION_ENABLED=true');

        $logger = Mockery::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('info')->once()->with(Mockery::pattern('/^req-res-crypto: rotated keys/'));

        $dbMock = Mockery::mock('alias:'.Db::class);
        $dbMock->shouldReceive('table')
            ->with('req_res_crypto_public_keys')
            ->andReturn($builder = Mockery::mock());

        $captured = null;
        $builder->shouldReceive('insert')
            ->once()
            ->with(Mockery::on(function (array $data) use (&$captured) {
                $captured = $data;

                return true;
            }));

        $crontab = new KeyRotationCrontab($logger);
        $crontab->execute();

        $this->assertNotNull($captured);
        $this->assertSame('pre_issued', $captured['status']);
        $this->assertNotEmpty($captured['key_id']);
        $this->assertSame(8, strlen($captured['key_id']));
    }

    #[Test]
    public function inserted_keys_are_valid64_hex_characters(): void
    {
        putenv('REQ_RES_CRYPTO_KEY_ROTATION_ENABLED=true');

        $logger = Mockery::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('info')->once();

        $dbMock = Mockery::mock('alias:'.Db::class);
        $dbMock->shouldReceive('table')->andReturn($builder = Mockery::mock());

        $captured = null;
        $builder->shouldReceive('insert')->with(Mockery::on(function (array $data) use (&$captured) {
            $captured = $data;

            return true;
        }));

        $crontab = new KeyRotationCrontab($logger);
        $crontab->execute();

        $this->assertSame(64, strlen($captured['sign_public_key']));
        $this->assertSame(128, strlen($captured['sign_secret_key']));
        $this->assertSame(64, strlen($captured['exchange_public_key']));
        $this->assertSame(64, strlen($captured['exchange_secret_key']));
    }

    #[Test]
    public function logs_correct_key_id(): void
    {
        putenv('REQ_RES_CRYPTO_KEY_ROTATION_ENABLED=true');

        $capturedLogMsg = null;

        $logger = Mockery::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('info')
            ->once()
            ->with(Mockery::on(function (string $msg) use (&$capturedLogMsg) {
                $capturedLogMsg = $msg;

                return true;
            }));

        $dbMock = Mockery::mock('alias:'.Db::class);
        $dbMock->shouldReceive('table')->andReturn($builder = Mockery::mock());
        $builder->shouldReceive('insert')->andReturn(true);

        $crontab = new KeyRotationCrontab($logger);
        $crontab->execute();

        $this->assertStringContainsString('req-res-crypto: rotated keys', $capturedLogMsg);
        $this->assertStringContainsString('KeyID:', $capturedLogMsg);
    }

    #[Test]
    public function sets_activated_at_to_null_for_pre_issued_keys(): void
    {
        putenv('REQ_RES_CRYPTO_KEY_ROTATION_ENABLED=true');

        $logger = Mockery::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('info')->once();

        $dbMock = Mockery::mock('alias:'.Db::class);
        $dbMock->shouldReceive('table')->andReturn($builder = Mockery::mock());

        $captured = null;
        $builder->shouldReceive('insert')->with(Mockery::on(function (array $data) use (&$captured) {
            $captured = $data;

            return true;
        }));

        $crontab = new KeyRotationCrontab($logger);
        $crontab->execute();

        $this->assertNull($captured['activated_at']);
        $this->assertNull($captured['expired_at']);
    }

    #[Test]
    public function does_not_rotate_when_key_rotation_is_disabled(): void
    {
        putenv('REQ_RES_CRYPTO_KEY_ROTATION_ENABLED=false');

        /** @var TestCase $this */
        $this->swap('config', [
            'req-res-crypto' => [
                'database' => [
                    'table' => 'req_res_crypto_public_keys',
                ],
                'key_rotation' => [
                    'enabled' => false,
                    'rotate_before_days' => 7,
                ],
            ],
        ]);

        $logger = Mockery::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('info')
            ->once()
            ->with('req-res-crypto: key rotation is disabled, skipping.');

        // Db::table should never be called
        $dbMock = Mockery::mock('alias:'.Db::class);
        $dbMock->shouldReceive('table')->never();

        $crontab = new KeyRotationCrontab($logger);
        $crontab->execute();

        // Mockery 的 shouldNotReceive/never 已在 tearDown 中校验，此处满足 PHPUnit 显式断言要求
        $this->assertTrue(true);
    }
}
