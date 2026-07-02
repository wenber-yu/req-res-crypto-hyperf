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
use Wenbo\ReqResCrypto\Core\Exceptions\CryptoException;
use Wenbo\ReqResCrypto\Core\JsonSerializer;
use Wenbo\ReqResCrypto\Core\SerializerInterface;
use Wenbo\ReqResCrypto\Core\UnsealerInterface;
use Wenbo\ReqResCrypto\Hyperf\Middleware\ReqResDecryptRequestMiddleware;
use Wenbo\ReqResCrypto\Hyperf\Tests\TestCase;

final class ReqResDecryptRequestMiddlewareTest extends TestCase
{
    private function mockSerializer(): SerializerInterface
    {
        return new JsonSerializer;
    }

    private function mockUnsealer(?string $return = null, ?\Throwable $throw = null): UnsealerInterface
    {
        return new class($return, $throw, $callCount) implements UnsealerInterface
        {
            public int $callCount = 0;

            public function __construct(
                private ?string $return,
                private ?\Throwable $throw,
            ) {}

            public function unseal(string $wire): mixed
            {
                $this->callCount++;
                if ($this->throw !== null) {
                    throw $this->throw;
                }

                return $this->return;
            }

            public function getClientExchangePubKey(): ?string
            {
                return null;
            }
        };
    }

    private function mockStreamFactory(): StreamFactoryInterface
    {
        return new class implements StreamFactoryInterface
        {
            /** @var array<string, StreamInterface> */
            private array $cache = [];

            public function createStream(string $content = ''): StreamInterface
            {
                if (! isset($this->cache[$content])) {
                    $this->cache[$content] = Mockery::mock(StreamInterface::class);
                }

                return $this->cache[$content];
            }

            public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
            {
                throw new \RuntimeException('Not implemented');
            }

            public function createStreamFromResource($resource): StreamInterface
            {
                throw new \RuntimeException('Not implemented');
            }
        };
    }

    #[Test]
    public function decrypts_request_body_when_valid_base64_ciphertext(): void
    {
        $plaintext = '{"user":"alice"}';
        $binary = random_bytes(128);
        $encoded = base64_encode($binary);

        $unsealer = $this->mockUnsealer(return: $plaintext);
        $streamFactory = $this->mockStreamFactory();
        $middleware = new ReqResDecryptRequestMiddleware($unsealer, $streamFactory, $this->mockSerializer());

        $requestBody = Mockery::mock(StreamInterface::class);
        $requestBody->allows('__toString')->andReturn($encoded);

        $request = Mockery::mock(ServerRequestInterface::class);
        $request->shouldReceive('getBody')->once()->andReturn($requestBody);
        $request->shouldReceive('withBody')->once()->with(Mockery::type(StreamInterface::class))->andReturnSelf();
        $request->shouldReceive('getHeaderLine')->once()->with('Content-Type')->andReturn('application/octet-stream');
        $request->shouldReceive('withHeader')->once()->with('Content-Type', 'application/json')->andReturnSelf();

        $response = Mockery::mock(ResponseInterface::class);

        $handler = Mockery::mock(RequestHandlerInterface::class);
        $handler->shouldReceive('handle')->once()->with($request)->andReturn($response);

        $result = $middleware->process($request, $handler);

        $this->assertSame($response, $result);
        $this->assertSame(1, $unsealer->callCount);
    }

    #[Test]
    public function skips_decryption_for_empty_request_body(): void
    {
        $unsealer = $this->mockUnsealer();
        $middleware = new ReqResDecryptRequestMiddleware($unsealer, $this->mockStreamFactory(), $this->mockSerializer());

        $requestBody = Mockery::mock(StreamInterface::class);
        $requestBody->allows('__toString')->andReturn('');

        $request = Mockery::mock(ServerRequestInterface::class);
        $request->shouldReceive('getBody')->once()->andReturn($requestBody);

        $response = Mockery::mock(ResponseInterface::class);

        $handler = Mockery::mock(RequestHandlerInterface::class);
        $handler->shouldReceive('handle')->once()->with($request)->andReturn($response);

        $result = $middleware->process($request, $handler);

        $this->assertSame($response, $result);
        $this->assertSame(0, $unsealer->callCount);
    }

