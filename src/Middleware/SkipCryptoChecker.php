<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Hyperf\Middleware;

use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\HttpServer\Router\Dispatched;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Wenbo\ReqResCrypto\Core\PathMatcher;
use Wenbo\ReqResCrypto\Hyperf\Attributes\SkipReqResCrypto;

/**
 * 跳过加解密判断逻辑的共享 Trait。
 *
 * 用于 ReqResDecryptRequestMiddleware 和 ReqResEncryptResponseMiddleware。
 */
trait SkipCryptoChecker
{
    /**
     * 判断当前请求是否应跳过加解密处理。
     *
     * 检查顺序：header → 路径匹配 → 注解属性。
     * header 名称通过 config skip_header 配置。
     *
     * 当 skip header 存在时：
     * - 若路由在跳过白名单内 → 跳过加解密，明文处理
     * - 若路由不在白名单内 → 抛出异常，拒绝明文请求
     */
    protected function shouldSkipCrypto(ServerRequestInterface $request): bool
    {
        $hasSkipHeader = $this->hasCryptoSkipHeader($request);
        $pathSkipped = $this->isPathSkipped($request);
        $attrSkipped = $this->hasSkipAttribute($request);

        if ($hasSkipHeader && !$pathSkipped && !$attrSkipped) {
            throw new RuntimeException('Plaintext request is not allowed for this endpoint.');
        }

        return $hasSkipHeader || $pathSkipped || $attrSkipped;
    }

    protected function hasCryptoSkipHeader(ServerRequestInterface $request): bool
    {
        $skipHeader = (string) $this->config->get('req-res-crypto.skip_header', '');

        return $skipHeader !== '' && $request->getHeaderLine($skipHeader) === '1';
    }

    /**
     * 当前端请求头携带跳过加密标识时，在响应头中同样返回该标识，
     * 告知前端本次响应为明文，无需解密。
     */
    protected function withCryptoSkipResponseHeader(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if (!$this->hasCryptoSkipHeader($request)) {
            return $response;
        }

        $skipHeader = (string) $this->config->get('req-res-crypto.skip_header', '');

        return $response->withHeader($skipHeader, '1');
    }

    protected function isPathSkipped(ServerRequestInterface $request): bool
    {
        $patterns = $this->config->get('req-res-crypto.skip_routes', []);

        if ($patterns === []) {
            return false;
        }

        $path = $request->getUri()->getPath();

        return PathMatcher::matchesAny($path, $patterns);
    }

    protected function hasSkipAttribute(ServerRequestInterface $request): bool
    {
        $dispatched = $request->getAttribute(Dispatched::class);

        if (!$dispatched instanceof Dispatched || !$dispatched->isFound()) {
            return false;
        }

        $callback = $dispatched->handler->callback;

        if (!is_array($callback) || count($callback) !== 2) {
            return false;
        }

        /** @var array{0: class-string, 1: string} $callback */
        [$class, $method] = $callback;

        if (!is_string($class) || !class_exists($class)) {
            return false;
        }

        $methodAnnotation = AnnotationCollector::getClassMethodAnnotation($class, $method);
        $classAnnotation = AnnotationCollector::getClassAnnotation($class, SkipReqResCrypto::class);

        return isset($methodAnnotation[SkipReqResCrypto::class]) || $classAnnotation !== null;
    }
}
