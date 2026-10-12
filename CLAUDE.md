# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

**uBixKelpie** is uBixSys's AI automation product: it picks up Git issues and fixes them, runs
servers that monitor code, and does that kind of work. It builds on
[uBixCore](https://gitlab.brainchurts.com/ubixsys/ubixcore) as a Composer **dependency**
(`vendor/ubixsys/ubixcore`, pinned by `composer.lock`); the framework is never committed here.

- Repo: `gitlab.brainchurts.com/ubixsys/ubixkelpie`. Base branch **`dev`** (MR-only).
- PHP namespace: **`Kelpie\`** in `php/Kelpie/` (tests: `Kelpie\Tests\` in `tests/`). "uBix" is understood.
- Modelled on kitg (`~/git/kitg`), the reference for how an app sits on uBixCore. When unsure how
  something should look, read how kitg does it, then leave out what is specific to Sowing.me.
- **Current state: foundation only.** Health endpoint and quality gate. No product features yet.

## Build, test, lint

```bash
composer install                     # uBixCore from the GitLab Composer registry
cp .env.example .env                 # local dev only; real secrets live in uBixVault

php bin/ubix list                    # CLI (commands auto-discovered from PSR-4)
php bin/ubix code:review             # phpcs + phpstan + phpunit: the same gate CI runs
vendor/bin/phpunit                   # strict: fails on risky/warning/deprecation
vendor/bin/phpstan                   # level max + bleeding edge, via the framework baseline
vendor/bin/phpcs                     # the `Ubix` standard, shipped in the framework package
vendor/bin/rector

APP_NAME=HelloApi php -S 127.0.0.1:8080 -t public    # serve one app
```

**Always run `php bin/ubix code:review` (or the three tools above) before opening an MR.**

## Layout

```
php/Kelpie/            PSR-4 root Kelpie\: Controller, Model, Repository, Service, DataType, Enum,
                       Console/Command, Payload, DataTransferObject, Middleware (create as needed)
app/<App>/src/         per-app Dependencies.php (PHP-DI), Middleware.php, Routes.php
public/index.php       web entry; APP_NAME selects app/<App>
bin/ubix               CLI entry; commands from Ubix\Console\Command and Kelpie\Console\Command
tests/                 mirrors php/Kelpie/; PhpunitTestCasesTest enforces a test per concrete class
```

CI (`.gitlab-ci.yml`): `build → lint-and-test`, on MRs and on `dev`. Deploy/migrate/promote/notify
are not wired yet; do not guess hostnames, namespaces, registry paths or Vault paths, ask.

## Conventions (inherited from uBixCore)

- **Repository pattern**, split interfaces: `XReaderInterface` + `XWriterInterface`, realised by
  `XSqlRepository`; query criteria are DTOs. **Service layer** holds business logic; controllers
  stay thin. **Payloads** shape HTTP in/out; **DataTypes** wrap domain scalars; Enums have no
  `Enum` suffix.
- PHP-DI wiring per app in `app/<App>/src/Dependencies.php`; routes in `Routes.php`. The
  PHP-DI/Slim bridge injects route placeholders **by name** (`string $slug`, not `array $args`).
- **PHP 8.5+**. Secrets live in **uBixVault**; `.env` is a local-dev convenience and is never
  baked into an image.
- Every concrete class needs a test under `tests/` mirroring its path.

## The uBixCore boundary

uBixCore is ours and may be updated, but the flow is one-directional: it gets primitives,
patterns and plumbing, never product logic. If explaining the code needs a Kelpie noun (issue
fixer, monitor, ...), it belongs in `Kelpie\`. If a framework capability is missing, add it
upstream and bump the pin rather than building a local shim.

## Working rules

- Plan first: bring the approach and file list to the person before changing code.
- Stop for what is genuinely theirs (money, published text, production, a design call with no
  default). A choice between two reasonable implementations is not one: pick, say why, go on.
- Tell the truth about gates: if something is red, say so with the output.
- Agents never approve a merge request. See `AGENTS-COORD.template.md` for concurrent sessions.
