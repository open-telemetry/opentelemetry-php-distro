<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use OTelDistroTests\ComponentTests\Util\AgentBackendComms;
use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AttributesExpectations;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\HttpServerHandle;
use OTelDistroTests\ComponentTests\Util\TestCaseHandle;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\ClassNameUtil;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\DebugContextScopeRef;
use OTelDistroTests\Util\FileUtil;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\MixedMap;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\TelemetryIncubatingAttributes;

/**
 * @group does_not_require_external_services
 */
final class DeclarativeConfigTest extends ComponentTestCaseBase
{
    private const YAML_TEMPLATE_FILE = __DIR__ . '/TestData/declarative_config_test.yaml';
    private const EXPECTED_SERVICE_NAME = 'declarative-config-component-test';
    private const EXPECTED_CUSTOM_ATTRIBUTE_VALUE = 'test-value-from-yaml';

    private function buildYamlConfigFile(TestCaseHandle $testCaseHandle): string
    {
        /** @noinspection HttpUrlsUsage */
        $endpoint = 'http://' . HttpServerHandle::CLIENT_LOCALHOST_ADDRESS . ':' . $testCaseHandle->getMockOTelCollector()->getPortForAgent();
        $yamlContent = FileUtil::getFileContents(self::YAML_TEMPLATE_FILE);
        $yamlContent = str_replace('${OTEL_EXPORTER_OTLP_ENDPOINT}', $endpoint, $yamlContent);
        $tmpFile = $testCaseHandle->getResourcesCleaner()->getClient()->createTempFile(
            fileNamePrefix: FileUtil::generateTempFileNamePrefix(ClassNameUtil::fqToShortFromRawString(__CLASS__) . '_otel_decl_cfg'),
            fileNameSuffix: '.yaml',
        );
        FileUtil::putFileContents($tmpFile, $yamlContent);
        return $tmpFile;
    }

    private function implTestDeclarativeConfigResourceAttributes(): void
    {
        $testCaseHandle = $this->getTestCaseHandle();
        $yamlConfigFile = $this->buildYamlConfigFile($testCaseHandle);

        // Pre-initialize app code host with OTEL_CONFIG_FILE env var
        // ensureMainAppCodeHost is lazy - subsequent calls return the same instance
        $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams) use ($yamlConfigFile): void {
                self::ensureTransactionSpanEnabled($appCodeHostParams);
                self::disableTimingDependentFeatures($appCodeHostParams);
                $appCodeHostParams->setProdOption(OptionForProdName::config_file, $yamlConfigFile);
            }
        );

        self::implTestForAppCodeSetsHowFinished(
            testArgs: new MixedMap([]),
            subAppCode: [__CLASS__, 'appCodeEmpty'],
            additionalAssertCode: function (DebugContextScopeRef $dbgCtx, AgentBackendComms $agentBackendComms): void {
                $resources = IterableUtil::toList($agentBackendComms->resources());
                $dbgCtx->add(compact('resources'));
                AssertEx::isPositiveInt(count($resources));

                $resourceAttributesExpectations = new AttributesExpectations(
                    attributes: [
                        ServiceAttributes::SERVICE_NAME                         => self::EXPECTED_SERVICE_NAME,
                        'test.custom.attribute'                                 => self::EXPECTED_CUSTOM_ATTRIBUTE_VALUE,
                        TelemetryIncubatingAttributes::TELEMETRY_DISTRO_NAME    => 'opentelemetry-php-distro',
                    ],
                );

                foreach ($resources as $resource) {
                    $resourceAttributesExpectations->assertMatches($resource->attributes);
                }
            }
        );
    }

    public function testDeclarativeConfigResourceAttributes(): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTest(__CLASS__, __FUNCTION__),
            $this->implTestDeclarativeConfigResourceAttributes(...),
        );
    }
}
