#pragma once

#include <main/php_version.h>
#include <Zend/zend_API.h>
#include <Zend/zend_attributes.h>
#include <Zend/zend_compile.h>
#include <Zend/zend_types.h>

#include <cstdint>
#include <optional>
#include <string>
#include <string_view>

namespace opentelemetry::php {

/// An attribute found on a function/method or on one of its parameters, together with the class scope it was
/// declared in. Arguments of an attribute inherited from an interface must be evaluated in the interface's scope
/// (so that self:: resolves there), not in the scope of the implementing class.
struct ResolvedAttribute {
    zend_attribute *attr = nullptr;
    zend_class_entry *scope = nullptr;
};

/// Finds #[WithSpan] on the function, or - for a method - on the method of the same name in an implemented interface.
std::optional<ResolvedAttribute> findWithSpanAttribute(zend_function *func);

/// Finds #[SpanAttribute] on the parameter with 0-based index `paramIndex`, or on the same parameter of the method
/// of the same name in an implemented interface.
std::optional<ResolvedAttribute> findSpanAttributeOnParameter(zend_function *func, uint32_t paramIndex);

/// Owns a zval produced by attribute argument evaluation.
class OwnedZval {
public:
    OwnedZval() { ZVAL_UNDEF(&zv_); }
    OwnedZval(OwnedZval const &) = delete;
    OwnedZval &operator=(OwnedZval const &) = delete;
    ~OwnedZval() { zval_ptr_dtor(&zv_); }

    zval *get() { return &zv_; }
    zval const *get() const { return &zv_; }
    int type() const { return Z_TYPE(zv_); }
    bool isUndef() const { return Z_TYPE(zv_) == IS_UNDEF; }

private:
    zval zv_;
};

/// RAII window for evaluating attribute arguments.
///
/// Evaluating an argument that could not be folded at compile time (SomeClass::CONST, enum case, `new` in a
/// const-expression) runs userland code: it can autoload, run constructors/destructors, throw, or call exit().
/// None of that may reach the observed application frame. While this object is alive EG(exception) is isolated;
/// after every evaluation whatever is pending is captured and cleared, so that evaluation of later arguments
/// can carry on. The original exception state is restored by the destructor.
///
/// Failures are only recorded here, the caller decides how to report them (see failure()).
class AttributeArgEvaluator {
public:
    struct Failure {
        std::string message;       ///< description of the first exception, or of the exit()
        bool exitRequested = false; ///< exit() was called during evaluation; it cannot be honoured
    };

    AttributeArgEvaluator();
    AttributeArgEvaluator(AttributeArgEvaluator const &) = delete;
    AttributeArgEvaluator &operator=(AttributeArgEvaluator const &) = delete;
    ~AttributeArgEvaluator();

    /// Evaluates the argument of `attribute` that is passed either as positional argument number `position` or as
    /// the named argument `name`. Returns false when the argument is absent, could not be evaluated or evaluation
    /// was stopped by exit(). On success `out` holds the value.
    bool evaluate(ResolvedAttribute const &attribute, uint32_t position, std::string_view name, OwnedZval &out);

    /// Same, for an argument whose index in attr->args is already known.
    bool evaluateAt(ResolvedAttribute const &attribute, uint32_t argIndex, OwnedZval &out);

    /// true after exit() was seen; no further argument will be evaluated.
    bool stopped() const { return stopped_; }

    /// The first failure seen in this window, if any.
    std::optional<Failure> const &failure() const { return failure_; }

private:
    void captureAndClearPending();

    struct SavedState;
    SavedState *saved_;
    bool stopped_ = false;
    std::optional<Failure> failure_;
};

/// Index into attr->args of the argument given as positional argument number `position` or as named argument `name`.
std::optional<uint32_t> findAttributeArgIndex(zend_attribute const *attr, uint32_t position, std::string_view name);

} // namespace opentelemetry::php
