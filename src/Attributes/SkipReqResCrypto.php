<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Hyperf\Attributes;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

/**
 * 跳过加解密处理。
 *
 * 标记在控制器类或方法上，该路由的请求体和响应体将不会被解密/加密，
 * 无论全局或注解中间件是否启用。
 *
 * 优先级高于全局中间件配置。
 *
 * @example
 *   #[SkipReqResCrypto]
 *   class HealthController { ... }
 *
 *   class ApiController {
 *       #[SkipReqResCrypto]
 *       public function publicEndpoint(): array { ... }
 *   }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class SkipReqResCrypto extends AbstractAnnotation {}
