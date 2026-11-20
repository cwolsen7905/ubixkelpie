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
