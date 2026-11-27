#include "WithSpanAttributes.h"
#include "PhpBridge.h"

#include <Zend/zend_API.h>
#include <Zend/zend_attributes.h>
#include <Zend/zend_hash.h>
#include <Zend/zend_types.h>

#include <string>
#include <string_view>

namespace opentelemetry::php {

using namespace std::string_view_literals;

namespace {

constexpr std::string_view spanAttrLcName = "opentelemetry\\api\\instrumentation\\spanattribute"sv;

std::optional<std::string> evaluateToString(AttributeArgEvaluator &evaluator, ResolvedAttribute const &attribute, uint32_t position, std::string_view name) {
    OwnedZval value;
    if (evaluator.evaluate(attribute, position, name, value) && value.type() == IS_STRING) {
        return std::string{Z_STRVAL_P(value.get()), Z_STRLEN_P(value.get())};
    }
    return std::nullopt;
}

/// Attribute key for a #[SpanAttribute]: its `name` argument, or `defaultName` when there is none (a bare
/// #[SpanAttribute] is *defined* to key on the parameter/property name, as is an explicit null). There is no default
/// for a parameter the function does not declare (inherited from an interface), such an attribute needs a name.
///
/// Any other name that cannot be used - not evaluable, not a non-empty string, a different named argument - yields
/// no key at all: falling back to the default would publish a plausible-looking wrong key nobody queries, while a
/// missing attribute is diagnosable. After exit() nothing more is evaluated, so nothing more is keyed either.
std::optional<std::string> resolveSpanAttributeKey(AttributeArgEvaluator &evaluator, ResolvedAttribute const &attribute, std::optional<std::string> defaultName) {
    if (evaluator.stopped()) {
        return std::nullopt;
    }
    if (attribute.attr->argc == 0) {
        return defaultName;
    }
    zend_string *firstArgName = attribute.attr->args[0].name;
    if (firstArgName && std::string_view{ZSTR_VAL(firstArgName), ZSTR_LEN(firstArgName)} != "name"sv) {
        return std::nullopt;
    }

    OwnedZval value;
    if (!evaluator.evaluateAt(attribute, 0, value)) {
        return std::nullopt;
    }
    if (value.type() == IS_NULL) {
        return defaultName;
    }
    // not coerced: whether userland would coerce a scalar depends on strict_types in the declaring file
    if (value.type() == IS_STRING && Z_STRLEN_P(value.get()) > 0) {
        return std::string{Z_STRVAL_P(value.get()), Z_STRLEN_P(value.get())};
    }
    return std::nullopt;
}

/// OpenTelemetry attribute values may only be of limited types.
bool isValidAttributeValue(zval const *value) {
    switch (Z_TYPE_P(value)) {
        case IS_STRING:
        case IS_LONG:
        case IS_DOUBLE:
        case IS_TRUE:
        case IS_FALSE:
        case IS_ARRAY:
            return true;
        default:
            return false;
    }
}

/// Adds a copy of the (dereferenced) value if it is a valid attribute value; later keys replace earlier ones.
void addAttributeValue(zval *out, std::string const &key, zval *value) {
    ZVAL_DEREF(value);
    if (!isValidAttributeValue(value)) {
        return;
    }
    zval copy;
    ZVAL_COPY(&copy, value);
    zend_hash_str_update(Z_ARR_P(out), key.c_str(), key.length(), &copy);
}

/// The zval of the call argument with 0-based index `index`. Arguments beyond the declared parameters of a user
/// function are not stored next to the declared ones but after the compiled variables and temporaries.
zval *callArgument(zend_execute_data *ex, uint32_t index) {
    uint32_t declared = ex->func->op_array.num_args;
    if (index < declared) {
        return ZEND_CALL_ARG(ex, index + 1);
    }
    return ZEND_CALL_VAR_NUM(ex, ex->func->op_array.last_var + ex->func->op_array.T) + (index - declared);
}

} // namespace

bool hasWithSpanAttribute(zend_function *func) {
    return findWithSpanAttribute(func).has_value();
}

bool withSpanHasStaticAttributes(zend_function *func) {
    auto resolved = findWithSpanAttribute(func);
    return resolved && findAttributeArgIndex(resolved->attr, 2, "attributes").has_value();
}

void readWithSpanStaticAttributes(zend_function *func, AttributeArgEvaluator &evaluator, zval *out) {
    auto resolved = findWithSpanAttribute(func);
    if (!resolved) {
        return;
    }

    OwnedZval value;
    if (!evaluator.evaluate(*resolved, 2, "attributes", value) || value.type() != IS_ARRAY) {
        return;
    }

    zend_string *key = nullptr;
    zval *item = nullptr;
    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARR_P(value.get()), key, item) {
        if (key && item) {
            zval copy;
            ZVAL_COPY(&copy, item);
            zend_hash_update(Z_ARR_P(out), key, &copy);
        }
    } ZEND_HASH_FOREACH_END();
}

