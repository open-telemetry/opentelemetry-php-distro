#include "Hooking.h"

#include "ModuleGlobals.h"

#include "PhpBridge.h"
#include "PhpErrorData.h"

#include <memory>
#include <string_view>
#include "InternalFunctionInstrumentation.h"
#include "PeriodicTaskExecutor.h"
#include "RequestScope.h"
#include "InferredSpans.h"
#include "os/OsUtils.h"

#include <main/php_version.h>
#include <Zend/zend_API.h>
#include <Zend/zend_execute.h>
#include <Zend/zend_observer.h>

namespace opentelemetry::php {

#if PHP_VERSION_ID < 80100
void otel_observer_error_cb(int type, const char *error_filename, uint32_t error_lineno, zend_string *message) {
    std::string_view fileName = error_filename ? std::string_view{error_filename} : std::string_view{};
#else
void otel_observer_error_cb(int type, zend_string *error_filename, uint32_t error_lineno, zend_string *message) {
    std::string_view fileName = error_filename ? std::string_view{ZSTR_VAL(error_filename), ZSTR_LEN(error_filename)} : std::string_view{};
#endif
    std::string_view msg = message && ZSTR_VAL(message) ? std::string_view{ZSTR_VAL(message), ZSTR_LEN(message)} : std::string_view{};

    ELOGF_DEBUG(OTEL_G(globals)->logger_, HOOKS, "otel_observer_error_cb type: %d, fn: " PRsv ":%d, msg: " PRsv " ED: %p", type, PRsvArg(fileName), error_lineno, PRsvArg(msg), EG(current_execute_data));
    static bool errorHandling = false;
    if (errorHandling) {
        ELOGF_WARNING(OTEL_G(globals)->logger_, HOOKS, "otel_observer_error_cb detected error handler loop, skipping error handler");
        return;
    }

    // we're looking if function (inside which error was thrown) is instrumented - if yes, w're skipping default error instrumentation and letting post hook to handler error.
    if (EG(current_execute_data)) {
        auto hash = getClassAndFunctionHashFromExecuteData(EG(current_execute_data));

        if (hash) {
            if (OTEL_G(globals)->logger_ && OTEL_G(globals)->logger_->doesMeetsLevelCondition(LogLevel::logLevel_debug)) {
                auto [cls, fun] = getClassAndFunctionName(EG(current_execute_data));
                ELOGF_DEBUG(OTEL_G(globals)->logger_, HOOKS, "otel_observer_error_cb currentED: %p currentEXception: %p hash: 0x%X " PRsv "::" PRsv, EG(current_execute_data), EG(exception), hash, PRsvArg(cls), PRsvArg(fun));
            }

            auto callbacks = reinterpret_cast<InstrumentedFunctionHooksStorage_t *>(OTEL_GL(hooksStorage_).get())->find(hash);
            if (callbacks) {
                ELOGF_DEBUG(OTEL_G(globals)->logger_, HOOKS, "otel_observer_error_cb type: %d, fn: " PRsv ":%d, msg: " PRsv ". Skipping default error instrumentation because function is instrumented and error will be passed to posthook", type, PRsvArg(fileName), error_lineno, PRsvArg(msg));
                return;
            }
        } else {
            ELOGF_WARNING(OTEL_G(globals)->logger_, HOOKS, "otel_observer_error_cb currentED: %p currentEXception: %p func null, msg: " PRsv, EG(current_execute_data), EG(exception), PRsvArg(msg));
        }
    }
    errorHandling = true;
    OTEL_G(globals)->requestScope_->handleError(type, fileName, error_lineno, msg, static_cast<bool>(EG(current_execute_data)));
    errorHandling = false;
}

static void otel_interrupt_function(zend_execute_data *execute_data) {
    ELOGF_DEBUG(OTEL_G(globals)->logger_, HOOKS, "%s: interrupt", __FUNCTION__);

    OTEL_G(globals)->inferredSpans_->attachBacktraceIfInterrupted();

    zend_try {
        if (Hooking::getInstance().getOriginalZendInterruptFunction()) {
            Hooking::getInstance().getOriginalZendInterruptFunction()(execute_data);
        }
    }
    zend_catch {
        ELOGF_DEBUG(OTEL_G(globals)->logger_, HOOKS, "%s: original call error", __FUNCTION__);
    }
    zend_end_try();
}

static void otel_execute_internal(INTERNAL_FUNCTION_PARAMETERS) {
    zend_try {
        if (Hooking::getInstance().getOriginalExecuteInternal()) {
            Hooking::getInstance().getOriginalExecuteInternal()(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        } else {
            execute_internal(INTERNAL_FUNCTION_PARAM_PASSTHRU);
        }
    }
    zend_catch {
        ELOGF_DEBUG(OTEL_G(globals)->logger_, HOOKS, "%s: original call error", __FUNCTION__);
    }
    zend_end_try();

    OTEL_G(globals)->inferredSpans_->attachBacktraceIfInterrupted();
}

static zend_op_array *otel_compile_file(zend_file_handle *file_handle, int type) {
    std::string_view file = file_handle->opened_path ? std::string_view(ZSTR_VAL(file_handle->opened_path), ZSTR_LEN(file_handle->opened_path)) : std::string_view(ZSTR_VAL(file_handle->filename), ZSTR_LEN(file_handle->filename));

    if (OTEL_G(globals)->dependencyAutoLoaderGuard_->shouldDiscardFileCompilation(file)) {
        return nullptr;
    }

    zend_op_array *ret = nullptr;
    zend_try {
        if (Hooking::getInstance().getOriginalZendCompileFile()) {
            ret = Hooking::getInstance().getOriginalZendCompileFile()(file_handle, type);
        }
    }
    zend_catch {
        ELOGF_DEBUG(OTEL_G(globals)->logger_, HOOKS, "%s: original call error", __FUNCTION__);
        ret = nullptr;
    }
    zend_end_try();
    return ret;
}

void Hooking::replaceHooks(bool enableInferredSpansHooks, bool enableDepenecyAutoloaderGuard) {
    zend_observer_error_register(otel_observer_error_cb);

    if (enableInferredSpansHooks) {
        ELOGF_DEBUG(OTEL_G(globals)->logger_, HOOKS, "Hooked into zend_execute_internal and zend_interrupt_function");
        zend_execute_internal = otel_execute_internal;
        zend_interrupt_function = otel_interrupt_function;
    }

    if (enableDepenecyAutoloaderGuard) {
        ELOGF_DEBUG(OTEL_G(globals)->logger_, HOOKS, "Hooked into zend_compile_file, original ptr: %p, new ptr: %p", zend_compile_file, otel_compile_file);
        zend_compile_file = otel_compile_file;
    }
}

} // namespace opentelemetry::php
