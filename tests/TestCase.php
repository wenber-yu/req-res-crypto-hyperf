<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Hyperf\Tests;

use Hyperf\Context\ApplicationContext;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Hyperf\Testing\TestCase as HyperfTestCase;

abstract class TestCase extends HyperfTestCase
{
    /**
     * 覆盖父类方法，跳过 ApplicationInterface 解析。
     * 在完整 Hyperf 应用中 ApplicationInterface 由框架注册，
     * 但独立的 composer 包测试中没有该绑定。
     */
    protected function refreshContainer(): void
    {
        if (! $this->container instanceof Container) {
            $this->container = ApplicationContext::setContainer(
                new Container((new DefinitionSourceFactory)()),
            );
        }
    }
}
