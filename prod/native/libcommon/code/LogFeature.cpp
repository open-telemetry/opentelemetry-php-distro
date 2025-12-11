#include "LogFeature.h"
#include <magic_enum.hpp>
#include <stdexcept>
#include <string>
#include <string_view>

namespace opentelemetry::php {

[[nodiscard]] LogFeature parseLogFeature(std::string_view featureName) {
    using namespace std::string_literals;
    auto feature = magic_enum::enum_cast<LogFeature>(featureName, magic_enum::case_insensitive);
    if (!feature.has_value()) {
        throw std::invalid_argument("Unknown log feature: "s + std::string(featureName));
    }
    return feature.value();
}

[[nodiscard]] std::string_view getLogFeatureName(LogFeature feature) {
    return magic_enum::enum_name(feature);
}

} // namespace opentelemetry::php
