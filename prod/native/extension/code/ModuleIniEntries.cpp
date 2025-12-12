
#include "ModuleIniEntries.h"
#include "ConfigurationManager.h"
#include "ConfigurationSnapshot.h"
#include "CommonUtils.h"
#include "basic_macros.h"

#include <php.h>
#include <main/php_ini.h>
#include <main/SAPI.h>
#include <Zend/zend_ini.h>
#include <Zend/zend_types.h>
#include <Zend/zend_string.h>
#include <Zend/zend_hash.h>

#define OTEL_INI_ENTRY_IMPL(optName, isReloadableFlag) PHP_INI_ENTRY("opentelemetry_distro." optName, /* default value: */ NULL, isReloadableFlag, /* on_modify (validator): */ NULL)

#define OTEL_INI_ENTRY(optName) OTEL_INI_ENTRY_IMPL(optName, PHP_INI_ALL)

#define OTEL_NOT_RELOADABLE_INI_ENTRY(optName) OTEL_INI_ENTRY_IMPL(optName, PHP_INI_PERDIR)

PHP_INI_BEGIN() // expands to: static const zend_ini_entry_def ini_entries[] = {
OTEL_INI_ENTRY(STRINGIFY_HELPER(OTEL_PHP_BOOTSTRAP_PHP_PART_FILE))
OTEL_NOT_RELOADABLE_INI_ENTRY(STRINGIFY_HELPER(OTEL_PHP_ENABLED))
OTEL_INI_ENTRY(STRINGIFY_HELPER(OTEL_PHP_LOG_FILE))
OTEL_INI_ENTRY(STRINGIFY_HELPER(OTEL_PHP_LOG_LEVEL))
OTEL_INI_ENTRY(STRINGIFY_HELPER(OTEL_PHP_LOG_LEVEL_FILE))
OTEL_INI_ENTRY(STRINGIFY_HELPER(OTEL_PHP_LOG_LEVEL_STDERR))
OTEL_INI_ENTRY(STRINGIFY_HELPER(OTEL_PHP_LOG_LEVEL_SYSLOG))

OTEL_INI_ENTRY(STRINGIFY_HELPER(OTEL_PHP_DEBUG_DIAGNOSTICS_FILE))
OTEL_INI_ENTRY(STRINGIFY_HELPER(OTEL_PHP_MAX_SEND_QUEUE_SIZE))
OTEL_INI_ENTRY(STRINGIFY_HELPER(OTEL_PHP_ASYNC_TRANSPORT))
OTEL_INI_ENTRY(STRINGIFY_HELPER(OTEL_PHP_DEBUG_INSTRUMENT_ALL))
OTEL_INI_ENTRY(STRINGIFY_HELPER(OTEL_PHP_DEBUG_PHP_HOOKS_ENABLED))

OTEL_INI_ENTRY(STRINGIFY_HELPER(OTEL_PHP_INFERRED_SPANS_ENABLED))
OTEL_INI_ENTRY(STRINGIFY_HELPER(OTEL_PHP_INFERRED_SPANS_REDUCTION_ENABLED))
OTEL_INI_ENTRY(STRINGIFY_HELPER(OTEL_PHP_INFERRED_SPANS_STACKTRACE_ENABLED))
OTEL_INI_ENTRY(STRINGIFY_HELPER(OTEL_PHP_INFERRED_SPANS_SAMPLING_INTERVAL))
OTEL_INI_ENTRY(STRINGIFY_HELPER(OTEL_PHP_INFERRED_SPANS_MIN_DURATION))
PHP_INI_END()

namespace opentelemetry::php {
extern opentelemetry::php::ConfigurationManager configManager;


constexpr const zend_string *iniEntryValue(zend_ini_entry *iniEntry, int type) {
    return (type == ZEND_INI_DISPLAY_ORIG) ? (iniEntry->modified ? iniEntry->orig_value : iniEntry->value) : iniEntry->value;
}

void displaySecretIniValue(zend_ini_entry *iniEntry, int type) {
    auto value = iniEntryValue(iniEntry, type);
    const char *valueToPrint = value && ZSTR_LEN(value) ? "***" : "no value";

    php_printf(sapi_module.phpinfo_as_text ? "%s" : "<i>%s</i>", valueToPrint);
}

bool registerIniEntries(opentelemetry::php::LoggerInterface *log, int module_number) {
    if (zend_register_ini_entries(ini_entries, module_number) != ZEND_RESULT_CODE::SUCCESS) {
        return false;
    }

    // register custom displayer for secret options
    auto options = configManager.getOptionMetadata();
    for (auto const &option : options) {
        if (!option.second.secret) {
            continue;
        }

        auto iniName = opentelemetry::utils::getIniName(option.first);

        if (zend_ini_register_displayer(iniName.data(), iniName.length(), displaySecretIniValue) != ZEND_RESULT_CODE::SUCCESS) {
            ELOGF_WARNING(log, MODULE, "zend_ini_register_displayer() failed; iniName: " PRsv, PRsvArg(iniName));
        }

    }
    return true;
}

}