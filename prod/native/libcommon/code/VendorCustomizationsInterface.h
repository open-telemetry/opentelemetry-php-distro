#pragma once

#include "config/OptionValueProviderInterface.h"

#include <string>
#include <memory>
#include <utility>

namespace opentelemetry::php {

/**
 * Interface that vendor plugins must implement to provide custom behavior.
 * Vendors can link their static library containing implementation of this interface.
 */
class VendorCustomizationsInterface {
public:
    virtual ~VendorCustomizationsInterface() = default;

    virtual std::string getVendorName() const = 0;
    virtual std::string getDistributionName() const = 0;
    virtual std::string getDistributionVersion() const = 0;
    virtual std::string getUserAgent() const = 0;

    virtual std::pair<int, std::shared_ptr<opentelemetry::php::config::OptionValueProviderInterface>>  getOptionValueProvider() = 0;
};

}

/**
 * Weak symbol declaration for vendor customizations factory function.
 * If vendor provides this symbol by linking their static library, it will be used.
 * If not provided, this will be nullptr and the system will work without vendor customizations.
 */
extern "C" {
    // Weak symbol - may or may not be provided by vendor's static library
    __attribute__((weak)) std::shared_ptr<opentelemetry::php::VendorCustomizationsInterface> getVendorCustomizations();
}
