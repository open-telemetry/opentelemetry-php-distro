#pragma once

#include <algorithm>
#include <array>
#include <tuple>
#include <string>
#include <string_view>

namespace opentelemetry::loader {

// Pure helpers, declared here so they can be unit tested. These carry the parsing and the table
// lookup that decide which extension the loader dispatches to. The functions below them read the
// process's dynamic symbol table and so are not pure.
std::string_view getMajorMinorVersion(std::string_view version);
std::string_view getZendVersion(std::string_view zendVersion);

std::string_view getMajorMinorZendVersion();
std::tuple<std::string_view, int, int, bool> getZendModuleApiVersion(std::string_view zendVersion);
bool isThreadSafe();

}