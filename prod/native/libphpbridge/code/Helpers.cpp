#include "Helpers.h"
#include <optional>
#include <tuple>
#include <cctype>
#include <Zend/zend_string.h>

namespace opentelemetry::php {

// Same DJBX33A recurrence as zend_inline_hash_func (the multi-byte-at-a-time version there is
// just an algebraic unrolling of this same per-byte formula), computed with an on-the-fly
// tolower() so no lowercased copy of the string needs to be allocated.
zend_ulong lowercaseHash(std::string_view sv) {
    zend_ulong hash = Z_UL(5381);
    for (unsigned char c : sv) {
        hash = hash * 33 + static_cast<unsigned char>(std::tolower(c));
    }

    // zend_inline_hash_func()/ZSTR_HASH() unconditionally set the high bit so the hash value
    // can never be zero (Zend reserves 0 as the "not yet computed" sentinel for a cached
    // zend_string hash). Mirrored here so this is bit-identical to PHP's own hash function.
#if SIZEOF_ZEND_LONG == 8
    return hash | Z_UL(0x8000000000000000);
#elif SIZEOF_ZEND_LONG == 4
    return hash | Z_UL(0x80000000);
#else
# error "Unknown SIZEOF_ZEND_LONG"
#endif
}

std::optional<std::string_view> zvalToOptionalStringView(zval *zv) {
    if (!zv || Z_TYPE_P(zv) != IS_STRING) {
        return std::nullopt;
    }
    return std::string_view{Z_STRVAL_P(zv), Z_STRLEN_P(zv)};
}


std::string_view zvalToStringView(zval *zv) {
    if (!zv || Z_TYPE_P(zv) != IS_STRING) {
        return {};
    }
    return {Z_STRVAL_P(zv), Z_STRLEN_P(zv)};
}

zend_ulong hashClassAndFunctionNameLowercase(std::string_view className, std::string_view functionName) {
    zend_ulong classHash = className.empty() ? 0 : lowercaseHash(className);
    zend_ulong funcHash = lowercaseHash(functionName);
    return classHash ^ (funcHash << 1);
}

zend_ulong getClassAndFunctionHashFromExecuteData(zend_execute_data *execute_data) {
    if (!execute_data || !execute_data->func || !execute_data->func->common.function_name) {
        return 0;
    }

    auto [className, functionName] = getClassAndFunctionName(execute_data);
    return hashClassAndFunctionNameLowercase(className, functionName);
}

std::tuple<std::string_view, std::string_view> getClassAndFunctionName(zend_execute_data *execute_data) {
    std::string_view cls;
    if (execute_data->func->common.scope && execute_data->func->common.scope->name) {
        cls = {ZSTR_VAL(execute_data->func->common.scope->name), ZSTR_LEN(execute_data->func->common.scope->name)};
    }
    std::string_view func;
    if (execute_data->func->common.function_name) {
        func = {ZSTR_VAL(execute_data->func->common.function_name), ZSTR_LEN(execute_data->func->common.function_name)};
    }
    return std::make_tuple(cls, func);
}



}
