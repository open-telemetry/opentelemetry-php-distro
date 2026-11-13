<?php

declare(strict_types=1);

namespace OTelDistroTests\UnitTests;

use OpenTelemetry\Distro\OTelDistroScoperConfig;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\FileUtil;
use OTelDistroTests\Util\RepoRootDir;
use OTelDistroTests\Util\TestCaseBase;

final class PhpScoperPatchersTest extends TestCaseBase
{
    private const SYMFONY_POLYFILL_PATH = '/tmp/repo/vendor/symfony/polyfill-php82/bootstrap.php';
    private const GETALLHEADERS_PATH = '/tmp/repo/vendor/ralouphie/getallheaders/src/getallheaders.php';

    private const SCOPED_POLYFILL_TEMPLATE = <<<'PHP'
        <?php

        namespace PREFIX;

        use PREFIX\Symfony\Polyfill\Php82 as p;
        GUARD
            function FUNC(): string
            {
                return 'polyfill';
            }
        }

        PHP;

    private static function applyPatchers(string $filePath, string $content): string
    {
        $config = AssertEx::isArray(require RepoRootDir::getFullPath() . '/tools/build/php-scoper.inc.php');
        foreach (AssertEx::isArray($config['patchers']) as $patcher) {
            self::assertIsCallable($patcher);
            $content = AssertEx::isString($patcher($filePath, OTelDistroScoperConfig::PREFIX, $content));
        }
        return $content;
    }

    private static function requireCode(string $code): void
    {
        $file = FileUtil::createTempFile(FileUtil::generateTempFileNamePrefix(__FUNCTION__));
        try {
            file_put_contents($file, $code);
            require $file;
        } finally {
            unlink($file);
        }
    }

    private static function patchScopedPolyfill(string $filePath, string $guard, string $func): string
    {
        $scoped = str_replace(
            ['PREFIX', 'FUNC'],
            [OTelDistroScoperConfig::PREFIX, $func],
            str_replace('GUARD', $guard, self::SCOPED_POLYFILL_TEMPLATE)
        );
        return self::applyPatchers($filePath, $scoped);
    }

    /**
     * php-scoper 0.17 (used for PHP 8.1) and 0.18 write the function_exists() guards differently.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function dataProviderForScopedPolyfills(): iterable
    {
        $guard017Global = <<<'PHP'
            if (!\function_exists('FUNC')) {
            PHP;
        $guard017Prefixed = <<<'PHP'
            if (!\function_exists('PREFIX\\FUNC')) {
            PHP;
        $guard018 = <<<'PHP'
            if (!\function_exists('FUNC') && !\function_exists('PREFIX\FUNC')) {
            PHP;

        yield 'symfony polyfill, php-scoper 0.17, global name' => [self::SYMFONY_POLYFILL_PATH, $guard017Global, 'otel_distro_tests_symfony_017_global'];
        yield 'symfony polyfill, php-scoper 0.17, prefixed name' => [self::SYMFONY_POLYFILL_PATH, $guard017Prefixed, 'otel_distro_tests_symfony_017_prefixed'];
        yield 'symfony polyfill, php-scoper 0.18' => [self::SYMFONY_POLYFILL_PATH, $guard018, 'otel_distro_tests_symfony_018'];
        yield 'getallheaders, php-scoper 0.17' => [self::GETALLHEADERS_PATH, $guard017Global, 'otel_distro_tests_getallheaders_017'];
        yield 'getallheaders, php-scoper 0.18' => [self::GETALLHEADERS_PATH, $guard018, 'otel_distro_tests_getallheaders_018'];
    }

    /**
     * Scoped code calls polyfilled functions by their global name, e.g. \ini_parse_quantity() on PHP 8.1.
     *
     * @dataProvider dataProviderForScopedPolyfills
     */
    public function testPolyfillDefinesGlobalFunction(string $filePath, string $guard, string $func): void
    {
        self::requireCode(self::patchScopedPolyfill($filePath, $guard, $func));

        self::assertTrue(function_exists($func));
        self::assertFalse(function_exists(OTelDistroScoperConfig::PREFIX . '\\' . $func));
    }

    /**
     * @dataProvider dataProviderForScopedPolyfills
     */
    public function testPolyfillKeepsExistingGlobalFunction(string $filePath, string $guard, string $func): void
    {
        $func .= '_existing';
        self::requireCode("<?php function $func(): string { return 'app'; }");

        self::requireCode(self::patchScopedPolyfill($filePath, $guard, $func));

        self::assertIsCallable($func);
        self::assertSame('app', $func());
    }

    public function testOtherFunctionFilesStayScoped(): void
    {
        // Scoped callers use \PREFIX\trigger_deprecation(), so this one must keep the namespace.
        $scoped = str_replace('PREFIX', OTelDistroScoperConfig::PREFIX, <<<'PHP'
            <?php

            namespace PREFIX;

            if (!\function_exists('PREFIX\\trigger_deprecation')) {
                function trigger_deprecation(string $package, string $version, string $message, mixed ...$args): void
                {
                }
            }

            PHP);

        self::assertSame($scoped, self::applyPatchers('/tmp/repo/vendor/symfony/deprecation-contracts/function.php', $scoped));
    }
}
