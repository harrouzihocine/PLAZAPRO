#!/usr/bin/env bash
# scripts/build-android.sh — build the signed PLAZA PRO Android APK (Capacitor shell).
#
#   Usage:  ./scripts/build-android.sh
#
# The shell runs in REMOTE mode (frontend/capacitor.config.json server.url →
# https://app.plaza-pro.com), so web-app updates ship with normal deploys and
# this script is only needed when the NATIVE shell changes (plugins, icons,
# permissions, Capacitor upgrades). Before a distributed rebuild, bump
# versionCode + versionName in frontend/android/app/build.gradle — procedure
# and keystore handling in docs/android-app.md.
#
# Everything runs in containers (this host has no Android SDK / no sudo):
#   node:20-alpine        npm ci + `cap sync` (same pattern as deploy.sh)
#   plaza-android-sdk     Gradle/AGP build + apksigner (docker/android/Dockerfile)
#
# Inputs:
#   PLAZA_KEYSTORE_DIR    dir holding keystore.properties + the .keystore file
#                         (default: ~/plaza-prod/secrets/android — prod secrets)
#   PLAZA_GRADLE_CACHE    persistent Gradle home between builds
#                         (default: ~/.cache/plaza-android-gradle)
# Output:
#   frontend/android/artifacts/plaza-pro.apk (+ versioned copy + version.json)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SDK_IMAGE=plaza-android-sdk:35-jdk21
NODE_IMAGE=node:20-alpine
KEYSTORE_DIR="${PLAZA_KEYSTORE_DIR:-$HOME/plaza-prod/secrets/android}"
GRADLE_CACHE="${PLAZA_GRADLE_CACHE:-$HOME/.cache/plaza-android-gradle}"
APK=frontend/android/app/build/outputs/apk/release/app-release.apk
OUT_DIR=frontend/android/artifacts

[[ -f "$KEYSTORE_DIR/keystore.properties" ]] || {
    echo "No signing config at $KEYSTORE_DIR/keystore.properties — an unsigned APK" >&2
    echo "cannot be installed. Generate the keystore ONCE (docs/android-app.md §Keystore)" >&2
    echo "or point PLAZA_KEYSTORE_DIR at it." >&2
    exit 1
}

echo "==> Web deps + Capacitor sync (stub webDir, plugin manifests)"
docker run --rm -u "$(id -u):$(id -g)" -e npm_config_cache=/tmp/npm-cache \
    -v "$ROOT/frontend":/app -w /app \
    "$NODE_IMAGE" sh -c "npm ci --no-audit --no-fund && npx cap sync android"

echo "==> Android SDK image (cached after first build)"
docker build -q -t "$SDK_IMAGE" docker/android

echo "==> Gradle assembleRelease (signed)"
mkdir -p "$GRADLE_CACHE"
docker run --rm -u "$(id -u):$(id -g)" \
    -e HOME=/tmp -e GRADLE_USER_HOME=/gradle-cache \
    -e PLAZA_KEYSTORE_PROPS=/keystore/keystore.properties \
    -v "$GRADLE_CACHE":/gradle-cache \
    -v "$KEYSTORE_DIR":/keystore:ro \
    -v "$ROOT/frontend":/project -w /project/android \
    "$SDK_IMAGE" ./gradlew --no-daemon assembleRelease

echo "==> Verifying signature"
docker run --rm -u "$(id -u):$(id -g)" -v "$ROOT/frontend":/project -w /project \
    "$SDK_IMAGE" apksigner verify --print-certs android/app/build/outputs/apk/release/app-release.apk

VERSION_NAME=$(grep -oP 'versionName "\K[^"]+' frontend/android/app/build.gradle)
VERSION_CODE=$(grep -oP 'versionCode \K\d+' frontend/android/app/build.gradle)

mkdir -p "$OUT_DIR"
cp "$APK" "$OUT_DIR/plaza-pro.apk"
cp "$APK" "$OUT_DIR/plaza-pro-v$VERSION_NAME.apk"
# Read by the /install page to show the downloadable version.
cat > "$OUT_DIR/version.json" <<EOF
{
    "versionName": "$VERSION_NAME",
    "versionCode": $VERSION_CODE,
    "builtAt": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
EOF

echo
echo "Built $OUT_DIR/plaza-pro.apk (v$VERSION_NAME, code $VERSION_CODE, $(du -h "$OUT_DIR/plaza-pro.apk" | cut -f1))"
echo "Publish it on the prod host:"
echo "  cp $OUT_DIR/plaza-pro.apk $OUT_DIR/version.json ~/plaza-prod/downloads/"
