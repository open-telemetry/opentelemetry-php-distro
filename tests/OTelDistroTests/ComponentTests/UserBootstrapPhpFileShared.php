<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

final class UserBootstrapPhpFileShared
{
    public const GLOBALS_KEY = __CLASS__ . '_globals_key';
    public const GLOBALS_VALUE = 'dummy ' . self::GLOBALS_KEY . ' value';
}
