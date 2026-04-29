<?php

declare(strict_types=1);

namespace OTelDistroTests\ComponentTests\Util\MySqli;

use OTelDistroTests\ComponentTests\Util\DbSpanExpectationsBuilder;
use OTelDistroTests\ComponentTests\Util\SpanExpectations;
use OTelDistroTests\Util\ClassNameUtil;
use mysqli;
use mysqli_stmt;

/**
 * Code in this file is part of implementation internals and thus it is not covered by the backward compatibility.
 *
 * @internal
 */
final class MySqliDbSpanDataExpectationsBuilder extends DbSpanExpectationsBuilder
{
    public const DB_SYSTEM_NAME = 'mysql';

    public function __construct(
        private readonly bool $isOOPApi,
    ) {
        parent::__construct();

        $this->dbSystemName(self::DB_SYSTEM_NAME);
    }

    private static function deduceFuncName(string $className, string $methodName): string
    {
        return $className . '_' . $methodName;
    }

    public function buildForApi(string $className, string $methodName, ?string $funcName = null, ?string $dbQueryText = null): SpanExpectations
    {
        $builderClone = clone $this;
        $builderClone->isOOPApi
            ? $builderClone->nameAndCodeFunctionUsingClassMethod($className, $methodName, isStaticMethod: false)
            : $builderClone->nameAndCodeAttributesUsingFuncName($funcName ?? self::deduceFuncName($className, $methodName));
        $builderClone->optionalDbQueryTextAndOperationName($dbQueryText);
        return $builderClone->build();
    }

    public function buildForMySqliClassMethod(string $methodName, ?string $funcName = null, ?string $dbQueryText = null): SpanExpectations
    {
        return $this->buildForApi(ClassNameUtil::fqToShort(mysqli::class), $methodName, $funcName, $dbQueryText);
    }

    public function buildForMySqliStmtClassMethod(string $methodName, ?string $funcName = null, ?string $dbQueryText = null): SpanExpectations
    {
        return $this->buildForApi(ClassNameUtil::fqToShort(mysqli_stmt::class), $methodName, $funcName, $dbQueryText);
    }
}
