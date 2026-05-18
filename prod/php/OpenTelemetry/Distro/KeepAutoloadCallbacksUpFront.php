<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

declare(strict_types=1);

namespace OpenTelemetry\Distro;

use OpenTelemetry\Distro\Log\LogFeature;
use OpenTelemetry\Distro\Log\SplAutoloadFunctionsLogTrait;
use OpenTelemetry\Distro\Log\SplAutoloadFunctionsLogUtil;
use OpenTelemetry\Distro\Util\DistroRuntimeException;
use Throwable;

final class KeepAutoloadCallbacksUpFront
{
    use BootstrapStageLoggingClassTrait;
    use SplAutoloadFunctionsLogTrait;

    private const SPL_AUTOLOAD_REGISTER_FUNC_NAME = 'spl_autoload_register';

    private bool $shouldIgnoreRegisterCalls = false;

    /**
     * @param list<callable> $callbacks
     */
    public function __construct(
        private readonly InstrumentationBridge $instrumBridge,
        private array $callbacks
    ) {
        $this->hookSplAutoloadRegister();
    }

    /**
     * @return list<callable>
     */
    public function getCallbacks(): array
    {
        return $this->callbacks;
    }

    /**
     * @param list<callable> $callbacksToKeepUpFront
     */
    public function setCallbacks(array $callbacksToKeepUpFront): void
    {
        $callbacksToKeepUpFrontCount = count($callbacksToKeepUpFront);
        $registeredCallbacks = spl_autoload_functions();
        $registeredCallbacksCount = count($registeredCallbacks);
        /**
         * @return array<string, mixed>
         */
        $buildBaseCtx = function () use ($callbacksToKeepUpFront, $callbacksToKeepUpFrontCount, $registeredCallbacks, $registeredCallbacksCount): array {
            return compact('callbacksToKeepUpFrontCount', 'registeredCallbacksCount')
                + [
                    'callbacksToKeepUpFront' => SplAutoloadFunctionsLogUtil::callbacksToLoggable($callbacksToKeepUpFront),
                    'registeredCallbacks' => SplAutoloadFunctionsLogUtil::callbacksToLoggable($registeredCallbacks),
                ];
        };

        if ($callbacksToKeepUpFrontCount > $registeredCallbacksCount) {
            throw new DistroRuntimeException('callbacksToKeepUpFrontCount is larger than registeredCallbacksCount', context: $buildBaseCtx());
        }

        for ($callbackIndex = 0; $callbackIndex != $callbacksToKeepUpFrontCount; ++$callbackIndex) {
            if ($registeredCallbacks[$callbackIndex] !== $callbacksToKeepUpFront[$callbackIndex]) {
                $ctx = compact('callbackIndex') + $buildBaseCtx();
                throw new DistroRuntimeException('callbacksToKeepUpFront is not a prefix of registeredCallbacks', context: $ctx);
            }
        }

        $this->callbacks = $callbacksToKeepUpFront;
    }

    private function hookSplAutoloadRegister(): void
    {
        $hookRetVal = $this->instrumBridge->hook(
            class: null,
            function: self::SPL_AUTOLOAD_REGISTER_FUNC_NAME,
            post: $this->splAutoloadRegisterPostHookToKeepDistroFirst(...),
        );

        if (!$hookRetVal) {
            throw new DistroRuntimeException('hook() return false');
        }

        self::logDebug(__LINE__, __FUNCTION__, 'Registered hook for ' . self::SPL_AUTOLOAD_REGISTER_FUNC_NAME);
    }

    /**
     * @param list<mixed> $params
     */
    private function splAutoloadRegisterPostHookToKeepDistroFirst(
        /** @noinspection PhpUnusedParameterInspection */ ?object $thisObj,
        array $params,
        mixed $returnValue,
        ?Throwable $throwable,
    ): void {
        self::logAutoloadFunctions(BootstrapStageLogger::LEVEL_DEBUG, __LINE__, __FUNCTION__, 'Entered');

        if ($this->shouldIgnoreRegisterCalls) {
            self::logDebug(__LINE__, __FUNCTION__, 'shouldIgnoreRegisterCalls is true - not doing anything');
            return;
        }

        if ($throwable !== null) {
            self::logDebug(__LINE__, __FUNCTION__, 'Call spl_autoload_register() thrown - not doing anything', ['throwable message' => $throwable->getMessage()]);
            return;
        }

        // function spl_autoload_register(?callable $callback, bool $throw = true, bool $prepend = false): bool {}
        if (count($params) < 3) {
            self::logError(__LINE__, __FUNCTION__, 'prepend parameter is missing', ['count($params)' => count($params)]);
            return;
        }

        if (!$params[2]) {
            self::logDebug(__LINE__, __FUNCTION__, 'prepend param value is not true - not doing anything');
            return;
        }

        self::unregisterCallbacks();
        self::registerCallbacks();

        self::logAutoloadFunctions(BootstrapStageLogger::LEVEL_DEBUG, __LINE__, __FUNCTION__, 'Exiting...');
    }

    private function unregisterCallbacks(): void
    {
        foreach ($this->callbacks as $callback) {
            spl_autoload_unregister($callback);
        }
    }

    private function registerCallbacks(): void
    {
        self::logAutoloadFunctions(BootstrapStageLogger::LEVEL_DEBUG, __LINE__, __FUNCTION__, 'Entered');

        $this->shouldIgnoreRegisterCalls = true;
        try {
            $callbacksCount = count($this->callbacks);
            for ($i = 0; $i != $callbacksCount; ++$i) {
                // iterate over callbacks array in reverse order
                spl_autoload_register($this->callbacks[$callbacksCount - $i - 1], prepend: true);
            }
        } finally {
            $this->shouldIgnoreRegisterCalls = false;
        }

        self::logAutoloadFunctions(BootstrapStageLogger::LEVEL_DEBUG, __LINE__, __FUNCTION__, 'Exiting');
    }

    /**
     * Must be defined in class using BootstrapStageLoggingClassTrait
     */
    private static function getCurrentSourceCodeFile(): string
    {
        return __FILE__;
    }

    /**
     * Must be defined in class using BootstrapStageLoggingClassTrait
     */
    private static function getCurrentSourceCodeClass(): string
    {
        return __CLASS__;
    }

    /**
     * Must be defined in class using BootstrapStageLoggingClassTrait
     */
    private static function getCurrentLogFeature(): int
    {
        return LogFeature::MODULE;
    }
}
