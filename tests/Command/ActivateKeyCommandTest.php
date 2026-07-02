<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Hyperf\Tests\Command;

use Hyperf\DbConnection\Db;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Wenbo\ReqResCrypto\Hyperf\Command\ActivateKeyCommand;
use Wenbo\ReqResCrypto\Hyperf\Tests\TestCase;

final class ActivateKeyCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->swap('config', [
            'req-res-crypto' => [
                'database' => [
                    'table' => 'req_res_crypto_public_keys',
                ],
            ],
        ]);
    }

    private function makeRow(string $keyId, string $status, int $id = 1): stdClass
    {
        $row = new stdClass;
        $row->id = $id;
        $row->key_id = $keyId;
        $row->status = $status;

        return $row;
    }

    #[Test]
    public function auto_activates_oldest_pre_issued_key_when_no_key_id_given(): void
    {
        $row = $this->makeRow('abc123abc123abc123abc123', 'pre_issued');
        $table = 'req_res_crypto_public_keys';

        $dbMock = Mockery::mock('alias:'.Db::class);
        $dbMock->shouldReceive('table')->with($table)->andReturn($builder = Mockery::mock());

        $builder->shouldReceive('where')->with('key_id', Mockery::any())->andReturnSelf();
        $builder->shouldReceive('where')->with('status', Mockery::any())->andReturnSelf();
        $builder->shouldReceive('where')->with('status', 'pre_issued')->andReturnSelf();
        $builder->shouldReceive('where')->with(Mockery::type('Closure'))->andReturnSelf();
        $builder->shouldReceive('orderBy')->with('issued_at')->andReturnSelf();
        $builder->shouldReceive('first')->andReturn($row);

        $builder->shouldReceive('where')->with('status', 'current')->andReturnSelf();
        $builder->shouldReceive('where')->with('status', 'pre_issued')->andReturnSelf();
        $builder->shouldReceive('where')->with('id', $row->id)->andReturnSelf();
        $builder->shouldReceive('update')->andReturn(1);

        $dbMock->shouldReceive('transaction')
            ->once()
            ->with(Mockery::type('callable'))
            ->andReturnUsing(function (callable $cb) {
                $cb();
            });

        $command = new ActivateKeyCommand;
        $command->setName('req-res-crypto:keys:activate');
        $command->setInput(new ArrayInput([], $command->getDefinition()));
        $command->setOutput(new NullOutput);

        $result = $command->handle();

        $this->assertSame(0, $result);
    }

    #[Test]
    public function activates_specific_key_by_key_id(): void
    {
        $row = $this->makeRow('target111target111target', 'pre_issued');
        $table = 'req_res_crypto_public_keys';

        $dbMock = Mockery::mock('alias:'.Db::class);
        $dbMock->shouldReceive('table')->with($table)->andReturn($builder = Mockery::mock());

        $builder->shouldReceive('where')->with('key_id', 'target111target111target')->andReturnSelf();
        $builder->shouldReceive('where')->with('activated_at', '<=', Mockery::any())->andReturnSelf();
        $builder->shouldReceive('orderBy')->with('issued_at')->andReturnSelf();
        $builder->shouldReceive('first')->andReturn($row);

        $builder->shouldReceive('where')->with('status', 'current')->andReturnSelf();
        $builder->shouldReceive('where')->with('id', $row->id)->andReturnSelf();
        $builder->shouldReceive('update')->andReturn(1);

        $dbMock->shouldReceive('transaction')
            ->once()
            ->with(Mockery::type('callable'))
            ->andReturnUsing(function (callable $cb) {
                $cb();
            });

        $command = new ActivateKeyCommand;
        $command->setName('req-res-crypto:keys:activate');
        $command->setInput(new ArrayInput(['key_id' => 'target111target111target'], $command->getDefinition()));
        $command->setOutput(new NullOutput);

        $result = $command->handle();

        $this->assertSame(0, $result);
    }

    #[Test]
    public function returns_failure_when_key_id_not_found(): void
    {
        $table = 'req_res_crypto_public_keys';

        $dbMock = Mockery::mock('alias:'.Db::class);
        $dbMock->shouldReceive('table')->with($table)->andReturn($builder = Mockery::mock());

        $builder->shouldReceive('where')->with('key_id', 'nonexistent000000000')->andReturnSelf();
        $builder->shouldReceive('first')->andReturn(null);
        $builder->shouldNotReceive('update');
        $dbMock->shouldNotReceive('transaction');

        $command = new ActivateKeyCommand;
        $command->setName('req-res-crypto:keys:activate');
        $command->setInput(new ArrayInput(['key_id' => 'nonexistent000000000'], $command->getDefinition()));
        $command->setOutput(new NullOutput);

        $result = $command->handle();

        $this->assertSame(1, $result); // FAILURE
    }

    #[Test]
    public function returns_failure_when_key_is_not_pre_issued(): void
    {
        $row = $this->makeRow('expired111expired11ex', 'expired');
        $table = 'req_res_crypto_public_keys';

        $dbMock = Mockery::mock('alias:'.Db::class);
        $dbMock->shouldReceive('table')->with($table)->andReturn($builder = Mockery::mock());

        $builder->shouldReceive('where')->with('key_id', 'expired111expired11ex')->andReturnSelf();
        $builder->shouldReceive('first')->andReturn($row);
        $builder->shouldNotReceive('update');
        $dbMock->shouldNotReceive('transaction');

        $command = new ActivateKeyCommand;
        $command->setName('req-res-crypto:keys:activate');
        $command->setInput(new ArrayInput(['key_id' => 'expired111expired11ex'], $command->getDefinition()));
        $command->setOutput(new NullOutput);

        $result = $command->handle();

        $this->assertSame(1, $result);
    }

    #[Test]
    public function returns_success_when_no_pre_issued_key_available(): void
    {
        $table = 'req_res_crypto_public_keys';

        $dbMock = Mockery::mock('alias:'.Db::class);
        $dbMock->shouldReceive('table')->with($table)->andReturn($builder = Mockery::mock());

        $builder->shouldReceive('where')->with('key_id', Mockery::any())->andReturnSelf();
        $builder->shouldReceive('where')->with('status', 'pre_issued')->andReturnSelf();
        $builder->shouldReceive('where')->with(Mockery::type('Closure'))->andReturnSelf();
        $builder->shouldReceive('orderBy')->with('issued_at')->andReturnSelf();
        $builder->shouldReceive('first')->andReturn(null);

        $builder->shouldNotReceive('update');
        $dbMock->shouldNotReceive('transaction');

        $command = new ActivateKeyCommand;
        $command->setName('req-res-crypto:keys:activate');
        $command->setInput(new ArrayInput([], $command->getDefinition()));
        $command->setOutput(new NullOutput);

        $result = $command->handle();

        $this->assertSame(0, $result);
    }

    #[Test]
    public function transaction_expires_current_key_and_activates_pre_issued(): void
    {
        $row = $this->makeRow('prekey111prekey111pre', 'pre_issued');
        $table = 'req_res_crypto_public_keys';
        $updates = [];

        $dbMock = Mockery::mock('alias:'.Db::class);
        $dbMock->shouldReceive('table')->with($table)->andReturn($builder = Mockery::mock());

        $builder->shouldReceive('where')->with('key_id', Mockery::any())->andReturnSelf();
        $builder->shouldReceive('where')->with('status', 'pre_issued')->andReturnSelf();
        $builder->shouldReceive('where')->with(Mockery::type('Closure'))->andReturnSelf();
        $builder->shouldReceive('orderBy')->with('issued_at')->andReturnSelf();
        $builder->shouldReceive('first')->andReturn($row);

        $builder->shouldReceive('where')->with('status', 'current')->andReturnSelf();
        $builder->shouldReceive('where')->with('id', $row->id)->andReturnSelf();
        $builder->shouldReceive('update')
            ->andReturnUsing(function (array $data) use (&$updates) {
                $updates[] = $data;

                return 1;
            });

        $dbMock->shouldReceive('transaction')
            ->once()
            ->with(Mockery::type('callable'))
            ->andReturnUsing(function (callable $cb) {
                $cb();
            });

        $command = new ActivateKeyCommand;
        $command->setName('req-res-crypto:keys:activate');
        $command->setInput(new ArrayInput([], $command->getDefinition()));
        $command->setOutput(new NullOutput);

        $result = $command->handle();

        $this->assertSame(0, $result);
        $this->assertCount(2, $updates);
        $this->assertSame('expired', $updates[0]['status']);
        $this->assertSame('current', $updates[1]['status']);
    }
}
