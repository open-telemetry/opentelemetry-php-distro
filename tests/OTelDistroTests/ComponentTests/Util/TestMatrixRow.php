<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util;

use OpenTelemetry\Distro\Util\ArrayUtil;
use OTelDistroTests\Util\ArrayUtilForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\OptionForTestsName;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LoggableTrait;
use OTelDistroTests\Util\OTelDistroProjectProperties;
use OTelDistroTests\Util\PhpVersionInfo;
use PHPUnit\Framework\Assert;

final class TestMatrixRow implements LoggableInterface
{
    use LoggableTrait;

    public const ROW_ELEMENTS_SEPARATOR = ',';

    private const APP_CODE_HOST_SHORT_TO_LONG_NAME = [
        'cli' => 'CLI_script',
        'http' => 'Builtin_HTTP_server',
    ];

    private const TESTS_GROUP_SHORT_TO_LONG_NAME = [
        'no_ext_svc' => 'does_not_require_external_services',
        'with_ext_svc' => 'requires_external_services',
    ];

    private function __construct(
        public readonly string $phpVersion,
        public readonly string $packageType,
        private readonly string $appCodeHostKindShortName,
        public readonly AppCodeHostKind $appCodeHostKind,
        private readonly string $testGroupShortName,
        public readonly TestGroupName $testGroupName,
        public readonly ?TestMatrixRowOptionalPart $optionalPart,
    ) {
    }

    public function mandatoryPartRaw(): string
    {
        /**
         * php_version,package_type,test_app_host_kind_short_name,test_group[,<optional tail>]
         * [0]         [1]          [2]                           [3]         [4]
         */
        return implode(self::ROW_ELEMENTS_SEPARATOR, [$this->phpVersion, $this->packageType, $this->appCodeHostKindShortName, $this->testGroupShortName]);
    }

    public static function parse(string $rowToParse): self
    {
        /**
         * @see tools/test/component/generate_matrix.sh
         *
         * Expected format
         *
         *      php_version,package_type,test_app_host_kind_short_name,test_group[,<optional tail>]
         *      [0]         [1]          [2]                           [3]         [4]
         */

        /** @phpstan-var int $optionalPartElementIndex */
        static $optionalPartElementIndex = 4;

        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $elements = explode(self::ROW_ELEMENTS_SEPARATOR, $rowToParse, limit: $optionalPartElementIndex + 1);
        $dbgCtx->add(compact('elements'));
        AssertEx::countAtMost($optionalPartElementIndex + 1, $elements);

        $result = [];
        ArrayUtilForTests::addAssertingKeyNew(OptionForTestsName::matrix_row->toEnvVarName(), $rowToParse, /* ref */ $result);

        $positionalElementIndex = 0;
        $dbgCtx->add(['positionalElementIndex' => &$positionalElementIndex]); // Track $positionalElementIndex by reference because it is changing through the flow of the function

        $phpVersion = $elements[$positionalElementIndex];
        Assert::assertTrue(OTelDistroProjectProperties::singletonInstance()->isSupportedPhpVersion(PhpVersionInfo::fromMajorDotMinor($phpVersion)));

        ++$positionalElementIndex;
        $packageType = $elements[$positionalElementIndex];
        Assert::assertContains($packageType, OTelDistroProjectProperties::singletonInstance()->supportedPackageTypes);

        ++$positionalElementIndex;
        $appCodeHostKindShortName = $elements[$positionalElementIndex];
        Assert::assertContains($appCodeHostKindShortName, OTelDistroProjectProperties::singletonInstance()->testAppCodeHostKindsShortNames);
        $appCodeHostKind = AssertEx::notNull(AppCodeHostKind::tryToFindByName(self::convertAppHostKindShortToLongName($appCodeHostKindShortName)));

        ++$positionalElementIndex;
        $testGroupShortName = $elements[$positionalElementIndex];
        Assert::assertContains($testGroupShortName, OTelDistroProjectProperties::singletonInstance()->testGroupsShortNames);
        $testGroupName = AssertEx::notNull(TestGroupName::tryToFindByName(self::convertTestGroupShortToLongName($testGroupShortName)));

        if (count($elements) === $positionalElementIndex + 1) {
            $optionalPart = null;
        } else {
            ++$positionalElementIndex;
            Assert::assertSame($optionalPartElementIndex, $positionalElementIndex);
            Assert::assertCount($optionalPartElementIndex + 1, $elements);
            $optionalPart = TestMatrixRowOptionalPart::parse($elements[$optionalPartElementIndex]);
        }

        return new self(
            phpVersion: $phpVersion,
            packageType: $packageType,
            appCodeHostKindShortName: $appCodeHostKindShortName,
            appCodeHostKind: $appCodeHostKind,
            testGroupShortName: $testGroupShortName,
            testGroupName: $testGroupName,
            optionalPart: $optionalPart,
        );
    }

    private static function convertAppHostKindShortToLongName(string $shortName): string
    {
        if (ArrayUtil::getValueIfKeyExists($shortName, self::APP_CODE_HOST_SHORT_TO_LONG_NAME, /* out */ $longName)) {
            return $longName;
        }

        Assert::fail("Unknown test app code host kind short name: $shortName");
    }

    private static function convertTestGroupShortToLongName(string $shortName): string
    {
        if (ArrayUtil::getValueIfKeyExists($shortName, self::TESTS_GROUP_SHORT_TO_LONG_NAME, /* out */ $longName)) {
            return $longName;
        }

        Assert::fail("Unknown test group short name: $shortName");
    }
}
