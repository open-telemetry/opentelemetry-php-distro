#include "otel_distro_version.h"
#include "LoggerInterface.h"
#include "ModuleGlobals.h"
#include "CommonUtils.h"
#include "os/StackTraceCapture.h"


#include <signal.h>

namespace signalHandlerData {
typedef void (*OsSignalHandler)(int);
static OsSignalHandler oldSigSegvHandler = nullptr;
} // namespace signalHandlerData

void SigSegvHandler(int signalId) {
#ifdef __OTEL_LIBC_MUSL__
#define LIBC_IMPL "musl"
#else
#define LIBC_IMPL "glibc"
#endif

    if (OTEL_G(globals) && OTEL_G(globals)->logger_) {
        auto output = opentelemetry::osutils::getStackTrace(0);
        ELOGF_NF_CRITICAL(OTEL_G(globals)->logger_.get(), "Received signal %d. Agent version: " OTEL_DISTRO_VERSION " " LIBC_IMPL "\n%s", signalId, output.c_str());

        /* Call the default signal handler to have core dump generated... */
        if (signalHandlerData::oldSigSegvHandler) {
            signal(signalId, signalHandlerData::oldSigSegvHandler);
        } else {
            signal(signalId, SIG_DFL);
        }
        raise(signalId);
    }
}

void registerSigSegvHandler(opentelemetry::php::LoggerInterface * logger) {
    auto retval = signal(SIGSEGV, SigSegvHandler);
    if (retval == SIG_ERR) {
        ELOGF_NF_ERROR(logger, "Unable to set SIGSEGV handler. Errno: %d", errno);
        return;
    } else {
        signalHandlerData::oldSigSegvHandler = retval;
    }
}

void unregisterSigSegvHandler() {
    if (signalHandlerData::oldSigSegvHandler == SigSegvHandler) {
        signal(SIGSEGV, signalHandlerData::oldSigSegvHandler);
        signalHandlerData::oldSigSegvHandler = nullptr;
    }
}