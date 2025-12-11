
#pragma once

#include "PhpBridgeInterface.h"

namespace opentelemetry::utils {

void storeDiagnosticInformation(std::string_view outputFileName, opentelemetry::php::PhpBridgeInterface const &bridge); //throws

}
