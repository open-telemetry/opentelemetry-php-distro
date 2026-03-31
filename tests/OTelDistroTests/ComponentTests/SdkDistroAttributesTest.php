<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use Composer\InstalledVersions;
use OpenTelemetry\Distro\OverrideOTelSdkResourceAttributes;
use OpenTelemetry\Distro\PhpPartVersion;
use OTelDistroTests\ComponentTests\Util\AppCodeContextDataUtil;
use OTelDistroTests\ComponentTests\Util\AppCodeContextUtil;
use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\AppCodeTarget;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\AttributesExpectations;
use OTelDistroTests\ComponentTests\Util\WaitForOTelSignalCounts;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\BoolUtilForTests;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\MixedMap;
use OTelDistroTests\Util\RangeUtil;
use OTelDistroTests\Util\TextUtilForTests;
use OpenTelemetry\SemConv\ResourceAttributes;
use PHPUnit\Framework\Assert;
use ReflectionClass;

/**
 * @group smoke
 * @group does_not_require_external_services
 */
final class SdkDistroAttributesTest extends ComponentTestCaseBase
{
    private const SHOULD_SET_SERVICE_NAME_KEY = 'should_set_service_name';
    private const SHOULD_SET_SERVICE_VERSION_KEY = 'should_set_service_version';

    private const SERVICE_NAME = 'my_service';
    private const SERVICE_VERSION = '333.22.1-dirty/1.22.333';

    private const DISTRO_VERSION_IN_APP_CONTEXT = 'distro_version_in_app_context';

    private const DEFAULT_SERVICE_NAME = 'unknown_service:php';

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestAttributes(): iterable
    {
        /**
         * @return iterable<array<string, mixed>>
         */
        $generateDataSets = function (): iterable {
            foreach (BoolUtilForTests::ALL_VALUES as $shouldSetServiceName) {
                $shouldSetServiceVersionVariants = $shouldSetServiceName ? BoolUtilForTests::ALL_VALUES : [false];
                foreach ($shouldSetServiceVersionVariants as $shouldSetServiceVersion) {
                    yield [
                        self::SHOULD_SET_SERVICE_NAME_KEY => $shouldSetServiceName,
                        self::SHOULD_SET_SERVICE_VERSION_KEY => $shouldSetServiceVersion,
                    ];
                }
            }
        };

        return self::adaptDataSetsGeneratorToSmokeToDescToMixedMap($generateDataSets);
    }

    public static function buildOTelResourceAttributesForAppProcess(MixedMap $testArgs): string
    {
        $result = '';
        $addToResult = function (string $key, string $value) use (&$result): void {
            if ($result !== '') {
                $result .= ',';
            }
            $result .= ($key . '=' . $value);
        };

        if ($testArgs->getBool(self::SHOULD_SET_SERVICE_NAME_KEY)) {
            $addToResult(ResourceAttributes::SERVICE_NAME, self::SERVICE_NAME);
        }
        if ($testArgs->getBool(self::SHOULD_SET_SERVICE_VERSION_KEY)) {
            $addToResult(ResourceAttributes::SERVICE_VERSION, self::SERVICE_VERSION);
        }

        return $result;
    }

    public static function appCodeForTestAttributes(MixedMap $appCodeArgs): void
    {
        self::appCodeSetsHowFinished(
            $appCodeArgs,
            /**
             * @retrun array<string, mixed>
             */
            function (): array {
                $overrideOTelSdkResourceAttributesClassName = AppCodeContextUtil::adaptClassName(OverrideOTelSdkResourceAttributes::class);
                return [
                    "$overrideOTelSdkResourceAttributesClassName class is defined in file" => (new ReflectionClass($overrideOTelSdkResourceAttributesClassName))->getFileName(),
                    self::DISTRO_VERSION_IN_APP_CONTEXT                                    => $overrideOTelSdkResourceAttributesClassName::getDistroVersion(),
                ];
            }
        );
    }

    private static function getOTelSdkVersion(): string
    {
        $otelSdkPackageName = 'open-telemetry/sdk';
        Assert::assertTrue(InstalledVersions::isInstalled($otelSdkPackageName));
        return AssertEx::notNull(InstalledVersions::getPrettyVersion($otelSdkPackageName));
    }

