# uBixKelpie

AI automation product from uBixSys: it picks up Git issues and fixes them, runs servers that
monitor code, and does that kind of work. Built on
[uBixCore](https://gitlab.brainchurts.com/ubixsys/ubixcore) (`ubixsys/ubixcore`).

The PHP namespace is `Kelpie\` (the "uBix" is understood).

> **Status: foundation only.** This repo is the uBixCore app skeleton, modelled on
> [kitg](https://gitlab.brainchurts.com/kitg/kitg). It has a health endpoint and the quality gate;
> the product features (issue fixing, code monitoring) are not built yet.

## Shape

uBixCore is **not** committed here. It is a Composer dependency installed into `vendor/`;
`composer.lock` pins the version an image is built from.

```
composer.json / composer.lock   name ubixsys/ubixkelpie; requires ubixsys/ubixcore
public/index.php  bin/ubix      thin entry points from the uBixCore skeleton; APP_NAME selects app/<App>
php/Kelpie/                     PSR-4 root Kelpie\  (Controller/HealthController today)
app/<App>/src/                  per-app Dependencies.php, Middleware.php, Routes.php; HelloApi is the smoke app
tests/                          mirrors php/Kelpie/; PhpunitTestCasesTest enforces a test per class
templates/default  sql/migrations   framework-required, empty for now
config/devops/nginx.conf        nginx site for the runtime image
Dockerfile  Dockerfile_Test     runtime image, and the test image the CI gate runs in
```

## Run it

```bash
composer install                # uBixCore from the GitLab Composer registry (needs a GitLab token)
cp .env.example .env            # local dev only; secrets belong in uBixVault

php bin/ubix list               # CLI, commands auto-discovered
php bin/ubix code:review        # phpcs + phpstan + phpunit, the same gate CI runs

APP_NAME=HelloApi php -S 127.0.0.1:8080 -t public
curl http://127.0.0.1:8080/health     # {"status":"ok","app":"HelloApi"}
```

Individual tools: `vendor/bin/phpunit`, `vendor/bin/phpstan`, `vendor/bin/phpcs`, `vendor/bin/rector`.

## CI

`.gitlab-ci.yml` builds the runtime and test images and runs phpcs, phpstan and phpunit on merge
requests and on `dev`. Migrate, deploy, promote and notify stages are deliberately not wired yet;
they need Kelpie's namespace, hostnames and Vault paths, which are not decided.

CI pulls uBixCore with the job token, so `ubixsys/ubixkelpie` must be on ubixcore's CI job-token
allowlist.

## Branching

`dev` is the base branch and is MR-only. See `AGENTS-COORD.template.md` for working with several
agent sessions at once.
