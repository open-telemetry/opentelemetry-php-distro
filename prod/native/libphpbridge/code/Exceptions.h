#pragma once

#include <Zend/zend_globals.h>
#include <Zend/zend_types.h>
#include <optional>
#include <string_view>

namespace opentelemetry::php {

struct SavedException {
    zend_object *exception = nullptr;
#if PHP_VERSION_ID < 80600
    zend_object *prev_exception = nullptr;
#endif
    const zend_op *opline_before_exception = nullptr;
    std::optional<const zend_op *> opline;
};

/// What to do when the isolated code (a hook) left an exit() pending.
enum class PendingExit {
    /// restore the saved state, the exit is dropped
    discard,
    /// keep the exit pending so that it terminates the script; the saved exception is released then, exit wins
    keep,
};

SavedException saveExceptionState();
void restoreExceptionState(SavedException savedException, PendingExit pendingExit = PendingExit::discard);



class AutomaticExceptionStateRestorer {
public:
    explicit AutomaticExceptionStateRestorer(PendingExit pendingExit = PendingExit::discard) : pendingExit(pendingExit), savedException(saveExceptionState()) {
    }
    ~AutomaticExceptionStateRestorer() {
        restoreExceptionState(savedException, pendingExit);
    }
    auto getException() {
        return savedException.exception;
    }

private:
    PendingExit pendingExit;
    SavedException savedException;
};

std::optional<std::string_view> getExceptionClass(zend_object *exception);
std::optional<std::string_view> getExceptionFileName(zend_object *exception);
std::optional<std::string_view> getExceptionFunction(zend_object *exception);
std::optional<long> getExceptionLine(zend_object *exception);
std::optional<std::string_view> getExceptionMessage(zend_object *exception);
std::optional<std::string_view> getExceptionStringStackTrace(zend_object *exception);

std::string exceptionToString(zend_object *exception);
}
