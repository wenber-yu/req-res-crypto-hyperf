<?php

declare(strict_types=1);

/*
 * 测试环境 Helper：定义框架基础常量 + config() 桥接函数。
 */

namespace {
    ! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__));
}

namespace Hyperf\Config {
    use Hyperf\Context\ApplicationContext;

    if (! function_exists('Hyperf\Config\config')) {
        /**
         * 从 ApplicationContext 容器中读取配置。
         */
        function config(string $key, mixed $default = null): mixed
        {
            $container = ApplicationContext::getContainer();
            if ($container === null) {
                return $default;
            }

            $config = $container->get('config');

            if (! is_array($config)) {
                return $default;
            }

            $keys = explode('.', $key);
            $value = $config;
            foreach ($keys as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    return $default;
                }
                $value = $value[$segment];
            }

            return $value;
        }
    }
}
