<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use OpenTelemetry\Distro\Log\LogLevel;
use OpenTelemetry\Distro\ProdPhpDir;
use OpenTelemetry\Distro\Util\BoolUtil;
use OTelDistroTests\ComponentTests\Util\AgentBackendComms;
use OTelDistroTests\ComponentTests\Util\AppCodeContextUtil;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\ArrayUtilForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\Config\OptionsForProdMetadata;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\DebugContextScopeRef;
use OTelDistroTests\Util\MixedMap;
use OTelDistroTests\Util\RepoRootDir;
use ReflectionClass;

/**
 * @group smoke
 * @group does_not_require_external_services
 */
final class ScopedDepsBasicTest extends ComponentTestCaseBase
{
    private const CLASSES_ONLY_IN_DISTRO = [
        'OpenTelemetry\\Contrib\\Instrumentation\\Curl\\CurlInstrumentation',
        'OpenTelemetry\\Contrib\\Instrumentation\\PDO\\PDOInstrumentation',
    ];

    /** @noinspection PhpFullyQualifiedNameUsageInspection */
    private const CLASSES_ONLY_IN_APP = [
        \PHPUnit\Framework\Assert::class,
        \PHPUnit\Framework\TestCase::class,
    ];

    /** @noinspection PhpFullyQualifiedNameUsageInspection */
    private const CLASSES_IN_BOTH = [
        LogLevel::class,
        \OpenTelemetry\SemConv\Attributes\ServiceAttributes::class,
    ];

    private const CLASS_NAME_TO_SOURCE_CODE_FILE_KEY = 'class_name_to_source_code_file';
    private const DISTRO_PROD_PHP_DIR_KEY = 'distro_prod_php_dir';
    private const TESTS_REPO_ROOT_DIR_KEY = 'tests_repo_root_dir';

    /**
     * @return list<string>
     */
    private static function getClassesFromAllSets(): array
    {
        return array_merge(self::CLASSES_ONLY_IN_DISTRO, self::CLASSES_ONLY_IN_APP, self::CLASSES_IN_BOTH);
    }

    public function test0InvariantsForClassesSets(): void
    {
        AssertEx::sameConstValues(count(self::CLASSES_ONLY_IN_DISTRO) + count(self::CLASSES_ONLY_IN_APP) + count(self::CLASSES_IN_BOTH), count(self::getClassesFromAllSets()));
    }

    private static function scopedDepsEnabledInMatrixRowOptionalPart(): ?bool
    {
        if (
            (($prodOptions = AmbientContextForTests::testConfig()->matrixRow()->optionalPart?->prodOptions) === null)
            || (($boolStringVal = $prodOptions->get(OptionForProdName::scoped_deps_enabled, default: null)) === null)
        ) {
            return null;
        }
        return BoolUtil::parse(AssertEx::isString($boolStringVal));
    }

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestWhereClassesAreLoadedFrom(): iterable
    {
        /**
         * @return array<string, mixed>
         */
        $generateDataSet = function (?bool $scopedDepsEnabled): array {
            return [OptionForProdName::scoped_deps_enabled->name => $scopedDepsEnabled];
        };

        /**
         * @return iterable<array<string, mixed>>
         */
        $generateDataSets = function () use ($generateDataSet): iterable {
            // Generate dataset - to not set scoped_deps_enabled in the matrix row optional part
            // and use the default or the value already included
            yield $generateDataSet(null);

            // If the matrix row has optional part that includes scoped_deps_enabled
            if (self::scopedDepsEnabledInMatrixRowOptionalPart() !== null) {
                // Then do not generate anymore datasets
                return;
            }

            yield $generateDataSet(!OptionsForProdMetadata::get()[OptionForProdName::scoped_deps_enabled->name]->defaultValue());
        };

        return self::adaptDataSetsGeneratorToSmokeToDescToMixedMap($generateDataSets);
    }

