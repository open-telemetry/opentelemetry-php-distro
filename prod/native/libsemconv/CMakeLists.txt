set (_Target  semconv)


set(EXPECTED_VERSION "${_PROJECT_PROPERTIES_OTEL_SEMCONV_VERSION}")
set(EXPECTED_WEAVER "${_PROJECT_PROPERTIES_OTEL_WEAVER_VERSION}")
set(TEMPLATES_PATH "${CMAKE_CURRENT_SOURCE_DIR}/templates")
set(SEMCONV_INCLUDE_DIR "${CMAKE_BINARY_DIR}/_deps/semconv-generate/include-${EXPECTED_VERSION}")
set(GENERATED_SCHEMA_HEADER "${SEMCONV_INCLUDE_DIR}/opentelemetry/semconv/schema_url.h")

# Check if weaver version changed since last generation
set(WEAVER_STAMP_FILE "${CMAKE_BINARY_DIR}/_deps/semconv-generate/weaver_version.stamp")
set(WEAVER_CHANGED FALSE)
if(EXISTS "${WEAVER_STAMP_FILE}")
    file(READ "${WEAVER_STAMP_FILE}" STAMPED_WEAVER_VERSION)
    string(STRIP "${STAMPED_WEAVER_VERSION}" STAMPED_WEAVER_VERSION)
    if(NOT STAMPED_WEAVER_VERSION STREQUAL EXPECTED_WEAVER)
        set(WEAVER_CHANGED TRUE)
    endif()
endif()

if(NOT EXISTS "${GENERATED_SCHEMA_HEADER}" OR WEAVER_CHANGED)
    if(WEAVER_CHANGED)
        message(STATUS "Weaver version changed: ${STAMPED_WEAVER_VERSION} -> ${EXPECTED_WEAVER}. Regenerating...")
    else()
        message(STATUS "Semconv headers not found in build tree. Generating v${EXPECTED_VERSION}...")
    endif()
    include(otel_generate_semconv)
    otel_generate_semconv(
        "${EXPECTED_VERSION}"
        "${EXPECTED_WEAVER}"
        "${TEMPLATES_PATH}"
        SEMCONV_INCLUDE_DIR
    )
endif()

# Always record current weaver version so changes are detected on next configure
file(MAKE_DIRECTORY "${CMAKE_BINARY_DIR}/_deps/semconv-generate")
file(WRITE "${WEAVER_STAMP_FILE}" "${EXPECTED_WEAVER}")

add_library (${_Target} INTERFACE)

target_compile_definitions(${_Target} INTERFACE OPENTELEMETRY_DEPRECATED=/*deprecated*/)



target_include_directories(${_Target}
    INTERFACE
        $<BUILD_INTERFACE:${SEMCONV_INCLUDE_DIR}>
)
