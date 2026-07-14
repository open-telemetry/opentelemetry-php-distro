#!/usr/bin/env bash
set -e -u -o pipefail
#set -x

SKIP_NOTICE=false
SKIP_VERIFY=false
DEV_ONLY_RESCOPE_DISTRO=false
LOCAL_REPOS_FILE=""
LOCAL_REPOS_EXTRA_MOUNTS=()
LOCAL_REPOS_OVERRIDE_CMDS=""
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
    echo "  --local-repos-file         Optional. Path to a JSON file listing local Composer package paths for development (gitignored)."
    echo "                             Each entry is mounted into Docker and overlaid onto vendor/ after composer install."
    echo "                             See .local-repos.json.example. Default: none (packages installed from Packagist)."
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
        --local-repos-file)
            LOCAL_REPOS_FILE="${2}"
            shift
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

load_local_repos() {
    if [ -z "${LOCAL_REPOS_FILE}" ]; then
        return
    fi
    if [ ! -f "${LOCAL_REPOS_FILE}" ]; then
        echo "Error: --local-repos-file not found: ${LOCAL_REPOS_FILE}"
        exit 1
    fi
    echo "Using local repositories from: ${LOCAL_REPOS_FILE}"
    local count
    count=$(jq '.repositories | length' "${LOCAL_REPOS_FILE}")
    local i
    for (( i=0; i<count; i++ )); do
        local host_path
        host_path=$(jq -r ".repositories[${i}].url" "${LOCAL_REPOS_FILE}")
        local docker_path="/local_repos/${i}"
        if [ ! -d "${host_path}" ]; then
            echo "Warning: local repo path does not exist, skipping: ${host_path}"
            continue
        fi
        echo "  [${i}] ${host_path} -> ${docker_path}"
        LOCAL_REPOS_EXTRA_MOUNTS+=(-v "${host_path}:${docker_path}:ro")
        # \$(...) is intentionally escaped so the subshell runs inside the container, not on the host.
        # If composer installed via symlink (path repo), replace with real files so the copy to host
        # and php-scoper work correctly (symlinks to /local_repos/* are invalid outside the container).
        # Exclude vendor/ from the copy: a published package never ships its own dev vendor tree,
        # and copying it would feed huge dev-only files (phpstan.phar, psalm dictionaries) into php-scoper.
        LOCAL_REPOS_OVERRIDE_CMDS+="\
            && echo 'Overriding vendor with local repo: ${host_path}' \
            && _pkg_vendor_dir=/tmp/repo/vendor/\$(jq -r .name ${docker_path}/composer.json) \
            && if [ -L \"\${_pkg_vendor_dir}\" ]; then rm \"\${_pkg_vendor_dir}\" && cp -rf ${docker_path}/. \"\${_pkg_vendor_dir}/\" && rm -rf \"\${_pkg_vendor_dir}/vendor/\"; \
               elif [ \"\$(realpath ${docker_path} 2>/dev/null)\" != \"\$(realpath \${_pkg_vendor_dir} 2>/dev/null)\" ]; then cp -rf ${docker_path}/. \"\${_pkg_vendor_dir}/\" && rm -rf \"\${_pkg_vendor_dir}/vendor/\"; fi \
        "
    done
}

