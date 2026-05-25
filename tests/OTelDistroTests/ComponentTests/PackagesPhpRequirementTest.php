<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use Composer\Semver\Semver;
use OpenTelemetry\Distro\VendorDir;
use OTelDistroTests\ComponentTests\Util\AgentBackendComms;
use OTelDistroTests\ComponentTests\Util\AppCodeContextUtil;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\EnvVarUtilForTests;
use OTelDistroTests\ComponentTests\Util\ProcessUtil;
use OTelDistroTests\ComponentTests\Util\ResourcesCleanerHandle;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\DebugContextScopeRef;
use OTelDistroTests\Util\FileUtil;
use OTelDistroTests\Util\JsonUtil;
use OTelDistroTests\Util\MixedMap;
use OTelDistroTests\Util\TimeUtil;
use PhpParser\Error as PhpParserError;
use PhpParser\ErrorHandler\Throwing as ThrowingPhpParserErrorHandler;
use PhpParser\ParserFactory;
use SplFileInfo;
use Throwable;

/**
 * @group does_not_require_external_services
 */
final class PackagesPhpRequirementTest extends ComponentTestCaseBase
{
    private const INSTALLED_DISTRO_VENDOR_DIR_KEY = 'installed_distro_vendor_dir';

    public function testSemverConstraint(): void
    {
        $assertSatisfies = function (string $version, string $constraint): void {
            self::assertTrue(Semver::satisfies($version, $constraint));
        };

        $assertNotSatisfies = function (string $version, string $constraint): void {
            self::assertNotTrue(Semver::satisfies($version, $constraint));
        };

        $assertThrows = function (string $version, string $constraint): void {
            $thrown = null;
            try {
                Semver::satisfies($version, $constraint);
            } catch (Throwable $throwable) {
                $thrown = $throwable;
            }
            self::assertNotNull($thrown);
        };

        $assertSatisfies('8.1', '^8.0');
        $assertSatisfies('8.1', '^8.1');
        $assertNotSatisfies('8.1', '^8.2');
        $assertNotSatisfies('8.1', '^8.3');
        $assertNotSatisfies('8.1', '^9.1');

        $assertSatisfies('8.1', '>=7.0');
        $assertSatisfies('8.1', '>=8.0');
        $assertSatisfies('8.1', '>=8.1');
        $assertNotSatisfies('8.1', '>=8.2');
        $assertNotSatisfies('8.1', '>=9.1');

        $assertSatisfies('8.1', '^5.3 || ^7.0 || ^8.0');
        $assertNotSatisfies('4.1', '^5.3 || ^7.0 || ^8.0');

        $assertSatisfies('8.1.2.3', '^8.1');
        $assertNotSatisfies('8.1.2.3', '^8.2');

        $assertThrows('8.1.2.3-extra', '^8.1');
    }

    private static function getCurrentPhpVersion(): string
    {
        // PHP_VERSION: 5.3.6-13ubuntu3.2
        // PHP_EXTRA_VERSION: -13ubuntu3.2

        if (PHP_EXTRA_VERSION === '') {
            return PHP_VERSION;
        }

        self::assertStringEndsWith(PHP_EXTRA_VERSION, PHP_VERSION);
        return substr(PHP_VERSION, offset: 0, length: strlen(PHP_VERSION) - strlen(PHP_EXTRA_VERSION));
    }

    private static function getPackagePhpVersionConstraints(string $packageDir): ?string
    {
        $packageComposerJsonFilePath = FileUtil::partsToPath($packageDir, 'composer.json');
        if (!file_exists($packageComposerJsonFilePath)) {
            return null;
        }
        $jsonEncoded = FileUtil::getFileContents($packageComposerJsonFilePath);
        $jsonDecoded = AssertEx::isArray(JsonUtil::decode($jsonEncoded));
        $requireMap = AssertEx::isArray(AssertEx::arrayHasKey('require', $jsonDecoded));
        return AssertEx::isString(AssertEx::arrayHasKey('php', $requireMap));
    }

