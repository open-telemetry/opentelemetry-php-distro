#include "PhpBridge.h"

#include <Zend/zend_portability.h>
#include <Zend/zend_compile.h>

namespace opentelemetry::php {

void PhpBridge::enableAccessToServerGlobal() const {
    zend_is_auto_global_str(ZEND_STRL("_SERVER"));
}

}