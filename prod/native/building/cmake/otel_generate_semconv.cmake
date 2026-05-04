function(otel_generate_semconv SEMCONV_VERSION WEAVER_VERSION TEMPLATES_PATH INCLUDE_DIR_OUT)
    set(WORK_DIR "${CMAKE_BINARY_DIR}/_deps/semconv-generate")
    set(SEMCONV_REPO_DIR "${WORK_DIR}/semantic-conventions-${SEMCONV_VERSION}")
    set(WEAVER_DIR "${WORK_DIR}/weaver-${WEAVER_VERSION}")
    # Mirror the source tree layout so #include <opentelemetry/semconv/...> works
    set(GENERATED_INCLUDE_DIR "${WORK_DIR}/include-${SEMCONV_VERSION}")
    set(GENERATED_OUTPUT_DIR "${GENERATED_INCLUDE_DIR}/opentelemetry/semconv")

    file(MAKE_DIRECTORY "${WORK_DIR}")
    file(MAKE_DIRECTORY "${GENERATED_OUTPUT_DIR}")

    # --- Determine weaver binary variant for current platform ---

    if(CMAKE_SYSTEM_PROCESSOR STREQUAL "x86_64")
        set(WEAVER_ARCH "x86_64")
    elseif(CMAKE_SYSTEM_PROCESSOR STREQUAL "aarch64")
        set(WEAVER_ARCH "aarch64")
    else()
        message(FATAL_ERROR "otel_generate_semconv: unsupported architecture: ${CMAKE_SYSTEM_PROCESSOR}")
    endif()

    # Always use the musl variant — it is statically linked and portable
    # across all Linux distributions regardless of the system glibc version.
    set(WEAVER_ARCHIVE_NAME "weaver-${WEAVER_ARCH}-unknown-linux-musl.tar.xz")
    set(WEAVER_URL "https://github.com/open-telemetry/weaver/releases/download/v${WEAVER_VERSION}/${WEAVER_ARCHIVE_NAME}")
    set(WEAVER_ARCHIVE_PATH "${WEAVER_DIR}/${WEAVER_ARCHIVE_NAME}")
    set(WEAVER_BIN "${WEAVER_DIR}/weaver")

    # --- Download and extract weaver ---

    if(NOT EXISTS "${WEAVER_BIN}")
        file(MAKE_DIRECTORY "${WEAVER_DIR}")

        message(STATUS "Downloading weaver v${WEAVER_VERSION} (${WEAVER_ARCHIVE_NAME})...")
        file(DOWNLOAD
            "${WEAVER_URL}"
            "${WEAVER_ARCHIVE_PATH}"
            STATUS DOWNLOAD_STATUS
            TLS_VERIFY ON
        )
        list(GET DOWNLOAD_STATUS 0 DOWNLOAD_ERROR_CODE)
        list(GET DOWNLOAD_STATUS 1 DOWNLOAD_ERROR_MSG)
        if(NOT DOWNLOAD_ERROR_CODE EQUAL 0)
            message(FATAL_ERROR "Failed to download weaver: ${DOWNLOAD_ERROR_MSG}\nURL: ${WEAVER_URL}")
        endif()

        message(STATUS "Extracting weaver...")
        execute_process(
            COMMAND tar xf "${WEAVER_ARCHIVE_PATH}" -C "${WEAVER_DIR}"
            RESULT_VARIABLE TAR_RESULT
        )
        if(NOT TAR_RESULT EQUAL 0)
            message(FATAL_ERROR "Failed to extract weaver archive")
        endif()

        # The archive contains weaver binary directly or in a subdirectory.
        # Find it and ensure it's at the expected path.
        if(NOT EXISTS "${WEAVER_BIN}")
            file(GLOB_RECURSE WEAVER_FOUND "${WEAVER_DIR}/weaver")
            if(WEAVER_FOUND)
                list(GET WEAVER_FOUND 0 WEAVER_FOUND_PATH)
                file(COPY "${WEAVER_FOUND_PATH}" DESTINATION "${WEAVER_DIR}")
            else()
                message(FATAL_ERROR "Could not find weaver binary after extraction in ${WEAVER_DIR}")
            endif()
        endif()

        file(CHMOD "${WEAVER_BIN}" PERMISSIONS OWNER_READ OWNER_WRITE OWNER_EXECUTE)
    endif()

    # --- Clone semantic-conventions (sparse checkout, model/ only) ---

    if(NOT EXISTS "${SEMCONV_REPO_DIR}/model")
        message(STATUS "Cloning semantic-conventions v${SEMCONV_VERSION} (sparse: model/)...")

        file(REMOVE_RECURSE "${SEMCONV_REPO_DIR}")

        execute_process(
            COMMAND git init "${SEMCONV_REPO_DIR}"
            RESULT_VARIABLE GIT_RESULT
        )
        if(NOT GIT_RESULT EQUAL 0)
            message(FATAL_ERROR "Failed to git init for semantic-conventions")
        endif()

        execute_process(
            COMMAND git -C "${SEMCONV_REPO_DIR}" remote add origin https://github.com/open-telemetry/semantic-conventions.git
            RESULT_VARIABLE GIT_RESULT
        )
        if(NOT GIT_RESULT EQUAL 0)
            message(FATAL_ERROR "Failed to add semantic-conventions remote")
        endif()

        execute_process(
            COMMAND git -C "${SEMCONV_REPO_DIR}" config core.sparseCheckout true
            RESULT_VARIABLE GIT_RESULT
        )
        if(NOT GIT_RESULT EQUAL 0)
            message(FATAL_ERROR "Failed to configure sparse checkout")
        endif()

        file(WRITE "${SEMCONV_REPO_DIR}/.git/info/sparse-checkout" "model\n")

        execute_process(
            COMMAND git -C "${SEMCONV_REPO_DIR}" pull --depth 1 origin "v${SEMCONV_VERSION}"
            RESULT_VARIABLE GIT_RESULT
        )
        if(NOT GIT_RESULT EQUAL 0)
            message(FATAL_ERROR "Failed to pull semantic-conventions v${SEMCONV_VERSION}")
        endif()
    endif()

    # --- Run weaver to generate headers ---

    message(STATUS "Running weaver registry generate...")
    execute_process(
        COMMAND "${WEAVER_BIN}" registry generate
            --registry=${SEMCONV_REPO_DIR}
            --templates=${TEMPLATES_PATH}
            ./
            ${GENERATED_OUTPUT_DIR}/./
            --param filter=all
            --param output=./
            --param schema_url=https://opentelemetry.io/schemas/v${SEMCONV_VERSION}
        RESULT_VARIABLE WEAVER_RESULT
        OUTPUT_VARIABLE WEAVER_OUTPUT
        ERROR_VARIABLE WEAVER_ERROR
    )
    if(NOT WEAVER_RESULT EQUAL 0)
        message(FATAL_ERROR "weaver registry generate failed:\n${WEAVER_OUTPUT}\n${WEAVER_ERROR}")
    endif()

    # --- Verify generation produced headers ---

    file(GLOB GENERATED_HEADERS "${GENERATED_OUTPUT_DIR}/*.h")
    if(NOT GENERATED_HEADERS)
        message(FATAL_ERROR "weaver produced no .h files in ${GENERATED_OUTPUT_DIR}")
    endif()

    list(LENGTH GENERATED_HEADERS HEADER_COUNT)
    message(STATUS "Semconv generation complete: ${HEADER_COUNT} headers in ${GENERATED_OUTPUT_DIR}")

    # Export the include base directory so the caller can add it to include paths
    set(${INCLUDE_DIR_OUT} "${GENERATED_INCLUDE_DIR}" PARENT_SCOPE)
endfunction()
