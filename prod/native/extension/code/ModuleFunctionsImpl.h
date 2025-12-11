#pragma once

#include <zend_types.h>
#include <string_view>

namespace opentelemetry::php {

void getConfigOptionbyName(std::string_view optionName, zval* return_value);

}
