<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Hyperf\Tests\Command;

use Hyperf\DbConnection\Db;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Wenbo\ReqResCrypto\Hyperf\Command\RotateKeysCommand;
use Wenbo\ReqResCrypto\Hyperf\Tests\TestCase;

final class RotateKeysCommandTest extends TestCase
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
    public function generates_key_pairs_and_inserts_with_pre_issued_status(): void
    {
        putenv('REQ_RES_CRYPTO_KEY_ROTATION_ENABLED=true');

        $dbMock = Mockery::mock('alias:'.Db::class);
        $dbMock->shouldReceive('table')
            ->once()
            ->with('req_res_crypto_public_keys')
            ->andReturn($builder = Mockery::mock());

        $captured = null;
        $builder->shouldReceive('insert')
            ->once()
            ->with(Mockery::on(function (array $data) use (&$captured) {
                $captured = $data;

                return true;
            }));

        $command = new RotateKeysCommand;
        $command->setName('req-res-crypto:keys:rotate');
        $command->setInput(new ArrayInput([], $command->getDefinition()));
        $command->setOutput(new NullOutput);

        $result = $command->handle();

        $this->assertSame(0, $result);
        $this->assertNotNull($captured);
        $this->assertSame('pre_issued', $captured['status']);
        $this->assertNull($captured['activated_at']);
        $this->assertNull($captured['expired_at']);
        $this->assertNotEmpty($captured['key_id']);
    }

    #[Test]
    public function inserted_key_id_is8_hex_characters(): void
    {
        putenv('REQ_RES_CRYPTO_KEY_ROTATION_ENABLED=true');

        $dbMock = Mockery::mock('alias:'.Db::class);
        $dbMock->shouldReceive('table')->andReturn($builder = Mockery::mock());

        $captured = null;
        $builder->shouldReceive('insert')->with(Mockery::on(function (array $data) use (&$captured) {
            $captured = $data;

            return true;
        }));

        $command = new RotateKeysCommand;
        $command->setName('req-res-crypto:keys:rotate');
        $command->setInput(new ArrayInput([], $command->getDefinition()));
        $command->setOutput(new NullOutput);
        $command->handle();

        $this->assertSame(8, strlen($captured['key_id']));
        $this->assertTrue(ctype_xdigit($captured['key_id']));
    }

    #[Test]
    public function inserted_keys_are64_hex_characters(): void
    {
        putenv('REQ_RES_CRYPTO_KEY_ROTATION_ENABLED=true');

        $dbMock = Mockery::mock('alias:'.Db::class);
        $dbMock->shouldReceive('table')->andReturn($builder = Mockery::mock());

        $captured = null;
        $builder->shouldReceive('insert')->with(Mockery::on(function (array $data) use (&$captured) {
            $captured = $data;

            return true;
        }));

        $command = new RotateKeysCommand;
        $command->setName('req-res-crypto:keys:rotate');
        $command->setInput(new ArrayInput([], $command->getDefinition()));
        $command->setOutput(new NullOutput);
        $command->handle();

        $this->assertSame(64, strlen($captured['sign_public_key']));
        $this->assertSame(128, strlen($captured['sign_secret_key']));
        $this->assertSame(64, strlen($captured['exchange_public_key']));
        $this->assertSame(64, strlen($captured['exchange_secret_key']));
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

        // Db::table should never be called
        $dbMock = Mockery::mock('alias:'.Db::class);
        $dbMock->shouldReceive('table')->never();

        $command = new RotateKeysCommand;
        $command->setName('req-res-crypto:keys:rotate');
        $command->setInput(new ArrayInput([], $command->getDefinition()));
        $command->setOutput(new NullOutput);

        $result = $command->handle();

        $this->assertSame(1, $result); // FAILURE
    }
}
