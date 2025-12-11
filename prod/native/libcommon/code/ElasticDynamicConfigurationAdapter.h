#pragma once

#include "LoggerInterface.h"
#include <memory>
#include <optional>
#include <string>
#include <string_view>
#include <unordered_map>

#include <iostream>

namespace opentelemetry::php::config {

using namespace std::literals;

std::unordered_map<std::string, std::string> parseJsonConfigFile(const std::string &jsonStr); // throws

class ElasticDynamicConfigurationAdapter {
public:
    using configFiles_t = std::unordered_map<std::string, std::string>; // filename->content
    using optionsMap_t = std::unordered_map<std::string, std::string>;  // optname->value

    ElasticDynamicConfigurationAdapter(std::shared_ptr<opentelemetry::php::LoggerInterface> logger) : logger_(std::move(logger)) {
    }

    void update(configFiles_t const &files);

    std::optional<std::string> getOption(std::string const &optionName) const {
        auto found = options_.find(optionName);
        if (found != options_.end()) {
            return found->second;
        }
        return std::nullopt;
    }

protected:
    optionsMap_t remapOptions(optionsMap_t remoteOptions) const;
    std::unordered_map<std::string, std::string> parseJsonConfigFile(const std::string &jsonStr) const;

private:
    optionsMap_t options_;
    std::shared_ptr<opentelemetry::php::LoggerInterface> logger_;
};

} // namespace opentelemetry::php::config