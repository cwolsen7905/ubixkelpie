# Autofix flags

Two environment variables decide where Kelpie acts on issues. They are feature flags, not
secrets, so they live in the deploy manifest (`app/KelpieApi/<env>-deploy.yaml`), where a change
is reviewed in git, and not in uBixVault.

| Variable | Meaning |
| --- | --- |
| `KELPIE_AUTOFIX_ENABLED` | The global kill switch. Anything but `true` (or `1`, `yes`, `on` for the webhook) makes Kelpie ignore every issue event. Set it to `false` and redeploy to stop everything at once. |
| `KELPIE_AUTOFIX_PROJECTS` | The per-project rollout list: comma-separated project paths Kelpie may act on. An event for any other project is ignored. Defaults to `ubixsys/ubixkelpie`. |

They are temporary. Once K-30's projects table exists, per-project enrolment lives there and
these two variables go away.

## What turns on

With both set, an issue opened on a listed project by anyone but the bot (or a skipped agent) is
classified and logged, and gets a 👀 award emoji from the bot user, once: a redelivered event
adds no second one, and a GitLab failure is logged and still answers the webhook with 200. Ignored
events get no emoji.

The flags are also read by the `kelpie:fix`, `kelpie:sweep` and `kelpie:respond` commands when they
run in an environment that sets them. The API pod runs none of those commands, and no CronJob
runs them in production.
