
# The detection logic is built as its own static library so that it can be linked into the unit
# tests as well as into the loader. It is position independent because the loader is a shared
# library.
set(_DetectionTarget loader_phpdetection)

add_library(${_DetectionTarget} STATIC phpdetection.cpp)

set_target_properties(${_DetectionTarget}
    PROPERTIES POSITION_INDEPENDENT_CODE ON)

target_include_directories(${_DetectionTarget}
    PUBLIC ${CMAKE_CURRENT_SOURCE_DIR})

set (_Target opentelemetry_php_distro_loader)

add_library (${_Target} SHARED loader.cpp)

target_link_libraries(${_Target}
    PRIVATE common
    PRIVATE ${_DetectionTarget}
)

set_target_properties(${_Target}
    PROPERTIES OUTPUT_NAME opentelemetry_php_distro_loader
    PREFIX ""
    DEBUG_SYMBOL_FILE "opentelemetry_php_distro_loader.debug"
)

if (RELEASE_BUILD)
    copy_debug_symbols(${_Target})
endif()
