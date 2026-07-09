<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandlerImpl;
use Illuminate\Foundation\Http\Kernel as HttpKernelImpl;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use OTelDistroTests\ComponentTests\Util\AppCodeContextUtil;
use OTelDistroTests\ComponentTests\Util\AppCodeHostParams;
use OTelDistroTests\ComponentTests\Util\AppCodeRequestParams;
use OTelDistroTests\ComponentTests\Util\AppCodeTarget;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\OtlpData\SpanKind;
use OTelDistroTests\ComponentTests\Util\SpanExpectationsBuilder;
use OTelDistroTests\ComponentTests\Util\WaitForOTelSignalCounts;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\DataProviderForTestBuilder;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\MixedMap;

/**
 * @group smoke
 * @group does_not_require_external_services
 */
final class LaravelAutoInstrumentationTest extends ComponentTestCaseBase
{
    private const AUTO_INSTRUMENTATION_NAME = 'laravel';
    private const LARAVEL_INSTRUMENTATION_SCOPE_NAME = 'io.opentelemetry.contrib.php.laravel';
    private const IS_AUTO_INSTRUMENTATION_ENABLED_KEY = 'is_auto_instrumentation_enabled';

    private const ROUTE_URI = '/hello/{name}';
    private const RESPONSE_BODY = 'Hello, world!';

    private static function writeMinimalConfigFiles(string $basePath): void
    {
        $configDir = $basePath . '/config';
        self::assertTrue(is_dir($configDir) || mkdir($configDir, recursive: true));

        file_put_contents($configDir . '/app.php', '<?php return ' . var_export(
            [
                'name' => 'LaravelAutoInstrumentationTest',
                'env' => 'testing',
                'debug' => true,
                'url' => 'http://localhost',
                'timezone' => 'UTC',
                'locale' => 'en',
                'key' => 'base64:' . base64_encode(str_repeat('a', 32)),
                'cipher' => 'AES-256-CBC',
                'providers' => [
                    \Illuminate\Filesystem\FilesystemServiceProvider::class,
                    \Illuminate\View\ViewServiceProvider::class,
                ],
                'aliases' => [],
            ],
            return: true
        ) . ';');

        file_put_contents($configDir . '/logging.php', '<?php return ' . var_export(
            [
                'default' => 'null',
                'channels' => [
                    'null' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class],
                ],
            ],
            return: true
        ) . ';');

