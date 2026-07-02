<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Hyperf\Middleware;

use Hyperf\Contract\ConfigInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wenbo\ReqResCrypto\Core\SerializerInterface;
use Wenbo\ReqResCrypto\Core\UnsealerInterface;

final readonly class ReqResDecryptRequestMiddleware implements MiddlewareInterface
{
    use SkipCryptoChecker;

    public function __construct(
        private UnsealerInterface $unsealer,
        private StreamFactoryInterface $streamFactory,
        private SerializerInterface $serializer,
        private ConfigInterface $config,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->shouldSkipCrypto($request)) {
            $response = $handler->handle($request);

            return $this->withCryptoSkipResponseHeader($request, $response);
        }

        $body = (string) $request->getBody();

        if (empty($body)) {
            return $handler->handle($request);
        }

        $binary = base64_decode($body, true);
        if ($binary === false) {
            return $handler->handle($request);
        }

        // 解密失败说明请求被篡改或密钥不匹配，
        // CryptoException 自然传播，由全局异常处理器返回错误响应，绝不透传。
        $plaintext = $this->unsealer->unseal($binary);

        // 提取客户端交换公钥（从 wire 中直接读取），供响应加密使用
        $clientPubkey = $this->unsealer->getClientExchangePubKey();
        if ($clientPubkey !== null) {
            $request = $request->withAttribute('req_res_crypto_client_pubkey', $clientPubkey);
        }

        $plainbody = is_string($plaintext) ? $plaintext : $this->serializer->serialize($plaintext);

        $stream = $this->streamFactory->createStream($plainbody);
        $request = $request->withBody($stream);

        if ($request->getHeaderLine('Content-Type') === 'application/octet-stream') {
            $request = $request->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
