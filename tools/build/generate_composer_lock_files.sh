#!/usr/bin/env bash
set -e -u -o pipefail
#set -x

function show_help() {
    echo "Usage: $0 [optional arguments]"
    echo
    echo "Options:"
    echo "  --keep_temp_files       Optional. Keep temporary files. Default: false (i.e., delete temporary files on both success and failure)."
    echo
    echo "Example:"
    echo "  $0 --keep_temp_files"
}

# Function to parse arguments
function parse_args() {
    export OTEL_PHP_PHP_TOOLS_KEEP_TEMP_FILES="false"

    while [[ "$#" -gt 0 ]]; do
        case $1 in
        --keep_temp_files)
            export OTEL_PHP_PHP_TOOLS_KEEP_TEMP_FILES="true"
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

function build_command_to_derive_for_prod() {
    # Make some inconsequential change to composer.json just to make the one for dev different from the one for production.
    # So that the hash codes are different and ComposerAutoloaderInit<composer.json hash code> classes defined in vendor/composer/autoload_real.php
    # in the installed package and component tests vendor directories have different names.
    # Note that even though it is `require_once __DIR__ . '/composer/autoload_real.php'` in vendor/autoload.php
    # it does not prevent `Cannot redeclare class` error because those two autoload_real.php files are located in different directories
    # require_once does not help.

    echo "composer --no-interaction --no-scripts --no-update --dev --quiet remove ext-mysqli"
}

function should_keep_dev_dep_for_prod_static_check() {
    local dep_name="${1:?}"

    local package_prefixes_to_keep=("php-parallel-lint/")
    package_prefixes_to_keep+=("phpstan/")

    local packages_to_keep+=("slevomat/coding-standard")
    package_prefixes_to_keep+=("squizlabs/php_codesniffer")

    for package_prefix_to_keep in "${package_prefixes_to_keep[@]}" ; do
        if [[ "${dep_name}" == "${package_prefix_to_keep}"* ]]; then
            echo "true"
            return
        fi
    done

    for package_to_keep in "${packages_to_keep[@]}" ; do
        if [[ "${dep_name}" == "${package_to_keep}" ]]; then
            echo "true"
            return
        fi
    done

    echo "false"
}

