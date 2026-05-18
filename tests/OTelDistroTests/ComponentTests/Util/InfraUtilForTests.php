<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OpenTelemetry\Distro\Util\BoolUtil;
use OpenTelemetry\Distro\Util\StaticClassTrait;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\ArrayUtilForTests;
use OTelDistroTests\Util\Config\IniRawSnapshotSource;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\Config\OptionForTestsName;
use OTelDistroTests\Util\EnvVarUtil;

/**
 * @phpstan-import-type EnvVars from EnvVarUtil
 */
final class InfraUtilForTests
{
    use StaticClassTrait;

    public static function generateSpawnedProcessInternalId(): string
    {
        return IdGenerator::generateId(idLengthInBytes: 16);
    }

    /**
     * @param int[] $targetServerPorts
     */
    public static function buildTestInfraDataPerProcess(string $targetSpawnedProcessInternalId, array $targetServerPorts, ?ResourcesCleanerHandle $resourcesCleaner): TestInfraDataPerProcess
    {
        return new TestInfraDataPerProcess(
            rootProcessId: ProcessUtil::getCurrentPid(),
            resourcesCleanerSpawnedProcessInternalId: $resourcesCleaner?->spawnedProcessInternalId,
            resourcesCleanerPort: $resourcesCleaner?->getMainPort(),
            thisSpawnedProcessInternalId: $targetSpawnedProcessInternalId,
            thisServerPorts: $targetServerPorts,
        );
    }

    /**
     * @phpstan-param EnvVars $baseEnvVars
     * @phpstan-param int[]   $targetServerPorts
     *
     * @return EnvVars
     */
    public static function addTestInfraDataPerProcessToEnvVars(
        array $baseEnvVars,
        string $targetSpawnedProcessInternalId,
        array $targetServerPorts,
        ?ResourcesCleanerHandle $resourcesCleaner,
        string $dbgProcessName
    ): array {
        $dataPerProcessEnvVarName = OptionForTestsName::data_per_process->toEnvVarName();
        $dataPerProcess = self::buildTestInfraDataPerProcess($targetSpawnedProcessInternalId, $targetServerPorts, $resourcesCleaner);
        $result = $baseEnvVars;
        $additionalEnvVars = [
            SpawnedProcessBase::DBG_PROCESS_NAME_ENV_VAR_NAME => $dbgProcessName,
            $dataPerProcessEnvVarName                         => PhpSerializationUtil::serializeToString($dataPerProcess),
        ];
        ArrayUtilForTests::append(from: $additionalEnvVars, to: $result);
        ksort(/* ref */ $result);
        return $result;
    }

    public static function buildAppCodePhpCmd(): string
    {
        $result = AmbientContextForTests::testConfig()->appCodePhpExe ?? 'php';

        if (($extBinary = AmbientContextForTests::testConfig()->appCodeExtBinary) !== null) {
            $result .= ' -d "extension=' . $extBinary . '"';
        }

        if (($bootstrapPhpPartFile = AmbientContextForTests::testConfig()->appCodeBootstrapPhpPartFile) !== null) {
            $bootstrapPhpPartFileIniOptName = IniRawSnapshotSource::DEFAULT_PREFIX . OptionForProdName::bootstrap_php_part_file->name;
            $result .= ' -d "' . $bootstrapPhpPartFileIniOptName . '=' . $bootstrapPhpPartFile . '"';
        }

        return $result;
    }

    /**
     * @param int[] $ports
     *
     * @return EnvVars
     */
    public static function buildEnvVarsForSpawnedProcessWithoutAppCode(string $dbgProcessName, string $spawnedProcessInternalId, array $ports, ?ResourcesCleanerHandle $resourcesCleaner): array
    {
        $baseEnvVars = EnvVarUtilForTests::getAll();
        $additionalEnvVars = [
            OptionForProdName::autoload_enabled->toEnvVarName()          => BoolUtil::toString(false),
            OptionForProdName::disabled_instrumentations->toEnvVarName() => ConfigUtilForTests::PROD_DISABLED_INSTRUMENTATIONS_ALL,
            OptionForProdName::enabled->toEnvVarName()                   => BoolUtil::toString(false),
        ];
        ArrayUtilForTests::append(from: $additionalEnvVars, to: $baseEnvVars);

        return InfraUtilForTests::addTestInfraDataPerProcessToEnvVars(
            $baseEnvVars,
            $spawnedProcessInternalId,
            $ports,
            $resourcesCleaner,
            $dbgProcessName
        );
    }
}