        file_put_contents($configDir . '/view.php', '<?php return ' . var_export(
            ['paths' => [], 'compiled' => $basePath . '/storage/framework/views'],
            return: true
        ) . ';');
    }

    private static function buildMinimalApp(string $basePath): Application
    {
        foreach (
            [
                $basePath . '/bootstrap/cache',
                $basePath . '/storage/framework/cache/data',
                $basePath . '/storage/framework/sessions',
                $basePath . '/storage/framework/testing',
                $basePath . '/storage/framework/views',
                $basePath . '/storage/logs',
            ] as $dir
        ) {
            self::assertTrue(is_dir($dir) || mkdir($dir, recursive: true));
        }
        self::writeMinimalConfigFiles($basePath);

        $app = new Application($basePath);

        // Anonymous subclasses: empty middleware stacks, since this smoke test
        // doesn't exercise sessions/CSRF/cookies.
        $app->singleton(HttpKernel::class, static function (Application $app) {
            /** @var Router $router */
            $router = $app->make('router');

            return new class ($app, $router) extends HttpKernelImpl {
                protected $middleware = [];
                protected $middlewareGroups = ['web' => [], 'api' => []];
                protected $middlewareAliases = [];
                protected $routeMiddleware = [];
            };
        });
        $app->singleton(ConsoleKernel::class, static function (Application $app) {
            /** @var Dispatcher $events */
            $events = $app->make('events');

            return new class ($app, $events) extends \Illuminate\Foundation\Console\Kernel {
                protected function commands(): void
                {
                }
            };
        });
        $app->singleton(ExceptionHandler::class, ExceptionHandlerImpl::class);

        /** @var Router $router */
        $router = $app->make('router');
        $router->get(self::ROUTE_URI, function (string $name) {
            return "Hello, {$name}!";
        });

        return $app;
    }

    private static function removeDirRecursively(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        self::assertNotFalse($items);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? self::removeDirRecursively($path) : unlink($path);
        }
        rmdir($dir);
    }

    public static function appCodeForTestAutoInstrumentation(MixedMap $appCodeRequestArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $isAutoInstrumentationEnabled = $appCodeRequestArgs->getBool(self::IS_AUTO_INSTRUMENTATION_ENABLED_KEY);
        if ($isAutoInstrumentationEnabled) {
            $laravelInstrumentationFqClassName = AppCodeContextUtil::adaptClassNameRawStringToScoping('OpenTelemetry\\Contrib\\Instrumentation\\Laravel\\LaravelInstrumentation');
            $dbgCtx->add(compact('laravelInstrumentationFqClassName'));
            self::assertTrue(class_exists($laravelInstrumentationFqClassName, autoload: false));
            AssertEx::sameConstValues(constant($laravelInstrumentationFqClassName . '::NAME'), self::AUTO_INSTRUMENTATION_NAME);
        }

        $basePath = sys_get_temp_dir() . '/laravel_smoke_test_' . bin2hex(random_bytes(8));
        try {
            $app = self::buildMinimalApp($basePath);

            /** @var HttpKernel $kernel */
            $kernel = $app->make(HttpKernel::class);
            $request = Request::create('/hello/world', 'GET');
            /** @var \Symfony\Component\HttpFoundation\Response $response */
            $response = $kernel->handle($request);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame(self::RESPONSE_BODY, $response->getContent());

            $kernel->terminate($request, $response);
        } finally {
            self::removeDirRecursively($basePath);
        }
    }

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestAutoInstrumentation(): iterable
    {
        return self::adaptDataProviderForTestBuilderToSmokeToDescToMixedMap(
            (new DataProviderForTestBuilder())
                ->addBoolKeyedDimensionAllValuesCombinable(self::IS_AUTO_INSTRUMENTATION_ENABLED_KEY)
        );
    }

    private function implTestAutoInstrumentation(MixedMap $testArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        $isAutoInstrumentationEnabled = $testArgs->getBool(self::IS_AUTO_INSTRUMENTATION_ENABLED_KEY);

        $testCaseHandle = $this->getTestCaseHandle();

        $appCodeHost = $testCaseHandle->ensureMainAppCodeHost(
            function (AppCodeHostParams $appCodeHostParams) use ($isAutoInstrumentationEnabled): void {
                if (!$isAutoInstrumentationEnabled) {
                    $appCodeHostParams->setProdOptionIfNotNull(OptionForProdName::disabled_instrumentations, self::AUTO_INSTRUMENTATION_NAME);
                }
                self::disableTimingDependentFeatures($appCodeHostParams);
            }
        );
        $appCodeHost->execAppCode(
            AppCodeTarget::asRouted([__CLASS__, 'appCodeForTestAutoInstrumentation']),
            function (AppCodeRequestParams $appCodeRequestParams) use ($testArgs): void {
                $appCodeRequestParams->setAppCodeRequestArgs($testArgs->cloneAsArray());
            }
        );

        if ($isAutoInstrumentationEnabled) {
            // +1 automatic local root span, +1 Kernel::handle span
            $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spans(2));
            $dbgCtx->add(compact('agentBackendComms'));

            $rootSpan = $agentBackendComms->singleRootSpan();
            $laravelServerSpan = $agentBackendComms->singleChildSpan($rootSpan->id);

            $expectationsForLaravelServerSpan = (new SpanExpectationsBuilder())
                ->name('GET ' . self::ROUTE_URI)
                ->kind(SpanKind::server)
                ->instrumentationScopeName(self::LARAVEL_INSTRUMENTATION_SCOPE_NAME)
                ->build();
            $expectationsForLaravelServerSpan->assertMatches($laravelServerSpan);
        } else {
            // +1 automatic local root span only
            $agentBackendComms = $testCaseHandle->waitForEnoughAgentBackendComms(WaitForOTelSignalCounts::spans(1));
            $dbgCtx->add(compact('agentBackendComms'));

            self::assertEmpty(iterator_to_array($agentBackendComms->findSpansByInstrumentationScope(self::LARAVEL_INSTRUMENTATION_SCOPE_NAME)));
        }
    }

    /**
     * @dataProvider dataProviderForTestAutoInstrumentation
     */
    public function testAutoInstrumentation(MixedMap $testArgs): void
    {
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTestWithArgs(__CLASS__, __FUNCTION__, $testArgs),
            function () use ($testArgs): void {
                $this->implTestAutoInstrumentation($testArgs);
            }
        );
    }
}
