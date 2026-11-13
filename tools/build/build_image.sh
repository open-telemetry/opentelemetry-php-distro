#!/usr/bin/env bash
set -e -u -o pipefail
#set -x

source "tools/shared.sh"
verify_running_from_repo_root
source "tools/read_properties.sh"
read_properties "project.properties" _PROJECT_PROPERTIES

PACKAGES_DIR="${PWD}/_BUILT/packages"
PLATFORMS=""

show_help() {
    echo "Usage: $0 --libc <glibc|musl> --tag <tag> [--packages_dir <dir>] [--platforms <platforms>]"
    echo
    echo "Builds the distro image (files only, for COPY --from) from built .deb or .apk packages."
    echo
    echo "Arguments:"
    echo "  --libc          Required. glibc takes the files from the .deb, musl from the .apk."
    echo "  --tag           Required. Image tag."
    echo "  --packages_dir  Optional. Directory holding the built packages. Default: _BUILT/packages"
    echo "  --platforms     Optional. Comma separated platforms (eg. linux/amd64,linux/arm64)."
    echo "                  Default: the Docker host's platform, loaded into the local image store."
    echo
    echo "Example:"
    echo "  $0 --libc musl --tag opentelemetry-php-distro:local-musl"
}

parse_args() {
    while [[ "$#" -gt 0 ]]; do
        case $1 in
            --libc)
                LIBC="$2"
                shift
                ;;
            --tag)
                TAG="$2"
                shift
                ;;
            --packages_dir)
                PACKAGES_DIR="$2"
                shift
                ;;
            --platforms)
                PLATFORMS="$2"
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

parse_args "$@"

if [[ -z "${LIBC+x}" ]] || [[ -z "${TAG+x}" ]]; then
    echo "Error: Missing required arguments."
    show_help
    exit 1
fi

platform_args=()
if [[ -n "${PLATFORMS}" ]]; then
    platform_args=(--platform "${PLATFORMS}")
fi

docker buildx build ${platform_args[@]+"${platform_args[@]}"} \
    --build-context "packages=${PACKAGES_DIR}" \
    --build-arg "LIBC=${LIBC}" \
    --build-arg "VERSION=${_PROJECT_PROPERTIES_VERSION}" \
    --tag "${TAG}" \
    --load \
    packaging/image