    /**
     * @return array<string, mixed>
     */
    public static function appCodeForTestWhereClassesAreLoadedFrom(): array
    {
        $classNameToSourceCodeFile = [];
        foreach (self::getClassesFromAllSets() as $unscopedClassName) {
            $scopedClassName = AppCodeContextUtil::buildScopedClassNameFromRawString($unscopedClassName);
            self::assertNotEquals($unscopedClassName, $scopedClassName);
            foreach ([$unscopedClassName, $scopedClassName] as $classToCheck) {
                $srcCodeFile = (class_exists($classToCheck) || interface_exists($classToCheck) || trait_exists($classToCheck)) ? (new ReflectionClass($classToCheck))->getFileName() : null;
                ArrayUtilForTests::addAssertingKeyNew($classToCheck, $srcCodeFile, $classNameToSourceCodeFile);
            }
        }
        return [
            OptionForProdName::scoped_deps_enabled->name => AppCodeContextUtil::isScopedDepsEnabled(),
            self::CLASS_NAME_TO_SOURCE_CODE_FILE_KEY => $classNameToSourceCodeFile,
            self::DISTRO_PROD_PHP_DIR_KEY => AppCodeContextUtil::adaptClassNameToScoping(ProdPhpDir::class)::$fullPath,
            self::TESTS_REPO_ROOT_DIR_KEY => RepoRootDir::getFullPath(),
        ];
    }

    /**
     * @param array<string, ?string> $classNameToSourceCodeFile
     * @param non-empty-list<non-empty-string> $srcAncestorDirs
     */
    private static function assertClassIsLoadedIsFrom(array $classNameToSourceCodeFile, string $className, array $srcAncestorDirs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $srcCodeFile = AssertEx::notNull($classNameToSourceCodeFile[$className]);
        $dbgCtx->add(compact('srcCodeFile'));

        foreach ($srcAncestorDirs as $srcAncestorDir) {
            if (str_starts_with($srcCodeFile, $srcAncestorDir)) {
                return;
            }
        }
        self::fail('srcCodeFile does not start with any of srcAncestorDirs');
    }

    /**
     * @param array<string, ?string> $classNameToSourceCodeFile
     */
    private static function assertClassDoesNotExist(array $classNameToSourceCodeFile, string $className): void
    {
        self::assertNull($classNameToSourceCodeFile[$className]);
    }

