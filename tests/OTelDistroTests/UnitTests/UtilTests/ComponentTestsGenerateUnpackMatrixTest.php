<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests\UtilTests;

use OpenTelemetry\Distro\Util\ArrayUtil;
use OTelDistroTests\ComponentTests\Util\TestMatrixRowOptionalPart;
use OTelDistroTests\Util\ArrayUtilForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\OptionForTestsName;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\OTelDistroProjectProperties;
use OTelDistroTests\Util\FileUtil;
use OTelDistroTests\Util\IterableUtil;
use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LoggableTrait;
use OTelDistroTests\Util\OsUtil;
use OTelDistroTests\Util\PhpVersionInfo;
use OTelDistroTests\Util\RepoRootDir;
use OTelDistroTests\Util\TestCaseBase;

/**
 * @group smoke
 * @group does_not_require_external_services
 */
final class ComponentTestsGenerateUnpackMatrixTest extends TestCaseBase implements LoggableInterface
{
    use LoggableTrait;

    private const PACKAGE_TYPE_APK = 'apk';

    private const UNPACKED_PHP_VERSION_ENV_VAR_NAME = OptionForTestsName::ENV_VAR_NAME_PREFIX . 'PHP_VERSION';
    private const UNPACKED_PACKAGE_TYPE_ENV_VAR_NAME = OptionForTestsName::ENV_VAR_NAME_PREFIX . 'PACKAGE_TYPE';

    private const APP_CODE_HOST_SHORT_TO_LONG_NAME = [
        'cli' => 'CLI_script',
        'http' => 'Builtin_HTTP_server',
    ];

    private const TESTS_GROUP_SHORT_TO_LONG_NAME = [
        'no_ext_svc' => 'does_not_require_external_services',
        'with_ext_svc' => 'requires_external_services',
    ];

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

    private static function convertAppHostKindShortToLongName(string $shortName): string
    {
        if (ArrayUtil::getValueIfKeyExists($shortName, self::APP_CODE_HOST_SHORT_TO_LONG_NAME, /* out */ $longName)) {
            return $longName;
        }

        self::fail("Unknown test app code host kind short name: $shortName");
    }

    private static function convertTestGroupShortToLongName(string $shortName): string
    {
        if (ArrayUtil::getValueIfKeyExists($shortName, self::TESTS_GROUP_SHORT_TO_LONG_NAME, /* out */ $longName)) {
            return $longName;
        }

        self::fail("Unknown test group short name: $shortName");
    }

    /**
     * @param string $matrixRow
     *
     * @return array<string, mixed>
     */
    private static function unpackRowToEnvVars(string $matrixRow): array
    {
        /*
         * Expected format (see generate_matrix.sh)
         *
         *      php_version,package_type,test_app_host_kind_short_name,test_group[,<optional tail>]
         *      [0]         [1]          [2]                           [3]         [4]
         */

        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $result = [];
        ArrayUtilForTests::addAssertingKeyNew(OptionForTestsName::matrix_row->toEnvVarName(), $matrixRow, /* ref */ $result);

        $matrixRowParts = explode(',', $matrixRow, limit: 5);
        $dbgCtx->add(compact('matrixRowParts'));
        AssertEx::countAtMost(5, $matrixRowParts);


        $phpVersion = PhpVersionInfo::fromMajorDotMinor($matrixRowParts[0]);
        self::assertTrue(OTelDistroProjectProperties::singletonInstance()->isSupportedPhpVersion($phpVersion));
        ArrayUtilForTests::addAssertingKeyNew(self::UNPACKED_PHP_VERSION_ENV_VAR_NAME, $phpVersion->asDotSeparated(), /* ref */ $result);

        $packageType = $matrixRowParts[1];
        self::assertContains($packageType, OTelDistroProjectProperties::singletonInstance()->supportedPackageTypes);
        ArrayUtilForTests::addAssertingKeyNew(self::UNPACKED_PACKAGE_TYPE_ENV_VAR_NAME, $packageType, /* ref */ $result);

        $testAppHostKindShortName = $matrixRowParts[2];
        self::assertContains($testAppHostKindShortName, OTelDistroProjectProperties::singletonInstance()->testAppCodeHostKindsShortNames);
        $testAppHostKind = self::convertAppHostKindShortToLongName($testAppHostKindShortName);
        ArrayUtilForTests::addAssertingKeyNew(OptionForTestsName::app_code_host_kind->toEnvVarName(), $testAppHostKind, /* ref */ $result);

        $testGroupShortName = $matrixRowParts[3];
        self::assertContains($testGroupShortName, OTelDistroProjectProperties::singletonInstance()->testGroupsShortNames);
        $testGroup = self::convertTestGroupShortToLongName($testGroupShortName);
        ArrayUtilForTests::addAssertingKeyNew(OptionForTestsName::group->toEnvVarName(), $testGroup, /* ref */ $result);

        $optionalTailPartIndex = 4;
        if (count($matrixRowParts) === $optionalTailPartIndex) {
            return $result;
        }

        $matrixRowOptionalPart = $matrixRowParts[$optionalTailPartIndex];
        ArrayUtilForTests::addAssertingKeyNew(OptionForTestsName::matrix_row_optional_part->toEnvVarName(), $matrixRowOptionalPart, /* ref */ $result);
        TestMatrixRowOptionalPart::parse($matrixRowOptionalPart);

        return $result;
    }

    private function execUnpackAndVerify(string $matrixRow): void
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

        $cmd = $unpackAndPrintEnvVarsScriptFullPath . ' ' . $matrixRow;
        $actualEnvVarNameValueLines = self::execCommand($cmd);
        self::assertNotEmpty($actualEnvVarNameValueLines);
        $actualEnvVars = [];
        foreach ($actualEnvVarNameValueLines as $actualEnvVarNameValueLine) {
            if (trim($actualEnvVarNameValueLine) === '') {
                continue;
            }
            $actualEnvVarNameValue = explode('=', $actualEnvVarNameValueLine, limit: 2);
            self::assertCount(2, $actualEnvVarNameValue);
            /** @var array{string, string} $actualEnvVarNameValue */
            $actualEnvVars[trim($actualEnvVarNameValue[0])] = trim($actualEnvVarNameValue[1]);
        }
        $dbgCtx->add(compact('actualEnvVarNameValueLines', 'actualEnvVars'));

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
            $this->execUnpackAndVerify($matrixRow);
        }
    }
}
