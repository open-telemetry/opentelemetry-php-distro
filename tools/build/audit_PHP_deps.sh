#!/usr/bin/env bash
set -e -u -o pipefail
#set -x

PHP_VERSIONS_WITHOUT_DOT=()

show_help() {
    echo "Usage: $0 --php_versions <versions>"
    echo
    echo "Arguments:"
    echo "  --php_versions    Required. List of PHP versions separated by spaces (e.g., '81 82 83 84 85')."
    echo
    echo "Example:"
    echo "  $0 --php_versions '85'"
    echo "  $0 --php_versions '81 82 83 84 85'"
}

parse_args() {
    while [[ "$#" -gt 0 ]]; do
        case $1 in
        --php_versions)
            PHP_VERSIONS_WITHOUT_DOT=($2)
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

main() {
    this_script_dir="$(dirname "${BASH_SOURCE[0]}")"
    this_script_dir="$(realpath "${this_script_dir}")"

    repo_root_dir="$(realpath "${this_script_dir}/../..")"
    source "${repo_root_dir}/tools/shared.sh"

    source "${repo_root_dir}/tools/read_properties.sh"
    read_properties "${repo_root_dir}/project.properties" _PROJECT_PROPERTIES

    # Parse arguments
    parse_args "$@"

    # Validate required arguments
    if [[ "${#PHP_VERSIONS_WITHOUT_DOT[@]}" -eq 0 ]]; then
        echo "::error Missing required arguments."
        show_help
        exit 1
    fi

    local _LOCK_FILES_DIR="${repo_root_dir}/${_PROJECT_PROPERTIES_GENERATED_LOCK_FILES_FOLDER:?}"
    local composer_json_file_name
    composer_json_file_name="$(build_generated_composer_json_file_name "prod")"
    local composer_json_full_path="${_LOCK_FILES_DIR}/${composer_json_file_name}"

    if [ ! -f "${composer_json_full_path}" ]; then
        echo "::error Composer JSON file not found at ${composer_json_full_path}"
        exit 1
    fi

    failed_versions=()
    for PHP_version_no_dot in "${PHP_VERSIONS_WITHOUT_DOT[@]}"; do
        local PHP_version_dot_separated
        PHP_version_dot_separated=$(convert_no_dot_to_dot_separated_version "${PHP_version_no_dot}")

        echo "::group::Running composer audit for PHP ${PHP_version_dot_separated} ..."

        local composer_lock_file_name
        composer_lock_file_name="$(build_generated_composer_lock_file_name "prod" "${PHP_version_no_dot}")"
        local composer_lock_full_path="${_LOCK_FILES_DIR}/${composer_lock_file_name}"

        if [ ! -f "${composer_lock_full_path}" ]; then
            echo "::error Composer lock file not found at ${composer_lock_full_path}"
            failed_versions+=("${PHP_version_dot_separated}")
            echo "::endgroup::"
            continue
        fi

        local PHP_docker_image
        PHP_docker_image=$(build_light_PHP_docker_image_name_for_version_no_dot "${PHP_version_no_dot}")

        set +e
        docker run --rm \
            -v "${composer_json_full_path}:/audit_workdir/composer.json:ro" \
            -v "${composer_lock_full_path}:/audit_workdir/composer.lock:ro" \
            -w "/audit_workdir" \
            "${PHP_docker_image}" \
            sh -c "\
                curl -sS https://getcomposer.org/installer | php -- --filename=composer --install-dir=/usr/local/bin \
                && composer audit --no-dev --locked \
            "

        if [ $? -ne 0 ]; then
            echo "::error Composer audit failed for PHP ${PHP_version_dot_separated}. At least one vulnerable dependency found."
            failed_versions+=("${PHP_version_dot_separated}")
        fi

        echo "::endgroup::"
        set -e
    done

    if [ ${#failed_versions[@]} -ne 0 ]; then
        echo "::error Composer audit failed for the following PHP versions: ${failed_versions[*]}"
        exit 1
    fi

    echo "All audits passed."
}

main "$@"
