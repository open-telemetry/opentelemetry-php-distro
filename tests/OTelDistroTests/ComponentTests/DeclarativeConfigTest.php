<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use OTelDistroTests\ComponentTests\Util\AgentBackendComms;
use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AttributesExpectations;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\ComponentTestsPHPUnitExtension;
use OTelDistroTests\ComponentTests\Util\HttpServerHandle;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\DebugContextScopeRef;
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

    private function buildYamlConfigFile(): string
    {
        $mockCollectorPort = ComponentTestsPHPUnitExtension::getGlobalTestInfra()->getMockOTelCollector()->getPortForAgent();
        /** @noinspection HttpUrlsUsage */
        $endpoint = 'http://' . HttpServerHandle::CLIENT_LOCALHOST_ADDRESS . ':' . $mockCollectorPort;
        $yamlContent = file_get_contents(self::YAML_TEMPLATE_FILE);
        self::assertNotFalse($yamlContent);
        $yamlContent = str_replace('${OTEL_EXPORTER_OTLP_ENDPOINT}', $endpoint, $yamlContent);
        $tmpFile = tempnam(sys_get_temp_dir(), 'otel_decl_cfg_') . '.yaml';
        file_put_contents($tmpFile, $yamlContent);
        return $tmpFile;
    }

    private function implTestDeclarativeConfigResourceAttributes(): void
    {
        $yamlConfigFile = $this->buildYamlConfigFile();

        // Pre-initialize app code host with OTEL_CONFIG_FILE env var
        // ensureMainAppCodeHost is lazy - subsequent calls return the same instance
        $this->getTestCaseHandle()->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams) use ($yamlConfigFile): void {
                self::ensureTransactionSpanEnabled($appCodeHostParams);
                self::disableTimingDependentFeatures($appCodeHostParams);
                $appCodeHostParams->setAdditionalEnvVar('OTEL_CONFIG_FILE', $yamlConfigFile);
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
            function (): void {
                $this->implTestDeclarativeConfigResourceAttributes();
            }
        );
    }
}
