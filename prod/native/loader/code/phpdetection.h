#pragma once

#include <algorithm>
#include <array>
#include <tuple>
#include <string>
#include <string_view>

namespace opentelemetry::loader {

std::string_view getMajorMinorZendVersion();
std::tuple<std::string_view, int, int, bool> getZendModuleApiVersion(std::string_view zendVersion);
bool isThreadSafe();

}