#!/usr/bin/env bash
set -e -u -o pipefail
#set -x

SKIP_NOTICE=false
SKIP_VERIFY=false
DEV_ONLY_RESCOPE_DISTRO=false
current_user_id="$(id -u)"
current_user_group_id="$(id -g)"

show_help() {
    echo "Usage: $0 --php_versions <versions>"
    echo
    echo "Arguments:"
    echo "  --php_versions             Required. List of PHP versions separated by spaces (e.g., '81 82 83 84 85')."
    echo "  --skip_notice              Optional. Skip notice file generator. Default: false (i.e., NOTICE file is generated)."
    echo "  --skip_verify              Optional. Skip verify step. Default: false (i.e., verify step is executed)."
    echo "  --dev_only_rescope_distro  Optional. Only re-scope distro code (prod/php/OpenTelemetry). Skips composer install and notice."
    echo
    echo "Example:"
    echo "  $0 --php_versions '81 82 83 84 85' --skip_notice"
    echo "  $0 --php_versions '84' --dev_only_rescope_distro"
}

# Function to parse arguments
parse_args() {
    while [[ "$#" -gt 0 ]]; do
        case $1 in
        --php_versions)
            # SC2206: Quote to prevent word splitting/globbing, or split robustly with mapfile or read -a.
            # shellcheck disable=SC2206
            PHP_VERSIONS_WITHOUT_DOT=($2)
            shift
            ;;
        --skip_notice)
            SKIP_NOTICE=true
            ;;
        --skip_verify)
            SKIP_VERIFY=true
            ;;
        --dev_only_rescope_distro)
            DEV_ONLY_RESCOPE_DISTRO=true
            ;;
        --help)
            show_help
            exit 0
            ;;
        *)
            echo "Unknown parameter passed: $1"
            show_help
            exit 1
            ;;
        esac
        shift
    done
}

verify_otel_proto_version() {
    local -r _VENDOR_DIR="${1:?}"
    local -r _OTEL_PROTO_VERSION="${2:?}"

    local gen_otlp_protobuf_version_file_path="${_VENDOR_DIR}/open-telemetry/gen-otlp-protobuf/VERSION"
    if [ ! -f "${gen_otlp_protobuf_version_file_path}" ]; then
        echo "File ${gen_otlp_protobuf_version_file_path} does not exist"
        return 1
    fi

    local otel_proto_version_in_gen_otlp_protobuf
    otel_proto_version_in_gen_otlp_protobuf="$(cat "${gen_otlp_protobuf_version_file_path}")"

    if [ "${_OTEL_PROTO_VERSION}" != "${otel_proto_version_in_gen_otlp_protobuf}" ]; then
        echo "Versions in project.properties and ${gen_otlp_protobuf_version_file_path} are different"
        echo "Version in project.properties: ${_OTEL_PROTO_VERSION}"
        echo "Version in ${gen_otlp_protobuf_version_file_path}: ${otel_proto_version_in_gen_otlp_protobuf}"
        echo "To fix it change otel_proto_version in project.properties to ${otel_proto_version_in_gen_otlp_protobuf}"
        return 1
    fi
}

