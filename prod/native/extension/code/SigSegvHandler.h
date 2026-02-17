#pragma once

#include "LoggerInterface.h"

namespace opentelemetry::php {
void registerSigSegvHandler(opentelemetry::php::LoggerInterface *logger);
void unregisterSigSegvHandler();
} // namespace opentelemetry::php
