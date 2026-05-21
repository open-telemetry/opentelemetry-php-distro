<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests;

use OTelDistroTests\ComponentTests\Util\AgentBackendComms;
use OTelDistroTests\ComponentTests\Util\AppCodeContextUtil;
use OTelDistroTests\ComponentTests\Util\ComponentTestCaseBase;
use OTelDistroTests\ComponentTests\Util\DbAutoInstrumentationUtilForTests;
use OTelDistroTests\ComponentTests\Util\PgSql\PgSqlDbSpanDataExpectationsBuilder;
use OTelDistroTests\ComponentTests\Util\SpanExpectations;
use OTelDistroTests\ComponentTests\Util\SpanSequenceExpectations;
use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\AssertEx;
use OTelDistroTests\Util\Config\OptionForProdName;
use OTelDistroTests\Util\DataProviderForTestBuilder;
use OTelDistroTests\Util\DebugContext;
use OTelDistroTests\Util\DebugContextScopeRef;
use OTelDistroTests\Util\Log\LoggableToString;
use OTelDistroTests\Util\MixedMap;
use OpenTelemetry\SemConv\Attributes\DbAttributes;

/**
 * @group smoke
 * @group requires_external_services
 */
final class PgSqlAutoInstrumentationTest extends ComponentTestCaseBase
{
    private const AUTO_INSTRUMENTATION_NAME = 'postgresql';
    private const IS_AUTO_INSTRUMENTATION_ENABLED_KEY = 'is_auto_instrumentation_enabled';

    private const MESSAGES
        = [
            'Just testing...'    => 1,
            'More testing...'    => 22,
            'SQLite3 is cool...' => 333,
        ];

