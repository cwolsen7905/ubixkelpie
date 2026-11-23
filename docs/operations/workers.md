# Applying and restarting the workers by hand

uBixCore's `deploy.sh` skips `*-worker.yaml`, so a green pipeline never updates the `kelpie-worker`
Deployments. Until that is fixed upstream, apply and restart them yourself. (What the worker does is in
`worker.md`.)

| Branch | Manifest | Namespace |
|---|---|---|
| `dev` | `app/KelpieApi/dev-worker.yaml` | `kelpie-dev` |
| `main` | `app/KelpieApi/main-worker.yaml` | `kelpie-prod` |

`main-worker.yaml` targets production. Apply it only from a checkout of `main` (or of `dev` when
the person has asked for it), and only the manifest, nothing else.

## When to do it

- After any change to a `*-worker.yaml` (memory, env, flags, `LOGGER_PATH`).
- After changing a value in uBixVault the worker reads (for example `KELPIE_TRIAGE_MODEL` in
  `kelpie/dev/app`).

## How secrets reach the pod

There is no ExternalSecret, vault-agent or synced Secret. The process logs in to Vault with its
Kubernetes service account (role `kelpie-<env>`) at startup and reads `kelpie/<env>/app` and
`kelpie/<env>/db`. A new Vault value therefore reaches the worker only when the pod restarts; there
is nothing to sync.

## Steps

```bash
git pull                                   # the manifest you apply is the one on your branch
export KUBECONFIG=~/.kube/k3s.yaml         # the context holding the kelpie-* namespaces

kubectl apply -f app/KelpieApi/dev-worker.yaml
kubectl -n kelpie-dev rollout restart deploy/kelpie-worker
kubectl -n kelpie-dev rollout status deploy/kelpie-worker --timeout=240s

# only for production, from main:
kubectl apply -f app/KelpieApi/main-worker.yaml
kubectl -n kelpie-prod rollout restart deploy/kelpie-worker
kubectl -n kelpie-prod rollout status deploy/kelpie-worker --timeout=240s
```

`apply` alone does not restart the pod when only Vault changed (the manifest is unchanged), so always
run `rollout restart` too. Never print the kubeconfig or secret values while checking.

## Checking it

- Limits: `kubectl -n kelpie-dev get deploy kelpie-worker -o jsonpath='{.spec.template.spec.containers[0].resources.limits.memory}'`
  should print `2Gi`.
- Logs: the CLI logger writes to a file, not stdout, so `kubectl logs` is empty. Read it with
  `kubectl -n kelpie-dev exec deploy/kelpie-worker -- tail -n 50 /tmp/logs/UbixCli.log`. The directory is an
  emptyDir: it is lost when the pod is deleted or rescheduled.
- An idle worker logs almost nothing. To see a triage call you need a job (a comment or a failed
  pipeline on a watched MR); then look for the triage result in that log and the note on the MR. The
  text "Triage unavailable: the model gave no answer" means the model call returned nothing: check the model name
  and the OpenRouter key in Vault.
- A sweep pod that ends in `Error` with a Vault login stack trace could not log in to Vault; check the
  role binding for the namespace.

## Checking triage

Triage runs when the worker handles a thread job. A job comes from a webhook Comment event (a person's
note in an MR thread), a failed gate pipeline, or the 15-minute `kelpie:sweep` backstop (see `worker.md`).
Read-only commands that worked:

```bash
export KUBECONFIG=~/.kube/k3s.yaml
kubectl -n kelpie-dev get pods                       # worker Running; sweep pods in Error mean a Vault login failure
kubectl -n kelpie-dev exec deploy/kelpie-worker -- tail -n 40 /tmp/logs/UbixCli.log
kubectl -n kelpie-dev exec deploy/kelpie-worker -- grep -n 'Triage\|Autofix reply\|mr_iid":<N>' /tmp/logs/UbixCli.log
glab api projects/<group>%2F<project>/hooks                 # hook URL and ticked events
glab api projects/<group>%2F<project>/hooks/<id>/events     # recent deliveries and response status
```

What the log lines mean:

- `GitLab webhook: autofix job` — the webhook reached kelpie-dev and queued a job for that thread.
- `Triage: model is from the review's family` (WARNING) — the model guard refused **before** any OpenRouter
  call. The thread gets a `needs_human` reply reading "Triage refused: its model is from the same family as
  the review's". This is not an OpenRouter failure. `KELPIE_TRIAGE_MODEL=anthropic/claude-haiku-5.5` trips it
  while the review family is `anthropic`; the value must be from another vendor.
- "Triage unavailable: the model gave no answer" — the guard passed and OpenRouter returned nothing
  (model name or key).
- `Settled thread resolved` — Kelpie resolved a thread it had settled; it is not a triage call.

A comment that produces no `GitLab webhook` line was filtered (loop guards, allow-list, draft, fork,
Shepherd branch) or never delivered. Check the hook's recent deliveries before suspecting the worker.
`glab api` against GitLab can answer HTTP 429; wait and retry rather than looping.

## Which hooks feed which Kelpie (checked 2026-11-23)

Triage on dev only happens for events that reach `kelpie.dev.ubixsys.com`. A project whose only hook points
at prod never triages on dev, however its `projects` row reads there.

| Project | Hook | Points at | Events | Purpose |
|---|---|---|---|---|
| `ubixsys/ubixshepherd` | 5 "Kelpie Automation" | dev | issues, merge requests, note, emoji, pipeline, job | everything dev needs, including threads |
| `ubixsys/ubixshepherd` | 15 "Kelpie prod: re-run sign-off on approve" | prod | merge requests, emoji | re-runs the `require-approval` job on Approve or 👍 (see `docs/merge-signoff.md`); keep it |
| `ubixsys/ubixkelpie` | 8 "Kelpie Automation" | prod | issues, merge requests, note, emoji | prod's sign-off re-run for Kelpie's own MRs |
| `ubixsys/ubixkelpie` | 17 "Kelpie dev: note events" | dev | note only | lets dev triage review threads on Kelpie's own MRs; the secret is dev's `GITLAB_WEBHOOK_SECRET` |

Both receivers see a note on `ubixsys/ubixkelpie`. Prod answers it only if prod has a `projects` row for the
project; dev has one (`propose`). Check the layout with `glab api projects/<group>%2F<project>/hooks`
(the secret is never returned).

Two things the check found:

- On dev, `ubixsys/ubixshepherd` is mode `push`, not `propose` as `docs/enrolment/ubixshepherd.md` says.
- Triage sees the thread text only, not the repo. A finding it cannot verify from the thread gets
  "Kelpie triage: needs human" and the label `kelpie::needs-you`; that is a verdict, not a failure.
  The bot's 👀 reaction on a thread can fail with HTTP 404 (`Triage 👀 could not be placed`): a
  token-permission matter, left alone.
