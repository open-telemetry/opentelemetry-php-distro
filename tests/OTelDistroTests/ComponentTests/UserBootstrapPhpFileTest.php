<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use OpenTelemetry\Distro\Util\ArrayUtil;
use OTelDistroTests\ComponentTests\Util\AgentBackendComms;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\DataProviderForTestBuilder;
use OTelDistroTests\Util\DebugContextScopeRef;
use OTelDistroTests\Util\MixedMap;

/**
 * @group smoke
 * @group does_not_require_external_services
 */
final class UserBootstrapPhpFileTest extends ComponentTestCaseBase
{
    private const USER_BOOTSTRAP_FILE_FULL_PATH = __DIR__ . DIRECTORY_SEPARATOR . 'user_bootstrap.php';

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestVariousValues(): iterable
    {
        return self::adaptDataProviderForTestBuilderToSmokeToDescToMixedMap(
            (new DataProviderForTestBuilder())
                ->addKeyedDimensionAllValuesCombinable(
                    OptionForProdName::user_bootstrap_php_file->name,
                    [
                        self::USER_BOOTSTRAP_FILE_FULL_PATH,
                        __DIR__ . DIRECTORY_SEPARATOR . 'user_bootstrap_file_that_does_not_exist.php',
                        null,
                        123, // not a valid value - not a ?string
                        678.9, // not a valid value - not a ?string
                    ]
                )
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function appCodeForTestVariousValues(): array
    {
        return [UserBootstrapPhpFileShared::GLOBALS_KEY => ArrayUtil::getValueIfKeyExistsElse(UserBootstrapPhpFileShared::GLOBALS_KEY, $GLOBALS, null)];
    }

    private function implTestVariousValues(MixedMap $testArgs): void
    {
        $this->implTestForAppCodeSetsHowFinished(
            testArgs: $testArgs,
            subAppCode: [__CLASS__, 'appCodeForTestVariousValues'],
            additionalAssertCode: function (DebugContextScopeRef $dbgCtx, AgentBackendComms $agentBackendComms, MixedMap $appCodeAuxOutput) use ($testArgs): void {
                $userBootstrapPhpFileOptVal = $testArgs->get(OptionForProdName::user_bootstrap_php_file->name);
                $globalsVal = $appCodeAuxOutput->getNullableString(UserBootstrapPhpFileShared::GLOBALS_KEY);
                self::assertSame($userBootstrapPhpFileOptVal === self::USER_BOOTSTRAP_FILE_FULL_PATH ? UserBootstrapPhpFileShared::GLOBALS_VALUE : null, $globalsVal);
            }
        );
    }

    /**
     * @dataProvider dataProviderForTestVariousValues
     */
    public function testVariousValues(MixedMap $testArgs): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTestWithArgs(__CLASS__, __FUNCTION__, $testArgs),
            function () use ($testArgs): void {
                $this->implTestVariousValues($testArgs);
            }
        );
    }
}
