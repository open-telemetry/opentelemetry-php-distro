#!/usr/bin/env bash
set -e -u -o pipefail
#set -x

source "tools/shared.sh"
verify_running_from_repo_root
source "tools/read_properties.sh"
read_properties "project.properties" _PROJECT_PROPERTIES

PLATFORM=""
SAPI="cli"

show_help() {
    echo "Usage: $0 --image <image> --libc <glibc|musl> --php_version <version> [--sapi <cli|fpm>] [--platform <platform>]"
    echo
    echo "Copies the distro image into a stock PHP image with COPY --from and runs the package smoke test."
    echo
    echo "Arguments:"
    echo "  --image        Required. Distro image to test."
    echo "  --libc         Required. libc the distro image was built for: glibc or musl."
    echo "  --php_version  Required. PHP version to test with (eg. 8.4)."
    echo "  --sapi         Optional. PHP image variant to test with: cli or fpm. Default: cli."
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
            --sapi)
                SAPI="$2"
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

case "${SAPI}" in
    cli|fpm) ;;
    *)
        echo "Unknown SAPI: ${SAPI}"
        show_help
        exit 1
        ;;
esac

if [[ "${PHP_VERSION}" == "8.6" && "${SAPI}" == "fpm" ]]; then
    # Official php:8.6-fpm images are ZTS-only (unsupported) and there is no own NTS fpm image, see get_nts_php_docker_image()
    echo "Skipping: no NTS php-fpm image available for PHP ${PHP_VERSION}"
    exit 0
fi

case "${LIBC}" in
    glibc)
        PHP_IMAGE="$(get_nts_php_docker_image "${PHP_VERSION}" glibc "php:${PHP_VERSION}-${SAPI}" "${PLATFORM:-}")"
        ;;
    musl)
        PHP_IMAGE="$(get_nts_php_docker_image "${PHP_VERSION}" musl "php:${PHP_VERSION}-${SAPI}-alpine" "${PLATFORM:-}")"
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

    # A locally loaded image holds one platform. Without this check, a build for
    # another platform fails with a manifest error, or picks up a stale image.
    image_platform=$(docker image inspect --format '{{.Os}}/{{.Architecture}}' "${IMAGE}")
    if [[ "${image_platform}" != "${PLATFORM}" ]]; then
        echo "Error: ${IMAGE} is for ${image_platform}, not ${PLATFORM}."
        exit 1
    fi
fi

this_script_dir="$(dirname "${BASH_SOURCE[0]}")"
test_image_tag="otel-php-distro-tests-image-${LIBC}-${PHP_VERSION}-${SAPI}"

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

checks="php /source/packaging/test/smokeTest.php ${_PROJECT_PROPERTIES_PHP_SCOPER_PREFIX} && sh /source/packaging/test/checkLicenseFiles.sh"
if [[ "${SAPI}" == "fpm" ]]; then
    checks+=" && sh /source/tools/test/image/check_fpm.sh"
fi
# post-install.sh needs bash, which only the Debian based images have. The settings
# it writes don't depend on libc, so checking on glibc is enough.
if [[ "${LIBC}" == "glibc" ]]; then
    checks+=" && sh /source/tools/test/image/check_ini_matches_post_install.sh"
fi

docker run --rm ${platform_args[@]+"${platform_args[@]}"} \
    -v "${PWD}:/source:ro" \
    -e OTEL_PHP_LOG_LEVEL_STDERR=error \
    "${test_image_tag}" \
    sh -c "${checks}"
