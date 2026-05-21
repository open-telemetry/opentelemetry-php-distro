<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OTelDistroTests\Util\EnumUtilForTestsTrait;
use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LogStreamInterface;
use Override;

enum AppCodeHostKind implements LoggableInterface
{
    use EnumUtilForTestsTrait;

    case CLI_script;
    case Builtin_HTTP_server;

    public function isHttp(): bool
    {
        return match ($this) {
            self::CLI_script => false,
            self::Builtin_HTTP_server => true,
        };
    }

    #[Override]
    public function toLog(LogStreamInterface $stream): void
    {
        $stream->toLogAs($this->name);
    }
}
