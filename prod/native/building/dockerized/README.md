# Docker Build Images

This directory contains Docker Compose configuration for building native components of the OpenTelemetry PHP Distro across multiple architectures.

## Image Architecture

The build system uses a two-layer image structure:

### 1. Base Build Images (GCC Toolchain)

Base images contain the core build toolchain including GCC, Binutils, CMake, and Python.

**Image naming pattern:**
```
otel/opentelemetry-php-distro-dev:native-build-{architecture}-gcc{gcc_version}-v{base_version}
```

**Supported architectures:**
- `linux-x86-64` - Linux x86-64 (glibc)
- `linuxmusl-x86-64` - Linux x86-64 (musl)
- `linux-arm64` - Linux ARM64 (glibc)
- `linuxmusl-arm64` - Linux ARM64 (musl)

**Current versions:**
- GCC: 15.2.0
- Binutils: 2.45.1
- CMake: 4.2.1
- Python: 3.14.0
- Base version: v0.0.2

**Example:**
```
otel/opentelemetry-php-distro-dev:native-build-linux-x86-64-gcc15.2.0-v0.0.2
```

### 2. Conan Cache Images

Built on top of base images, these images include pre-populated Conan package caches to speed up builds.

**Image naming pattern:**
```
otel/opentelemetry-php-distro-dev:native-build-{architecture}-gcc{gcc_version}-v{base_version}-conancache-v{cache_version}
```

**Current cache version:** v0.0.1

**Example:**
```
otel/opentelemetry-php-distro-dev:native-build-linux-x86-64-gcc15.2.0-v0.0.2-conancache-v0.0.1
```

## Image Dependency Chain

```
Base Image (GCC Toolchain)
    └─→ Conan Cache Image
```

Each Conan cache image depends on its corresponding base image:

| Conan Cache Image | Base Image |
|------------------|------------|
| `build_linux-x86-64_conan` | `build_linux-x86-64` |
| `build_linuxmusl-x86-64_conan` | `build_linuxmusl-x86-64` |
| `build_linux-arm64_conan` | `build_linux-arm64` |
| `build_linuxmusl-arm64_conan` | `build_linuxmusl-arm64` |

## Building Images

Build base images only:
```bash
docker compose build build_linux-x86-64
docker compose build build_linuxmusl-x86-64
docker compose build build_linux-arm64
docker compose build build_linuxmusl-arm64
```

Build Conan cache images (automatically builds base images as dependencies):
```bash
docker compose build build_linux-x86-64_conan
docker compose build build_linuxmusl-x86-64_conan
docker compose build build_linux-arm64_conan
docker compose build build_linuxmusl-arm64_conan
```

Build all images:
```bash
docker compose build
```

## Platform Requirements

ARM64 images (`linux-arm64` and `linuxmusl-arm64`) require ARM64 platform support. These are built with `platform: linux/arm64` specification and should be built on ARM64 hardware or using Docker BuildKit with cross-platform support.

## Version Management

**All versions are parameterized and only require editing the `docker-compose.yml` file.**

All toolchain versions (GCC, Binutils, CMake, Python) and image tags are defined in `docker-compose.yml`. This single source of truth is used by both local builds and CI/CD pipelines.

### CI/CD Automatic Image Building

During CI builds, the image versions are automatically read from `docker-compose.yml`:
- If the image already exists in DockerHub, it will be pulled and used
- If the image doesn't exist in DockerHub, it will be automatically built during the CI process

This ensures that new image versions are created on-demand without manual intervention.

### Updating Toolchain Versions

When updating toolchain versions:

1. **Edit only `docker-compose.yml`** to update:
   - Version arguments (`GCC_VERSION`, `BINUTILS_VERSION`, `CMAKE_VERSION`, `PYTHON_VERSION`)
   - Image tags (increment base version, e.g., `v0.0.2` → `v0.0.3`)
   - Cache version if needed (e.g., `conancache-v0.0.1` → `conancache-v0.0.2`)
   - `BASEIMAGE` argument in Conan cache services to match new base image tags

2. **Commit and push** to trigger CI build

3. CI will automatically detect the new image versions and build them if they don't exist in DockerHub

## Publishing Images

After building and testing images locally, you must create a branch in the upstream repository. Only in this case will the images be built in CI and pushed to Docker Hub. For pull requests from forks, images will be built but push is blocked for security reasons.

## Updating the Base Image for Distro Builds

To update the base image used for building the distro, modify the image reference in the [`tools/build/build_native.sh`](../../../../tools/build/build_native.sh) script.
