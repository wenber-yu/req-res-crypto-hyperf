<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Hyperf\Tests\KeyProvider;

use Hyperf\DbConnection\Db;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Wenbo\ReqResCrypto\Core\Exceptions\KeyException;
use Wenbo\ReqResCrypto\Hyperf\KeyProvider\DatabaseKeyProvider;
use Wenbo\ReqResCrypto\Hyperf\Tests\TestCase;

final class DatabaseKeyProviderTest extends TestCase
{
    #[Test]
    public function returns_current_key_with_all_fields(): void
    {
        $table = 'req_res_crypto_public_keys';
        $expectedKeyId = 'abc123ab';
        $expectedSignPub = bin2hex(random_bytes(32));
        $expectedExchangePub = bin2hex(random_bytes(32));
        $expectedSignSecret = bin2hex(random_bytes(64));
        $expectedExchangeSecret = bin2hex(random_bytes(32));

        $row = new stdClass;
        $row->key_id = $expectedKeyId;
        $row->sign_public_key = $expectedSignPub;
        $row->sign_secret_key = $expectedSignSecret;
        $row->exchange_public_key = $expectedExchangePub;
        $row->exchange_secret_key = $expectedExchangeSecret;

        $builder = Mockery::mock();
        $builder->shouldReceive('where')->with('status', 'current')->andReturnSelf();
        $builder->shouldReceive('first')->andReturn($row);

        $dbMock = Mockery::mock('alias:'.Db::class);
        $dbMock->shouldReceive('table')->with($table)->andReturn($builder);

        $provider = new DatabaseKeyProvider($table);
        $result = $provider->getCurrentKey();

        $this->assertNotNull($result);
        $this->assertSame($expectedKeyId, $result->keyId);
        $this->assertSame(hex2bin($expectedSignPub), $result->signPublicKey);
        $this->assertSame(hex2bin($expectedExchangePub), $result->exchangePublicKey);
        $this->assertSame(hex2bin($expectedSignSecret), $result->signSecretKey);
        $this->assertSame(hex2bin($expectedExchangeSecret), $result->exchangeSecretKey);
    }

    #[Test]
    public function returns_pre_issued_key(): void
    {
        $table = 'req_res_crypto_public_keys';
        $expectedKeyId = 'pre123pre';

        $row = new stdClass;
        $row->key_id = $expectedKeyId;
        $row->sign_public_key = bin2hex(random_bytes(32));
        $row->sign_secret_key = bin2hex(random_bytes(64));
        $row->exchange_public_key = bin2hex(random_bytes(32));
        $row->exchange_secret_key = bin2hex(random_bytes(32));

        $builder = Mockery::mock();
        $builder->shouldReceive('where')->with('status', 'pre_issued')->andReturnSelf();
        $builder->shouldReceive('first')->andReturn($row);

        $dbMock = Mockery::mock('alias:'.Db::class);
        $dbMock->shouldReceive('table')->with($table)->andReturn($builder);

        $provider = new DatabaseKeyProvider($table);
        $result = $provider->getPreIssuedKey();

        $this->assertNotNull($result);
        $this->assertSame($expectedKeyId, $result->keyId);
    }

    #[Test]
    public function returns_null_when_key_not_found(): void
    {
        $table = 'req_res_crypto_public_keys';

        $builder = Mockery::mock();
        $builder->shouldReceive('where')->with('status', 'current')->andReturnSelf();
        $builder->shouldReceive('first')->andReturn(null);

        $dbMock = Mockery::mock('alias:'.Db::class);
        $dbMock->shouldReceive('table')->with($table)->andReturn($builder);

        $provider = new DatabaseKeyProvider($table);
        $result = $provider->getCurrentKey();

        $this->assertNull($result);
    }

    #[Test]
    public function throws_key_exception_on_database_error(): void
    {
        $table = 'req_res_crypto_public_keys';

        $builder = Mockery::mock();
        $builder->shouldReceive('where')->with('status', 'current')->andReturnSelf();
        $builder->shouldReceive('first')->andThrow(new \PDOException('connection lost'));

        $dbMock = Mockery::mock('alias:'.Db::class);
        $dbMock->shouldReceive('table')->with($table)->andReturn($builder);

        $provider = new DatabaseKeyProvider($table);

        $this->expectException(KeyException::class);
        $provider->getCurrentKey();
    }
}