function build_list_of_dev_deps_to_remove_for_prod_static_check() {
    local base_composer_json_full_path="${1:?}"

    mapfile -t present_deps_in_quotes< <(jq '."require-dev" | keys | .[]' "${base_composer_json_full_path}")

    local deps_to_remove=()
    for present_dep_in_quotes in "${present_deps_in_quotes[@]}" ; do
        local present_dep="${present_dep_in_quotes%\"}"
        present_dep="${present_dep#\"}"
        present_deps+=("${present_dep}")
        should_keep=$(should_keep_dev_dep_for_prod_static_check "${present_dep}")
        if [[ "${should_keep}" != "true" ]]; then
            deps_to_remove+=("${present_dep}")
        fi
    done

    if [ ${#deps_to_remove[@]} -eq 0 ]; then
        echo "There should be at least one package to remove to generate composer json derived for test env"
        exit 1
    fi

    echo "${deps_to_remove[*]}"
}

function build_command_to_derive_composer_json_for_prod_static_check() {
    local base_composer_json_full_path="${1:?}"

    # composer json for prod_static_check env is used to run 'composer run-script -- static_check' on prod code
    # so we would like to remove all the dev dependencies that are not used by 'composer run-script -- static_check'
    local dev_deps_to_remove
    dev_deps_to_remove=$(build_list_of_dev_deps_to_remove_for_prod_static_check "${base_composer_json_full_path}")

    echo "composer --no-scripts --no-update --quiet --dev remove ${dev_deps_to_remove}"
}

function should_remove_not_dev_dep_for_test() {
    local dep_name="${1:?}"

    local package_prefixes_to_remove_if_present=("open-telemetry/opentelemetry-auto-")
    local packages_to_remove_if_present=("php-http/guzzle7-adapter")
    packages_to_remove_if_present+=("nyholm/psr7-server")

    for package_prefix_to_remove_if_present in "${package_prefixes_to_remove_if_present[@]}" ; do
        if [[ "${dep_name}" == "${package_prefix_to_remove_if_present}"* ]]; then
            echo "true"
            return
        fi
    done

    for package_to_remove_if_present in "${packages_to_remove_if_present[@]}" ; do
        if [[ "${dep_name}" == "${package_to_remove_if_present}" ]]; then
            echo "true"
            return
        fi
    done

    echo "false"
}

function build_list_of_not_dev_deps_to_remove_for_test() {
    local base_composer_json_full_path="${1:?}"

    mapfile -t present_deps_in_quotes< <(jq '."require" | keys | .[]' "${base_composer_json_full_path}")

    local deps_to_remove=()
    for present_dep_in_quotes in "${present_deps_in_quotes[@]}" ; do
        local present_dep="${present_dep_in_quotes%\"}"
        present_dep="${present_dep#\"}"
        present_deps+=("${present_dep}")
        should_remove=$(should_remove_not_dev_dep_for_test "${present_dep}")
        if [ "${should_remove}" == "true" ] ; then
            deps_to_remove+=("${present_dep}")
        fi
    done

    if [ ${#deps_to_remove[@]} -eq 0 ]; then
        echo "There should be at least one package to remove to generate composer json derived for test env"
        exit 1
    fi

    echo "${deps_to_remove[*]}"
}

function build_command_to_derive_for_test() {
    local base_composer_json_full_path="${1:?}"

    # composer json for test env is used in PHPUnit and application code for component tests context
    # so we would like to not have any dependencies that we don't use in tests code and that should be loaded by EDOT package
    # such as open-telemetry/opentelemetry-auto-*, etc.
    # We would like to make sure that those dependencies are loaded by EDOT package and not loaded from tests vendor
    local not_dev_deps_to_remove
    not_dev_deps_to_remove=$(build_list_of_not_dev_deps_to_remove_for_test "${base_composer_json_full_path}")

    echo "composer --no-scripts --no-update --quiet remove ${not_dev_deps_to_remove}"
}

function build_generated_composer_json_full_path() {
    local _STAGE_DIR="${1:?}"
    local _ENV_KIND="${2:?}"

    local _GENERATED_COMPOSER_JSON_FILE_NAME="$(build_generated_composer_json_file_name "${_ENV_KIND}")"
    echo "${_STAGE_DIR}/${_GENERATED_COMPOSER_JSON_FILE_NAME}"
}

function derive_composer_json_for_env_kind() {
    local _STAGE_DIR="${1:?}"
    local _ENV_KIND="${2:?}"

    local base_composer_json_full_path
    base_composer_json_full_path="$(build_generated_composer_json_full_path ${_STAGE_DIR} "dev")"

    echo "Deriving composer json for ${_ENV_KIND} from ${base_composer_json_full_path} ..."

    local derived_composer_json_full_path
    derived_composer_json_full_path="$(build_generated_composer_json_full_path ${_STAGE_DIR} "${_ENV_KIND}")"

    local command_to_derive
    case ${_ENV_KIND} in
        prod)
            command_to_derive=$(build_command_to_derive_for_prod)
            ;;
        prod_static_check)
            command_to_derive=$(build_command_to_derive_composer_json_for_prod_static_check "${base_composer_json_full_path}")
            ;;
        test)
            command_to_derive=$(build_command_to_derive_for_test "${base_composer_json_full_path}")
            ;;
        *)
            echo "There is no way to generate derived composer json for environment kind ${_ENV_KIND}"
            exit 1
            ;;
    esac

    cp -f "${base_composer_json_full_path}" "${derived_composer_json_full_path}"

    local current_user_id
    current_user_id="$(id -u)"
    local current_user_group_id
    current_user_group_id="$(id -g)"

    local -r lowest_supported_php_version_no_dot=$(get_array_min_value ${_PROJECT_PROPERTIES_SUPPORTED_PHP_VERSIONS})
    local -r PHP_docker_image=$(build_light_PHP_docker_image_name_for_version_no_dot "${lowest_supported_php_version_no_dot}")

    docker run --rm \
        -v "${derived_composer_json_full_path}:/repo_root/composer.json" \
        -w "/repo_root" \
        "${PHP_docker_image}" \
        sh -c "\
            curl -sS https://getcomposer.org/installer | php -- --filename=composer --install-dir=/usr/local/bin \
            && ${command_to_derive} \
            && chown ${current_user_id}:${current_user_group_id} composer.json \
            && chmod +r,u+w composer.json \
        "

    echo "Diff between ${base_composer_json_full_path} and ${derived_composer_json_full_path}"
    local has_compared_the_same="true"
    diff "${base_composer_json_full_path}" "${derived_composer_json_full_path}" || has_compared_the_same="false"
    if [ "${has_compared_the_same}" == "true" ]; then
        echo "${base_composer_json_full_path} and ${derived_composer_json_full_path} should be different"
        exit 1
    fi
}

