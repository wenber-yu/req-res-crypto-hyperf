# req-res-crypto-hyperf

Hyperf 适配层，为 [req-res-crypto-core](https://github.com/wenber-yu/req-res-crypto-core) 提供 PSR-15 中间件、命令、Crontab 定时轮换和数据库密钥管理。

依赖：PHP >= 8.3，Hyperf ~3.2。

## 安装

```bash
composer require wenber-yu/req-res-crypto-hyperf
```

Hyperf 通过 `ConfigProvider` 自动注册所有依赖绑定、命令和 Crontab，无需手动配置。

## 配置发布

```bash
php bin/hyperf.php vendor:publish wenber-yu/req-res-crypto-hyperf
```

发布后生成：
- `config/autoload/req-res-crypto.php` — 配置文件
- `migrations/2026_01_01_000000_create_req_res_crypto_public_keys.php` — 数据库迁移

### 配置说明（`config/autoload/req-res-crypto.php`）

| 配置项 | 环境变量 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `key_id` | `REQ_RES_CRYPTO_KEY_ID` | `''` | 当前密钥 ID（bootstrap） |
| `sign_secret_key` | `REQ_RES_CRYPTO_SIGN_SECRET_KEY` | `''` | 服务端 Ed25519 签名私钥（hex） |
| `sign_public_key` | `REQ_RES_CRYPTO_SIGN_PUBLIC_KEY` | `''` | 服务端 Ed25519 签名公钥（hex） |
| `exchange_secret_key` | `REQ_RES_CRYPTO_EXCHANGE_SECRET_KEY` | `''` | 服务端 X25519 交换私钥（hex） |
| `exchange_public_key` | `REQ_RES_CRYPTO_EXCHANGE_PUBLIC_KEY` | `''` | 服务端 X25519 交换公钥（hex） |
| `key_rotation.enabled` | `REQ_RES_CRYPTO_KEY_ROTATION_ENABLED` | `false` | 是否启用数据库密钥轮换 |
| `key_rotation.rotate_before_days` | — | `7` | 新密钥提前多少天发布为 pre_issued |
| `key_rotation.activate_after_days` | — | `7` | 新密钥发布多少天后自动激活 |
| `time_window` | — | `300` | 防重放时间容差（秒） |
| `nonce_ttl` | — | `600` | Nonce 过期时间（秒） |
| `database.connection` | `REQ_RES_CRYPTO_DB_CONNECTION` | `default` | 密钥表所在数据库连接 |
| `database.table` | — | `req_res_crypto_public_keys` | 密钥数据表名 |
| `crontab.enabled` | `REQ_RES_CRYPTO_CRONTAB_ENABLED` | `true` | 是否注册 Crontab 定时任务 |
| `crontab.rule` | — | `0 2 * * *` | Crontab 执行规则（默认每天凌晨 2 点） |
| `skip_routes` | — | `[]` | 跳过加解密的路由模式（支持 `*` / `**` 通配符） |
| `skip_header` | `REQ_RES_CRYPTO_SKIP_HEADER` | `X-Skip-Req-Res-Crypto` | 前端声明跳过加密的请求头名称 |

> **统一设计**：配置结构已完全扁平化（不再有 `default.*` 子数组）。无论 `key_rotation.enabled` 开启与否，中间件和 API 使用方式完全一致。

### 自动发现依赖绑定

`ConfigProvider` 自动注册以下依赖（全部为统一实现，内部自动适配轮换模式）：

| 接口 | 实现 |
| --- | --- |
| `ServerKeyProviderInterface` | `ServerKeyProvider` |
| `NonceStoreInterface` | `CacheNonceStore` |
| `SerializerInterface` | `JsonSerializer` |

## 数据库迁移

```bash
php bin/hyperf.php migrate
```

创建 `req_res_crypto_public_keys` 表：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | bigint (PK) | 自增主键 |
| `key_id` | varchar(32) UNIQUE | 72 位 hex，12 字节随机数 |
| `sign_public_key` | text | Ed25519 签名公钥（hex） |
| `sign_secret_key` | text | Ed25519 签名私钥（hex） |
| `exchange_public_key` | text | X25519 交换公钥（hex） |
| `exchange_secret_key` | text | X25519 交换私钥（hex） |
| `status` | enum(pre_issued, current, expired) | 密钥状态 |
| `issued_at` | timestamp | 密钥生成时间 |
| `activated_at` | timestamp | 激活时间 |
| `expired_at` | timestamp | 过期时间 |

## 中间件（PSR-15）

两个中间件均为 PSR-15 标准实现，在 `config/autoload/middlewares.php` 中注册即可。

### 解密请求：`ReqResDecryptRequestMiddleware`

```php
// config/autoload/middlewares.php
return [
    'http' => [
        \Wenbo\ReqResCrypto\Hyperf\Middleware\ReqResDecryptRequestMiddleware::class,
    ],
];
```

**行为**：
- 对所有请求尝试 base64 解码 body
- 若 body 不是有效 base64 → 透传
- 解密成功后，自动将 `Content-Type: application/octet-stream` 替换为 `application/json`
- 解密失败时透传原始请求（不抛异常）

### 加密响应：`ReqResEncryptResponseMiddleware`

```php
// config/autoload/middlewares.php
return [
    'http' => [
        \Wenbo\ReqResCrypto\Hyperf\Middleware\ReqResEncryptResponseMiddleware::class,
    ],
];
```

**行为**：
- 检测 `req_res_crypto_client_pubkey` request attribute，若不存在则跳过加密（非加密请求自动透传）
- 客户端 X25519 公钥由解密中间件从 wire 提取并存入 attribute
- 获取服务端当前活跃密钥，加密响应后回写 `base64(wire_format)`
- 响应 `Content-Type` 设为 `application/octet-stream`

**密钥轮换通知**：当数据库中存在 `pre_issued` 状态的密钥时，中间件自动在响应头附加 JSON 格式的轮换通知：

```
X-Req-Res-Crypto-Key-Rotate: {"key_id":"<新key_id>","sign_public_key":"<新签名公钥>","exchange_public_key":"<新交换公钥>"}
```

客户端 SDK 检测到此头后可自动缓存新公钥，实现无缝密钥轮换过渡。

## 命令

### 生成 Bootstrap 密钥（用于 .env 配置）

```bash
php bin/hyperf.php req-res-crypto:keys:generate
```

输出可直接复制到 `.env` 的密钥对配置，不依赖数据库和 rotation 开关。

### 生成新密钥（数据库轮换）

```bash
php bin/hyperf.php req-res-crypto:keys:rotate
```

若 `key_rotation.enabled` 为 `false`，命令直接退出。

### 激活密钥

```bash
# 激活最早的、已到达 activated_at 的 pre_issued 密钥
php bin/hyperf.php req-res-crypto:keys:activate

# 激活指定 Key ID
php bin/hyperf.php req-res-crypto:keys:activate a1b2c3d4e5f6g7h8i9j0k1l2
```

在数据库事务中：将当前 `current` 设为 `expired`，目标 `pre_issued` 设为 `current`。

## 使用方式

中间件提供两种注册方式，可混合使用：

### 方式一：全局中间件（推荐）

在 `config/autoload/middlewares.php` 中全局注册，一次配置，全站生效：

```php
return [
    'http' => [
        \Wenbo\ReqResCrypto\Hyperf\Middleware\ReqResDecryptRequestMiddleware::class,
        \Wenbo\ReqResCrypto\Hyperf\Middleware\ReqResEncryptResponseMiddleware::class,
    ],
];
```

- 解密中间件对非加密请求完全透明（非 base64 body → 自动透传）
- 加密中间件仅对存在 `req_res_crypto_client_pubkey` attribute 的请求加密响应（公钥由解密中间件从 wire 提取）

### 方式二：Hyperf `#[Middleware]` 注解（精细化控制）

使用 Hyperf 原生注解在控制器或方法级别精确控制：

```php
use Hyperf\HttpServer\Annotation\Middleware;
use Wenbo\ReqResCrypto\Hyperf\Middleware\ReqResDecryptRequestMiddleware;
use Wenbo\ReqResCrypto\Hyperf\Middleware\ReqResEncryptResponseMiddleware;

#[Middleware(ReqResDecryptRequestMiddleware::class)]
#[Middleware(ReqResEncryptResponseMiddleware::class)]
class OrderController extends AbstractController
{
    public function store(): array
    {
        // 请求自动解密、响应自动加密
        return ['order_id' => 123];
    }

    // #[Middleware] 也可标注在单个方法上
    #[Middleware(ReqResEncryptResponseMiddleware::class)]
    public function show(int $id): array
    {
        // 仅此方法加密响应
        return ['id' => $id, 'name' => 'Alice'];
    }
}
```

> **注意**：`#[Middleware]` 注解和全局中间件可以共存，不会重复执行。

## 跳过加解密

三种方式可让特定路由跳过加解密处理，优先级从高到低：

### 方式一：`#[SkipReqResCrypto]` 注解（推荐）

在控制器类或方法上标注，该路由完全跳过加解密：

```php
use Wenbo\ReqResCrypto\Hyperf\Attributes\SkipReqResCrypto;

// 整个控制器跳过
#[SkipReqResCrypto]
class HealthController extends AbstractController
{
    public function check(): array
    {
        return ['status' => 'ok'];
    }
}

// 单个方法跳过
class ApiController extends AbstractController
{
    #[SkipReqResCrypto]
    public function publicEndpoint(): array
    {
        return ['data' => 'public'];
    }
}
```

### 方式二：`skip_routes` 路径模式

在配置中按 URL 模式批量跳过：

```php
// config/autoload/req-res-crypto.php
'skip_routes' => [
    '/health',
    '/api/public/**',   // /api/public 下所有路径
    '/api/docs/*',      // /api/docs 下单层路径
],
```

### 方式三：`skip_header` 请求头

前端在请求中携带 `X-Skip-Req-Res-Crypto: 1` 头，**仅在路由命中 skip_routes 或 SkipReqResCrypto 注解时**服务端才接受明文，否则返回 400 错误。跳过加密时响应中会返回同名响应头，通知前端此次响应为明文。

```typescript
// 前端：声明发送明文
fetch('/api/health', {
  headers: { 'X-Skip-Req-Res-Crypto': '1' },
});
// 响应头中自动返回 X-Skip-Req-Res-Crypto: 1
```

> **安全机制**：skip_header 是"白名单确认"机制，不是"无条件跳过"。前端声明跳过 + 后端路由不在白名单 = 直接拒绝，防止攻击者伪造请求头绕过加密。

## Crontab（定时密钥轮换）

`ConfigProvider` 自动注册 Crontab 任务 `KeyRotationCrontab`。

### 启用条件

`.env` 中设置：

```env
REQ_RES_CRYPTO_KEY_ROTATION_ENABLED=true
```

### 行为

按 `crontab.rule`（默认每天凌晨 2 点）执行：
1. 检查 `key_rotation.enabled`，若为 `false` 则跳过并记录日志
2. 生成全新的 Ed25519 + X25519 密钥对
3. 以 `pre_issued` 状态写入数据库，`activated_at` = 当前时间 + `rotate_before_days` 天

### 禁用 Crontab

```env
REQ_RES_CRYPTO_CRONTAB_ENABLED=false
```

## 公开公钥端点

服务端需自行实现一个端点返回当前活跃密钥的公钥信息：

```php
namespace App\Controller;

use Wenbo\ReqResCrypto\Core\ServerKeyProviderInterface;

class CryptoController extends AbstractController
{
    public function __construct(
        private ServerKeyProviderInterface $keyProvider,
    ) {}

    public function publicKey(): array
    {
        $keyId = $this->keyProvider->getCurrentKeyId();
        if ($keyId === null) {
            return ['error' => 'No active key'];
        }

        return [
            'key_id' => $keyId,
            'sign_public_key' => $this->keyProvider->getSignPublicKey($keyId),
            'exchange_public_key' => $this->keyProvider->getExchangePublicKey($keyId),
        ];
    }
}
```

路由注册（`config/routes.php`）：

```php
Router::get('/crypto/public-key', [App\Controller\CryptoController::class, 'publicKey']);
Router::addRoute(['GET', 'POST'], '/api/orders', 'App\Controller\OrderController@store');
Router::addRoute(['GET'], '/api/user', 'App\Controller\UserController@show');
```

## 前端对接

### Hyperf 路由配置

```php
// config/routes.php
Router::get('/crypto/public-key', [App\Controller\CryptoController::class, 'publicKey']);
Router::post('/api/orders', [App\Controller\OrderController::class, 'store']);
Router::get('/api/user', [App\Controller\UserController::class, 'show']);
```

### 前端请求示例

```typescript
// 1. 获取服务端公钥
const pubKey = await fetch('/crypto/public-key').then(r => r.json());
// => { key_id: "...", sign_public_key: "...", exchange_public_key: "..." }

// 2. 发送加密请求（客户端公钥已嵌入 wire，无需额外请求头）
await fetch('/api/orders', {
  method: 'POST',
  headers: { 'Content-Type': 'application/octet-stream' },
  body: base64EncryptedWire, // base64(wire_format)
});
```

### 协议约定

| 约定 | 值 |
| --- | --- |
| 请求 `Content-Type` | `application/octet-stream` |
| 请求 body | `base64(wire_format)` — 客户端 X25519 公钥已嵌入 wire |
| 响应 `Content-Type` | `application/octet-stream` |
| 响应 body | `base64(wire_format)` |
| 响应头 `X-Req-Res-Crypto-Key-Rotate`（可选） | JSON：`{"key_id":"...","sign_public_key":"...","exchange_public_key":"..."}` — 密钥轮换通知 |

前端加密和解密的完整 TypeScript 实现参见 [req-res-crypto-js 客户端](https://github.com/wenber-yu/req-res-crypto-js)。

## 相关包

| 包 | 说明 |
| --- | --- |
| [req-res-crypto-js](https://github.com/wenber-yu/req-res-crypto-js) | 前端（浏览器）客户端 |
| [req-res-crypto-core](https://github.com/wenber-yu/req-res-crypto-core) | 核心加解密库（零框架依赖） |
| [req-res-crypto-laravel](https://github.com/wenber-yu/req-res-crypto-laravel) | Laravel 适配包 |
