#pragma once

#include <main/php_version.h>
#include <Zend/zend_API.h>
#include <Zend/zend_attributes.h>
#include <Zend/zend_compile.h>
#include <Zend/zend_types.h>

#include "Exceptions.h"

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

/// Number of declared parameters (a variadic one counts as one) of the function.
uint32_t declaredParameterCount(zend_function const *func);

/// The largest declared parameter count among the function and the methods of the same name in the implemented
/// interfaces. An attribute inherited from an interface can sit at an index the implementation never declares
/// (e.g. a variadic implementation of a fixed-arity interface method).
uint32_t maxDeclaredParameterCount(zend_function *func);

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

    /// The outcome of evaluating one argument. Only what callers need is kept, so the PHP value itself is released
    /// inside the evaluator, which then drains whatever its release left pending (a destructor can throw or exit()).
    struct Value {
        bool ok = false;           ///< evaluated, and no exit() was seen
        int type = IS_UNDEF;       ///< PHP type of the value, valid if ok
        std::string text;          ///< the string, if type is IS_STRING
        int64_t number = 0;        ///< the integer, if type is IS_LONG
    };

    /// Evaluates the argument of `attribute` that is passed either as positional argument number `position` or as
    /// the named argument `name`. ok is false when the argument is absent, could not be evaluated or evaluation
    /// was stopped by exit().
    Value evaluate(ResolvedAttribute const &attribute, uint32_t position, std::string_view name);

    /// Same, for an argument whose index in attr->args is already known.
    Value evaluateAt(ResolvedAttribute const &attribute, uint32_t argIndex);

    /// Evaluates an argument that is expected to be an array and copies its string-keyed entries to `out`, an
    /// initialised PHP array zval. Returns false if the argument is absent, failed, or is not an array.
    bool evaluateArrayEntries(ResolvedAttribute const &attribute, uint32_t position, std::string_view name, zval *out);

    /// true after exit() was seen; no further argument will be evaluated.
    bool stopped() const { return stopped_; }

    /// The first failure seen in this window, if any.
    std::optional<Failure> const &failure() const { return failure_; }

private:
    void captureAndClearPending();

    SavedException saved_; ///< the exception state to restore, the evaluator lives on the stack of the caller
    bool stopped_ = false;
    std::optional<Failure> failure_;
};

/// Index into attr->args of the argument given as positional argument number `position` or as named argument `name`.
std::optional<uint32_t> findAttributeArgIndex(zend_attribute const *attr, uint32_t position, std::string_view name);

} // namespace opentelemetry::php
