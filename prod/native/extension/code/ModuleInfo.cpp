
#include "ConfigurationManager.h"
#include "ConfigurationStorage.h"
#include "ModuleGlobals.h"

#include <php.h>
#include <ext/standard/info.h>

#include "otel_distro_version.h"

namespace opentelemetry::php {
extern opentelemetry::php::ConfigurationManager configManager;
}

void printPhpInfo(zend_module_entry *zend_module) {


    php_info_print_table_start();
    php_info_print_table_header( 1, OTEL_DISTRO_PRODUCT_NAME);
    php_info_print_table_end();

    php_info_print_table_colspan_header(2, "Effective configuration");
    php_info_print_table_start();
    php_info_print_table_header(2, "Configuration option", "Value");

    auto const &options = opentelemetry::php::configManager.getOptionMetadata();
    for (auto const &option : options) {
        auto value = opentelemetry::php::ConfigurationManager::accessOptionStringValueByMetadata(option.second, OTEL_GL(config_)->get());
        php_info_print_table_row(2, option.first.c_str(), option.second.secret ? "***" : value.c_str());
    }
    php_info_print_table_end();

    php_info_print_table_colspan_header(2, "INI configuration");
    display_ini_entries(zend_module);
}