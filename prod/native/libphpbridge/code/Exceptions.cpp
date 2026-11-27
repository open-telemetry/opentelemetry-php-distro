#include "PhpBridge.h"
#include "Helpers.h"

#include <Zend/zend_exceptions.h>
#include <Zend/zend_globals.h>
#include <Zend/zend_types.h>
#include <optional>

namespace opentelemetry::php {

using namespace std::literals;

SavedException saveExceptionState() {
    SavedException savedException;
    savedException.exception = EG(exception);
#if PHP_VERSION_ID < 80600
    savedException.prev_exception = EG(prev_exception);
#endif
    savedException.opline_before_exception = EG(opline_before_exception);
    savedException.exit_status = EG(exit_status);

    EG(exception) = nullptr;
#if PHP_VERSION_ID < 80600
    EG(prev_exception) = nullptr;
#endif
    EG(opline_before_exception) = nullptr;

    if (EG(current_execute_data)) {
        savedException.opline = EG(current_execute_data)->opline;
    }
    return savedException;
}

void restoreExceptionState(SavedException savedException, PendingExit pendingExit) {
    if (pendingExit == PendingExit::keep && EG(exception) && zend_is_unwind_exit(EG(exception))) {
        // exit() called by the isolated code wins over whatever was pending before it
        if (savedException.exception) {
            OBJ_RELEASE(savedException.exception);
        }
#if PHP_VERSION_ID < 80600
        if (savedException.prev_exception) {
            OBJ_RELEASE(savedException.prev_exception);
        }
#endif
        return;
    }

    // Whatever the isolated code left pending is dropped now: release it, it is not referenced from anywhere else. A
    // destructor of what is released can throw again, hence the loop.
    for (int i = 0; EG(exception) && EG(exception) != savedException.exception && i < 64; ++i) {
        zend_object *dropped = EG(exception);
        if (zend_is_unwind_exit(dropped)) {
            // an exit() that is dropped here must not leave its exit status behind: the script goes on
            EG(exit_status) = savedException.exit_status;
        }
        EG(exception) = nullptr;
        OBJ_RELEASE(dropped);
    }
    if (EG(exception) && EG(exception) != savedException.exception) {
        // the destructors keep throwing: drop the rest, it would be overwritten below and leaked
        zend_object *rest = EG(exception);
        EG(exception) = nullptr;
        OBJ_RELEASE(rest);
    }
    EG(exception) = savedException.exception;
#if PHP_VERSION_ID < 80600
    EG(prev_exception) = savedException.prev_exception;
#endif
    EG(opline_before_exception) = savedException.opline_before_exception;

    if (EG(current_execute_data) && savedException.opline.has_value()) {
        EG(current_execute_data)->opline = savedException.opline.value();
    }
}


std::optional<std::string_view> getExceptionMessage(zend_object *exception) {
    return zvalToOptionalStringView(getClassPropertyValue(exception->ce, exception, "message"sv));
}

std::optional<std::string_view> getExceptionFileName(zend_object *exception) {
    return zvalToOptionalStringView(getClassPropertyValue(exception->ce, exception, "file"sv));
}

std::optional<long> getExceptionLine(zend_object *exception) {
    auto value = getClassPropertyValue(exception->ce, exception, "line"sv);
    if (Z_TYPE_P(value) == IS_LONG) {
        return Z_LVAL_P(value);
    }
    return -1;
}

std::optional<long> getExceptionCode(zend_object *exception) {
    auto value = getClassPropertyValue(exception->ce, exception, "code"sv);
    if (Z_TYPE_P(value) == IS_LONG) {
        return Z_LVAL_P(value);
    }
    return std::nullopt;
}

std::optional<std::string_view> getExceptionClass(zend_object *exception) {
    return zvalToOptionalStringView(getClassPropertyValue(exception->ce, exception, "class"sv));
}

std::optional<std::string_view> getExceptionFunction(zend_object *exception) {
    return zvalToOptionalStringView(getClassPropertyValue(exception->ce, exception, "function"sv));
}

std::optional<std::string_view> getExceptionStringStackTrace(zend_object *exception) {
    return zvalToOptionalStringView(getClassPropertyValue(exception->ce, exception, "string"sv));
}

std::string_view getExceptionName(zend_object *exception) {
    zend_string *str = exception->handlers->get_class_name(exception);
    if (!str) {
        return {};
    }
    return {ZSTR_VAL(str), ZSTR_LEN(str)};
}

std::string exceptionToString(zend_object *exception) {
    if (!exception || !instanceof_function(exception->ce, zend_ce_throwable)) {
        return {};
    }

    std::stringstream msg;
    auto exceptionClass = getExceptionClass(exception);
    auto exceptionName = getExceptionName(exception);

    msg << exceptionClass.value_or(exceptionName);
    msg << " thrown"sv;

    auto message = getExceptionMessage(exception);
    if (message.has_value()) {
        msg << " with message '"sv << *message << "'";
    }

    auto code = getExceptionCode(exception);
    if (code.has_value() && *code != 0) {
        msg << " with code "sv << *code;
    }

    auto fileName = getExceptionFileName(exception);
    if (fileName.has_value()) {
        msg << " in "sv << *fileName;
        auto line = getExceptionLine(exception);
        if (line.has_value()) {
            msg << ":"sv << *line;
        }
    }

    auto stack = getExceptionStringStackTrace(exception);
    if (stack.has_value() && !stack.value().empty()) {
        msg << " stacktrace: '"sv << *stack << "'";
    }
    return msg.str();
}
}
