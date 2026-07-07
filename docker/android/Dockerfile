# docker/android/Dockerfile — pinned Android SDK build image for the Capacitor
# shell APK (used only by scripts/build-android.sh; never part of the running
# stack). This host has no Android SDK and no sudo, so the whole toolchain
# lives in this image.
#
# Version pins (all must satisfy the Capacitor android/ project):
#   JDK 21          — required by AGP 8.7 / Gradle 8.11 (android/build.gradle)
#   platforms;35    — compileSdkVersion in android/variables.gradle
#   build-tools 34/35 — AGP's default + apksigner for the verify step
FROM eclipse-temurin:21-jdk-noble

# commandlinetools zip version (Google keeps old zips downloadable; 11076708 = v12.0)
ARG CMDLINE_TOOLS=11076708

ENV ANDROID_HOME=/opt/android-sdk
ENV PATH=$PATH:$ANDROID_HOME/cmdline-tools/latest/bin:$ANDROID_HOME/platform-tools:$ANDROID_HOME/build-tools/35.0.0

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip wget \
    && rm -rf /var/lib/apt/lists/*

RUN mkdir -p "$ANDROID_HOME/cmdline-tools" \
    && wget -q "https://dl.google.com/android/repository/commandlinetools-linux-${CMDLINE_TOOLS}_latest.zip" -O /tmp/clt.zip \
    && unzip -q /tmp/clt.zip -d "$ANDROID_HOME/cmdline-tools" \
    && mv "$ANDROID_HOME/cmdline-tools/cmdline-tools" "$ANDROID_HOME/cmdline-tools/latest" \
    && rm /tmp/clt.zip

# Licenses accepted at image-build time; the build container runs as the host
# UID (no sudo on the host → files it writes must stay host-owned), so the SDK
# tree must be world-readable.
RUN yes | sdkmanager --licenses > /dev/null \
    && sdkmanager --install "platform-tools" "platforms;android-35" "build-tools;35.0.0" "build-tools;34.0.0" \
    && chmod -R a+rX "$ANDROID_HOME"