verify_otel_proto_version() {
    local -r _NOT_SCOPED_VENDOR_DIR="${1:?}"
    local -r _OTEL_PROTO_VERSION="${2:?}"

    local gen_otlp_protobuf_version_file_path="${_NOT_SCOPED_VENDOR_DIR}/open-telemetry/gen-otlp-protobuf/VERSION"
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
    local -r _OTEL_PROTO_VERSION="${3:?}"
    local -r _NATIVE_OTLP_EXPORTERS_PROTO_VERSION="${4:?}"

    verify_otel_proto_version "${_NOT_SCOPED_VENDOR_DIR}" "${_OTEL_PROTO_VERSION}"
    verify_otlp_exporters "${_PHP_VERSION_WITHOUT_DOT}" "${_NOT_SCOPED_VENDOR_DIR}" "${_NATIVE_OTLP_EXPORTERS_PROTO_VERSION:?}"
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
    load_local_repos

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
    local -r _BUILT_NOTICE_FILE="${repo_root_dir}/_BUILT/NOTICE"
    if [ "${SKIP_NOTICE}" = "false" ]; then
        mkdir -p "${repo_root_dir}/_BUILT"
        cp "${repo_root_dir}/packaging/NOTICE.template" "${_BUILT_NOTICE_FILE}"
        docker_notice_mount_args=(
            -v "${_BUILT_NOTICE_FILE}:/docker_host_dst_NOTICE"
        )
        NOTICE_APPEND_CMD="\
            && if [ -f ./NOTICE ]; then cat ./NOTICE >> /docker_host_dst_NOTICE; fi \
        "
    fi

    # ./tools/build/configure_php_templates.sh must be called before any .php scripts in ./tools/build/
    # because .php scripts in ./tools/build/ depend on some of .php source files
    # generated by ./tools/build/configure_php_templates.sh
    ./tools/build/configure_php_templates.sh

    local -r _BUILT_PHP_CODE_FOR_PACKAGES_DIR="${repo_root_dir}/_BUILT/php_code_for_packages"
    if [ "${DEV_ONLY_RESCOPE_DISTRO}" = "false" ]; then
        # Clean previously generated output inside docker — docker-generated files (scoped/, not_scoped/vendor_*/)
        # are owned by root and cannot be deleted locally without sudo
        if [ -d "${_BUILT_PHP_CODE_FOR_PACKAGES_DIR}" ]; then
            local _cleanup_docker_image
            _cleanup_docker_image=$(build_light_PHP_docker_image_name_for_version_no_dot "${PHP_VERSIONS_WITHOUT_DOT[0]}")
            docker run --rm \
                -v "${_BUILT_PHP_CODE_FOR_PACKAGES_DIR}:/target" \
                "${_cleanup_docker_image}" \
                sh -c "rm -rf /target/*"
        fi
    fi
    mkdir -p "${_BUILT_PHP_CODE_FOR_PACKAGES_DIR}"

    # See prod/php/bootstrap_php_part.php for the layout of PHP code after package is installed
    #
    #          bootstrap_php_part.php
    #          ScoperConfig.php
    #          not_scoped
    #              OpenTelemetry/ (under this directory the layout is the same as in <repo>/prod/php/OpenTelemetry/)

    # Copy files (but not subdirectories which at the moment is only prod/php/OpenTelemetry/)
    cp "${repo_root_dir}/prod/php/"*.php "${_BUILT_PHP_CODE_FOR_PACKAGES_DIR}/"

    # Copy subdirectories (which at the moment is only prod/php/OpenTelemetry/) but not files
    rm -rf "${_BUILT_PHP_CODE_FOR_PACKAGES_DIR}/not_scoped"
    mkdir "${_BUILT_PHP_CODE_FOR_PACKAGES_DIR}/not_scoped"
    find "${repo_root_dir}/prod/php/" -mindepth 1 -maxdepth 1 -type d -exec cp -r {} "${_BUILT_PHP_CODE_FOR_PACKAGES_DIR}/not_scoped/" \;

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

        local COPY_REPO_TO_TMP_CMD="mkdir -p /tmp/repo && cd /read_only_repo_root && cp -r . /tmp/repo/ && cd /tmp/repo"

        local _NOT_SCOPED_DISTRO_TEMP_IN_DOCKER_DIR="/tmp/repo/prod/php/OpenTelemetry"
        local _SCOPED_DISTRO_TEMP_IN_DOCKER_DIR="/tmp/repo/prod_php_OpenTelemetry_scoped"
        local SCOPE_DISTRO_CMD="OTEL_PHP_SCOPER_PREFIX='${_SCOPER_PREFIX}' php -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED' /usr/local/bin/php-scoper.phar add-prefix --force --config=/tmp/repo/tools/build/php-scoper.inc.php --output-dir=${_SCOPED_DISTRO_TEMP_IN_DOCKER_DIR} ${_NOT_SCOPED_DISTRO_TEMP_IN_DOCKER_DIR}"

        # --- Build docker mount args and command from blocks ---
        local docker_mount_args=(
            -v "${repo_root_dir}/:/read_only_repo_root/:ro"
            -v "${_BUILT_PHP_CODE_FOR_PACKAGES_DIR}/:/docker_host_dst_php_code_for_packages/"
        )
        if [ "${#LOCAL_REPOS_EXTRA_MOUNTS[@]}" -gt 0 ]; then
            docker_mount_args+=("${LOCAL_REPOS_EXTRA_MOUNTS[@]}")
        fi
        local docker_sh_cmd=""

        # See prod/php/bootstrap_php_part.php for the layout of PHP code after package is installed
        #
        #          not_scoped
        #              vendor_85/ (vendor_<PHP major><PHP minor>)
        #          scoped
        #              85 (<PHP major><PHP minor>)
        #                  OpenTelemetry/  (under this directory the layout is the same as in <repo>/prod/php/OpenTelemetry/)
        #                      ...
        #                      Distro/
        #                      ...
        #                  vendor/

        local _NOT_SCOPED_VENDOR_PHP_VERSION_IN_DOCKER_DIR="/docker_host_dst_php_code_for_packages/not_scoped/vendor_${_PHP_VERSION_WITHOUT_DOT}"
        local _SCOPED_PHP_VERSION_IN_DOCKER_DIR="/docker_host_dst_php_code_for_packages/scoped/${_PHP_VERSION_WITHOUT_DOT}"

        if [ "${DEV_ONLY_RESCOPE_DISTRO}" = "true" ]; then
            echo "Re-scoping distro code only for PHP version ${_PHP_VERSION_WITH_DOT} ..."

            if [ ! -d "${_BUILT_PHP_CODE_FOR_PACKAGES_DIR}/scoped/${_PHP_VERSION_WITHOUT_DOT}" ]; then
                echo "Error: vendor dir ${_BUILT_PHP_CODE_FOR_PACKAGES_DIR}/scoped/${_PHP_VERSION_WITHOUT_DOT} does not exist. Run full build first."
                exit 1
            fi

            docker_sh_cmd="\
                ${INSTALL_SCOPER_CMD} \
                && ${COPY_REPO_TO_TMP_CMD} \
                \
                && echo 'Re-scoping distro code with prefix ${_SCOPER_PREFIX}' \
                && ${SCOPE_DISTRO_CMD} \
                && rm -rf ${_SCOPED_PHP_VERSION_IN_DOCKER_DIR}/OpenTelemetry \
                && mkdir -p ${_SCOPED_PHP_VERSION_IN_DOCKER_DIR}/OpenTelemetry \
                && cp -r ${_SCOPED_DISTRO_TEMP_IN_DOCKER_DIR}/. ${_SCOPED_PHP_VERSION_IN_DOCKER_DIR}/OpenTelemetry/ \
                \
                && chown -R ${current_user_id}:${current_user_group_id} ${_SCOPED_PHP_VERSION_IN_DOCKER_DIR}/OpenTelemetry/ \
                && chmod -R +r,u+w ${_SCOPED_PHP_VERSION_IN_DOCKER_DIR}/OpenTelemetry/ \
                \
                && echo 'Generating unscoped API aliases' \
                && php /tmp/repo/tools/build/generate_unscoped_api_aliases.php '${_SCOPER_PREFIX}' ${_SCOPED_PHP_VERSION_IN_DOCKER_DIR}/vendor ${_SCOPED_PHP_VERSION_IN_DOCKER_DIR}/unscoped_api_aliases.php \
                && chown ${current_user_id}:${current_user_group_id} ${_SCOPED_PHP_VERSION_IN_DOCKER_DIR}/unscoped_api_aliases.php \
                && chmod +r,u+w ${_SCOPED_PHP_VERSION_IN_DOCKER_DIR}/unscoped_api_aliases.php \
            "
        else
            echo "Building PHP code (production code and its dependencies) for the packages for PHP version ${_PHP_VERSION_WITH_DOT} ..."

            if [ "$SKIP_NOTICE" = "false" ]; then
                echo "This project depends on following packages for PHP ${_PHP_VERSION_WITH_DOT}" >>"${_BUILT_NOTICE_FILE}"
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

            docker_mount_args+=("${docker_notice_mount_args[@]}")

            local _APK_EXTRA_PKGS="bash"
            local VERIFY_LOCK_FILES_CMD="cd /read_only_repo_root/ && php ./tools/build/verify_generated_composer_lock_files.php"
            if [ "${#LOCAL_REPOS_EXTRA_MOUNTS[@]}" -gt 0 ]; then
                _APK_EXTRA_PKGS="bash jq"
                VERIFY_LOCK_FILES_CMD="echo 'Skipping verify_generated_composer_lock_files: local repos in use (dev mode)'"
            fi

            docker_sh_cmd="\
                apk update && apk add ${_APK_EXTRA_PKGS} \
                && curl -sS https://getcomposer.org/installer | php -- --filename=composer --install-dir=/usr/local/bin \
                && ${VERIFY_LOCK_FILES_CMD} \
                && ${COPY_REPO_TO_TMP_CMD} \
                && rm -rf composer.json composer.lock ./vendor/ \
                && php ./tools/build/select_json_lock_and_install_PHP_deps.php prod \
                ${LOCAL_REPOS_OVERRIDE_CMDS} \
                && rm -rf ${_NOT_SCOPED_VENDOR_PHP_VERSION_IN_DOCKER_DIR} \
                && mkdir -p ${_NOT_SCOPED_VENDOR_PHP_VERSION_IN_DOCKER_DIR} \
                && cp -r /tmp/repo/vendor/. ${_NOT_SCOPED_VENDOR_PHP_VERSION_IN_DOCKER_DIR}/ \
                && php /tmp/repo/tools/build/fix_composer_autoload_order.php ${_NOT_SCOPED_VENDOR_PHP_VERSION_IN_DOCKER_DIR} \
                \
                && chown -R ${current_user_id}:${current_user_group_id} ${_NOT_SCOPED_VENDOR_PHP_VERSION_IN_DOCKER_DIR}/ \
                && chmod -R +r,u+w ${_NOT_SCOPED_VENDOR_PHP_VERSION_IN_DOCKER_DIR}/ \
                \
                && echo 'Scoping PHP dependencies with prefix ${_SCOPER_PREFIX}' \
                && ${INSTALL_SCOPER_CMD} \
                && cd /tmp/repo/vendor \
                && OTEL_PHP_SCOPER_PREFIX='${_SCOPER_PREFIX}' php -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED' /usr/local/bin/php-scoper.phar add-prefix --force --config=/tmp/repo/tools/build/php-scoper.inc.php --output-dir=/tmp/repo/vendor-scoped \
                && cd /tmp/repo \
                && rm -rf /tmp/repo/vendor \
                && php ./tools/build/fix_scoped_composer_autoload.php '${_SCOPER_PREFIX}' /tmp/repo/vendor-scoped \
                && php ./tools/build/fix_composer_autoload_order.php /tmp/repo/vendor-scoped \
                && mv /tmp/repo/vendor-scoped /tmp/repo/vendor \
                && rm -rf ${_SCOPED_PHP_VERSION_IN_DOCKER_DIR}/vendor \
                && mkdir -p ${_SCOPED_PHP_VERSION_IN_DOCKER_DIR}/vendor \
                && cp -r ./vendor/. ${_SCOPED_PHP_VERSION_IN_DOCKER_DIR}/vendor/ \
                \
                && echo 'Generating unscoped API aliases' \
                && php /tmp/repo/tools/build/generate_unscoped_api_aliases.php '${_SCOPER_PREFIX}' ${_SCOPED_PHP_VERSION_IN_DOCKER_DIR}/vendor ${_SCOPED_PHP_VERSION_IN_DOCKER_DIR}/unscoped_api_aliases.php \
                \
                && echo 'Scoping distro code with prefix ${_SCOPER_PREFIX}' \
                && ${SCOPE_DISTRO_CMD} \
                && rm -rf ${_SCOPED_PHP_VERSION_IN_DOCKER_DIR}/OpenTelemetry \
                && mkdir -p ${_SCOPED_PHP_VERSION_IN_DOCKER_DIR}/OpenTelemetry \
                && cp -r ${_SCOPED_DISTRO_TEMP_IN_DOCKER_DIR}/. ${_SCOPED_PHP_VERSION_IN_DOCKER_DIR}/OpenTelemetry/ \
                \
                && chown -R ${current_user_id}:${current_user_group_id} ${_SCOPED_PHP_VERSION_IN_DOCKER_DIR}/ \
                && chmod -R +r,u+w ${_SCOPED_PHP_VERSION_IN_DOCKER_DIR}/ \
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
                "${_BUILT_PHP_CODE_FOR_PACKAGES_DIR}/not_scoped/vendor_${_PHP_VERSION_WITHOUT_DOT}" \
                "${_PROJECT_PROPERTIES_OTEL_PROTO_VERSION}" \
                "${_PROJECT_PROPERTIES_NATIVE_OTLP_EXPORTERS_BASED_ON_PHP_IMPL_VERSION}"
        fi
    done
}

main "$@"
