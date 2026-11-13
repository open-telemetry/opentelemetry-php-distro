#!/usr/bin/env bash
set -e -u -o pipefail
#set -x

source "tools/shared.sh"
verify_running_from_repo_root

image="ghcr.io/example/distro"
failures=0

print_indented() {
    local line
    while IFS= read -r line; do
        echo "    ${line}"
    done <<< "$1"
}

assert_tags() {
    local -r description="$1"
    local -r expected="$2"
    shift 2

    local actual
    if ! actual=$(./tools/build/image_tags.sh "$@" 2>&1); then
        echo "FAIL: ${description}: exited non-zero"
        echo "${actual}"
        failures=$((failures + 1))
        return
    fi
    if [[ "${actual}" != "${expected}" ]]; then
        echo "FAIL: ${description}"
        echo "  expected:"
        print_indented "${expected}"
        echo "  actual:"
        print_indented "${actual}"
        failures=$((failures + 1))
        return
    fi
    echo "ok: ${description}"
}

assert_fails() {
    local -r description="$1"
    local -r expected_error="$2"
    shift 2

    local output
    if output=$(./tools/build/image_tags.sh "$@" 2>&1); then
        echo "FAIL: ${description}: expected a non-zero exit"
        failures=$((failures + 1))
        return
    fi
    if [[ "${output}" != *"${expected_error}"* ]]; then
        echo "FAIL: ${description}: expected error containing '${expected_error}', got:"
        print_indented "${output}"
        failures=$((failures + 1))
        return
    fi
    echo "ok: ${description}"
}

assert_tags "0.x glibc release has no major tag" \
"${image}:0.7.1
${image}:0.7
${image}:latest" \
    --image "${image}" --version 0.7.1 --libc glibc

assert_tags "0.x musl release has no major tag" \
"${image}:0.7.1-musl
${image}:0.7-musl
${image}:musl" \
    --image "${image}" --version 0.7.1 --libc musl

assert_tags "1.x glibc release has a major tag" \
"${image}:1.2.3
${image}:1.2
${image}:1
${image}:latest" \
    --image "${image}" --version 1.2.3 --libc glibc

assert_tags "1.x musl release has a major tag" \
"${image}:1.2.3-musl
${image}:1.2-musl
${image}:1-musl
${image}:musl" \
    --image "${image}" --version 1.2.3 --libc musl

assert_tags "pre-release glibc gets only its own tag" \
"${image}:0.8.0-rc1" \
    --image "${image}" --version 0.8.0-rc1 --libc glibc

assert_tags "pre-release musl gets only its own tag" \
"${image}:0.8.0-rc1-musl" \
    --image "${image}" --version 0.8.0-rc1 --libc musl

assert_fails "unknown libc" "Unknown libc: uclibc" \
    --image "${image}" --version 0.7.1 --libc uclibc
assert_fails "version that is not a valid tag" "Version is not MAJOR.MINOR.PATCH" \
    --image "${image}" --version "0.7.1+build" --libc glibc
assert_fails "missing version" "Missing required arguments" \
    --image "${image}" --libc glibc

if [[ ${failures} -ne 0 ]]; then
    echo "${failures} image tag test(s) failed"
    exit 1
fi
echo "All image tag tests passed"
