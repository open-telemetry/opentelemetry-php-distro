#!/usr/bin/env bash
set -e -u -o pipefail
#set -x

show_help() {
    echo "Usage: $0 --image <image> --version <version> --libc <glibc|musl>"
    echo
    echo "Prints the tags to publish the distro image under, one per line."
    echo
    echo "Arguments:"
    echo "  --image    Required. Image name without a tag (eg. ghcr.io/open-telemetry/opentelemetry-php-distro)."
    echo "  --version  Required. Release version (eg. 0.7.1)."
    echo "  --libc     Required. glibc or musl. musl tags take a -musl suffix."
    echo
    echo "Example:"
    echo "  $0 --image ghcr.io/open-telemetry/opentelemetry-php-distro --version 0.7.1 --libc musl"
}

parse_args() {
    while [[ "$#" -gt 0 ]]; do
        case $1 in
            --image)
                IMAGE="$2"
                shift
                ;;
            --version)
                VERSION="$2"
                shift
                ;;
            --libc)
                LIBC="$2"
                shift
                ;;
            --help)
                show_help
                exit 0
                ;;
            *)
                echo "Unknown parameter passed: $1" >&2
                show_help >&2
                exit 1
                ;;
        esac
        shift
    done
}

parse_args "$@"

if [[ -z "${IMAGE+x}" ]] || [[ -z "${VERSION+x}" ]] || [[ -z "${LIBC+x}" ]]; then
    echo "Error: Missing required arguments." >&2
    show_help >&2
    exit 1
fi

case "${LIBC}" in
    glibc)
        suffix=""
        floating_tag="latest"
        ;;
    musl)
        suffix="-musl"
        floating_tag="musl"
        ;;
    *)
        echo "Unknown libc: ${LIBC}" >&2
        exit 1
        ;;
esac

if [[ ! "${VERSION}" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)(-[0-9A-Za-z.-]+)?$ ]]; then
    echo "Version is not MAJOR.MINOR.PATCH with an optional -pre-release: ${VERSION}" >&2
    exit 1
fi
major="${BASH_REMATCH[1]}"
minor="${BASH_REMATCH[2]}"
pre_release="${BASH_REMATCH[4]}"

echo "${IMAGE}:${VERSION}${suffix}"

# A pre-release must not move the tags users follow.
if [[ -n "${pre_release}" ]]; then
    exit 0
fi

echo "${IMAGE}:${major}.${minor}${suffix}"
# Before 1.0 a minor release can break things, so a major tag would pull breaking changes.
if [[ "${major}" != "0" ]]; then
    echo "${IMAGE}:${major}${suffix}"
fi
echo "${IMAGE}:${floating_tag}"
