#pragma once

#include "OptionValueProviderInterface.h"

#include <algorithm>
#include <memory>
#include <vector>

namespace opentelemetry::php::config {

class PrioritizedOptionValueProviderChain : public OptionValueProviderInterface {
public:
    PrioritizedOptionValueProviderChain(std::initializer_list<std::pair<int32_t, std::shared_ptr<OptionValueProviderInterface>>> providers) {
        for (const auto& provider : providers) {
            if (provider.second) {
                providers_.emplace_back(provider);
            }
        }
        std::sort(providers_.begin(), providers_.end(), [](const auto& a, const auto& b) {
            return a.first > b.first;
        });
    }

    std::optional<std::string> getEnvironmentOptionValue(std::string_view name) override {
        for (const auto& provider : providers_) {
            auto value = provider.second->getEnvironmentOptionValue(name);
            if (value.has_value()) {
                return value;
            }
        }
        return std::nullopt;
    }

    std::optional<std::string> getIniOptionValue(std::string_view name) override {
        for (const auto& provider : providers_) {
            auto value = provider.second->getIniOptionValue(name);
            if (value.has_value()) {
                return value;
            }
        }
        return std::nullopt;
    }

    std::optional<std::string> getDynamicOptionValue(std::string_view name) override {
        for (const auto& provider : providers_) {
            auto value = provider.second->getDynamicOptionValue(name);
            if (value.has_value()) {
                return value;
            }
        }
        return std::nullopt;
    }

    void update(configFiles_t const &configFiles) override {
        for (const auto& provider : providers_) {
            provider.second->update(configFiles);
        }
    }

private:
    std::vector<std::pair<int32_t, std::shared_ptr<OptionValueProviderInterface>>> providers_;
};

}
