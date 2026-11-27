#include "AttributeResolver.h"
#include "Exceptions.h"

#include <main/php_version.h>
#include <Zend/zend_API.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_globals.h>
#include <Zend/zend_hash.h>

#include <algorithm>

namespace opentelemetry::php {

using namespace std::string_view_literals;

namespace {

constexpr std::string_view withSpanLcName = "opentelemetry\\api\\instrumentation\\withspan"sv;
constexpr std::string_view spanAttributeLcName = "opentelemetry\\api\\instrumentation\\spanattribute"sv;

// A destructor of a discarded exception can itself throw; each throw is another exception to discard.
constexpr int maxChainedDiscards = 64;

/// The method in `iface` whose lowercase name is `lcName`, or nullptr. function_table keys are lowercase.
/// zend_hash_find_ptr_lc() is not exported by PHP 8.2.
zend_function *findMethodInInterface(zend_class_entry *iface, zend_string *lcName) {
    return static_cast<zend_function *>(zend_hash_find_ptr(&iface->function_table, lcName));
}

template <typename Lookup>
std::optional<ResolvedAttribute> findOnFunctionOrInterfaces(zend_function *func, Lookup lookup) {
    if (func->common.type != ZEND_USER_FUNCTION) {
        return std::nullopt;
    }
    if (auto *attr = lookup(func)) {
        return ResolvedAttribute{attr, func->common.scope};
    }

    zend_class_entry *ce = func->common.scope;
    if (!ce || !func->common.function_name || ce->num_interfaces == 0) {
        return std::nullopt;
    }
    // one lowercase copy of the name for all the interfaces (no allocation when it is lowercase already)
    zend_string *lcName = zend_string_tolower(func->common.function_name);
    std::optional<ResolvedAttribute> found;
    for (uint32_t i = 0; i < ce->num_interfaces && !found; ++i) {
        zend_class_entry *iface = ce->interfaces[i];
        if (!iface) {
            continue;
        }
        zend_function *ifaceMethod = findMethodInInterface(iface, lcName);
        if (!ifaceMethod) {
            continue;
        }
        if (auto *attr = lookup(ifaceMethod)) {
            found = ResolvedAttribute{attr, ifaceMethod->common.scope};
        }
    }
    zend_string_release(lcName);
    return found;
}

} // namespace

uint32_t declaredParameterCount(zend_function const *func) {
    return func->common.num_args + ((func->common.fn_flags & ZEND_ACC_VARIADIC) ? 1 : 0);
}

uint32_t maxDeclaredParameterCount(zend_function *func) {
    uint32_t count = declaredParameterCount(func);
    zend_class_entry *ce = func->common.scope;
    if (!ce || !func->common.function_name || ce->num_interfaces == 0) {
        return count;
    }
    zend_string *lcName = zend_string_tolower(func->common.function_name);
    for (uint32_t i = 0; i < ce->num_interfaces; ++i) {
        if (ce->interfaces[i]) {
            if (zend_function *m = findMethodInInterface(ce->interfaces[i], lcName)) {
                count = std::max(count, declaredParameterCount(m));
            }
        }
    }
    zend_string_release(lcName);
    return count;
}

std::optional<ResolvedAttribute> findWithSpanAttribute(zend_function *func) {
    return findOnFunctionOrInterfaces(func, [](zend_function *f) -> zend_attribute * {
        return f->common.attributes ? zend_get_attribute_str(f->common.attributes, withSpanLcName.data(), withSpanLcName.size()) : nullptr;
    });
}

std::optional<ResolvedAttribute> findSpanAttributeOnParameter(zend_function *func, uint32_t paramIndex) {
    return findOnFunctionOrInterfaces(func, [paramIndex](zend_function *f) -> zend_attribute * {
        // zend_get_parameter_attribute_str takes a 0-based parameter index
        return f->common.attributes ? zend_get_parameter_attribute_str(f->common.attributes, spanAttributeLcName.data(), spanAttributeLcName.size(), paramIndex) : nullptr;
    });
}

std::optional<uint32_t> findAttributeArgIndex(zend_attribute const *attr, uint32_t position, std::string_view name) {
    for (uint32_t i = 0; i < attr->argc; ++i) {
        zend_string *argName = attr->args[i].name;
        if (!argName) {
            if (i == position) {
                return i;
            }
        } else if (std::string_view{ZSTR_VAL(argName), ZSTR_LEN(argName)} == name) {
            return i;
        }
    }
    return std::nullopt;
}

AttributeArgEvaluator::AttributeArgEvaluator() : saved_(saveExceptionState()) {
}

AttributeArgEvaluator::~AttributeArgEvaluator() {
    // whatever is still pending (an unwind exit that stopped evaluation) must not outlive the window
    try {
        captureAndClearPending();
    } catch (...) {
        // building the description of a failure can run out of memory, a destructor must not throw
    }
    restoreExceptionState(saved_);
    // exit(code) sets the exit status of the process before it unwinds. The unwind was discarded, the application goes on.
    EG(exit_status) = saved_.exit_status;
}

void AttributeArgEvaluator::captureAndClearPending() {
    for (int i = 0; EG(exception) && i < maxChainedDiscards; ++i) {
        zend_object *pending = EG(exception);

        if (zend_is_unwind_exit(pending)) {
            stopped_ = true;
            if (!failure_ || !failure_->exitRequested) {
                Failure f;
                f.message = "exit() called during attribute argument evaluation";
                f.exitRequested = true;
                if (failure_) {
                    f.message += "; first failure: " + failure_->message;
                }
                failure_ = std::move(f);
            }
        } else if (!failure_) {
            failure_ = Failure{exceptionToString(pending), false};
        }

        EG(exception) = nullptr;
#if PHP_VERSION_ID < 80600
        EG(prev_exception) = nullptr;
#endif
        // may run a userland __destruct which can set EG(exception) again - handled by the loop
        OBJ_RELEASE(pending);
    }

    if (EG(exception)) {
        // destructor chain longer than the limit: drop the rest. Not with zend_clear_exception(), it would move the opline
        // of the observed frame.
        zend_object *rest = EG(exception);
        EG(exception) = nullptr;
#if PHP_VERSION_ID < 80600
        EG(prev_exception) = nullptr;
#endif
        OBJ_RELEASE(rest);
    }
}

AttributeArgEvaluator::Value AttributeArgEvaluator::evaluateAt(ResolvedAttribute const &attribute, uint32_t argIndex) {
    Value result;
    if (stopped_) {
        return result;
    }

    zval value;
    ZVAL_UNDEF(&value);
    bool ok = zend_get_attribute_value(&value, attribute.attr, argIndex, attribute.scope) == SUCCESS;

    if (ok) {
        result.type = Z_TYPE(value);
        if (Z_TYPE(value) == IS_STRING) {
            result.text.assign(Z_STRVAL(value), Z_STRLEN(value));
        } else if (Z_TYPE(value) == IS_LONG) {
            result.number = static_cast<int64_t>(Z_LVAL(value));
        }
        // May run a destructor, which can throw or call exit(): that is drained below, before anything else is evaluated.
        zval_ptr_dtor(&value);
    }
    // else: on failure the zval has already been released

    // After either outcome: a failure leaves an exception pending, and so can a success (a destructor that ran while a
    // temporary was released). Capturing instead of clearing keeps the first one for the caller.
    captureAndClearPending();

    result.ok = ok && !stopped_;
    return result;
}

AttributeArgEvaluator::Value AttributeArgEvaluator::evaluate(ResolvedAttribute const &attribute, uint32_t position, std::string_view name) {
    auto index = findAttributeArgIndex(attribute.attr, position, name);
    if (!index) {
        return {};
    }
    return evaluateAt(attribute, *index);
}

bool AttributeArgEvaluator::evaluateArrayEntries(ResolvedAttribute const &attribute, uint32_t position, std::string_view name, zval *out) {
    auto index = findAttributeArgIndex(attribute.attr, position, name);
    if (!index || stopped_) {
        return false;
    }

    zval value;
    ZVAL_UNDEF(&value);
    bool ok = zend_get_attribute_value(&value, attribute.attr, *index, attribute.scope) == SUCCESS;

    bool copied = false;
    if (ok) {
        if (Z_TYPE(value) == IS_ARRAY) {
            zend_string *key = nullptr;
            zval *item = nullptr;
            ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARR(value), key, item) {
                if (key && item) {
                    zval copy;
                    ZVAL_COPY(&copy, item);
                    zend_hash_update(Z_ARR_P(out), key, &copy);
                }
            } ZEND_HASH_FOREACH_END();
            copied = true;
        }
        zval_ptr_dtor(&value);
    }
    captureAndClearPending();

    if (stopped_ && copied) {
        // an exit() while the array was released: the entries must not be used
        zend_hash_clean(Z_ARR_P(out));
    }
    return copied && !stopped_;
}

} // namespace opentelemetry::php
