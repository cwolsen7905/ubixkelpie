# uBixKelpie

AI automation product from uBixSys: it picks up Git issues and fixes them, runs servers that
monitor code, and does that kind of work. Built on
[uBixCore](https://gitlab.brainchurts.com/ubixsys/ubixcore) (`ubixsys/ubixcore`).

The PHP namespace is `Kelpie\` (the "uBix" is understood).

> **Status: foundation only.** This repo is the uBixCore app skeleton, modelled on
> [kitg](https://gitlab.brainchurts.com/kitg/kitg). It has a health endpoint, the GitLab webhook
> receiver, the quality gate and a dev/staging/prod deployment; the product features (issue
> fixing, code monitoring) are not built yet.

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
bin/deploy.sh promote.sh        deploy a branch to its namespace; fast-forward the next branch
app/KelpieApi/k8s/              <env>-{deploy,service,ingress}.yaml per environment (dev, staging, main)
Dockerfile  Dockerfile_Test     runtime image, and the test image the CI gate runs in
```

## AI review for merge requests

`ci/ai-review/` is a reusable GitLab CI job that reviews merge requests with OpenCode and
OpenRouter and posts findings as threads. It complements uBixCore's `code:review` (it
reviews logic, security and design, not lint or conventions) and is advisory by default.
Other repos include it with `include: {project: ubixsys/ubixkelpie, ref: <tag or dev>, file:
ci/ai-review/ai-review.yml}`. Setup, variables and overrides are in
[docs/ai-review.md](docs/ai-review.md).

## Run it

```bash
composer install                # uBixCore from the GitLab Composer registry (needs a GitLab token)
cp .env.example .env            # local dev only; secrets belong in uBixVault

php bin/ubix list               # CLI, commands auto-discovered
vendor/bin/phpcs && vendor/bin/phpstan && vendor/bin/phpunit   # the gate CI runs
# (php bin/ubix code:review wraps these, but errors in its phpstan step on ubixcore 0.45.0)

APP_NAME=HelloApi php -S 127.0.0.1:8080 -t public
curl http://127.0.0.1:8080/health     # {"status":"ok","app":"HelloApi"}
```

Individual tools: `vendor/bin/phpunit`, `vendor/bin/phpstan`, `vendor/bin/phpcs`, `vendor/bin/rector`.

## CI

`.gitlab-ci.yml` builds the runtime and test images and runs phpcs, phpstan and phpunit on merge
requests and on `dev`, `staging` and `main`. A green deploy of `dev` fast-forwards `staging`, a
green deploy of `staging` fast-forwards `main` (`bin/promote.sh`, ff-only), and each branch deploys
with `bin/deploy.sh`:

| Branch | Namespace | URL |
| --- | --- | --- |
| `dev` | `kelpie-dev` | https://kelpie.dev.ubixsys.com |
| `staging` | `kelpie-staging` | https://kelpie.staging.ubixsys.com |
| `main` | `kelpie-prod` | https://kelpie.prod.ubixsys.com |

Manifests are in `app/KelpieApi/k8s/`; secrets come from uBixVault (`kelpie/<env>/app`) and are
never committed. What must exist outside this repo first is listed in
[`docs/merge-signoff.md`](docs/merge-signoff.md#outside-this-repo-kubernetes-repo--gitlab-before-the-first-deploy).
No migrate or notify stage: Kelpie has no database or chat channel yet.

CI pulls uBixCore with the job token, so `ubixsys/ubixkelpie` must be on ubixcore's CI job-token
allowlist.

## Merge sign-off

Approving an MR re-runs the `require-approval` job. Kelpie receives the GitLab webhook itself:

- URL: `https://kelpie.prod.ubixsys.com/gitlab/webhook`
- Secret token: `GITLAB_WEBHOOK_SECRET` (uBixVault `kelpie/prod/app`)
- Triggers: **Merge request events** and **Emoji events**
- SSL verification on

Once Kelpie's prod is live, **remove the old uBixOps hook** from the projects that now use this one.
Full wiring: [`docs/merge-signoff.md`](docs/merge-signoff.md).

## Branching

`dev` is the base branch and is MR-only. See `AGENTS-COORD.template.md` for working with several
agent sessions at once.
