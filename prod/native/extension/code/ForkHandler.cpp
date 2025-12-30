#include "ForkHandler.h"

#ifndef _WINDOWS
#include <pthread.h>
#include <errno.h>

#include "os/OsUtils.h"
#include "LoggerInterface.h"
#include "ModuleGlobals.h"
#include "ForkableRegistry.h"

namespace opentelemetry::php {

static void beforeFork() {
    ELOGF_NF_DEBUG(OTEL_GL(logger_), "Before process fork (i.e., in parent context); its parent (i.e., grandparent) PID: %d", static_cast<int>(opentelemetry::osutils::getParentProcessId()));
    // TODO implement forkable registry
    if (OTEL_G(globals) && OTEL_G(globals)->forkableRegistry_) {
        OTEL_G(globals)->forkableRegistry_->preFork();
    }
}

static void afterForkInParent() {
    ELOGF_NF_DEBUG(OTEL_GL(logger_), "After process fork (in parent context)");
    if (OTEL_G(globals) && OTEL_G(globals)->forkableRegistry_) {
        OTEL_G(globals)->forkableRegistry_->postFork(false);
    }
}

static void afterForkInChild() {
    ELOGF_NF_DEBUG(OTEL_GL(logger_), "After process fork (in child context); parent PID: %d", static_cast<int>(opentelemetry::osutils::getParentProcessId()));
    if (OTEL_G(globals) && OTEL_G(globals)->forkableRegistry_) {
        OTEL_G(globals)->forkableRegistry_->postFork(true);
    }
}

void registerCallbacksToHandleFork() {
    int retVal = pthread_atfork(beforeFork, afterForkInParent, afterForkInChild);
    if (retVal == 0) {
        ELOGF_NF_DEBUG(OTEL_GL(logger_), "Registered callbacks to log process fork");
    } else {
        ELOGF_NF_WARNING(OTEL_GL(logger_), "Failed to register callbacks to log process fork; return value: %d", retVal);
    }
}

#else
void registerCallbacksToHandleFork() {
}
#endif
}
