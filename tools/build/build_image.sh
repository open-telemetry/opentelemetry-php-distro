#!/usr/bin/env bash
set -e -u -o pipefail
#set -x

source "tools/shared.sh"
verify_running_from_repo_root
source "tools/read_properties.sh"
read_properties "project.properties" _PROJECT_PROPERTIES

PACKAGES_DIR="${PWD}/_BUILT/packages"
PLATFORMS=""
TAGS=()
PUSH=false
METADATA_FILE=""

show_help() {
    echo "Usage: $0 --libc <glibc|musl> --tag <tag> [--tag <tag> ...] [--packages_dir <dir>] [--platforms <platforms>] [--push] [--metadata_file <file>]"
    echo
    echo "Builds the distro image (files only, for COPY --from) from built .deb or .apk packages."
    echo
    echo "Arguments:"
    echo "  --libc           Required. glibc takes the files from the .deb, musl from the .apk."
    echo "  --tag            Required. Image tag. Repeat for more tags."
    echo "  --packages_dir   Optional. Directory holding the built packages. Default: _BUILT/packages"
    echo "  --platforms      Optional. Comma separated platforms (eg. linux/amd64,linux/arm64)."
    echo "                   Default: the Docker host's platform."
    echo "  --push           Optional. Push to the registry with provenance and SBOM attestations,"
    echo "                   instead of loading into the local image store."
    echo "  --metadata_file  Optional. Write the build result, including the pushed digest, to this file."
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
                TAGS+=("$2")
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
            --push)
                PUSH=true
                ;;
            --metadata_file)
                METADATA_FILE="$2"
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

if [[ -z "${LIBC+x}" ]] || [[ ${#TAGS[@]} -eq 0 ]]; then
    echo "Error: Missing required arguments."
    show_help
    exit 1
fi

build_args=()
if [[ -n "${PLATFORMS}" ]]; then
    build_args+=(--platform "${PLATFORMS}")
fi
for tag in "${TAGS[@]}"; do
    build_args+=(--tag "${tag}")
done
if [[ "${PUSH}" == "true" ]]; then
    build_args+=(--push --provenance=mode=max --sbom=true)
    # ghcr.io reads a multi-platform image's description from the index, not from the
    # image labels. The local image store has no index, so only set it when pushing.
    build_args+=(--annotation "index:org.opencontainers.image.description=OpenTelemetry PHP Distro files, for use with COPY --from")
else
    build_args+=(--load)
fi
if [[ -n "${METADATA_FILE}" ]]; then
    build_args+=(--metadata-file "${METADATA_FILE}")
fi

docker buildx build "${build_args[@]}" \
    --build-context "packages=${PACKAGES_DIR}" \
    --build-arg "LIBC=${LIBC}" \
    --build-arg "VERSION=${_PROJECT_PROPERTIES_VERSION}" \
    packaging/image