verify_otlp_exporters() {
    local -r _PHP_VERSION_WITHOUT_DOT="${1:?}"
    local -r _NOT_SCOPED_VENDOR_DIR="${2:?}"
    local -r _NATIVE_OTLP_EXPORTERS_PROTO_VERSION="${3:?}"

    local -r _PHP_IMPL_PACKAGE_NAME="open-telemetry/exporter-otlp"

    local PHP_docker_image
    PHP_docker_image=$(build_light_PHP_docker_image_name_for_version_no_dot "${_PHP_VERSION_WITHOUT_DOT}")

    local has_compared_the_same=""
    docker run --rm \
        -v "${_NOT_SCOPED_VENDOR_DIR}:/new_vendor:ro" \
        -w / \
        "${PHP_docker_image}" \
        sh -c \
        "\
            mkdir /used_as_base && cd /used_as_base \
            && apk update && apk add bash git \
            && curl -sS https://getcomposer.org/installer | php -- --filename=composer --install-dir=/usr/local/bin \
            && composer require ${_PHP_IMPL_PACKAGE_NAME}:${_NATIVE_OTLP_EXPORTERS_PROTO_VERSION:?} \
            && composer --no-dev install \
            && diff -r /used_as_base/vendor/${_PHP_IMPL_PACKAGE_NAME} /new_vendor/${_PHP_IMPL_PACKAGE_NAME} \
        " ||
        has_compared_the_same="false"

    if [ "${has_compared_the_same}" = "false" ]; then
        echo "${_NOT_SCOPED_VENDOR_DIR}/${_PHP_IMPL_PACKAGE_NAME} content differs from the base"
        echo "It means that PHP implementation of OTLP exporter (i.e., ${_PHP_IMPL_PACKAGE_NAME}) in composer.json differs from the version (which is ${_NATIVE_OTLP_EXPORTERS_PROTO_VERSION:?}) used as the base for the native implementation"
        echo "1) If the changes require it make sure native implementation is updated"
        echo "2) Set native_otlp_exporters_based_on_php_impl_version in project.properties to the version of ${_PHP_IMPL_PACKAGE_NAME} in composer.json"
        return 1
    fi
}

verify_vendor_dir() {
    local -r _PHP_VERSION_WITHOUT_DOT="${1:?}"
    local -r _NOT_SCOPED_VENDOR_DIR="${2:?}"
    local -r _VENDOR_DIR="${3:?}"
    local -r _OTEL_PROTO_VERSION="${4:?}"
    local -r _NATIVE_OTLP_EXPORTERS_PROTO_VERSION="${5:?}"

    verify_otel_proto_version "${_VENDOR_DIR}" "${_OTEL_PROTO_VERSION}"
    verify_otlp_exporters "${_PHP_VERSION_WITHOUT_DOT}" "${_NOT_SCOPED_VENDOR_DIR}" "${_NATIVE_OTLP_EXPORTERS_PROTO_VERSION:?}"
}

function on_script_exit() {
    if [ -n "${_BUILD_PHP_CODE_FOR_PACKAGES_TEMP_DIR+x}" ] && [ -d "${_BUILD_PHP_CODE_FOR_PACKAGES_TEMP_DIR}" ]; then
        delete_temp_dir "${_BUILD_PHP_CODE_FOR_PACKAGES_TEMP_DIR}"
    fi
}

