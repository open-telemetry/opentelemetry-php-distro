<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use OTelDistroTests\ComponentTests\Util\AppCodeContextDataUtil;
use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AppCodeTarget;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\WaitForOTelSignalCounts;
use OTelDistroTests\Util\DebugContext;

/**
 * @group smoke
 * @group does_not_require_external_services
 */
final class DependenciesScopingTest extends ComponentTestCaseBase
{
    public function implTestWhichClassesAreLoaded(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $testCaseHandle = $this->getTestCaseHandle();

        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeParams): void {
                self::ensureTransactionSpanEnabled($appCodeParams);
            }
        );

        $appCodeArgs = [];
        AppCodeContextDataUtil::createTempFile($testCaseHandle, /* in,out */ $appCodeArgs);

        $appCodeHost->execAppCode(AppCodeTarget::asRouted([__CLASS__, 'appCodeSetsHowFinished']));

        $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spans(1)); // exactly 1 span (the root span) is expected
        $dbgCtx->add(compact('agentBackendComms'));

        // Assert

        $appCodeContextData = AppCodeContextDataUtil::readDataAsMixedMapFromTempFile($appCodeArgs);
        $dbgCtx->add(compact('appCodeContextData'));
        self::assertTrue($appCodeContextData->getBool(self::DID_APP_CODE_FINISH_SUCCESSFULLY_KEY));

        $rootSpan = $agentBackendComms->singleRootSpan();
        $dbgCtx->add(compact('rootSpan'));
    }

    public function testWhichClassesAreLoaded(): void
    {
        self::runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTest(__CLASS__, __FUNCTION__),
            function (): void {
                $this->implTestWhichClassesAreLoaded();
            }
        );
    }
}
