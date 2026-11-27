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

/// The method of the same name in `iface`, or nullptr.
zend_function *findMethodInInterface(zend_class_entry *iface, zend_string *name) {
    // function_table keys are lowercase. zend_hash_find_ptr_lc() is not exported by PHP 8.2.
    zend_string *lcName = zend_string_tolower(name);
    auto *method = static_cast<zend_function *>(zend_hash_find_ptr(&iface->function_table, lcName));
    zend_string_release(lcName);
    return method;
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
    if (!ce || !func->common.function_name) {
        return std::nullopt;
    }
    for (uint32_t i = 0; i < ce->num_interfaces; ++i) {
        zend_class_entry *iface = ce->interfaces[i];
        if (!iface) {
            continue;
        }
        zend_function *ifaceMethod = findMethodInInterface(iface, func->common.function_name);
        if (!ifaceMethod) {
            continue;
        }
        if (auto *attr = lookup(ifaceMethod)) {
            return ResolvedAttribute{attr, ifaceMethod->common.scope};
        }
    }
    return std::nullopt;
}

} // namespace

uint32_t declaredParameterCount(zend_function const *func) {
    return func->common.num_args + ((func->common.fn_flags & ZEND_ACC_VARIADIC) ? 1 : 0);
}

uint32_t maxDeclaredParameterCount(zend_function *func) {
    uint32_t count = declaredParameterCount(func);
    zend_class_entry *ce = func->common.scope;
    if (!ce || !func->common.function_name) {
        return count;
    }
    for (uint32_t i = 0; i < ce->num_interfaces; ++i) {
        if (ce->interfaces[i]) {
            if (zend_function *m = findMethodInInterface(ce->interfaces[i], func->common.function_name)) {
                count = std::max(count, declaredParameterCount(m));
            }
        }
    }
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

struct AttributeArgEvaluator::SavedState {
    SavedException exception;
};

AttributeArgEvaluator::AttributeArgEvaluator() : saved_(new SavedState{saveExceptionState()}) {
}

AttributeArgEvaluator::~AttributeArgEvaluator() {
    // whatever is still pending (an unwind exit that stopped evaluation) must not outlive the window
    captureAndClearPending();
    restoreExceptionState(saved_->exception);
    delete saved_;
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
        // destructor chain longer than the limit: drop the rest without running anything else
        zend_clear_exception();
        EG(exception) = nullptr;
    }
}

bool AttributeArgEvaluator::evaluateAt(ResolvedAttribute const &attribute, uint32_t argIndex, OwnedZval &out) {
    if (stopped_) {
        return false;
    }

    zval value;
    ZVAL_UNDEF(&value);
    bool ok = zend_get_attribute_value(&value, attribute.attr, argIndex, attribute.scope) == SUCCESS;

    // After either outcome: a failure leaves an exception pending, and so can a success (e.g. a destructor that
    // ran while a temporary was released). Capturing instead of clearing keeps the first one for the caller.
    captureAndClearPending();

    if (!ok) {
        // on failure the zval has already been released
        return false;
    }
    if (stopped_) {
        zval_ptr_dtor(&value);
        return false;
    }
    ZVAL_COPY_VALUE(out.get(), &value);
    return true;
}

bool AttributeArgEvaluator::evaluate(ResolvedAttribute const &attribute, uint32_t position, std::string_view name, OwnedZval &out) {
    auto index = findAttributeArgIndex(attribute.attr, position, name);
    if (!index) {
        return false;
    }
    return evaluateAt(attribute, *index, out);
}

} // namespace opentelemetry::php
