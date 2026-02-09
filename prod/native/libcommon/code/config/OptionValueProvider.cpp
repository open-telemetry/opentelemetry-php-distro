#include "OptionValueProvider.h"

#include <cstdlib>
#include <optional>
#include <string_view>

namespace opentelemetry::php::config {

std::optional<std::string> OptionValueProvider::getEnvironmentOptionValue(std::string_view name) {
    auto envValue = std::getenv(std::string(name).c_str());
    if (!envValue) {
        return std::nullopt;
    }
    return envValue;
}

std::optional<std::string> OptionValueProvider::getIniOptionValue(std::string_view name) {
    return readIniValue_(name);
}

std::optional<std::string> OptionValueProvider::getDynamicOptionValue(std::string_view name) {
    return std::nullopt; // No dynamic options in default provider
}

} // namespace opentelemetry::php::config