main() {
    this_script_dir="$(dirname "${BASH_SOURCE[0]}")"
    this_script_dir="$(realpath "${this_script_dir}")"

    repo_root_dir="$(realpath "${PWD}")"
    source "${repo_root_dir}/tools/shared.sh"

    source "${repo_root_dir}/tools/helpers/array_helpers.sh"
    source "${repo_root_dir}/tools/read_properties.sh"
    read_properties "${repo_root_dir}/project.properties" _PROJECT_PROPERTIES

    # Parse arguments
    parse_args "$@"

    # Validate required arguments
    # SC2128: Expanding an array without an index only gives the first element.
    # shellcheck disable=SC2128
    if [[ -z "${PHP_VERSIONS_WITHOUT_DOT}" ]]; then
        echo "Error: Missing required arguments."
        show_help
        exit 1
    fi

    GEN_NOTICE=""
    if [ "$SKIP_NOTICE" = true ]; then
        echo "Skipping notice file generation..."
    else
        GEN_NOTICE="\
            && echo 'Generating NOTICE file. This may take some time...' \
            && php ./packaging/notice_generator.php >> ./NOTICE \
        "
    fi

    if [ "${SKIP_VERIFY}" = "true" ]; then
        echo "Skipping verify step"
    fi

    local NOTICE_APPEND_CMD=""
    local docker_notice_mount_args=()
    if [ "${SKIP_NOTICE}" = "false" ]; then
        docker_notice_mount_args=(
            -v "${repo_root_dir}/NOTICE:/docker_host_dst_NOTICE"
        )
        NOTICE_APPEND_CMD="\
            && if [ -f ./NOTICE ]; then cat ./NOTICE >> /docker_host_dst_NOTICE; fi \
        "
    fi

    # ./tools/build/configure_php_templates.sh must be called before any .php scripts in ./tools/build/
    # because .php scripts in ./tools/build/ depend on some of .php source files
    # generated by ./tools/build/configure_php_templates.sh
    ./tools/build/configure_php_templates.sh

    trap on_script_exit EXIT

    _BUILD_PHP_CODE_FOR_PACKAGES_TEMP_DIR="$(mktemp -d)"
    echo "_BUILD_PHP_CODE_FOR_PACKAGES_TEMP_DIR: ${_BUILD_PHP_CODE_FOR_PACKAGES_TEMP_DIR}"

    # See prod/php/bootstrap_php_part.php for final layout of PHP code after for the packages

    local -r _BUILT_PHP_CODE_FOR_PACKAGES_DIR="${repo_root_dir}/_BUILT/php_code_for_packages"
    mkdir -p "${_BUILT_PHP_CODE_FOR_PACKAGES_DIR}"
    # Copy files (but not subdirectories which at the moment is only prod/php/OpenTelemetry/)
    cp "${repo_root_dir}/prod/php/"*.php "${_BUILT_PHP_CODE_FOR_PACKAGES_DIR}/"

    for _PHP_VERSION_WITHOUT_DOT in "${PHP_VERSIONS_WITHOUT_DOT[@]}"; do
        local _PHP_VERSION_WITH_DOT
        _PHP_VERSION_WITH_DOT=$(convert_no_dot_to_dot_separated_version "${_PHP_VERSION_WITHOUT_DOT}")

        # --- Common scoper setup ---
        local _SCOPER_PREFIX="${_PROJECT_PROPERTIES_PHP_SCOPER_PREFIX:?}"
        local _PHP_SCOPER_VERSION="0.18.19"
        if [ "${_PHP_VERSION_WITHOUT_DOT}" = "81" ]; then
            _PHP_SCOPER_VERSION="0.17.7"
        fi

        local PHP_docker_image
        PHP_docker_image=$(build_light_PHP_docker_image_name_for_version_no_dot "${_PHP_VERSION_WITHOUT_DOT}")

        local docker_run_env_vars_cmd_line_args=()
        build_docker_env_vars_command_line_part docker_run_env_vars_cmd_line_args

        # --- Reusable command blocks ---
        local INSTALL_SCOPER_CMD="curl -fLsS -o /usr/local/bin/php-scoper.phar https://github.com/humbug/php-scoper/releases/download/${_PHP_SCOPER_VERSION}/php-scoper.phar"

        local COPY_REPO_TO_TMP_CMD="mkdir -p /tmp/repo && cd /read_only_repo_root && for entry in *; do [ \"\${entry}\" = \"NOTICE\" ] && continue; cp -r \"\${entry}\" /tmp/repo/; done && cd /tmp/repo"

        local _SCOPED_DISTRO_TEMP_IN_DOCKER_DIR="/tmp/repo/prod_php_OpenTelemetry_scoped"
        local SCOPE_DISTRO_CMD="OTEL_PHP_SCOPER_PREFIX='${_SCOPER_PREFIX}' php -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED' /usr/local/bin/php-scoper.phar add-prefix --force --config=/tmp/repo/tools/build/php-scoper.inc.php --output-dir=${_SCOPED_DISTRO_TEMP_IN_DOCKER_DIR} /tmp/repo/prod/php/OpenTelemetry"

        # --- Build docker mount args and command from blocks ---
        local docker_mount_args=(
            -v "${repo_root_dir}/:/read_only_repo_root/:ro"
            -v "${_BUILT_PHP_CODE_FOR_PACKAGES_DIR}/:/docker_host_dst_php_code_for_packages/"
        )
        local docker_sh_cmd=""

        if [ "${DEV_ONLY_RESCOPE_DISTRO}" = "true" ]; then
            echo "Re-scoping distro code only for PHP version ${_PHP_VERSION_WITH_DOT} ..."

            if [ ! -d "${_BUILT_PHP_CODE_FOR_PACKAGES_DIR}/${_PHP_VERSION_WITHOUT_DOT}" ]; then
                echo "Error: vendor dir ${_BUILT_PHP_CODE_FOR_PACKAGES_DIR}/${_PHP_VERSION_WITHOUT_DOT} does not exist. Run full build first."
                exit 1
            fi

            docker_sh_cmd="\
                ${INSTALL_SCOPER_CMD} \
                && ${COPY_REPO_TO_TMP_CMD} \
                && echo 'Re-scoping distro code with prefix ${_SCOPER_PREFIX}' \
                && ${SCOPE_DISTRO_CMD} \
                && rm -rf /docker_host_dst_php_code_for_packages/${_PHP_VERSION_WITHOUT_DOT}/OpenTelemetry \
                && mkdir -p /docker_host_dst_php_code_for_packages/${_PHP_VERSION_WITHOUT_DOT}/OpenTelemetry \
                && cp -r ${_SCOPED_DISTRO_TEMP_IN_DOCKER_DIR}/. /docker_host_dst_php_code_for_packages/${_PHP_VERSION_WITHOUT_DOT}/OpenTelemetry/ \
                \
                && chown -R ${current_user_id}:${current_user_group_id} /docker_host_dst_php_code_for_packages/${_PHP_VERSION_WITHOUT_DOT}/OpenTelemetry/ \
                && chmod -R +r,u+w /docker_host_dst_php_code_for_packages/${_PHP_VERSION_WITHOUT_DOT}/OpenTelemetry/ \
            "
        else
            echo "Building PHP code (production code and its dependencies) for the packages for PHP version ${_PHP_VERSION_WITH_DOT} ..."

            if [ "$SKIP_NOTICE" = "false" ]; then
                echo "This project depends on following packages for PHP ${_PHP_VERSION_WITH_DOT}" >>NOTICE
            fi

            local COMPOSER_LOCK_FILENAME
            COMPOSER_LOCK_FILENAME="$(build_generated_composer_lock_file_name "prod" "${_PHP_VERSION_WITHOUT_DOT}")"
            local COMPOSER_LOCK_FULL_PATH="${_PROJECT_PROPERTIES_GENERATED_LOCK_FILES_FOLDER:?}/${COMPOSER_LOCK_FILENAME}"

            local _SEMCONV_INSTALLED_VERSION
            _SEMCONV_INSTALLED_VERSION=$(jq -r '.packages[] | select(.name == "open-telemetry/sem-conv") | .version' "${COMPOSER_LOCK_FULL_PATH}")

            local _SEMCONV_INSTALLED_MAJOR_MINOR=${_SEMCONV_INSTALLED_VERSION%.*}
            local _SEMCONV_EXPECTED_MAJOR_MINOR=${_PROJECT_PROPERTIES_OTEL_SEMCONV_VERSION%.*}

            if [[ "$_SEMCONV_INSTALLED_MAJOR_MINOR" != "$_SEMCONV_EXPECTED_MAJOR_MINOR" ]]; then
                echo "PHP side semantic conventions version $_SEMCONV_INSTALLED_MAJOR_MINOR doesn't match native version $_SEMCONV_EXPECTED_MAJOR_MINOR"
                exit 1
            fi

            local _TEMP_NOT_SCOPED_VENDOR_DIR="${_BUILD_PHP_CODE_FOR_PACKAGES_TEMP_DIR}/temp_not_scoped_vendor"
            rm -rf "${_TEMP_NOT_SCOPED_VENDOR_DIR}"
            mkdir -p "${_TEMP_NOT_SCOPED_VENDOR_DIR}"

            docker_mount_args+=(
                -v "${_TEMP_NOT_SCOPED_VENDOR_DIR}/:/docker_host_dst_not_scoped_vendor/"
            )
            docker_mount_args+=("${docker_notice_mount_args[@]}")

            docker_sh_cmd="\
                apk update && apk add bash \
                && curl -sS https://getcomposer.org/installer | php -- --filename=composer --install-dir=/usr/local/bin \
                && cd /read_only_repo_root/ && php ./tools/build/verify_generated_composer_lock_files.php \
                && ${COPY_REPO_TO_TMP_CMD} \
                && rm -rf composer.json composer.lock ./vendor/ \
                && php ./tools/build/select_json_lock_and_install_PHP_deps.php prod \
                && cp -r ./vendor/. /docker_host_dst_not_scoped_vendor/ \
                && echo 'Scoping PHP dependencies with prefix ${_SCOPER_PREFIX}' \
                && ${INSTALL_SCOPER_CMD} \
                && cd /tmp/repo/vendor \
                && OTEL_PHP_SCOPER_PREFIX='${_SCOPER_PREFIX}' php -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED' /usr/local/bin/php-scoper.phar add-prefix --force --config=/tmp/repo/tools/build/php-scoper.inc.php --output-dir=/tmp/repo/vendor-scoped \
                && rm -rf /tmp/repo/vendor \
                && cd /tmp/repo \
                && php ./tools/build/fix_scoped_composer_autoload.php '${_SCOPER_PREFIX}' /tmp/repo/vendor-scoped \
                && mv /tmp/repo/vendor-scoped /tmp/repo/vendor \
                && rm -rf /docker_host_dst_php_code_for_packages/${_PHP_VERSION_WITHOUT_DOT}/vendor \
                && mkdir -p /docker_host_dst_php_code_for_packages/${_PHP_VERSION_WITHOUT_DOT}/vendor \
                && cp -r ./vendor/. /docker_host_dst_php_code_for_packages/${_PHP_VERSION_WITHOUT_DOT}/vendor/ \
                \
                && echo 'Scoping distro code with prefix ${_SCOPER_PREFIX}' \
                && ${SCOPE_DISTRO_CMD} \
                && rm -rf /docker_host_dst_php_code_for_packages/${_PHP_VERSION_WITHOUT_DOT}/OpenTelemetry \
                && mkdir -p /docker_host_dst_php_code_for_packages/${_PHP_VERSION_WITHOUT_DOT}/OpenTelemetry \
                && cp -r ${_SCOPED_DISTRO_TEMP_IN_DOCKER_DIR}/. /docker_host_dst_php_code_for_packages/${_PHP_VERSION_WITHOUT_DOT}/OpenTelemetry/ \
                \
                && chown -R ${current_user_id}:${current_user_group_id} /docker_host_dst_not_scoped_vendor/ \
                && chmod -R +r,u+w /docker_host_dst_not_scoped_vendor/ \
                && chown -R ${current_user_id}:${current_user_group_id} /docker_host_dst_php_code_for_packages/${_PHP_VERSION_WITHOUT_DOT}/ \
                && chmod -R +r,u+w /docker_host_dst_php_code_for_packages/${_PHP_VERSION_WITHOUT_DOT}/ \
                ${GEN_NOTICE} \
                ${NOTICE_APPEND_CMD} \
            "
        fi

        docker run --rm \
            "${docker_run_env_vars_cmd_line_args[@]}" \
            "${docker_mount_args[@]}" \
            -w "/" \
            "${PHP_docker_image}" \
            sh -c "${docker_sh_cmd}"

        if [ "${DEV_ONLY_RESCOPE_DISTRO}" = "false" ] && [ "${SKIP_VERIFY}" = "false" ]; then
            verify_vendor_dir \
                "${_PHP_VERSION_WITHOUT_DOT}" \
                "${_TEMP_NOT_SCOPED_VENDOR_DIR}" \
                "${_BUILT_PHP_CODE_FOR_PACKAGES_DIR}/${_PHP_VERSION_WITHOUT_DOT}/vendor" \
                "${_PROJECT_PROPERTIES_OTEL_PROTO_VERSION}" \
                "${_PROJECT_PROPERTIES_NATIVE_OTLP_EXPORTERS_BASED_ON_PHP_IMPL_VERSION}"
        fi
    done
}

main "$@"
