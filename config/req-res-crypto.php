<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [

    /*
    |--------------------------------------------------------------------------
    | 密钥对配置（bootstrap 引导密钥）
    |--------------------------------------------------------------------------
    |
    | 所有模式都必须配置。当 key_rotation.enabled = false 时作为唯一密钥来源；
    | 当 key_rotation.enabled = true 时作为数据库无记录时的降级密钥。
    |
    | 部署前建议通过 php bin/hyperf.php req-res-crypto:keys:generate 生成。
    | key_id：8 字符 hex（取公钥指纹前 4 字节），私钥 64 字符 hex，公钥 64 字符 hex。
    |
    */

    'key_id' => env('REQ_RES_CRYPTO_KEY_ID', ''),
    'sign_secret_key' => env('REQ_RES_CRYPTO_SIGN_SECRET_KEY', ''),
    'sign_public_key' => env('REQ_RES_CRYPTO_SIGN_PUBLIC_KEY', ''),
    'exchange_secret_key' => env('REQ_RES_CRYPTO_EXCHANGE_SECRET_KEY', ''),
    'exchange_public_key' => env('REQ_RES_CRYPTO_EXCHANGE_PUBLIC_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | 密钥轮换
    |--------------------------------------------------------------------------
    |
    | enabled: 是否启用数据库密钥轮换。启用后密钥从数据库动态读取，
    |          bootstrap 密钥仅作降级。关闭时完全使用 bootstrap 密钥，
    |          getPreIssuedKeyId() 永远返回 null。
    | rotate_before_days: 新密钥提前多少天发布为 pre_issued
    | activate_after_days: 新密钥发布多少天后自动激活
    |
    */

    'key_rotation' => [
        'enabled' => env('REQ_RES_CRYPTO_KEY_ROTATION_ENABLED', false),
        'rotate_before_days' => 7,
        'activate_after_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | 防重放
    |--------------------------------------------------------------------------
    */

    'time_window' => 300,
    'nonce_ttl' => 600,

    /*
    |--------------------------------------------------------------------------
    | 数据库
    |--------------------------------------------------------------------------
    */

    'database' => [
        'connection' => env('REQ_RES_CRYPTO_DB_CONNECTION', 'default'),
        'table' => 'req_res_crypto_public_keys',
    ],

    /*
    |--------------------------------------------------------------------------
    | Crontab
    |--------------------------------------------------------------------------
    |
    | enabled: 是否启用 crontab 自动轮换（仅 key_rotation.enabled=true 时生效）
    | rule: crontab 表达式
    |
    */

    /*
    |--------------------------------------------------------------------------
    | 跳过加解密的路由
    |--------------------------------------------------------------------------
    |
    | 全局中间件默认对所有请求执行加解密。此处配置的路径模式将跳过加解密处理。
    |
    | 支持通配符：* 匹配单段路径，** 递归匹配多段。
    | 例如：['/api/health', '/api/public/**']
    |
    | 也可用 #[SkipReqResCrypto] 属性标记控制器类或方法。
    |
    */
    'skip_routes' => [],

    /*
    |--------------------------------------------------------------------------
    | 跳过加解密的 Header
    |--------------------------------------------------------------------------
    |
    | 前端发送此 Header 声明本次请求不发加密数据。
    | 后端只有在路由命中 skip_routes 或 #[SkipReqResCrypto] 注解时才接受明文。
    | 留空则完全禁用 header 跳过机制。
    |
    */
    'skip_header' => env('REQ_RES_CRYPTO_SKIP_HEADER', 'X-Skip-Req-Res-Crypto'),

    'crontab' => [
        'enabled' => env('REQ_RES_CRYPTO_CRONTAB_ENABLED', true),
        'rule' => '0 2 * * *',
    ],

];
