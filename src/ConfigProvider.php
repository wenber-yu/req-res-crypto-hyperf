<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Hyperf;

use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Wenbo\ReqResCrypto\Core\JsonSerializer;
use Wenbo\ReqResCrypto\Core\KeyExchange;
use Wenbo\ReqResCrypto\Core\NonceStoreInterface;
use Wenbo\ReqResCrypto\Core\Sealer;
use Wenbo\ReqResCrypto\Core\SealerInterface;
use Wenbo\ReqResCrypto\Core\SerializerInterface;
use Wenbo\ReqResCrypto\Core\ServerKeyProviderInterface;
use Wenbo\ReqResCrypto\Core\Unsealer;
use Wenbo\ReqResCrypto\Core\UnsealerInterface;
use Wenbo\ReqResCrypto\Hyperf\Command\ActivateKeyCommand;
use Wenbo\ReqResCrypto\Hyperf\Command\GenerateKeyCommand;
use Wenbo\ReqResCrypto\Hyperf\Command\RotateKeysCommand;
use Wenbo\ReqResCrypto\Hyperf\Crontab\KeyRotationCrontab;
use Wenbo\ReqResCrypto\Hyperf\KeyProvider\ServerKeyProvider;
use Wenbo\ReqResCrypto\Hyperf\NonceStore\CacheNonceStore;
use Wenbo\ReqResCrypto\Hyperf\Stream\SwooleStreamFactory;

final class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                // 统一密钥提供者（内部自动处理 rotation 开关）
                ServerKeyProviderInterface::class => function (ContainerInterface $container) {
                    $config = $container->get(ConfigInterface::class);

                    return new ServerKeyProvider(
                        $config->get('req-res-crypto', []),
                    );
                },
                NonceStoreInterface::class => CacheNonceStore::class,
                SerializerInterface::class => JsonSerializer::class,
                StreamFactoryInterface::class => SwooleStreamFactory::class,
                SealerInterface::class => function (ContainerInterface $container) {
                    return new Sealer(
                        $container->get(KeyExchange::class),
                        $container->get(SerializerInterface::class),
                    );
                },
                UnsealerInterface::class => function (ContainerInterface $container) {
                    return new Unsealer(
                        $container->get(KeyExchange::class),
                        $container->get(ServerKeyProviderInterface::class),
                        $container->get(NonceStoreInterface::class),
                        $container->get(SerializerInterface::class),
                    );
                },
            ],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__.'/Annotation',
                        __DIR__.'/Command',
                    ],
                ],
            ],
            'commands' => [
                RotateKeysCommand::class,
                ActivateKeyCommand::class,
                GenerateKeyCommand::class,
            ],
            'crontab' => [
                'req_res_crypto_rotation' => [
                    'name' => 'req-res-crypto-key-rotation',
                    'rule' => '0 2 * * *',
                    'callback' => [KeyRotationCrontab::class, 'execute'],
                ],
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The config for req-res-crypto.',
                    'source' => __DIR__.'/../config/req-res-crypto.php',
                    'destination' => BASE_PATH.'/config/autoload/req-res-crypto.php',
                ],
                [
                    'id' => 'migration',
                    'description' => 'The migration for req-res-crypto public keys table.',
                    'source' => __DIR__.'/../migrations/2026_01_01_000000_create_req_res_crypto_public_keys.php',
                    'destination' => BASE_PATH.'/migrations/2026_01_01_000000_create_req_res_crypto_public_keys.php',
                ],
            ],
        ];
    }
}
