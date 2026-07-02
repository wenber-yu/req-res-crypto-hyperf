<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Hyperf\Stream;

use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

final class SwooleStreamFactory implements StreamFactoryInterface
{
    public function createStream(string $content = ''): StreamInterface
    {
        return new SwooleStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return new SwooleStream(file_get_contents($filename));
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return new SwooleStream((string) stream_get_contents($resource));
    }
}
