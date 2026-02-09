#pragma once

#include "OptionValueProviderInterface.h"

#include <functional>

namespace opentelemetry::php::config {

class OptionValueProvider : public OptionValueProviderInterface {
public:
    OptionValueProvider(std::function<std::optional<std::string>(std::string_view)> readIniValue) : readIniValue_(readIniValue) {
    }

    std::optional<std::string> getEnvironmentOptionValue(std::string_view name) override;
    std::optional<std::string> getIniOptionValue(std::string_view name) override;
    std::optional<std::string> getDynamicOptionValue(std::string_view name) override;

    void update(configFiles_t const &configFiles) override {
        // No dynamic options in default provider, so nothing to update
    }

private:
    std::function<std::optional<std::string>(std::string_view)> readIniValue_;
};

}