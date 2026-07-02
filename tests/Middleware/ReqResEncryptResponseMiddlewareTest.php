<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Hyperf\Tests\Middleware;

use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wenbo\ReqResCrypto\Core\JsonSerializer;
use Wenbo\ReqResCrypto\Core\KeyExchange;
use Wenbo\ReqResCrypto\Core\KeyPair;
use Wenbo\ReqResCrypto\Core\Sealer;
use Wenbo\ReqResCrypto\Core\ServerKey;
use Wenbo\ReqResCrypto\Core\ServerKeyProviderInterface;
use Wenbo\ReqResCrypto\Hyperf\Middleware\ReqResEncryptResponseMiddleware;
use Wenbo\ReqResCrypto\Hyperf\Tests\TestCase;

final class ReqResEncryptResponseMiddlewareTest extends TestCase
{
    private function createMockKeyProvider(KeyPair $serverKp): ServerKeyProviderInterface
    {
        return new class($serverKp) implements ServerKeyProviderInterface
        {
            public function __construct(private KeyPair $kp) {}

            public function getCurrentKey(): ?ServerKey
            {
                return new ServerKey(
                    keyId: $this->kp->keyId(),
                    signSecretKey: $this->kp->signSecretKey,
                    signPublicKey: $this->kp->signPublicKey,
                    exchangeSecretKey: $this->kp->exchangeSecretKey,
                    exchangePublicKey: $this->kp->exchangePublicKey,
                );
            }

            public function getPreIssuedKey(): ?ServerKey
            {
                return null;
            }
        };
    }

    private function createMockStreamFactory(): StreamFactoryInterface
    {
        return Mockery::mock(StreamFactoryInterface::class);
    }

    #[Test]
    public function skips_encryption_when_no_client_pubkey_attribute(): void
    {
        $serverKp = KeyPair::generate();
        $keyProvider = $this->createMockKeyProvider($serverKp);
        $sealer = new Sealer(new KeyExchange, new JsonSerializer);
        $middleware = new ReqResEncryptResponseMiddleware(
            $keyProvider,
            new JsonSerializer,
            $sealer,
            $this->createMockStreamFactory(),
        );

        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldNotReceive('getBody');
        $response->shouldNotReceive('withHeader');

        $handler = Mockery::mock(RequestHandlerInterface::class);
        $handler->shouldReceive('handle')->once()->andReturn($response);

        $request = Mockery::mock(ServerRequestInterface::class);
        $request->shouldReceive('getAttribute')->once()->with('req_res_crypto_client_pubkey', '')->andReturn('');

        $result = $middleware->process($request, $handler);

        $this->assertSame($response, $result);
    }

    #[Test]
    public function skips_encryption_for_empty_response_body(): void
    {
        $serverKp = KeyPair::generate();
        $keyProvider = $this->createMockKeyProvider($serverKp);
        $sealer = new Sealer(new KeyExchange, new JsonSerializer);
        $middleware = new ReqResEncryptResponseMiddleware(
            $keyProvider,
            new JsonSerializer,
            $sealer,
            $this->createMockStreamFactory(),
        );

        $bodyStream = Mockery::mock(StreamInterface::class);
        $bodyStream->allows('__toString')->andReturn('');

        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldReceive('getBody')->once()->andReturn($bodyStream);
        $response->shouldNotReceive('withHeader');

        $handler = Mockery::mock(RequestHandlerInterface::class);
        $handler->shouldReceive('handle')->once()->andReturn($response);

        $request = Mockery::mock(ServerRequestInterface::class);
        $request->shouldReceive('getAttribute')->once()->with('req_res_crypto_client_pubkey', '')->andReturn(random_bytes(32));

        $result = $middleware->process($request, $handler);

        $this->assertSame($response, $result);
    }

    #[Test]
    public function encrypts_response_when_client_pubkey_attribute_is_present(): void
    {
        $serverKp = KeyPair::generate();
        $clientKp = KeyPair::generate();
        $keyProvider = $this->createMockKeyProvider($serverKp);
        $plaintextBody = '{"data":"hello world"}';

        $sealer = new Sealer(new KeyExchange, new JsonSerializer);

        $bodyStream = Mockery::mock(StreamInterface::class);
        $bodyStream->allows('__toString')->andReturn($plaintextBody);

        $response = Mockery::mock(ResponseInterface::class);
        $response->shouldReceive('getBody')->andReturn($bodyStream);
        $response->shouldReceive('withBody')->andReturnSelf();
        $response->shouldReceive('withHeader')->with('Content-Type', 'application/octet-stream')->andReturnSelf();

        $handler = Mockery::mock(RequestHandlerInterface::class);
        $handler->shouldReceive('handle')->once()->andReturn($response);

        $request = Mockery::mock(ServerRequestInterface::class);
        $request->shouldReceive('getAttribute')->once()->with('req_res_crypto_client_pubkey', '')->andReturn($clientKp->exchangePublicKey);

        $mockStreamFactory = Mockery::mock(StreamFactoryInterface::class);
        $mockStreamFactory->shouldReceive('createStream')->andReturn($bodyStream);

        $middleware = new ReqResEncryptResponseMiddleware(
            $keyProvider,
            new JsonSerializer,
            $sealer,
            $mockStreamFactory,
        );

        $result = $middleware->process($request, $handler);

        $this->assertNotNull($result);
    }
}
