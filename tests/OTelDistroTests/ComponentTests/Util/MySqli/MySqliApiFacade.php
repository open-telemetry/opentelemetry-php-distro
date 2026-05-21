<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util\MySqli;

use OTelDistroTests\Util\AmbientContextForTests;
use OTelDistroTests\Util\Log\LogCategoryForTests;
use OTelDistroTests\Util\Log\LoggableInterface;
use OTelDistroTests\Util\Log\LoggableTrait;
use OTelDistroTests\Util\Log\Logger;
use mysqli;

/**
 * Code in this file is part of implementation internals and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class MySqliApiFacade implements LoggableInterface
{
    use LoggableTrait;

    private Logger $logger;

    public function __construct(
        private readonly bool $isOOPApi
    ) {
        $this->logger = AmbientContextForTests::loggerFactory()->loggerForClass(LogCategoryForTests::TEST_INFRA, __NAMESPACE__, __CLASS__, __FILE__)->addContext('this', $this);
    }

    public function connect(string $host, int $port, string $username, string $password, ?string $dbName): ?MySqliWrapped
    {
        $this->logger->logTrace(__FUNCTION__)?->with(__LINE__, 'Entered', compact('host', 'port', 'username', 'password', 'dbName'));

        $wrappedObj = $this->isOOPApi
            ? new mysqli($host, $username, $password, $dbName, $port)
            : mysqli_connect($host, $username, $password, $dbName, $port);
        return ($wrappedObj instanceof mysqli) ? new MySqliWrapped($wrappedObj, $this->isOOPApi) : null;
    }
}
