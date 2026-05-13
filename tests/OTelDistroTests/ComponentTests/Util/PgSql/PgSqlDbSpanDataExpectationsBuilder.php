<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util\PgSql;

use OTelDistroTests\ComponentTests\Util\DbSpanExpectationsBuilder;
use OTelDistroTests\ComponentTests\Util\SpanExpectations;

/**
 * Code in this file is part of implementation internals and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class PgSqlDbSpanDataExpectationsBuilder extends DbSpanExpectationsBuilder
{
    public const DB_SYSTEM_NAME = 'postgresql';

    public function __construct()
    {
        parent::__construct();

        $this->dbSystemName(self::DB_SYSTEM_NAME);
    }

    public function buildForPgFunction(string $funcName, ?string $dbQueryText = null): SpanExpectations
    {
        $builderClone = clone $this;
        $builderClone->nameAndCodeAttributesUsingFuncName($funcName);
        $builderClone->optionalDbQueryTextAndOperationName($dbQueryText);
        return $builderClone->build();
    }
}