    public function implTestAttributes(MixedMap $testArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $testCaseHandle = $this->getTestCaseHandle();

        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeParams) use ($testArgs): void {
                self::ensureTransactionSpanEnabled($appCodeParams);
                $appCodeParams->setProdOption(OptionForProdName::resource_attributes, self::buildOTelResourceAttributesForAppProcess($testArgs));
            }
        );

        $appCodeArgs = $testArgs->cloneAsArray();
        AppCodeContextDataUtil::createTempFile($testCaseHandle, /* in,out */ $appCodeArgs);

        $appCodeHost->execAppCode(
            AppCodeTarget::asRouted([__CLASS__, 'appCodeForTestAttributes']),
            function (AppCodeRequestParams $appCodeRequestParams) use ($appCodeArgs): void {
                $appCodeRequestParams->setAppCodeArgs($appCodeArgs);
            }
        );

        $expectedResourceAttributes = [
            ResourceAttributes::TELEMETRY_DISTRO_NAME    => 'opentelemetry-php-distro',
            ResourceAttributes::TELEMETRY_SDK_LANGUAGE   => 'php',
            ResourceAttributes::TELEMETRY_SDK_NAME       => 'opentelemetry',
            ResourceAttributes::TELEMETRY_SDK_VERSION    => self::getOTelSdkVersion(),
        ];
        $notExpectedAttributes = [];

        $expectedResourceAttributes[ResourceAttributes::SERVICE_NAME] = $testArgs->getBool(self::SHOULD_SET_SERVICE_NAME_KEY) ? self::SERVICE_NAME : self::DEFAULT_SERVICE_NAME;

        if ($testArgs->getBool(self::SHOULD_SET_SERVICE_VERSION_KEY)) {
            $expectedResourceAttributes[ResourceAttributes::SERVICE_VERSION] = self::SERVICE_VERSION;
        } else {
            $notExpectedAttributes[] = ResourceAttributes::SERVICE_VERSION;
        }

        $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spans(1)); // exactly 1 span (the root span) is expected
        $dbgCtx->add(compact('agentBackendComms'));

        // Assert

        $appCodeContextData = AppCodeContextDataUtil::readDataAsMixedMapFromTempFile($appCodeArgs);
        $dbgCtx->add(compact('appCodeContextData'));
        self::assertTrue($appCodeContextData->getBool(self::DID_APP_CODE_FINISH_SUCCESSFULLY_KEY));

        $rootSpan = $agentBackendComms->singleRootSpan();
        $dbgCtx->add(compact('rootSpan'));

        $removeVersionSuffix = function (string $inputVer): string {
            $suffixStartPos = null;
            foreach (IterableUtil::zipOneWithIndex(TextUtilForTests::iterateOverChars($inputVer)) as [$currentCharPos, $currentCharAsciiCode]) {
                if (!(RangeUtil::isInClosedRange(ord('0'), $currentCharAsciiCode, ord('9')) || ($currentCharAsciiCode === ord('.')))) {
                    $suffixStartPos = $currentCharPos;
                    break;
                }
            }

            if ($suffixStartPos === null) {
                return $inputVer;
            }
            self::assertGreaterThan(0, $suffixStartPos);

            return substr($inputVer, 0, $suffixStartPos);
        };

        $distroVersionInAppContext = $appCodeContextData->getString(self::DISTRO_VERSION_IN_APP_CONTEXT);
        $dbgCtx->add(compact('distroVersionInAppContext'));
        $dbgCtx->add(['PhpPartVersion::VALUE' => PhpPartVersion::VALUE]);
        $phpPartVerWithoutSuffix = $removeVersionSuffix(PhpPartVersion::VALUE);
        $dbgCtx->add(compact('phpPartVerWithoutSuffix'));
        /**
         * @see OverrideOTelSdkResourceAttributes::buildDistroVersion
         */
        $distroVerSlashPos = strpos($distroVersionInAppContext, '/');
        if ($distroVerSlashPos === false) {
            self::assertSame($phpPartVerWithoutSuffix, $removeVersionSuffix($distroVersionInAppContext));
        } else {
            self::assertGreaterThan(0, $distroVerSlashPos);
            self::assertLessThan(strlen($distroVersionInAppContext) - 1, $distroVerSlashPos);
            $nativePartVerInAppContext = substr($distroVersionInAppContext, 0, $distroVerSlashPos);
            self::assertSame($phpPartVerWithoutSuffix, $removeVersionSuffix($nativePartVerInAppContext));
            $phpPartVerInAppContext = substr($distroVersionInAppContext, $distroVerSlashPos + 1);
            self::assertSame($phpPartVerWithoutSuffix, $removeVersionSuffix($phpPartVerInAppContext));
        }

        $expectedResourceAttributes[ResourceAttributes::TELEMETRY_DISTRO_VERSION] = $distroVersionInAppContext;
        $resources = IterableUtil::toList($agentBackendComms->resources());
        $dbgCtx->add(compact('resources'));
        AssertEx::isPositiveInt(count($resources));
        $resourceAttributesExpectations = new AttributesExpectations(attributes: $expectedResourceAttributes, notAllowedAttributes: $notExpectedAttributes);
        foreach ($resources as $resource) {
            $resourceAttributesExpectations->assertMatches($resource->attributes);
        }
    }

    /**
     * @dataProvider dataProviderForTestAttributes
     */
    public function testAttributes(MixedMap $testArgs): void
    {
        self::runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTestWithArgs(__CLASS__, __FUNCTION__, $testArgs),
            function () use ($testArgs): void {
                $this->implTestAttributes($testArgs);
            }
        );
    }
}
