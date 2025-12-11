#pragma once

#include <string>

namespace opentelemetry::osutils {
std::string getStackTrace(size_t numberOfFramesToSkip);
}
