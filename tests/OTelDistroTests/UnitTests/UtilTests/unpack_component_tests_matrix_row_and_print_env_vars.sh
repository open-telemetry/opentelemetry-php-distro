#!/usr/bin/env bash
set -e -u -o pipefail
set -x

function main {
    this_script_dir="$(dirname "${BASH_SOURCE[0]}")"
    this_script_dir="$(realpath "${this_script_dir}")"
    repo_root_dir="$(realpath "${this_script_dir}/../../../..")"

    pushd "${repo_root_dir}" || exit 1

    echo "Current directory: ${PWD}"

    source ./tools/test/component/unpack_matrix_row.sh

    local -r matrix_row="${1:?}"
    local -r env_output_dest_file="${2:?}"

    local -r unpack_matrix_row_verbose='true'
    unpack_matrix_row "${matrix_row}" "${unpack_matrix_row_verbose}"

    env | sort > "${env_output_dest_file}"

    popd || exit 1
}

main "$@"
