#pragma once

#include "AttributeResolver.h"

#include <Zend/zend_types.h>

#include <cstdint>
#include <optional>
#include <string>
#include <vector>

namespace opentelemetry::php {

/// Describes a function parameter annotated with #[SpanAttribute].
struct SpanAttributeParam {
    uint32_t argIndex; ///< 0-based argument index (for a variadic parameter: the index of its first argument)
    std::string attrKey;  ///< span attribute key (from SpanAttribute::$name, or parameter name)
};

/// Describes a class property annotated with #[SpanAttribute].
struct SpanAttributeProp {
    std::string propName; ///< PHP property name
    std::string attrKey;  ///< span attribute key (from SpanAttribute::$name, or property name)
};

/// Metadata extracted from #[WithSpan] / #[SpanAttribute] attributes on a function/method.
/// Note: static attributes from WithSpan::$attributes are NOT stored here to avoid PHP value
/// lifecycle issues; they are re-read from func->common.attributes at call time.
struct WithSpanMetadata {
    std::optional<std::string> spanName;        ///< from WithSpan::$span_name
    std::optional<int64_t>     spanKind;        ///< from WithSpan::$span_kind
    std::vector<SpanAttributeParam> paramAttributes; ///< from #[SpanAttribute] on params
    std::vector<SpanAttributeProp>  propAttributes;  ///< from #[SpanAttribute] on properties
    /// The #[WithSpan] attribute has an `attributes` argument.
    bool hasStaticAttributes = false;
    /// exit() was called while the attribute arguments were evaluated; nothing may be evaluated for this function after that.
    bool evaluationStopped = false;
    /// Set once an evaluation failure was reported for this function, to not repeat it on every call.
    mutable bool evalFailureReported = false;
};

/// Returns true if the function/method (or the same method of an implemented interface) has the #[WithSpan] attribute.
bool hasWithSpanAttribute(zend_function *func);

/// Reads WithSpan metadata from the function/method attributes.
/// Attribute arguments are evaluated through `evaluator`, which isolates exceptions/exit() raised by userland code
/// run during evaluation; the caller reports evaluator.failure().
/// Returns std::nullopt if #[WithSpan] is not present.
std::optional<WithSpanMetadata> readWithSpanMetadata(zend_function *func, AttributeArgEvaluator &evaluator);

/// Evaluates WithSpan::$attributes (positional 2 / named 'attributes') and stores its string-keyed entries in `out`
/// (an initialised PHP array zval). Does nothing when the attribute has no such argument.
void readWithSpanStaticAttributes(zend_function *func, AttributeArgEvaluator &evaluator, zval *out);

/// Adds to `out` (an initialised PHP array zval) the values of the arguments of the current call that belong to
/// #[SpanAttribute] parameters, keyed by the attribute key. Only values that are valid OTel attribute values
/// (string, int, float, bool, array) are added; references are dereferenced.
void addParameterAttributeValues(zend_execute_data *executeData, WithSpanMetadata const &metadata, zval *out);

/// Same for #[SpanAttribute] properties, read from $this.
void addPropertyAttributeValues(zend_execute_data *executeData, WithSpanMetadata const &metadata, zval *out);


} // namespace opentelemetry::php
