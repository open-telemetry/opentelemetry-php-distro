#pragma once

#include "LoggerInterface.h"

namespace opentelemetry::php {
bool registerIniEntries(opentelemetry::php::LoggerInterface *log, int module_number);
}