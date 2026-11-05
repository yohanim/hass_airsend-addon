# Changelog

## Unreleased

- Build: `build.yaml` content moved into the Dockerfile (Home Assistant
  BuildKit app builds); multi-arch base image `ghcr.io/home-assistant/base:3.24`.
  Standalone `docker build` no longer needs a `BUILD_FROM` argument.
- Drop armhf, armv7 and i386: Home Assistant no longer supports 32-bit systems
  and no longer publishes base images for them.
- run.sh: quoting fixes (shellcheck).

## 1.3

- Standalone version

## 1.2

- RF listening and states managements on callback

## 1.1

- Repository Installation

## 1.0

- Initial release
