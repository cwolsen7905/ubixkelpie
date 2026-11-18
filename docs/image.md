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

Trade-off: the pin fixes the `@anthropic-ai/claude-code` version only. There is no lockfile,
so its transitive dependencies resolve from their semver ranges at build time, and two builds
of the same version may differ in them. Locking them (a committed `package-lock.json` installed
with `npm ci`) needs a file outside this image's Dockerfile and docs, so it is left as a
follow-up if that reproducibility matters.

## Credentials

None are baked into the image. Authentication for `claude` is supplied at runtime from
uBixVault, like every other secret.

## Check

```bash
docker run --rm --entrypoint claude <image> --version
```