function generate_composer_lock_for_PHP_version() {
    local _STAGE_DIR="${1:?}"
    local _ENV_KIND="${2:?}"
    local PHP_version_no_dot="${3:?}"
    local _PHP_PACKAGES_ADAPTED_TO_PHP_81_REL_PATH="${4:?}"
    local _COMPOSER_HOME_FOR_PACKAGES_ADAPTED_TO_PHP_81_REL_PATH="${5:?}"

    local composer_json_full_path
    composer_json_full_path="$(build_generated_composer_json_full_path "${_STAGE_DIR}" "${_ENV_KIND}")"

    local composer_lock_file_name
    composer_lock_file_name="$(build_generated_composer_lock_file_name "${_ENV_KIND}" "${PHP_version_no_dot}")"

    echo "Generating ${composer_lock_file_name} from ${composer_json_full_path} ..."

    local PHP_docker_image
    PHP_docker_image=$(build_light_PHP_docker_image_name_for_version_no_dot "${PHP_version_no_dot}")

    echo "composer_json_full_path: ${composer_json_full_path} ..."
    echo "composer_lock_file_name: ${composer_lock_file_name} ..."

    local docker_args_for_PHP_81=()
    local docker_cmds_for_PHP_81=()
    if [ "${PHP_version_no_dot}" = "81" ]; then
        docker_args_for_PHP_81+=(-v "${_REPO_TEMP_COPY_DIR}/${_PHP_PACKAGES_ADAPTED_TO_PHP_81_REL_PATH:?}:/repo_root/${_PHP_PACKAGES_ADAPTED_TO_PHP_81_REL_PATH:?}:ro")
        docker_args_for_PHP_81+=(-v "${_REPO_TEMP_COPY_DIR}/${_COMPOSER_HOME_FOR_PACKAGES_ADAPTED_TO_PHP_81_REL_PATH:?}:/from_docker_host/${_COMPOSER_HOME_FOR_PACKAGES_ADAPTED_TO_PHP_81_REL_PATH:?}:ro")
        docker_args_for_PHP_81+=(-e "COMPOSER_HOME=/repo_root/${_COMPOSER_HOME_FOR_PACKAGES_ADAPTED_TO_PHP_81_REL_PATH:?}")

        docker_cmds_for_PHP_81+=("&&" mkdir -p "/repo_root/${_COMPOSER_HOME_FOR_PACKAGES_ADAPTED_TO_PHP_81_REL_PATH:?}/")
        docker_cmds_for_PHP_81+=("&&" cp "/from_docker_host/${_COMPOSER_HOME_FOR_PACKAGES_ADAPTED_TO_PHP_81_REL_PATH:?}/"* "/repo_root/${_COMPOSER_HOME_FOR_PACKAGES_ADAPTED_TO_PHP_81_REL_PATH:?}/")
    fi

    docker run --rm \
        -v "${composer_json_full_path}:/repo_root/composer.json:ro" \
        -v "${_STAGE_DIR}:/from_docker_host/generated_composer_lock_files_stage_dir" \
        "${docker_args_for_PHP_81[@]}" \
        -w "/repo_root" \
        "${PHP_docker_image}" \
        sh -c "\
            curl -sS https://getcomposer.org/installer | php -- --filename=composer --install-dir=/usr/local/bin \
            ${docker_cmds_for_PHP_81[*]} \
            && composer run-script -- generate_lock_use_current_json \
            && cp -f /repo_root/composer.lock /from_docker_host/generated_composer_lock_files_stage_dir/${composer_lock_file_name} \
            && chown -R ${current_user_id}:${current_user_group_id} /from_docker_host/generated_composer_lock_files_stage_dir/${composer_lock_file_name} \
            && chmod -R +r,u+w /from_docker_host/generated_composer_lock_files_stage_dir/${composer_lock_file_name} \
        "
}

function on_script_exit() {
    if [ -n "${_REPO_TEMP_COPY_DIR+x}" ] && [ -d "${_REPO_TEMP_COPY_DIR}" ]; then
        delete_temp_dir "${_REPO_TEMP_COPY_DIR}"
    fi
}

