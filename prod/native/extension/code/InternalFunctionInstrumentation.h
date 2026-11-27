#pragma once

#include "AutoZval.h"
#include "LoggerInterface.h"
#include "AttrHooksStorage.h"
#include "InstrumentedFunctionHooksStorage.h"
#include <string_view>
#include <Zend/zend_observer.h>

namespace opentelemetry::php {

// Everything that is registered for functions of a request, dropped when the request ends.
class InstrumentedFunctionHooksStorage_t : public InstrumentedFunctionHooksStorage<zend_ulong, AutoZval> {
public:
    void clear() override {
        InstrumentedFunctionHooksStorage<zend_ulong, AutoZval>::clear();
        AttrHooksStorage::getInstance().clear();
    }
};

bool instrumentFunction(LoggerInterface *log, std::string_view className, std::string_view functionName, zval *callableOnEntry, zval *callableOnExit);
zend_observer_fcall_handlers registerObserverHandlers(zend_execute_data *execute_data);


}