    /**
     * @param callable(string $packageVendor, string $packageName): void $code
     */
    private static function callForEachPackage(string $prodVendorDir, callable $code): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $dbgCtx->pushSubScope();
        foreach (FileUtil::iterateDirectory($prodVendorDir) as $vendorDirChildEntry) {
            if (!$vendorDirChildEntry->isDir()) {
                continue;
            }
            $dbgCtx->resetTopSubScope(compact('vendorDirChildEntry'));

            $dbgCtx->pushSubScope();
            foreach (FileUtil::iterateDirectory($vendorDirChildEntry->getRealPath()) as $vendorDirGrandChildEntry) {
                if (!$vendorDirGrandChildEntry->isDir()) {
                    continue;
                }
                $dbgCtx->resetTopSubScope(compact('vendorDirGrandChildEntry'));

                $code($vendorDirChildEntry->getBasename(), $vendorDirGrandChildEntry->getBasename());
            }
            $dbgCtx->popSubScope();
        }
        $dbgCtx->popSubScope();
    }

    private static function assertOpcacheEnabled(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);
        /** @noinspection PhpComposerExtensionStubsInspection */
        $opcacheStatus = AssertEx::isArray(opcache_get_status());
        $dbgCtx->add(compact('opcacheStatus'));
        $opcacheEnabled = AssertEx::isBool(AssertEx::arrayHasKey('opcache_enabled', $opcacheStatus));
        self::assertTrue($opcacheEnabled);
    }

    private static function verifyPackagesPhpVersion(string $prodVendorDir): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);
        $currentPhpVersion = self::getCurrentPhpVersion();
        $dbgCtx->add(compact('currentPhpVersion'));

        self::callForEachPackage(
            $prodVendorDir,
            function (string $packageVendor, string $packageName) use ($prodVendorDir, $dbgCtx, $currentPhpVersion) {
                $packageDir = FileUtil::partsToPath($prodVendorDir, $packageVendor, $packageName);
                if (($phpVersionConstraints = self::getPackagePhpVersionConstraints($packageDir)) === null) {
                    return;
                }

                $packageFqName = "$packageVendor/$packageName";
                $dbgCtx->add(compact('packageFqName'));
                $dbgCtx->add(compact('phpVersionConstraints'));
                if (!Semver::satisfies($currentPhpVersion, $phpVersionConstraints)) {
                    self::fail(
                        'Encountered a package with PHP constraints that are not satisfied by the current PHP version; '
                        . "package: $packageFqName, PHP constraints: $phpVersionConstraints, current PHP version: $currentPhpVersion"
                    );
                }
            }
        );
    }

    private static function containsHiddenDirInPath(string $filePath): bool
    {
        $pathParts = explode(DIRECTORY_SEPARATOR, $filePath);
        foreach ($pathParts as $pathPart) {
            if (str_starts_with($pathPart, '.')) {
                return true;
            }
        }
        return false;
    }

    private static function validatePhpFilesUseParser(string $installedDistroVendorDir): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $logDebug = self::getLoggerStatic(__NAMESPACE__, __CLASS__, __FILE__)->logDebug(__FUNCTION__);
        $logDebug?->with(__LINE__, 'Entered', compact('installedDistroVendorDir'));

        $parser = (new ParserFactory())->createForHostVersion();
        $throwingErrorHandler = new ThrowingPhpParserErrorHandler();
        $dbgCtx->pushSubScope();
        foreach (FileUtil::iterateOverFilesInDirectoryRecursively($installedDistroVendorDir) as $fileInfo) {
            $filePath = $fileInfo->getRealPath();
            if ($fileInfo->getExtension() !== 'php' || self::containsHiddenDirInPath($filePath)) {
                continue;
            }

            $dbgCtx->resetTopSubScope(compact('filePath'));
            $logDebug?->with(__LINE__, '', compact('filePath'));

            try {
                $tokens = $parser->parse(FileUtil::getFileContents($filePath), $throwingErrorHandler);
                self::assertNotNull($tokens);
            } catch (PhpParserError $parserError) {
                $dbgCtx->add(compact('parserError'));
                self::fail("PHP parser failed on $filePath: {$parserError->getMessage()}");
            }
        }
        $dbgCtx->popSubScope();
    }

    private static function validatePhpFilesUseOpCache(string $installedDistroVendorDir, ResourcesCleanerHandle $resourcesCleanerHandle): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        self::getLoggerStatic(__NAMESPACE__, __CLASS__, __FILE__)->logDebug(__FUNCTION__)?->with(__LINE__, 'Entered', compact('installedDistroVendorDir'));

        $helperScript = __DIR__ . DIRECTORY_SEPARATOR . 'helperToTestPackagesPhpRequirement.php';
        $helperScriptFileInfo = new SplFileInfo($helperScript);
        $procInfo = ProcessUtil::startProcessAndWaitForItToExit(
            dbgProcessName: $helperScriptFileInfo->getBasename($helperScriptFileInfo->getExtension()),
            command: "php \"$helperScript\" \"$installedDistroVendorDir\"",
            envVars: EnvVarUtilForTests::getAll(),
            resourcesCleanerClient: $resourcesCleanerHandle->getClient(),
            isTestScoped: true,
            maxWaitTimeInMicroseconds: intval(TimeUtil::secondsToMicroseconds(60)), // 1 minute
        );
        $dbgCtx->add(compact('procInfo'));
        self::assertSame(0, AssertEx::notNull($procInfo->exitCode));
    }

    /**
     * @return array<string, mixed>
     */
    public static function appCodeForTestPackagesHaveCorrectPhpVersion(): array
    {
        return [self::INSTALLED_DISTRO_VENDOR_DIR_KEY => AppCodeContextUtil::adaptClassNameToScoping(VendorDir::class)::$fullPath];
    }

    private function implTestPackagesHaveCorrectPhpVersion(): void
    {
        self::assertOpcacheEnabled();

        $this->implTestForAppCodeSetsHowFinished(
            testArgs: new MixedMap(),
            subAppCode: [__CLASS__, 'appCodeForTestPackagesHaveCorrectPhpVersion'],
            additionalAssertCode: function (DebugContextScopeRef $dbgCtx, AgentBackendComms $agentBackendComms, MixedMap $appCodeAuxOutput): void {
                $installedDistroVendorDir = $appCodeAuxOutput->getString(self::INSTALLED_DISTRO_VENDOR_DIR_KEY);
                $dbgCtx->add(compact('installedDistroVendorDir'));

                self::verifyPackagesPhpVersion($installedDistroVendorDir);
                self::validatePhpFilesUseParser($installedDistroVendorDir);
                self::validatePhpFilesUseOpCache($installedDistroVendorDir, $this->getTestCaseHandle()->getResourcesCleaner());
            },
        );
    }

    public function testPackagesHaveCorrectPhpVersion(): void
    {
        $this->runAndEscalateLogLevelOnFailure(self::buildDbgDescForTest(__CLASS__, __FUNCTION__), fn() => $this->implTestPackagesHaveCorrectPhpVersion());
    }
}
