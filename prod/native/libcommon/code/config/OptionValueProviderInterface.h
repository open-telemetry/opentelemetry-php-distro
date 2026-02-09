#pragma once

#include <optional>
#include <string>
#include <string_view>
#include <unordered_map>

namespace opentelemetry::php::config {

class OptionValueProviderInterface {
public:
    using configFiles_t = std::unordered_map<std::string, std::string>; // filename->content

    virtual ~OptionValueProviderInterface() = default;
    virtual std::optional<std::string> getEnvironmentOptionValue(std::string_view name) = 0;
    virtual std::optional<std::string> getIniOptionValue(std::string_view name) = 0;
    virtual std::optional<std::string> getDynamicOptionValue(std::string_view name) = 0;

    // This method will be called when new config files are received from coordinator, so that provider can update its internal state if needed - before any of the get*OptionValue methods are called with new config values
    virtual void update(configFiles_t const &configFiles) = 0;
};

}