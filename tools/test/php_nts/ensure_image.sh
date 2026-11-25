#!/usr/bin/env bash
set -e -u -o pipefail

#
# Makes sure a local non-thread-safe (NTS) PHP docker image is available and prints its name on stdout.
# See ./Dockerfile for why it is needed.
#
# Image is taken, in order, from: local docker images, the tarball cache directory
# (OTEL_PHP_NTS_IMAGE_CACHE_DIR, restored by CI cache), or built from ./Dockerfile (and saved to the cache directory).
#

this_script_dir="$(realpath "$(dirname "${BASH_SOURCE[0]}")")"

function show_help() {
    echo "Usage: $0 --php_version <X.Y> --libc <glibc|musl> [--platform <linux/amd64|linux/arm64>]"
    echo "Prints the name of the local NTS image, e.g. otel-php-nts:8.6-cli-amd64 (glibc) or otel-php-nts:8.6-cli-alpine-amd64 (musl)."
}

php_version=""
libc=""
platform=""
while [[ "$#" -gt 0 ]]; do
    case $1 in
        --php_version) php_version="${2:?}"; shift ;;
        --libc) libc="${2:?}"; shift ;;
        --platform) platform="${2:?}"; shift ;;
        --help) show_help; exit 0 ;;
        *) echo "Unknown parameter passed: $1" >&2; show_help >&2; exit 1 ;;
    esac
    shift
done
if [[ -z "${php_version}" || -z "${libc}" ]]; then show_help >&2; exit 1; fi

case "${libc}" in
    glibc) base_image="php:${php_version}-cli-trixie"; tag="otel-php-nts:${php_version}-cli" ;;
    musl)  base_image="php:${php_version}-cli-alpine"; tag="otel-php-nts:${php_version}-cli-alpine" ;;
    *) echo "Unknown libc: ${libc}" >&2; exit 1 ;;
esac

# Native platform of the docker host when not given
if [[ -z "${platform}" ]]; then
    case "$(uname -m)" in
        aarch64|arm64) platform="linux/arm64" ;;
        *) platform="linux/amd64" ;;
    esac
fi
# Normalize (run.sh scripts use linux/x86_64)
case "${platform}" in
    linux/x86_64) platform="linux/amd64" ;;
    linux/aarch64) platform="linux/arm64" ;;
esac
tag="${tag}-${platform#linux/}"

dockerfile_hash="$(sha256sum "${this_script_dir}/Dockerfile" | cut -c1-12)"
label_key="otel.php_nts.dockerfile_hash"
cache_dir="${OTEL_PHP_NTS_IMAGE_CACHE_DIR:-}"
cache_file=""
if [[ -n "${cache_dir}" ]]; then
    mkdir -p "${cache_dir}"
    cache_file="${cache_dir}/${tag//[:\/]/_}.tar"
fi

function image_is_current() {
    [[ "$(docker image inspect --format "{{ index .Config.Labels \"${label_key}\" }}" "${tag}" 2>/dev/null || true)" == "${dockerfile_hash}" ]]
}

if ! image_is_current && [[ -n "${cache_file}" && -f "${cache_file}" ]]; then
    echo "Loading ${tag} from ${cache_file}" >&2
    docker load --input "${cache_file}" >&2 || true
fi

if ! image_is_current; then
    echo "Building ${tag} from ${base_image} (compiling PHP, this takes several minutes)" >&2
    docker build \
        --platform "${platform}" \
        --build-arg "BASE_IMAGE=${base_image}" \
        --label "${label_key}=${dockerfile_hash}" \
        --tag "${tag}" \
        --file "${this_script_dir}/Dockerfile" \
        "${this_script_dir}" >&2
    if [[ -n "${cache_file}" ]]; then
        docker save --output "${cache_file}" "${tag}" >&2
    fi
fi

echo "${tag}"