    private const CREATE_TABLE_SQL
        = /** @lang text */
        'CREATE TABLE IF NOT EXISTS messages (
            id SERIAL PRIMARY KEY,
            text TEXT,
            time INTEGER
        )';

    private const DROP_TABLE_SQL
        = /** @lang text */
        'DROP TABLE IF EXISTS messages';

    private const INSERT_SQL
        = /** @lang text */
        'INSERT INTO messages (text, time) VALUES ($1, $2)';

    private const SELECT_SQL
        = /** @lang text */
        'SELECT * FROM messages';

    private static bool $verifiedPrerequisites = false;

    private static function assertExtensionLoaded(): void
    {
        $pgsqlExtensionName = 'pgsql';
        self::assertTrue(extension_loaded($pgsqlExtensionName), 'Extension ' . $pgsqlExtensionName . ' is not loaded');
    }

    private static function buildConnectionString(string $host, int $port, string $user, string $password, ?string $dbName): string
    {
        $connStr = "host=$host port=$port user=$user password=$password";
        if ($dbName !== null) {
            $connStr .= " dbname=$dbName";
        }
        return $connStr;
    }

    private static function assertPrerequisites(): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        self::assertExtensionLoaded();

        $config = AmbientContextForTests::testConfig();
        self::assertNotNull($config->postgresqlHost);
        self::assertNotNull($config->postgresqlPort);
        self::assertNotNull($config->postgresqlUser);
        self::assertNotNull($config->postgresqlPassword);
        self::assertNotNull($config->postgresqlDb);

        $connStr = self::buildConnectionString(
            AssertEx::notNull($config->postgresqlHost),
            AssertEx::notNull($config->postgresqlPort),
            AssertEx::notNull($config->postgresqlUser),
            AssertEx::notNull($config->postgresqlPassword),
            AssertEx::notNull($config->postgresqlDb)
        );
        $conn = pg_connect($connStr);
        self::assertNotFalse($conn, 'Failed to connect to PostgreSQL');
        pg_close($conn);
    }

    /**
     * @return iterable<string, array{MixedMap}>
     */
    public static function dataProviderForTestAutoInstrumentation(): iterable
    {
        if (!AmbientContextForTests::testConfig()->doesRequireExternalServices()) {
            return ['dummy test args' => [new MixedMap()]];
        }

        return self::adaptDataProviderForTestBuilderToSmokeToDescToMixedMap(
            (new DataProviderForTestBuilder())
                ->addBoolKeyedDimensionAllValuesCombinable(self::IS_AUTO_INSTRUMENTATION_ENABLED_KEY)
                ->addGeneratorOnlyFirstValueCombinable(DbAutoInstrumentationUtilForTests::wrapTxRelatedArgsDataProviderGenerator())
        );
    }

    public static function appCodeForTestAutoInstrumentation(MixedMap $appCodeRequestArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        self::assertExtensionLoaded();

        $isAutoInstrumentationEnabled = $appCodeRequestArgs->getBool(self::IS_AUTO_INSTRUMENTATION_ENABLED_KEY);
        if ($isAutoInstrumentationEnabled) {
            $pgSqlInstrumentationFqClassName = AppCodeContextUtil::adaptClassNameRawStringToScoping('OpenTelemetry\\Contrib\\Instrumentation\\PostgreSql\\PostgreSqlInstrumentation');
            self::assertTrue(class_exists($pgSqlInstrumentationFqClassName, autoload: false));
            AssertEx::sameConstValues(constant($pgSqlInstrumentationFqClassName . '::NAME'), self::AUTO_INSTRUMENTATION_NAME);
        }

        $wrapInTx = $appCodeRequestArgs->getBool(DbAutoInstrumentationUtilForTests::WRAP_IN_TX_KEY);
        $rollback = $appCodeRequestArgs->getBool(DbAutoInstrumentationUtilForTests::SHOULD_ROLLBACK_KEY);

        $host = $appCodeRequestArgs->getString(DbAutoInstrumentationUtilForTests::HOST_KEY);
        $port = $appCodeRequestArgs->getInt(DbAutoInstrumentationUtilForTests::PORT_KEY);
        $user = $appCodeRequestArgs->getString(DbAutoInstrumentationUtilForTests::USER_KEY);
        $password = $appCodeRequestArgs->getString(DbAutoInstrumentationUtilForTests::PASSWORD_KEY);
        $dbName = $appCodeRequestArgs->getString(DbAutoInstrumentationUtilForTests::DB_NAME_KEY);

        $connStr = self::buildConnectionString($host, $port, $user, $password, $dbName);
        $conn = pg_connect($connStr);
        self::assertNotFalse($conn, 'Failed to connect to PostgreSQL');

        // Drop table first to reset state, then create
        $result = pg_query($conn, self::DROP_TABLE_SQL);
        self::assertNotFalse($result);
        $result = pg_query($conn, self::CREATE_TABLE_SQL);
        self::assertNotFalse($result);

        if ($wrapInTx) {
            $result = pg_query($conn, 'BEGIN');
            self::assertNotFalse($result);
        }

        $stmtResult = pg_prepare($conn, 'insert_msg', self::INSERT_SQL);
        self::assertNotFalse($stmtResult);
        foreach (self::MESSAGES as $msgText => $msgTime) {
            $execResult = pg_execute($conn, 'insert_msg', [$msgText, $msgTime]);
            self::assertNotFalse($execResult);
        }

        $queryResult = pg_query($conn, self::SELECT_SQL);
        self::assertNotFalse($queryResult);
        $rowCount = pg_num_rows($queryResult);
        self::assertSame(count(self::MESSAGES), $rowCount);
        while (($row = pg_fetch_assoc($queryResult)) !== false) {
            $dbgCtx = LoggableToString::convert(compact('row'));
            $msgText = $row['text'];
            self::assertIsString($msgText);
            self::assertArrayHasKey($msgText, self::MESSAGES, $dbgCtx);
            self::assertEquals(self::MESSAGES[$msgText], (int)$row['time'], $dbgCtx);
        }

        if ($wrapInTx) {
            $result = pg_query($conn, $rollback ? 'ROLLBACK' : 'COMMIT');
            self::assertNotFalse($result);
        }

        // Cleanup
        $result = pg_query($conn, self::DROP_TABLE_SQL);
        self::assertNotFalse($result);

        pg_close($conn);
    }

    private function implTestAutoInstrumentation(MixedMap $testArgs): void
    {
        DebugContext::getCurrentScope(/* out */ $dbgCtx);

        self::assertNotEmpty(self::MESSAGES); // @phpstan-ignore staticMethod.alreadyNarrowedType

        $logger = self::getLoggerStatic(__NAMESPACE__, __CLASS__, __FILE__);
        $logger->logTrace(__FUNCTION__)?->with(__LINE__, 'Entered', compact('testArgs'));

        $wrapInTx = $testArgs->getBool(DbAutoInstrumentationUtilForTests::WRAP_IN_TX_KEY);
        $rollback = $testArgs->getBool(DbAutoInstrumentationUtilForTests::SHOULD_ROLLBACK_KEY);

        $dbName = AssertEx::notNull(AmbientContextForTests::testConfig()->postgresqlDb);

        $testArgsEx = $testArgs->clone();
        /** @var SpanExpectations[] $expectedDbSpans */
        $expectedDbSpans = [];
        if ($testArgs->getBool(self::IS_AUTO_INSTRUMENTATION_ENABLED_KEY)) {
            $expectationsBuilder = (new PgSqlDbSpanDataExpectationsBuilder())
                ->serverAddress(AssertEx::notNull(AmbientContextForTests::testConfig()->postgresqlHost))
                ->dbNamespace($dbName);

            // pg_connect
            $expectedDbSpans[] = $expectationsBuilder->buildForPgFunction('pg_connect');

            // pg_query: DROP TABLE IF EXISTS
            $expectedDbSpans[] = $expectationsBuilder->buildForPgFunction('pg_query', self::DROP_TABLE_SQL);

            // pg_query: CREATE TABLE
            $expectedDbSpans[] = $expectationsBuilder->buildForPgFunction('pg_query', self::CREATE_TABLE_SQL);

            if ($wrapInTx) {
                // pg_query: BEGIN
                $expectedDbSpans[] = $expectationsBuilder->buildForPgFunction('pg_query', 'BEGIN');
            }

            // pg_prepare: INSERT
            $expectedDbSpans[] = $expectationsBuilder->buildForPgFunction('pg_prepare', self::INSERT_SQL);

            // pg_execute × N
            foreach (self::MESSAGES as $ignored) {
                $expectedDbSpans[] = $expectationsBuilder->buildForPgFunction('pg_execute', self::INSERT_SQL);
            }

            // pg_query: SELECT
            $expectedDbSpans[] = $expectationsBuilder->buildForPgFunction('pg_query', self::SELECT_SQL);

            if ($wrapInTx) {
                // pg_query: COMMIT/ROLLBACK
                $expectedDbSpans[] = $expectationsBuilder->buildForPgFunction('pg_query', $rollback ? 'ROLLBACK' : 'COMMIT');
            }

            // pg_query: DROP TABLE (cleanup)
            $expectedDbSpans[] = $expectationsBuilder->buildForPgFunction('pg_query', self::DROP_TABLE_SQL);
        } else {
            $testArgsEx[OptionForProdName::disabled_instrumentations->name] = self::AUTO_INSTRUMENTATION_NAME;
        }
        $dbgCtx->add(compact('testArgsEx', 'expectedDbSpans'));

        $testArgsEx[DbAutoInstrumentationUtilForTests::HOST_KEY] = AmbientContextForTests::testConfig()->postgresqlHost;
        $testArgsEx[DbAutoInstrumentationUtilForTests::PORT_KEY] = AmbientContextForTests::testConfig()->postgresqlPort;
        $testArgsEx[DbAutoInstrumentationUtilForTests::USER_KEY] = AmbientContextForTests::testConfig()->postgresqlUser;
        $testArgsEx[DbAutoInstrumentationUtilForTests::PASSWORD_KEY] = AmbientContextForTests::testConfig()->postgresqlPassword;
        $testArgsEx[DbAutoInstrumentationUtilForTests::DB_NAME_KEY] = AmbientContextForTests::testConfig()->postgresqlDb;

        $this->implTestForAppCodeSetsHowFinished(
            testArgs: $testArgsEx,
            subAppCode: [__CLASS__, 'appCodeForTestAutoInstrumentation'],
            expectedMinSpanCount: 1 + count($expectedDbSpans), // +1 for automatic local root span
            additionalAssertCode: function (DebugContextScopeRef $dbgCtx, AgentBackendComms $agentBackendComms) use ($expectedDbSpans): void {
                $actualDbSpans = [];
                foreach ($agentBackendComms->spans() as $span) {
                    if ($span->attributes->keyExists(DbAttributes::DB_SYSTEM_NAME)) {
                        $actualDbSpans[] = $span;
                    }
                }
                (new SpanSequenceExpectations($expectedDbSpans))->assertMatches($actualDbSpans);
            },
        );
    }

    /**
     * @dataProvider dataProviderForTestAutoInstrumentation
     */
    public function testAutoInstrumentation(MixedMap $testArgs): void
    {
        if (!self::$verifiedPrerequisites) {
            self::assertPrerequisites();
            self::$verifiedPrerequisites = true;
        }
        $this->runAndEscalateLogLevelOnFailure(
            self::buildDbgDescForTestWithArgs(__CLASS__, __FUNCTION__, $testArgs),
            function () use ($testArgs): void {
                $this->implTestAutoInstrumentation($testArgs);
            }
        );
    }
}
