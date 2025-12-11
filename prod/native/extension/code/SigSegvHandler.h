#pragma once

#include "LoggerInterface.h"

void registerSigSegvHandler(opentelemetry::php::LoggerInterface *logger);
void unregisterSigSegvHandler();