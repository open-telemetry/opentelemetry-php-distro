#!/usr/bin/env bash
set -e -u -o pipefail
#set -x

this_script_dir="$( dirname "${BASH_SOURCE[0]}" )"
this_script_dir="$( realpath "${this_script_dir}" )"
repo_root_dir="$( realpath "${this_script_dir}/../../../.." )"

source "${repo_root_dir}/tools/test/component/unpack_matrix_row.sh"

unpack_matrix_row "$@" "OTEL_PHP_TESTS" "true" &> /dev/null

env | sort 2> /dev/null
