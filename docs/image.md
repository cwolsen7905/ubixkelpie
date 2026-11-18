# The runtime image

Built from `Dockerfile` on the uBixCore PHP runtime (Alpine, nginx + PHP-FPM, port 8080).

## Contents

- The app code and its Composer dependencies (from `composer.lock`, no dev packages).
- `git`.
- Node.js and npm, and the **`claude` CLI** (`@anthropic-ai/claude-code`), which the issue
  fixer shells out to. It is installed through npm because the native installer targets glibc
  and the base image is musl.

## The claude pin

The version is the `CLAUDE_CODE_VERSION` build argument, defaulting to the value in the
`Dockerfile`. To bump it, change that default (or pass `--build-arg`) in an MR; the build runs
`claude --version`, so an image without a working binary does not build.

## Credentials

None are baked into the image. Authentication for `claude` is supplied at runtime from
uBixVault, like every other secret.

## Check

```bash
docker run --rm --entrypoint claude <image> --version
```
