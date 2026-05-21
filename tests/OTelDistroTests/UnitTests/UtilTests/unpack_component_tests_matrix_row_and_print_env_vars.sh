#!/usr/bin/env bash
set -e -u -o pipefail
#set -x

function main {
    this_script_dir="$( dirname "${BASH_SOURCE[0]}" )"
    this_script_dir="$( realpath "${this_script_dir}" )"
    repo_root_dir="$( realpath "${this_script_dir}/../../../.." )"

    source "${repo_root_dir}/tools/test/component/unpack_matrix_row.sh"

    local -r matrix_row="${1:?}"
    local -r verbose='true'
    unpack_matrix_row "${matrix_row}" "${verbose}" &> /dev/null

    env | sort 2> /dev/null
}

main "$@"