    #[Test]
    public function skips_decryption_when_base64_decode_fails(): void
    {
        $unsealer = $this->mockUnsealer();
        $middleware = new ReqResDecryptRequestMiddleware($unsealer, $this->mockStreamFactory(), $this->mockSerializer());

        $requestBody = Mockery::mock(StreamInterface::class);
        $requestBody->allows('__toString')->andReturn('!!!not-base64!!!');

        $request = Mockery::mock(ServerRequestInterface::class);
        $request->shouldReceive('getBody')->once()->andReturn($requestBody);

        $response = Mockery::mock(ResponseInterface::class);

        $handler = Mockery::mock(RequestHandlerInterface::class);
        $handler->shouldReceive('handle')->once()->with($request)->andReturn($response);

        $result = $middleware->process($request, $handler);

        $this->assertSame($response, $result);
        $this->assertSame(0, $unsealer->callCount);
    }

    #[Test]
    public function throws_crypto_exception_when_unseal_fails(): void
    {
        $encoded = base64_encode('corrupted-wire');
        $unsealer = $this->mockUnsealer(throw: new CryptoException('decrypt failed'));
        $middleware = new ReqResDecryptRequestMiddleware($unsealer, $this->mockStreamFactory(), $this->mockSerializer());

        $requestBody = Mockery::mock(StreamInterface::class);
        $requestBody->allows('__toString')->andReturn($encoded);

        $request = Mockery::mock(ServerRequestInterface::class);
        $request->shouldReceive('getBody')->once()->andReturn($requestBody);

        $handler = Mockery::mock(RequestHandlerInterface::class);
        $handler->shouldNotReceive('handle');

        $this->expectException(CryptoException::class);
        $this->expectExceptionMessage('decrypt failed');

        $middleware->process($request, $handler);
    }

    #[Test]
    public function preserves_non_octet_stream_content_type(): void
    {
        $plaintext = 'hello';
        $binary = random_bytes(90);
        $encoded = base64_encode($binary);

        $unsealer = $this->mockUnsealer(return: $plaintext);
        $streamFactory = $this->mockStreamFactory();
        $middleware = new ReqResDecryptRequestMiddleware($unsealer, $streamFactory, $this->mockSerializer());

        $requestBody = Mockery::mock(StreamInterface::class);
        $requestBody->allows('__toString')->andReturn($encoded);

        $request = Mockery::mock(ServerRequestInterface::class);
        $request->shouldReceive('getBody')->once()->andReturn($requestBody);
        $request->shouldReceive('withBody')->once()->with(Mockery::type(StreamInterface::class))->andReturnSelf();
        $request->shouldReceive('getHeaderLine')->once()->with('Content-Type')->andReturn('text/plain');
        $request->shouldNotReceive('withHeader');

        $response = Mockery::mock(ResponseInterface::class);

        $handler = Mockery::mock(RequestHandlerInterface::class);
        $handler->shouldReceive('handle')->once()->with($request)->andReturn($response);

        $result = $middleware->process($request, $handler);

        $this->assertSame($response, $result);
    }

    #[Test]
    public function throws_crypto_exception_on_truncated_wire(): void
    {
        $shortWire = base64_encode('short');
        $unsealer = $this->mockUnsealer(throw: new CryptoException('length too short'));
        $middleware = new ReqResDecryptRequestMiddleware($unsealer, $this->mockStreamFactory(), $this->mockSerializer());

        $requestBody = Mockery::mock(StreamInterface::class);
        $requestBody->allows('__toString')->andReturn($shortWire);

        $request = Mockery::mock(ServerRequestInterface::class);
        $request->shouldReceive('getBody')->once()->andReturn($requestBody);

        $handler = Mockery::mock(RequestHandlerInterface::class);
        $handler->shouldNotReceive('handle');

        $this->expectException(CryptoException::class);

        $middleware->process($request, $handler);
    }
}