function main() {
    this_script_dir="$(dirname "${BASH_SOURCE[0]}")"
    this_script_dir="$(realpath "${this_script_dir}")"

    source "tools/shared.sh"
    repo_root_dir=$(verify_running_from_repo_root)

    source "tools/helpers/array_helpers.sh"

    source "${repo_root_dir}/tools/read_properties.sh"
    read_properties "${repo_root_dir}/project.properties" _PROJECT_PROPERTIES


    # Parse arguments
    parse_args "$@"

    current_user_id="$(id -u)"
    current_user_group_id="$(id -g)"

    trap on_script_exit EXIT

    local _REPO_TEMP_COPY_DIR="$(mktemp -d)"
    echo "_REPO_TEMP_COPY_DIR: ${_REPO_TEMP_COPY_DIR}"

    copy_file "composer.json" "${_REPO_TEMP_COPY_DIR}/"

    pushd "${_REPO_TEMP_COPY_DIR}" || exit 1
        # composer run-script -- download_and_adapt_packages_to_PHP_81 "${_REPO_TEMP_COPY_DIR}"
        #   expects
        #       - "./composer.json"
        #   creates
        #       - "./${_PHP_PACKAGES_ADAPTED_TO_PHP_81_REL_PATH:?}/"
        #       - "./${_COMPOSER_HOME_FOR_PACKAGES_ADAPTED_TO_PHP_81_REL_PATH:?}/config.json"
        php "${this_script_dir}/download_adapt_packages_to_PHP_81_and_gen_config.php"
    popd || exit 1

    local GENERATED_COMPOSER_LOCK_FILES_STAGE_DIR="${_REPO_TEMP_COPY_DIR}/${_PROJECT_PROPERTIES_GENERATED_LOCK_FILES_FOLDER:?}"
    mkdir -p "${GENERATED_COMPOSER_LOCK_FILES_STAGE_DIR}"

    local _DEV_COMPOSER_JSON_FULL_PATH="$(build_generated_composer_json_full_path "${GENERATED_COMPOSER_LOCK_FILES_STAGE_DIR}" "dev")"
    copy_file "composer.json" "${_DEV_COMPOSER_JSON_FULL_PATH}"

    echo "ls -al ${_REPO_TEMP_COPY_DIR}"
    ls -al "${_REPO_TEMP_COPY_DIR}"
    echo "ls -al ${GENERATED_COMPOSER_LOCK_FILES_STAGE_DIR}"
    ls -al "${GENERATED_COMPOSER_LOCK_FILES_STAGE_DIR}"

    for env_kind in "prod" "prod_static_check" "test"; do
        derive_composer_json_for_env_kind "${GENERATED_COMPOSER_LOCK_FILES_STAGE_DIR}" "${env_kind}"
    done


    for PHP_version_no_dot in $(get_array $_PROJECT_PROPERTIES_SUPPORTED_PHP_VERSIONS) ; do
        for env_kind in "dev" "prod" "prod_static_check" "test"; do
            generate_composer_lock_for_PHP_version "${GENERATED_COMPOSER_LOCK_FILES_STAGE_DIR}" "${env_kind}" "${PHP_version_no_dot}" "${_PROJECT_PROPERTIES_PACKAGES_ADAPTED_TO_PHP_81_REL_PATH}" "${_PROJECT_PROPERTIES_COMPOSER_HOME_FOR_PACKAGES_ADAPTED_TO_PHP_81_REL_PATH}"
        done
    done

    pushd "${_REPO_TEMP_COPY_DIR}" || exit 1
        php "${repo_root_dir}/tools/build/verify_generated_composer_lock_files.php"
    popd || exit 1

    mkdir -p "${_PROJECT_PROPERTIES_GENERATED_LOCK_FILES_FOLDER:?}"
    delete_dir_contents "${_PROJECT_PROPERTIES_GENERATED_LOCK_FILES_FOLDER:?}"
    cp "${GENERATED_COMPOSER_LOCK_FILES_STAGE_DIR}/"* "${_PROJECT_PROPERTIES_GENERATED_LOCK_FILES_FOLDER:?}/"
    # No need for delete_temp_dir "${_REPO_TEMP_COPY_DIR}" - ${_REPO_TEMP_COPY_DIR} is deleted in on_script_exit()
}

main "$@"
