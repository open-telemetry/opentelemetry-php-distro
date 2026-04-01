<?php

declare(strict_types=1);

namespace OTelDistroTests\Util;

use OpenTelemetry\Distro\Util\SingletonInstanceTrait;
use PHPUnit\Framework\Assert;

/**
 * Code in this file is part of implementation internals and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class OTelDistroProjectProperties
{
    use SingletonInstanceTrait;

    /** @var PhpVersionInfo[] */
    public readonly array $supportedPhpVersions;

    /** @var string[] */
    public readonly array $supportedPackageTypes;

    /** @var string */
    public readonly string $testAllPhpVersionsWithPackageType;

    /** @var string[] */
    public readonly array $testAppCodeHostKindsShortNames;

    /** @var string[] */
    public readonly array $testGroupsShortNames;

    private function __construct()
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $fileFullPath = RepoRootDir::adaptRelativeUnixStylePath('project.properties');
        Assert::assertFileExists($fileFullPath);
        Assert::assertNotFalse($fileContents = file_get_contents($fileFullPath));

        /** @var ?array<PhpVersionInfo> $supportedPhpVersions */
        $supportedPhpVersions = null;
        /** @var ?array<string> $supportedPackageTypes */
        $supportedPackageTypes = null;
        /** @var ?string $testAllPhpVersionsWithPackageType */
        $testAllPhpVersionsWithPackageType = null;
        /** @var ?array<string> $testAppCodeHostKindsShortNames */
        $testAppCodeHostKindsShortNames = null;
        /** @var ?array<string> $testGroupsShortNames */
        $testGroupsShortNames = null;

        foreach (TextUtilForTests::iterateLines($fileContents, keepEndOfLine: false) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $keyValue = explode(separator: '=', string: $line, limit: 2);
            if (count($keyValue) == 1) {
                continue;
            }

            $dbgCtx->add(compact('keyValue'));
            $dbgCtx->add(compact('line'));
            Assert::assertCount(2, $keyValue);
            $propName = trim($keyValue[0]);
            $propValue = trim($keyValue[1]);
            $dbgCtx->add(compact('propName', 'propValue'));
            switch ($propName) {
                case 'supported_php_versions':
                    $supportedPhpVersions = self::parseSupportedPhpVersions($propValue);
                    break;
                case 'supported_package_types':
                    $supportedPackageTypes = self::parseArray($propValue);
                    break;
                case 'test_app_code_host_kinds_short_names':
                    $testAppCodeHostKindsShortNames = self::parseArray($propValue);
                    break;
                case 'test_all_php_versions_with_package_type':
                    $testAllPhpVersionsWithPackageType = AssertEx::notEmptyString($propValue);
                    break;
                case 'test_groups_short_names':
                    $testGroupsShortNames = self::parseArray($propValue);
                    break;
            }
        }

        $this->supportedPhpVersions = AssertEx::notNull($supportedPhpVersions);
        $this->supportedPackageTypes = AssertEx::notNull($supportedPackageTypes);
        $this->testAllPhpVersionsWithPackageType = AssertEx::notNull($testAllPhpVersionsWithPackageType);
        $this->testAppCodeHostKindsShortNames = AssertEx::notNull($testAppCodeHostKindsShortNames);
        $this->testGroupsShortNames = AssertEx::notNull($testGroupsShortNames);
    }

    /**
     * @return string[]
     */
    private static function parseArray(string $propValue): array
    {
        // Example $propValue: (apk deb rpm tar)

        Assert::assertGreaterThanOrEqual(2, strlen($propValue));
        Assert::assertSame('(', substr($propValue, offset: 0, length: 1));
        Assert::assertSame(')', substr($propValue, offset: strlen($propValue) - 1, length: 1));
        $parts = preg_split('/\s+/', substr($propValue, offset: 1, length: strlen($propValue) - 2));
        Assert::assertNotFalse($parts);
        return array_map(trim(...), $parts);
    }

    /**
     * @return PhpVersionInfo[]
     */
    private static function parseSupportedPhpVersions(string $propValue): array
    {
        // Example $propValue: (81 82 83 84)

        return array_map(PhpVersionInfo::fromMajorMinorNoDotString(...), self::parseArray($propValue));
    }

    /**
     * @return array<string, string>
     */
    public static function loadAsMap(): array
    {
        $fileFullPath = RepoRootDir::adaptRelativeUnixStylePath('project.properties');
        $fileContents = file_get_contents($fileFullPath);
        if ($fileContents === false) {
            return [];
        }

        $result = [];
        foreach (explode("\n", $fileContents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $keyValue = explode('=', $line, 2);
            if (count($keyValue) !== 2) {
                continue;
            }
            $result[trim($keyValue[0])] = trim($keyValue[1]);
        }
        return $result;
    }

    public function getLowestSupportedPhpVersion(): PhpVersionInfo
    {
        /** @var ?PhpVersionInfo $result */
        $result = null;
        foreach ($this->supportedPhpVersions as $current) {
            if ($result === null || $current->isLessThan($result)) {
                $result = $current;
            }
        }
        return AssertEx::notNull($result);
    }

    public function getHighestSupportedPhpVersion(): PhpVersionInfo
    {
        /** @var ?PhpVersionInfo $result */
        $result = null;
        foreach ($this->supportedPhpVersions as $current) {
            if ($result === null || $current->isGreaterThan($result)) {
                $result = $current;
            }
        }
        return AssertEx::notNull($result);
    }

    public function isSupportedPhpVersion(PhpVersionInfo $phpVersion): bool
    {
        foreach ($this->supportedPhpVersions as $current) {
            if ($current->isEqual($phpVersion)) {
                return true;
            }
        }
        return false;
    }
}
