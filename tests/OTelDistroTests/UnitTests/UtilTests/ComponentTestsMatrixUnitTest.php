<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests;

use OpenTelemetry\DistroTools\Build\BuildToolsUtil;
use OTelDistroTests\ComponentTests\Util\TestMatrixRow;
use OTelDistroTests\Util\ArrayUtilForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\ClassNameUtil;
use OTelDistroTests\Util\Config\OptionForTestsName;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\OTelDistroProjectProperties;
use OTelDistroTests\Util\FileUtil;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\OsUtil;
use OTelDistroTests\Util\RepoRootDir;
use OTelDistroTests\Util\TestCaseBase;
use OTelDistroTests\Util\TextUtilForTests;

final class ComponentTestsMatrixUnitTest extends TestCaseBase
{
    private const PACKAGE_TYPE_APK = 'apk';

    private const UNPACKED_PHP_VERSION_ENV_VAR_NAME = OptionForTestsName::ENV_VAR_NAME_PREFIX . 'PHP_VERSION';
    private const UNPACKED_PACKAGE_TYPE_ENV_VAR_NAME = OptionForTestsName::ENV_VAR_NAME_PREFIX . 'PACKAGE_TYPE';

    /**
     * @return string[]
     */
    private static function execCommand(string $command): array
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);
        $outputLastLine = exec($command, /* out */ $outputLinesAsArray, /* out */ $exitCode);
        $dbgCtx->add(compact('exitCode', 'outputLinesAsArray', 'outputLastLine'));
        self::assertSame(0, $exitCode);
        self::assertIsString($outputLastLine);
        return $outputLinesAsArray;
    }

    /**
     * @param string $rowSoFar
     *
     * @return iterable<string>
     */
    private static function appendTestAppCodeHostKindAndGroup(string $rowSoFar): iterable
    {
        foreach (OTelDistroProjectProperties::singletonInstance()->testAppCodeHostKindsShortNames as $testAppCodeHostKindShortName) {
            foreach (OTelDistroProjectProperties::singletonInstance()->testGroupsShortNames as $testGroupShortName) {
                yield "$rowSoFar,$testAppCodeHostKindShortName,$testGroupShortName";
            }
        }
    }

    /**
     * @return iterable<string>
     */
    private static function generateRowsToTestIncreasedLogLevel(): iterable
    {
        AssertEx::countAtLeast(2, OTelDistroProjectProperties::singletonInstance()->testAppCodeHostKindsShortNames);
        AssertEx::countAtLeast(2, OTelDistroProjectProperties::singletonInstance()->testGroupsShortNames);

        $phpVersion = OTelDistroProjectProperties::singletonInstance()->getLowestSupportedPhpVersion();
        $packageType = OTelDistroProjectProperties::singletonInstance()->testAllPhpVersionsWithPackageType;
        $testAppCodeHostKindShortName = OTelDistroProjectProperties::singletonInstance()->testAppCodeHostKindsShortNames[0];
        $testGroupShortName = OTelDistroProjectProperties::singletonInstance()->testGroupsShortNames[0];
        yield "{$phpVersion->asDotSeparated()},$packageType,$testAppCodeHostKindShortName,$testGroupShortName,OTEL_PHP_LOG_LEVEL_SYSLOG=TRACE";

        $phpVersion = OTelDistroProjectProperties::singletonInstance()->getHighestSupportedPhpVersion();
        $packageType = self::PACKAGE_TYPE_APK;
        $testAppCodeHostKindShortName = OTelDistroProjectProperties::singletonInstance()->testAppCodeHostKindsShortNames[1];
        $testGroupShortName = OTelDistroProjectProperties::singletonInstance()->testGroupsShortNames[1];
        yield "{$phpVersion->asDotSeparated()},$packageType,$testAppCodeHostKindShortName,$testGroupShortName,OTEL_PHP_LOG_LEVEL_STDERR=DEBUG";
    }

    /**
     * @return iterable<string>
     */
    private static function generateRowsToTestHighestSupportedPhpVersionWithOtherPackageTypes(): iterable
    {
        $packageTypeToExclude = OTelDistroProjectProperties::singletonInstance()->testAllPhpVersionsWithPackageType;
        $phpVersion = OTelDistroProjectProperties::singletonInstance()->getHighestSupportedPhpVersion();

        foreach (OTelDistroProjectProperties::singletonInstance()->supportedPackageTypes as $packageType) {
            if ($packageType === $packageTypeToExclude) {
                continue;
            }
            yield from self::appendTestAppCodeHostKindAndGroup("{$phpVersion->asDotSeparated()},$packageType");
        }
    }

    /**
     * @return iterable<string>
     */
    private static function generateRowsToTestAllPhpVersionsWithOnePackageType(): iterable
    {
        $packageType = OTelDistroProjectProperties::singletonInstance()->testAllPhpVersionsWithPackageType;

        foreach (OTelDistroProjectProperties::singletonInstance()->supportedPhpVersions as $phpVersion) {
            yield from self::appendTestAppCodeHostKindAndGroup("{$phpVersion->asDotSeparated()},$packageType");
        }
    }

    /**
     * @return iterable<string>
     */
    private static function generateExpectedMatrix(): iterable
    {
        yield from self::generateRowsToTestAllPhpVersionsWithOnePackageType();
        yield from self::generateRowsToTestHighestSupportedPhpVersionWithOtherPackageTypes();

        yield from self::generateRowsToTestIncreasedLogLevel();
    }

    /**
     * @return string[]
     */
    private static function generateMatrix(): array
    {
        $generateMatrixScriptFullPath = RepoRootDir::adaptRelativeUnixStylePath('tools/test/component/generate_matrix.sh');
        self::assertFileExists($generateMatrixScriptFullPath);

        return self::execCommand($generateMatrixScriptFullPath);
    }

    public function testGenerateMatrixAsExpected(): void
    {
        if (OsUtil::isWindows()) {
            self::dummyAssert();
            return;
        }

        $expectedMatrixRows = IterableUtil::toList(self::generateExpectedMatrix());
        $actualMatrixRows = self::generateMatrix();
        self::assertSame($expectedMatrixRows, $actualMatrixRows);
    }

    /**
     * @return array<string, mixed>
     */
    private static function unpackRowToEnvVars(string $matrixRowRaw): array
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $result = [];
        ArrayUtilForTests::addAssertingKeyNew(OptionForTestsName::matrix_row->toEnvVarName(), $matrixRowRaw, /* ref */ $result);

        $matrixRowParsed = TestMatrixRow::parse($matrixRowRaw);

        ArrayUtilForTests::addAssertingKeyNew(self::UNPACKED_PHP_VERSION_ENV_VAR_NAME, $matrixRowParsed->phpVersion, /* ref */ $result);
        ArrayUtilForTests::addAssertingKeyNew(self::UNPACKED_PACKAGE_TYPE_ENV_VAR_NAME, $matrixRowParsed->packageType, /* ref */ $result);
        ArrayUtilForTests::addAssertingKeyNew(OptionForTestsName::app_code_host_kind->toEnvVarName(), $matrixRowParsed->appCodeHostKind->name, /* ref */ $result);
        ArrayUtilForTests::addAssertingKeyNew(OptionForTestsName::group->toEnvVarName(), $matrixRowParsed->testGroupName->name, /* ref */ $result);

        return $result;
    }


    private static function execUnpackAndVerify(string $matrixRow): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        /** @var ?string $unpackAndPrintEnvVarsScriptFullPath */
        static $unpackAndPrintEnvVarsScriptFullPath = null;
        if ($unpackAndPrintEnvVarsScriptFullPath === null) {
            $unpackAndPrintEnvVarsScriptFullPath = FileUtil::normalizePath(__DIR__ . DIRECTORY_SEPARATOR . 'unpack_component_tests_matrix_row_and_print_env_vars.sh');
            self::assertFileExists($unpackAndPrintEnvVarsScriptFullPath);
        }

        $expectedEnvVars = self::unpackRowToEnvVars($matrixRow);
        $dbgCtx->add(compact('expectedEnvVars'));

        $actualEnvVars = [];
        $envVarsFromUnpackScriptFile = FileUtil::createTempFile(FileUtil::generateTempFileNamePrefix(ClassNameUtil::fqToShortFromRawString(__CLASS__) . '_env_vars_from_matrix_unpack'));
        try {
            $cmd = BuildToolsUtil::buildShellCommand(BuildToolsUtil::surroundEachInSingleQuotes([$unpackAndPrintEnvVarsScriptFullPath, $matrixRow, $envVarsFromUnpackScriptFile]));
            $cmd .= ' 2>&1';
            $unpackScriptDebugOutput = self::execCommand($cmd);
            $dbgCtx->add(compact('unpackScriptDebugOutput'));
            $actualEnvVarNameValueLines = FileUtil::getFileContents($envVarsFromUnpackScriptFile);
            self::assertNotEmpty($actualEnvVarNameValueLines);
            foreach (TextUtilForTests::iterateLines($actualEnvVarNameValueLines) as $actualEnvVarNameValueLine) {
                if (trim($actualEnvVarNameValueLine) === '') {
                    continue;
                }
                $actualEnvVarNameValue = explode('=', $actualEnvVarNameValueLine, limit: 2);
                self::assertCount(2, $actualEnvVarNameValue);
                /** @var array{string, string} $actualEnvVarNameValue */
                $actualEnvVars[trim($actualEnvVarNameValue[0])] = trim($actualEnvVarNameValue[1]);
            }
            $dbgCtx->add(compact('actualEnvVarNameValueLines', 'actualEnvVars'));
        } finally {
            BuildToolsUtil::deleteTempFile($envVarsFromUnpackScriptFile);
        }

        AssertEx::mapIsSubsetOf($expectedEnvVars, $actualEnvVars);
    }

    public function testGenerateAndUnpackAreInSync(): void
    {
        if (OsUtil::isWindows()) {
            self::dummyAssert();
            return;
        }

        $actualMatrixRows = self::generateMatrix();
        foreach ($actualMatrixRows as $matrixRow) {
            self::execUnpackAndVerify($matrixRow);
        }
    }
}
