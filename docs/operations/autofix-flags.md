# Autofix flags

One environment variable decides whether Kelpie acts at all. It is a feature flag, not a secret, so
it lives in the deploy manifest (`app/KelpieApi/<env>-deploy.yaml`), where a change is reviewed in
git, and not in uBixVault.

| Variable | Meaning |
| --- | --- |
| `KELPIE_AUTOFIX_ENABLED` | The global kill switch. Anything but `true` (or `1`, `yes`, `on` for the webhook) makes Kelpie ignore every event. Set it to `false` and redeploy to stop everything at once. It can only stop things: it never turns a project on. |

Which projects Kelpie acts on, and how far, is the `projects` table (see `projects.md`). The old
`KELPIE_AUTOFIX_PROJECTS` list is gone: nothing reads it, and there is no fallback to it. A project
with no row is off.

## What turns on

With the switch on, an issue opened on a project that is not off by anyone but the bot (or a skipped agent) is
classified and logged, and gets a 👀 award emoji from the bot user, once: a redelivered event
adds no second one, and a GitLab failure is logged and still answers the webhook with 200. Ignored
events get no emoji.

A triaged issue is also queued for the triage worker, which posts one comment and one label; see
`issue-triage-worker.md`. The worker reads the same switch and the same table: switched off, it idles and claims nothing, and it claims jobs only of projects that are not off.

The switch and the table are also read by the `kelpie:fix`, `kelpie:sweep` and `kelpie:respond` commands. The API pod runs none of those commands, and no CronJob
runs them in production. The `kelpie-worker` Deployment runs `kelpie:work` only.
