#!/usr/bin/env bash
set -e -u -o pipefail
#set -x

source "tools/shared.sh"
verify_running_from_repo_root
source "tools/read_properties.sh"
read_properties "project.properties" _PROJECT_PROPERTIES

PLATFORM=""

show_help() {
    echo "Usage: $0 --image <image> --libc <glibc|musl> --php_version <version> [--platform <platform>]"
    echo
    echo "Copies the distro image into a stock PHP image with COPY --from and runs the package smoke test."
    echo
    echo "Arguments:"
    echo "  --image        Required. Distro image to test."
    echo "  --libc         Required. libc the distro image was built for: glibc or musl."
    echo "  --php_version  Required. PHP version to test with (eg. 8.4)."
    echo "  --platform     Optional. Docker platform (eg. linux/arm64). Default: the Docker host's platform."
    echo
    echo "Example:"
    echo "  $0 --image opentelemetry-php-distro:local-musl --libc musl --php_version 8.4"
}

parse_args() {
    while [[ "$#" -gt 0 ]]; do
        case $1 in
            --image)
                IMAGE="$2"
                shift
                ;;
            --libc)
                LIBC="$2"
                shift
                ;;
            --php_version)
                PHP_VERSION="$2"
                shift
                ;;
            --platform)
                PLATFORM="$2"
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

if [[ -z "${IMAGE+x}" ]] || [[ -z "${LIBC+x}" ]] || [[ -z "${PHP_VERSION+x}" ]]; then
    echo "Error: Missing required arguments."
    show_help
    exit 1
fi

case "${LIBC}" in
    glibc)
        PHP_IMAGE="php:${PHP_VERSION}-cli"
        ;;
    musl)
        PHP_IMAGE="php:${PHP_VERSION}-cli-alpine"
        ;;
    *)
        echo "Unknown libc: ${LIBC}"
        show_help
        exit 1
        ;;
esac

platform_args=()
if [[ -n "${PLATFORM}" ]]; then
    platform_args=(--platform "${PLATFORM}")
fi

this_script_dir="$(dirname "${BASH_SOURCE[0]}")"
test_image_tag="otel-php-distro-tests-image-${LIBC}-${PHP_VERSION}"

echo "Testing ${IMAGE} in ${PHP_IMAGE}"

remove_test_image() {
    docker image rm "${test_image_tag}" >/dev/null 2>&1 || true
}
trap remove_test_image EXIT

docker build ${platform_args[@]+"${platform_args[@]}"} \
    --build-arg "DISTRO_IMAGE=${IMAGE}" \
    --build-arg "PHP_IMAGE=${PHP_IMAGE}" \
    --tag "${test_image_tag}" \
    "${this_script_dir}"

docker run --rm ${platform_args[@]+"${platform_args[@]}"} \
    -v "${PWD}/packaging/test:/smoke_test:ro" \
    -e OTEL_PHP_LOG_LEVEL_STDERR=error \
    "${test_image_tag}" \
    sh -c "php /smoke_test/smokeTest.php ${_PROJECT_PROPERTIES_PHP_SCOPER_PREFIX} \
        && echo -n 'Checking for \"copyright\" files existence: ' \
        && test -f /opt/opentelemetry/php/distro/LICENSE \
        && test -f /opt/opentelemetry/php/distro/NOTICE \
        && echo OK"
