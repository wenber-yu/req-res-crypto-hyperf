<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Hyperf\Middleware;

use Hyperf\Contract\ConfigInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Wenbo\ReqResCrypto\Core\SealerInterface;
use Wenbo\ReqResCrypto\Core\SerializerInterface;
use Wenbo\ReqResCrypto\Core\ServerKeyProviderInterface;

final readonly class ReqResEncryptResponseMiddleware implements MiddlewareInterface
{
    use SkipCryptoChecker;

    public function __construct(
        private ServerKeyProviderInterface $keyProvider,
        private SerializerInterface $serializer,
        private SealerInterface $sealer,
        private StreamFactoryInterface $streamFactory,
        private ConfigInterface $config,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $response = $handler->handle($request);

        if ($this->shouldSkipCrypto($request)) {
            return $this->withCryptoSkipResponseHeader($request, $response);
        }

        // 检查解密中间件是否已提取到客户端公钥（存入 attribute）
        $theirExchangePubKey = $request->getAttribute('req_res_crypto_client_pubkey', '');
        if (empty($theirExchangePubKey)) {
            return $response;
        }

        $body = (string) $response->getBody();
        if (empty($body)) {
            return $response;
        }

        // 一次调用获取当前密钥所有字段
        $currentKey = $this->keyProvider->getCurrentKey();
        if ($currentKey === null
            || empty($currentKey->exchangeSecretKey)
            || empty($currentKey->exchangePublicKey)
        ) {
            return $response;
        }

        // 使用统一序列化器解码响应体，与 core 包保持一致
        $data = $this->serializer->unserialize($body);

        $ciphertext = $this->sealer->seal(
            bin2hex($currentKey->exchangePublicKey),
            $currentKey->exchangeSecretKey,
            $theirExchangePubKey,
            $data,
        );

        $encoded = base64_encode($ciphertext);

        $response = $response
            ->withBody($this->streamFactory->createStream($encoded))
            ->withHeader('Content-Type', 'application/octet-stream');

        // 检查是否有 pre_issued 密钥，通知客户端即将轮换
        return $this->attachKeyRotationHeader($response);
    }

    /**
     * 检测 pre_issued 密钥，存在时附加 X-Req-Res-Crypto-Key-Rotate 响应头。
     */
    private function attachKeyRotationHeader(ResponseInterface $response): ResponseInterface
    {
        $preIssued = $this->keyProvider->getPreIssuedKey();
        if ($preIssued === null) {
            return $response;
        }

        return $response->withHeader('X-Req-Res-Crypto-Key-Rotate',
            $this->serializer->serialize([
                'key_id' => $preIssued->keyId,
                'sign_public_key' => bin2hex($preIssued->signPublicKey),
                'exchange_public_key' => bin2hex($preIssued->exchangePublicKey),
            ]));
    }
}
