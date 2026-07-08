#pragma once

#include <php.h>
#include <Zend/zend_types.h>
#include <optional>
#include <string_view>

namespace opentelemetry::php {

std::string_view zvalToStringView(zval *zv);
std::optional<std::string_view> zvalToOptionalStringView(zval *zv);

zend_ulong getClassAndFunctionHashFromExecuteData(zend_execute_data *execute_data);
std::tuple<std::string_view, std::string_view> getClassAndFunctionName(zend_execute_data *execute_data);

zend_ulong hashClassAndFunctionNameLowercase(std::string_view className, std::string_view functionName);

/// Same DJBX33A recurrence as zend_inline_hash_func()/ZSTR_HASH(), computed with an on-the-fly tolower()
zend_ulong lowercaseHash(std::string_view sv);
}
