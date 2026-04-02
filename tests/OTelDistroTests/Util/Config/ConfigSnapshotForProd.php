<?php

declare(strict_types=1);

namespace OTelDistroTests\Util\Config;

use OpenTelemetry\Distro\Log\LogLevel;
use OpenTelemetry\Distro\Util\WildcardListMatcher;
use OTelDistroTests\Util\Duration;
use OTelDistroTests\Util\Log\LoggableInterface;

/**
 * Code in this file is part of implementation internals, and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class ConfigSnapshotForProd implements LoggableInterface
{
    use SnapshotTrait;

    private readonly ?bool $autoloadEnabled; // @phpstan-ignore property.uninitializedReadonly
    private readonly ?string $bootstrapPhpPartFile; // @phpstan-ignore property.uninitializedReadonly
    private readonly bool $debugScoperEnabled; // @phpstan-ignore property.uninitializedReadonly
    private readonly ?WildcardListMatcher $disabledInstrumentations; // @phpstan-ignore property.uninitializedReadonly
    private readonly bool $enabled; // @phpstan-ignore property.uninitializedReadonly
    private readonly ?string $exporterOtlpEndpoint; // @phpstan-ignore property.uninitializedReadonly
    private readonly bool $inferredSpansEnabled; // @phpstan-ignore property.uninitializedReadonly
    private readonly Duration $inferredSpansMinDuration; // @phpstan-ignore property.uninitializedReadonly
    private readonly bool $inferredSpansReductionEnabled; // @phpstan-ignore property.uninitializedReadonly
    private readonly Duration $inferredSpansSamplingInterval; // @phpstan-ignore property.uninitializedReadonly
    private readonly bool $inferredSpansStacktraceEnabled; // @phpstan-ignore property.uninitializedReadonly
    private readonly ?string $logFile; // @phpstan-ignore property.uninitializedReadonly
    private readonly LogLevel $logLevelFile; // @phpstan-ignore property.uninitializedReadonly
    private readonly LogLevel $logLevelStderr; // @phpstan-ignore property.uninitializedReadonly
    private readonly LogLevel $logLevelSyslog; // @phpstan-ignore property.uninitializedReadonly
    private readonly ?string $resourceAttributes; // @phpstan-ignore property.uninitializedReadonly
    private readonly bool $transactionSpanEnabled; // @phpstan-ignore property.uninitializedReadonly
    private readonly bool $transactionSpanEnabledCli; // @phpstan-ignore property.uninitializedReadonly

    /**
     * @param array<string, mixed> $optNameToParsedValue
     */
    public function __construct(array $optNameToParsedValue)
    {
        self::setPropertiesToValuesFrom($optNameToParsedValue);
    }

    public function effectiveLogLevel(): LogLevel
    {
        $maxFoundLevel = LogLevel::off;

        $keepMaxLevel = function (LogLevel $logLevel) use (&$maxFoundLevel): void {
            if ($logLevel->value > $maxFoundLevel->value) {
                $maxFoundLevel = $logLevel;
            }
        };

        $keepMaxLevel($this->logLevelStderr);
        $keepMaxLevel($this->logLevelSyslog);
        if ($this->logFile !== null) {
            $keepMaxLevel($this->logLevelFile);
        }

        return $maxFoundLevel;
    }

    /** @noinspection PhpUnused */
    public function isInstrumentationDisabled(string $name): bool
    {
        if ($this->disabledInstrumentations === null) {
            return false;
        }

        /**
         * @see \OpenTelemetry\SDK\Sdk::isInstrumentationDisabled
         * @see \OpenTelemetry\SDK\Sdk::OTEL_PHP_DISABLED_INSTRUMENTATIONS_ALL
         */
        return $this->disabledInstrumentations->match('all') || $this->disabledInstrumentations->match($name);
    }
}
