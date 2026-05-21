<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use Composer\InstalledVersions;
use OpenTelemetry\Distro\OverrideOTelSdkResourceAttributes;
use OpenTelemetry\Distro\PhpPartVersion;
use OTelDistroTests\ComponentTests\Util\AgentBackendComms;
use OTelDistroTests\ComponentTests\Util\AppCodeContextUtil;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\AttributesExpectations;
use OTelDistroTests\Util\ArrayUtilForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\BoolUtilForTests;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\DebugContextScopeRef;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\MixedMap;
use OTelDistroTests\Util\RangeUtil;
use OTelDistroTests\Util\TextUtilForTests;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use OpenTelemetry\SemConv\Attributes\TelemetryAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\TelemetryIncubatingAttributes;
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
            $addToResult(ServiceAttributes::SERVICE_NAME, self::SERVICE_NAME);
        }
        if ($testArgs->getBool(self::SHOULD_SET_SERVICE_VERSION_KEY)) {
            $addToResult(ServiceAttributes::SERVICE_VERSION, self::SERVICE_VERSION);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public static function appCodeForTestAttributes(): array
    {
        $overrideOTelSdkResourceAttributesClassName = AppCodeContextUtil::adaptClassNameToScoping(OverrideOTelSdkResourceAttributes::class);
        return [
            "$overrideOTelSdkResourceAttributesClassName class is defined in file" => (new ReflectionClass($overrideOTelSdkResourceAttributesClassName))->getFileName(),
            self::DISTRO_VERSION_IN_APP_CONTEXT                                    => $overrideOTelSdkResourceAttributesClassName::getDistroVersion(),
        ];
    }

    private static function getOTelSdkVersion(): string
    {
        $otelSdkPackageName = 'open-telemetry/sdk';
        Assert::assertTrue(InstalledVersions::isInstalled($otelSdkPackageName));
        return AssertEx::notNull(InstalledVersions::getPrettyVersion($otelSdkPackageName));
    }

    private function implTestAttributes(MixedMap $testArgs): void
    {
        $testArgsEx = $testArgs->cloneAsArray();
        ArrayUtilForTests::addAssertingKeyNew(OptionForProdName::resource_attributes->name, self::buildOTelResourceAttributesForAppProcess($testArgs), /* in,out */ $testArgsEx);
        $this->implTestForAppCodeSetsHowFinished(
            testArgs: new MixedMap($testArgsEx),
            subAppCode: [__CLASS__, 'appCodeForTestAttributes'],
            additionalAssertCode: function (DebugContextScopeRef $dbgCtx, AgentBackendComms $agentBackendComms, MixedMap $appCodeAuxOutput) use ($testArgs): void {
                $expectedResourceAttributes = [
                    TelemetryIncubatingAttributes::TELEMETRY_DISTRO_NAME => 'opentelemetry-php-distro',
                    TelemetryAttributes::TELEMETRY_SDK_LANGUAGE => 'php',
                    TelemetryAttributes::TELEMETRY_SDK_NAME => 'opentelemetry',
                    TelemetryAttributes::TELEMETRY_SDK_VERSION => self::getOTelSdkVersion(),
                ];
                $notExpectedAttributes = [];

                $expectedResourceAttributes[ServiceAttributes::SERVICE_NAME] = $testArgs->getBool(self::SHOULD_SET_SERVICE_NAME_KEY) ? self::SERVICE_NAME : self::DEFAULT_SERVICE_NAME;

                if ($testArgs->getBool(self::SHOULD_SET_SERVICE_VERSION_KEY)) {
                    $expectedResourceAttributes[ServiceAttributes::SERVICE_VERSION] = self::SERVICE_VERSION;
                } else {
                    $notExpectedAttributes[] = ServiceAttributes::SERVICE_VERSION;
                }

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

                $distroVersionInAppContext = $appCodeAuxOutput->getString(self::DISTRO_VERSION_IN_APP_CONTEXT);
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

                $expectedResourceAttributes[TelemetryIncubatingAttributes::TELEMETRY_DISTRO_VERSION] = $distroVersionInAppContext;
                $resources = IterableUtil::toList($agentBackendComms->resources());
                $dbgCtx->add(compact('resources'));
                AssertEx::isPositiveInt(count($resources));
                $resourceAttributesExpectations = new AttributesExpectations(attributes: $expectedResourceAttributes, notAllowedAttributes: $notExpectedAttributes);
                foreach ($resources as $resource) {
                    $resourceAttributesExpectations->assertMatches($resource->attributes);
                }
            }
        );
    }

    /**
     * @dataProvider dataProviderForTestAttributes
     */
    public function testAttributes(MixedMap $testArgs): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTestWithArgs(__CLASS__, __FUNCTION__, $testArgs),
            function () use ($testArgs): void {
                $this->implTestAttributes($testArgs);
            }
        );
    }
}
