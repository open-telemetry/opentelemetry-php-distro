<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OpenTelemetry\Distro\PhpPartFacade;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\OTelDistroExtensionUtil;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\LoggableToString;
use OTelDistroTests\Util\Log\Logger;
use OTelDistroTests\Util\MixedMap;
use Override;
use PHPUnit\Framework\Assert;
use Throwable;

abstract class AppCodeHostBase extends SpawnedProcessBase
{
    private readonly Logger $logger;

    public function __construct()
    {
        parent::__construct();

        $this->logger = AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__)->addAllContext(compact('this'));

        $this->logger->logDebug(__FUNCTION__)?->with(__LINE__, 'Done');
    }

    #[Override]
    protected function shouldTracingBeEnabled(): bool
    {
        return true;
    }

    #[Override]
    protected function processConfig(): void
    {
        parent::processConfig();
        AmbientContextForTests::testConfig()->verifyForAppCode();
    }

    abstract protected function runImpl(): void;

    public static function run(): void
    {
        self::runSkeleton(
            function (SpawnedProcessBase $thisObj): void {
                Assert::assertInstanceOf(self::class, $thisObj);
                if (!OTelDistroExtensionUtil::isLoaded()) {
                    throw new ComponentTestsInfraException(
                        'Environment hosting component tests application code should have '
                        . OTelDistroExtensionUtil::EXTENSION_NAME . ' extension loaded.'
                        . ' php_ini_loaded_file(): ' . php_ini_loaded_file() . '.'
                    );
                }
                $adaptedClassName = AppCodeContextUtil::adaptClassNameToScoping(PhpPartFacade::class);
                if (!$adaptedClassName::$wasBootstrapCalled) {
                    throw new ComponentTestsInfraException($adaptedClassName . '::$wasBootstrapCalled is false while it should be true for the process with app code');
                }

                AmbientContextForTests::testConfig()->verifyForAppCodeRequest();

                $thisObj->runImpl();
            }
        );
    }

    #[Override]
    protected function isThisProcessTestScoped(): bool
    {
        return true;
    }

    protected function callAppCode(): void
    {
        $dataPerRequest = AmbientContextForTests::testConfig()->dataPerRequest();
        $logDebug = $this->logger->logDebug(__FUNCTION__);

        $logDebug?->with(__LINE__, 'Calling application code...', compact('dataPerRequest'));

        $msg = LoggableToString::convert(AmbientContextForTests::testConfig());
        $appCodeTarget = $dataPerRequest->appCodeTarget;
        Assert::assertNotNull($appCodeTarget, $msg);
        Assert::assertNotNull($appCodeTarget->appCodeClass, $msg);
        Assert::assertNotNull($appCodeTarget->appCodeMethod, $msg);

        try {
            $methodToCall = [$appCodeTarget->appCodeClass, $appCodeTarget->appCodeMethod];
            Assert::assertIsCallable($methodToCall, $msg);
            $appCodeRequestArgs = $dataPerRequest->appCodeRequestArgs;
            if ($appCodeRequestArgs === null) {
                call_user_func($methodToCall);
            } else {
                call_user_func($methodToCall, new MixedMap($appCodeRequestArgs));
            }
        } catch (Throwable $throwable) {
            $logThrown = ($dataPerRequest->isAppCodeExpectedToThrow) ? $logDebug : $this->logger->logCritical(__FUNCTION__);
            $logThrown?->withThrowable(__LINE__, 'Call to application code exited by exception', $throwable);
            throw $dataPerRequest->isAppCodeExpectedToThrow ? new WrappedAppCodeException($throwable) : $throwable;
        }

        $logDebug?->with(__LINE__, 'Call to application code completed');
    }
}