void addParameterAttributeValues(zend_execute_data *executeData, WithSpanMetadata const &metadata, zval *out) {
    uint32_t argCount = ZEND_CALL_NUM_ARGS(executeData);
    for (auto const &p : metadata.paramAttributes) {
        if (p.argIndex >= argCount) {
            continue;
        }
        zval *arg = callArgument(executeData, p.argIndex);
        if (Z_TYPE_P(arg) != IS_UNDEF) {
            addAttributeValue(out, p.attrKey, arg);
        }
    }
}

void addPropertyAttributeValues(zend_execute_data *executeData, WithSpanMetadata const &metadata, zval *out) {
    if (metadata.propAttributes.empty() || Z_TYPE(executeData->This) != IS_OBJECT) {
        return;
    }
    zend_class_entry *ce = Z_OBJCE(executeData->This);
    for (auto const &p : metadata.propAttributes) {
        zval *prop = getClassPropertyValue(ce, Z_OBJ(executeData->This), p.propName);
        if (prop && Z_TYPE_P(prop) != IS_UNDEF) {
            addAttributeValue(out, p.attrKey, prop);
        }
    }
}

std::optional<WithSpanMetadata> readWithSpanMetadata(zend_function *func, AttributeArgEvaluator &evaluator) {
    auto withSpan = findWithSpanAttribute(func);
    if (!withSpan) {
        return std::nullopt;
    }

    WithSpanMetadata metadata;

    metadata.spanName = evaluateToString(evaluator, *withSpan, 0, "span_name"sv);

    {
        OwnedZval kind;
        if (evaluator.evaluate(*withSpan, 1, "span_kind"sv, kind) && kind.type() == IS_LONG) {
            metadata.spanKind = static_cast<int64_t>(Z_LVAL_P(kind.get()));
        }
    }

    // #[SpanAttribute] on parameters, own or inherited from the interface method
    // The range covers parameters only an implemented interface declares, and the variadic parameter.
    uint32_t declared = declaredParameterCount(func);
    uint32_t paramCount = maxDeclaredParameterCount(func);
    for (uint32_t i = 0; i < paramCount; i++) {
        auto paramAttr = findSpanAttributeOnParameter(func, i);
        if (!paramAttr) {
            continue;
        }

        // vars[] holds the declared parameters first, the variadic one last
        std::optional<std::string> paramName;
        if (i < declared) {
            zend_string *name = func->op_array.vars[i];
            paramName = std::string{ZSTR_VAL(name), ZSTR_LEN(name)};
        }
        auto attrKey = resolveSpanAttributeKey(evaluator, *paramAttr, std::move(paramName));
        if (attrKey) {
            metadata.paramAttributes.push_back({i, std::move(*attrKey)});
        }
    }

    // #[SpanAttribute] on class properties (requires a scope/class)
    if (zend_class_entry *scope = func->common.scope) {
        zend_string *propName = nullptr;
        zval *propZval = nullptr;

        ZEND_HASH_FOREACH_STR_KEY_VAL(&scope->properties_info, propName, propZval) {
            if (!propName || !propZval) {
                continue;
            }
            auto *propInfo = static_cast<zend_property_info *>(Z_PTR_P(propZval));
            if (!propInfo || !propInfo->attributes) {
                continue;
            }

            zend_attribute *propAttr = zend_get_attribute_str(propInfo->attributes, spanAttrLcName.data(), spanAttrLcName.size());
            if (!propAttr) {
                continue;
            }

            std::string propNameStr{ZSTR_VAL(propName), ZSTR_LEN(propName)};
            auto attrKey = resolveSpanAttributeKey(evaluator, ResolvedAttribute{propAttr, scope}, propNameStr);
            if (attrKey) {
                metadata.propAttributes.push_back({propNameStr, std::move(*attrKey)});
            }
        } ZEND_HASH_FOREACH_END();
    }

    metadata.evaluationStopped = evaluator.stopped();
    return metadata;
}

} // namespace opentelemetry::php
