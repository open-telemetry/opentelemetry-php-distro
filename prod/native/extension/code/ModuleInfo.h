#pragma once

#include <Zend/zend_modules.h>

namespace opentelemetry::php {

void printPhpInfo(zend_module_entry *zend_module);

}