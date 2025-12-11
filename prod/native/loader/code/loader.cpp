#include "phpdetection.h"

#include <dlfcn.h>
#include <stddef.h>
#include <stdio.h>
#include <syslog.h>
#include <unistd.h>
#include <sys/syscall.h>
#include <filesystem>


#include "otel_distro_version.h"

#define LOG_TO_SYSLOG_AND_STDERR(fmt, ... ) \
    do { \
        fprintf(stderr, \
            "[otel_distro_loader][PID: %d]" \
            "[TID: %d] " \
            fmt \
            ,(int)getpid() \
            ,(int)syscall( SYS_gettid ) \
            , ##__VA_ARGS__ ); \
        syslog( \
            LOG_WARNING, \
            "[otel_distro_loader][PID: %d]" \
            "[TID: %d] " \
            fmt \
            , (int)(getpid()) \
            , (int)(syscall( SYS_gettid )) \
            , ##__VA_ARGS__ ); \
    } \
    while(0);

namespace opentelemetry::loader {

namespace phpdata {

#define INIT_FUNC_ARGS		int type, int module_number
#define INIT_FUNC_ARGS_PASSTHRU	type, module_number
#define SHUTDOWN_FUNC_ARGS	int type, int module_number
#define SHUTDOWN_FUNC_ARGS_PASSTHRU type, module_number
#define ZEND_MODULE_INFO_FUNC_ARGS zend_module_entry *zend_module
#define ZEND_MODULE_INFO_FUNC_ARGS_PASSTHRU zend_module

struct zend_module_entry {
    unsigned short size;
    unsigned int zend_api;
    unsigned char zend_debug;
    unsigned char zts;
    const void *ini_entry;
    const void *deps;
    const char *name;
    const void *functions;
    int (*module_startup_func)(INIT_FUNC_ARGS);
    int (*module_shutdown_func)(SHUTDOWN_FUNC_ARGS);
    int (*request_startup_func)(INIT_FUNC_ARGS);
    int (*request_shutdown_func)(SHUTDOWN_FUNC_ARGS);
    void (*info_func)(ZEND_MODULE_INFO_FUNC_ARGS);
    const char *version;
    size_t globals_size;
#ifdef ZTS
    ts_rsrc_id* globals_id_ptr;
#else
    void* globals_ptr;
#endif
    void (*globals_ctor)(void *global);
    void (*globals_dtor)(void *global);
    int (*post_deactivate_func)(void);
    int module_started;
    unsigned char type;
    void *handle;
    int module_number;
    const char *build_id;
};

}

}

opentelemetry::loader::phpdata::zend_module_entry otel_distro_loader_module_entry = {
    sizeof(opentelemetry::loader::phpdata::zend_module_entry),
    0, // API, f.ex.20220829
    0, // DEBUG
    0, // USING_ZTS
    nullptr,
    nullptr,
    "otel_distro_loader",      /* Extension name */
    nullptr,                        /* zend_function_entry */
    nullptr,                        /* PHP_MINIT - Module initialization */
    nullptr,                        /* PHP_MSHUTDOWN - Module shutdown */
    nullptr,                        /* PHP_RINIT - Request initialization */
    nullptr,                        /* PHP_RSHUTDOWN - Request shutdown */
    nullptr,                        /* PHP_MINFO - Module info */
    OTEL_DISTRO_VERSION,        /* Version */
    0,                              /* globals_size */
    nullptr,                        /* globals ptr */
    nullptr,                        /* PHP_GINIT */
    nullptr,                        /* PHP_GSHUTDOWN */
    nullptr,                        /* post deactivate */
     0,
    0,
    nullptr,
    0,
    "" // API20220829,NTS ..
};

extern "C" {

__attribute__ ((visibility("default"))) opentelemetry::loader::phpdata::zend_module_entry *get_module(void) {
    using namespace std::string_view_literals;
    using namespace std::string_literals;

    auto zendVersion = opentelemetry::loader::getMajorMinorZendVersion();
    if (zendVersion.empty()) {
        LOG_TO_SYSLOG_AND_STDERR( "Can't find Zend/PHP Engine version\n");
        return &otel_distro_loader_module_entry;
    }

    auto [zendEngineVersion, phpVersion, zendModuleApiVersion, isVersionSupported] = opentelemetry::loader::getZendModuleApiVersion(zendVersion);

    bool isThreadSafe = opentelemetry::loader::isThreadSafe();

    static std::string zendBuildId{"API"s};
    zendBuildId.append(std::to_string(zendModuleApiVersion));
    zendBuildId.append(isThreadSafe ? ",TS"sv : ",NTS"sv);

    otel_distro_loader_module_entry.zend_api = zendModuleApiVersion;
    otel_distro_loader_module_entry.build_id = zendBuildId.c_str();
    otel_distro_loader_module_entry.zts = isThreadSafe;

    if (!isVersionSupported) {
        LOG_TO_SYSLOG_AND_STDERR("Zend Engine version %s is not supported by OpenTelemetry PHP distribution\n", std::string(zendVersion).c_str());
        return &otel_distro_loader_module_entry;
    }

    if (isThreadSafe) {
        LOG_TO_SYSLOG_AND_STDERR("Thread Safe mode (ZTS) is not supported by OpenTelemetry PHP distribution\n");
        return &otel_distro_loader_module_entry; // unsupported thread safe mode
    }

    // get path to libraries
    Dl_info dl_info;
    dladdr((void *)get_module, &dl_info);
    if (!dl_info.dli_fname) {
        LOG_TO_SYSLOG_AND_STDERR("Unable to resolve path to OpenTelemetry PHP distribution libraries\n");
        return &otel_distro_loader_module_entry;
    }

    auto loaderLibraryPath = std::filesystem::path(dl_info.dli_fname).parent_path();

    auto agentLibrary = (loaderLibraryPath/"opentelemetry_php_distro_"sv);
    agentLibrary += std::to_string(phpVersion);
    agentLibrary += ".so"sv;

    void *agentHandle = dlopen(agentLibrary.c_str(), RTLD_LAZY | RTLD_GLOBAL);
    if (!agentHandle) {
        LOG_TO_SYSLOG_AND_STDERR( "Unable to load agent library from path: %s\n", agentLibrary.c_str());
        return &otel_distro_loader_module_entry;
    }

    auto agentGetModule = reinterpret_cast<opentelemetry::loader::phpdata::zend_module_entry *(*)(void)>(dlsym(agentHandle, "get_module"));
    if (!agentGetModule) {
        LOG_TO_SYSLOG_AND_STDERR( "Unable to resolve agent entry point from library: %s\n", agentLibrary.c_str());
        return &otel_distro_loader_module_entry;
    }

    return agentGetModule(); // or we can call zend_register_module_ex(agentGetModule())) and have both fully loaded
}


}
