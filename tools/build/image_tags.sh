#!/usr/bin/env bash
set -e -u -o pipefail
#set -x

show_help() {
    echo "Usage: $0 --image <image> --version <version> --libc <glibc|musl> --released_versions <versions>"
    echo
    echo "Prints the tags to publish the distro image under, one per line."
    echo "A floating tag moves only when the version is the newest release it covers, so a backport"
    echo "or a rerun for an old release doesn't send users of latest back to an older line."
    echo
    echo "Arguments:"
    echo "  --image    Required. Image name without a tag (eg. ghcr.io/open-telemetry/opentelemetry-php-distro)."
    echo "  --version  Required. Release version (eg. 0.7.1)."
    echo "  --libc     Required. glibc or musl. musl tags take a -musl suffix."
    echo "  --released_versions"
    echo "             Required, may be empty. Released versions separated by whitespace (eg. \"0.7.0 0.7.1\")."
    echo "             Pre-releases and anything that isn't MAJOR.MINOR.PATCH are ignored."
    echo
    echo "Example:"
    echo "  $0 --image ghcr.io/open-telemetry/opentelemetry-php-distro --version 0.7.1 --libc musl --released_versions \"0.7.0 0.7.1\""
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
            --released_versions)
                RELEASED_VERSIONS="$2"
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

if [[ -z "${IMAGE+x}" ]] || [[ -z "${VERSION+x}" ]] || [[ -z "${LIBC+x}" ]] || [[ -z "${RELEASED_VERSIONS+x}" ]]; then
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
patch="${BASH_REMATCH[3]}"
pre_release="${BASH_REMATCH[4]}"

is_later_than_version() {
    if (( 10#$1 != 10#${major} )); then
        (( 10#$1 > 10#${major} ))
        return
    fi
    if (( 10#$2 != 10#${minor} )); then
        (( 10#$2 > 10#${minor} ))
        return
    fi
    (( 10#$3 > 10#${patch} ))
}

newest_overall=true
newest_in_major=true
newest_in_minor=true
# shellcheck disable=SC2086 # split the list on whitespace
for released in ${RELEASED_VERSIONS}; do
    if [[ ! "${released}" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
        continue
    fi
    released_major="${BASH_REMATCH[1]}"
    released_minor="${BASH_REMATCH[2]}"
    if ! is_later_than_version "${released_major}" "${released_minor}" "${BASH_REMATCH[3]}"; then
        continue
    fi
    newest_overall=false
    if (( 10#${released_major} == 10#${major} )); then
        newest_in_major=false
        if (( 10#${released_minor} == 10#${minor} )); then
            newest_in_minor=false
        fi
    fi
done

echo "${IMAGE}:${VERSION}${suffix}"

# A pre-release must not move the tags users follow.
if [[ -n "${pre_release}" ]]; then
    exit 0
fi

if [[ "${newest_in_minor}" == true ]]; then
    echo "${IMAGE}:${major}.${minor}${suffix}"
fi
# Before 1.0 a minor release can break things, so a major tag would pull breaking changes.
if [[ "${major}" != "0" ]] && [[ "${newest_in_major}" == true ]]; then
    echo "${IMAGE}:${major}${suffix}"
fi
if [[ "${newest_overall}" == true ]]; then
    echo "${IMAGE}:${floating_tag}"
fi
