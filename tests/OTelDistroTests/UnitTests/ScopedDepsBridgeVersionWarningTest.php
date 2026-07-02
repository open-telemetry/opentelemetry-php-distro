<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests;

use OpenTelemetry\API\Globals;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Distro\ScopedDepsBridge;
use OpenTelemetry\Distro\Log\LogBackend;
use OpenTelemetry\Distro\Log\LogLevel;
use OpenTelemetry\SDK\Sdk;
use OTelDistroTests\UnitTests\UtilTests\ProdLogTests\LogBackendTestUtil;
use OTelDistroTests\Util\TestCaseBase;
use ReflectionMethod;

final class ScopedDepsBridgeVersionWarningTest extends TestCaseBase
{
    /**
     * Exercises ScopedDepsBridge::warnIfVersionMismatch() directly via Reflection (it is
     * private, called only from a shutdown function registered by load()).
     *
     * Forces the 3 probe classes to autoload as plain unscoped classes first - this reproduces the
     * "app won the race" case the alias-validity check exists for: the bridge alias was never applied
     * (or applied too late), so ReflectionClass(...)->getName() does not start with the scoped prefix,
     * and a warning must be logged per package instead of silently reporting no mismatch.
     */
    public function testWarnsWhenAppLoadedItsOwnCopyBeforeAliasWasApplied(): void
    {
        self::assertTrue(class_exists(Globals::class));
        self::assertTrue(class_exists(Context::class));
        self::assertTrue(class_exists(Sdk::class));

        /** @var list<array{LogLevel, string, array<string, mixed>}> $warnings */
        $warnings = [];
        $formatAndWrite = function (LogLevel $level, null|int|string $featureOrCategory, string $file, int $line, string $func, string $message, array $context) use (&$warnings): void {
            $warnings[] = [$level, $message, $context];
        };
        $tempLogBackend = new LogBackend(maxEnabledLevel: LogLevel::warning->value, sourceCodeRootDirs: [], formatAndWrite: $formatAndWrite);

        $warnIfVersionMismatch = new ReflectionMethod(ScopedDepsBridge::class, 'warnIfVersionMismatch');
        $warnIfVersionMismatch->setAccessible(true);

        $bundledVersions = [
            'open-telemetry/api'     => '1.0.0',
            'open-telemetry/context' => '1.0.0',
            'open-telemetry/sdk'     => '1.0.0',
        ];
        LogBackendTestUtil::saveActOnTempInstanceRestore(
            $tempLogBackend,
            function () use ($warnIfVersionMismatch, $bundledVersions): void {
                $warnIfVersionMismatch->invoke(null, $bundledVersions, 'Test Distro');
            },
        );

        self::assertCount(3, $warnings);
        $warnedPackages = [];
        foreach ($warnings as [$level, $message, $context]) {
            self::assertSame(LogLevel::warning, $level);
            self::assertStringContainsString('loaded its own', $message);
            $warnedPackages[] = $context['package'];
        }
        self::assertSame(array_keys($bundledVersions), $warnedPackages);
    }

    public function testNoWarningsWhenNoBundledVersionsGiven(): void
    {
        /** @var list<array{LogLevel, string}> $warnings */
        $warnings = [];
        $formatAndWrite = function (LogLevel $level, null|int|string $featureOrCategory, string $file, int $line, string $func, string $message, array $context) use (&$warnings): void {
            $warnings[] = [$level, $message];
        };
        $tempLogBackend = new LogBackend(maxEnabledLevel: LogLevel::warning->value, sourceCodeRootDirs: [], formatAndWrite: $formatAndWrite);

        $warnIfVersionMismatch = new ReflectionMethod(ScopedDepsBridge::class, 'warnIfVersionMismatch');
        $warnIfVersionMismatch->setAccessible(true);

        LogBackendTestUtil::saveActOnTempInstanceRestore(
            $tempLogBackend,
            function () use ($warnIfVersionMismatch): void {
                $warnIfVersionMismatch->invoke(null, [], 'Test Distro');
            },
        );

        self::assertSame([], $warnings);
    }
}
