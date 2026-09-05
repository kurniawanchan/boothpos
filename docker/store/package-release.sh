#!/usr/bin/env bash
# Packages one BoothPOS store-deployment release
# (specs/016-docker-store-deployment/, research.md R3). Run by whoever
# maintains releases — NOT run on a store's machine.
#
# Produces BOTH distribution paths from one build:
#   1. An offline archive (dist/boothpos-store-<version>.tar), for
#      venues with unreliable/no internet — transferred via USB/download.
#   2. An optional registry push, IF a registry is configured via
#      STORE_REGISTRY — never required, must not fail if absent.
#
# Usage: docker/store/package-release.sh <version>
#   e.g. docker/store/package-release.sh 1.0.0
set -euo pipefail

VERSION="${1:?Usage: docker/store/package-release.sh <version>}"
IMAGE_TAG="boothpos-store:${VERSION}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DIST_DIR="${REPO_ROOT}/dist"

echo "[package-release] building ${IMAGE_TAG}..."
docker build -f "${REPO_ROOT}/docker/store/Dockerfile" -t "${IMAGE_TAG}" "${REPO_ROOT}"

mkdir -p "${DIST_DIR}"
ARCHIVE_PATH="${DIST_DIR}/boothpos-store-${VERSION}.tar"
echo "[package-release] saving offline archive to ${ARCHIVE_PATH}..."
docker save "${IMAGE_TAG}" -o "${ARCHIVE_PATH}"
echo "[package-release] offline archive ready: ${ARCHIVE_PATH}"

# Registry push is opt-in — only attempted if STORE_REGISTRY is set.
# Never a hard failure if absent: not every maintainer has a registry
# set up, and the offline path above is already a complete, valid
# distribution on its own (research.md R3).
if [ -n "${STORE_REGISTRY:-}" ]; then
    REGISTRY_TAG="${STORE_REGISTRY}/boothpos-store:${VERSION}"
    echo "[package-release] tagging and pushing to registry: ${REGISTRY_TAG}..."
    docker tag "${IMAGE_TAG}" "${REGISTRY_TAG}"
    docker push "${REGISTRY_TAG}"
    echo "[package-release] pushed: ${REGISTRY_TAG}"
else
    echo "[package-release] STORE_REGISTRY not set — skipping registry push (offline archive only)."
fi