    private static function assertWhereClassesAreLoadedFrom(?bool $scopedDepsEnabledInTestArgs, DebugContextScopeRef $dbgCtx, MixedMap $appCodeAuxOutput): void
    {
        if (($scopedDepsEnabledInMatrixRowOptionalPart = self::scopedDepsEnabledInMatrixRowOptionalPart()) !== null) {
            $expectedScopedDepsEnabledInAppContext = $scopedDepsEnabledInMatrixRowOptionalPart;
        } else {
            if ($scopedDepsEnabledInTestArgs !== null) {
                $expectedScopedDepsEnabledInAppContext = $scopedDepsEnabledInTestArgs;
            } else {
                $expectedScopedDepsEnabledInAppContext = OptionsForProdMetadata::get()[OptionForProdName::scoped_deps_enabled->name]->defaultValue();
            }
        }

        $isScopedDepsEnabled = $appCodeAuxOutput->getBool(OptionForProdName::scoped_deps_enabled->name);
        $dbgCtx->add(compact('isScopedDepsEnabled'));
        self::assertSame($expectedScopedDepsEnabledInAppContext, $isScopedDepsEnabled);

        /** @var array<string, ?string> $classNameToSourceCodeFile */
        $classNameToSourceCodeFile = AssertEx::isArray($appCodeAuxOutput->get(self::CLASS_NAME_TO_SOURCE_CODE_FILE_KEY));
        $distroProdPhpDir = AssertEx::notEmptyString($appCodeAuxOutput->getString(self::DISTRO_PROD_PHP_DIR_KEY));
        $testsRepoRootDir = AssertEx::notEmptyString($appCodeAuxOutput->getString(self::TESTS_REPO_ROOT_DIR_KEY));
        self::assertStringStartsNotWith($testsRepoRootDir, $distroProdPhpDir);
        self::assertStringStartsNotWith($distroProdPhpDir, $testsRepoRootDir);
        self::assertSame(RepoRootDir::getFullPath(), $testsRepoRootDir);

        foreach (self::CLASSES_ONLY_IN_DISTRO as $unscopedClassName) {
            $scopedClassName = AppCodeContextUtil::buildScopedClassNameFromRawString($unscopedClassName);
            if ($isScopedDepsEnabled) {
                self::assertClassDoesNotExist($classNameToSourceCodeFile, $unscopedClassName);
                self::assertClassIsLoadedIsFrom($classNameToSourceCodeFile, $scopedClassName, [$distroProdPhpDir]);
            } else {
                self::assertClassIsLoadedIsFrom($classNameToSourceCodeFile, $unscopedClassName, [$distroProdPhpDir]);
                self::assertClassDoesNotExist($classNameToSourceCodeFile, $scopedClassName);
            }
        }

        foreach (self::CLASSES_ONLY_IN_APP as $unscopedClassName) {
            self::assertClassIsLoadedIsFrom($classNameToSourceCodeFile, $unscopedClassName, [$testsRepoRootDir]);
            $scopedClassName = AppCodeContextUtil::buildScopedClassNameFromRawString($unscopedClassName);
            self::assertClassDoesNotExist($classNameToSourceCodeFile, $scopedClassName);
        }

        foreach (self::CLASSES_IN_BOTH as $unscopedClassName) {
            self::assertClassIsLoadedIsFrom($classNameToSourceCodeFile, $unscopedClassName, [$distroProdPhpDir, $testsRepoRootDir]);
            $scopedClassName = AppCodeContextUtil::buildScopedClassNameFromRawString($unscopedClassName);
            if ($isScopedDepsEnabled) {
                self::assertClassIsLoadedIsFrom($classNameToSourceCodeFile, $scopedClassName, [$distroProdPhpDir]);
            } else {
                self::assertClassDoesNotExist($classNameToSourceCodeFile, $scopedClassName);
            }
        }
    }

    private function implTestWhereClassesAreLoadedFrom(MixedMap $testArgs): void
    {
        $scopedDepsEnabledInTestArgs = $testArgs->getNullableBool(OptionForProdName::scoped_deps_enabled->name);
        if (self::scopedDepsEnabledInMatrixRowOptionalPart() !== null) {
            // Then do not generate anymore datasets
            self::assertNull($scopedDepsEnabledInTestArgs);
        }

        $testArgsToPass = $testArgs->cloneAsArray();
        if ($scopedDepsEnabledInTestArgs === null) {
            unset($testArgsToPass[OptionForProdName::scoped_deps_enabled->name]);
        }
        $this->implTestForAppCodeSetsHowFinished(
            testArgs: new MixedMap($testArgsToPass),
            subAppCode: [__CLASS__, 'appCodeForTestWhereClassesAreLoadedFrom'],
            additionalAssertCode: function (DebugContextScopeRef $dbgCtx, AgentBackendComms $agentBackendComms, MixedMap $appCodeAuxOutput) use ($scopedDepsEnabledInTestArgs): void {
                self::assertWhereClassesAreLoadedFrom($scopedDepsEnabledInTestArgs, $dbgCtx, $appCodeAuxOutput);
            },
        );
    }

    /**
     * @dataProvider dataProviderForTestWhereClassesAreLoadedFrom
     */
    public function testWhereClassesAreLoadedFrom(MixedMap $testArgs): void
    {
        self::runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTest(__CLASS__, __FUNCTION__),
            fn() => $this->implTestWhereClassesAreLoadedFrom($testArgs),
        );
    }
}
