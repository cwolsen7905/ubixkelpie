# The gate pod: where the fix gate's steps run

Before Kelpie pushes a fix (a push round on a merge request) or opens one (an issue fix), it runs the project's gate:
`composer install`, then phpcs, phpstan and phpunit, or the project's `make check`. Every step after `composer
install` runs the repository's own code, and that includes what the fixer model just wrote. Run in the worker, that
code would sit next to everything the worker holds: the ServiceAccount token it logs in to Vault with, the bot token
and the model key in its environment, and the network. The command list (`ActionGuard`, see
[context-scan.md](context-scan.md)) decides which programs start; it cannot decide what a test does once phpunit is
running. So those steps run somewhere that holds nothing: the gate pod.

## Who does what

```
worker (has secrets)                                    gate pod (has nothing)
  clone at the sha, apply the fix
  composer install --no-scripts --no-plugins   (here: it needs COMPOSER_AUTH, and runs no repository code)
  pack the checkout, vendor/ included, .git left out
  POST /run  ── steps + tar.gz ─────────────────────▶  check every step, unpack into an empty /work
                                                        run the steps in order, stop at the first red one
  read the result (data only)  ◀── JSON ────────────  answer, then exit 0: the pod restarts clean
```

- `GateRunner` runs `composer install` itself and hands phpcs, phpstan and phpunit to a `GateExecutorInterface`;
  `IssueGateService` hands it `make check` or the project's gate command. Every gate path (push rounds, reactions to
  a proposal, issue fixes) goes through these two.
- `LocalGateExecutor` runs the steps in the worker, as before. It is bound when `KELPIE_GATE_URL` is unset.
- `RemoteGateExecutor` sends them to the gate pod. It is bound when `KELPIE_GATE_URL` is set
  (`bin/cli-dependencies.php`).
- `kelpie:gate-serve` (`GateServer`) is the gate pod's process.

## The protocol

One request per run:

```
POST /run HTTP/1.1
Content-Type: application/gzip
Content-Length: <bytes>
X-Kelpie-Gate-Steps: {"steps":[["vendor/bin/phpcs"],["vendor/bin/phpstan","analyse","--no-progress","--no-interaction"],["vendor/bin/phpunit"]],"timeout":900}

<the checkout as a gzip-compressed tar>
```

- `steps`: 1 to 10 steps, each a non-empty list of strings (an argv, no shell). Every step must be a gate step
  (`ActionGuard::gateRefusal()`: `vendor/bin/phpcs|phpstan|phpunit`, exactly `make check`, `composer install` with
  `--no-scripts --no-plugins`) or the whole run is refused.
- `timeout`: seconds per step, 1 to 1800.
- The body: a POSIX tar (ustar, pax headers for long names), gzip-compressed, at most `KELPIE_GATE_MAX_BODY_BYTES`
  (512 MiB by default). It is packed and unpacked by `TarArchive` in PHP, so neither side shells out to `tar`.

Answers:

| Status | Body | When |
|---|---|---|
| 200 | `{"steps":[{"exit_code":0,"timed_out":false,"output":"..."}, ...]}` | The run ran. One entry per step that ran, in order, stopping at the first that failed. `output` is the tail (6000 bytes) of stdout and stderr, or `Timed out.` |
| 400 | `{"error":"..."}` | Not HTTP, a bad steps header, a step that is not a gate step, a short body, or an archive that is refused |
| 404 / 405 / 411 / 413 | `{"error":"..."}` | Not `/run`, not `POST`, no `Content-Length`, a body over the limit |

A request refused on its head (method, path, length, steps) is not the run: the server answers, closes, and keeps
listening, and nothing is unpacked. Once the head passes, that connection is the run: whatever happens next, the
server answers it and exits.

## The pod's controls

| Control | Setting (`app/KelpieApi/{dev,main}-gate.yaml`) |
|---|---|
| Secrets | None: no `envFrom`, no Vault variables, only the gate settings below |
| ServiceAccount | Its own, `kelpie-gate`, with `automountServiceAccountToken: false` and no RBAC |
| User | uid/gid 1000 (`www`), `runAsNonRoot`, `allowPrivilegeEscalation: false`, all capabilities dropped, `RuntimeDefault` seccomp |
| Filesystem | `readOnlyRootFilesystem`; size-limited emptyDirs for `/work` (4 GiB), `/tmp`, `/logs`, `/web/var`, `/web/log` |
| Network | NetworkPolicy: in, only pods labelled `app.kubernetes.io/name: kelpie-worker`, TCP 8090; out, nothing (not even DNS) |
| Resources | 1 CPU, 2 GiB, as the worker had for the gate |
| Lifetime | One run, then exit 0; `restartPolicy: Always` starts the next. At start the server empties `/work` and `/tmp`, because an emptyDir survives a container restart |

What the server itself does on top:

- Steps run with `HOME` (the run directory) and `PATH` only, through `sh -c` with every inherited file descriptor
  above 2 closed, so a step cannot use the server's listening socket or the worker's connection.
- The listener stays open, unaccepted, during the run, so nothing in the run can take the port; the ready file is
  removed when the run starts, so the readiness probe takes the pod out of the Service.
- The archive is unpacked into an empty directory. An absolute path, a `..` component, a link pointing out
  (absolute, by `..`, or through another link), a hard link, a device or FIFO, a damaged header, more than 4 GiB of
  content or 500 000 entries: refused, 400, nothing run. Links are created after every file, so no file is written
  through one. This is defence in depth: the pod is disposable and holds nothing, so the pod, not the archive check,
  is the boundary.
- It has no token to check. The NetworkPolicy is the door.

## Fail closed

On the worker side, a run that did not produce a trustworthy answer is never a pass. `RemoteGateExecutor` returns
"gate unavailable: ..." (and `GateRunner` reports the gate red at step `gate`, so nothing is pushed or proposed) when:

- the checkout cannot be packed;
- the pod cannot be reached (connection refused or timed out) for longer than the wait: while the pod restarts
  after a run it is retried every 5 seconds for up to 300 seconds;
- the request times out (`KELPIE_GATE_HTTP_TIMEOUT`, 3600 seconds by default) or fails mid-way;
- the answer is not a 200 (a 400's reason is passed on, cut to 200 printable characters);
- the answer is larger than 1 MiB, not JSON, or not exactly what a run gives: entries of the wrong type, more entries
  than steps, an entry after a failed one, or fewer entries than steps with the last one passing.

A step is checked on the worker side too, before anything is packed or sent; a refused step is reported as
"Refused: ..." and nothing reaches the pod. The answer is only data: exit codes, a flag and capped text. The worker
runs nothing from it.

## Switching over

Kelpie runs the gate locally until a worker is given a gate URL, so nothing changes on merge.

1. Apply `app/KelpieApi/dev-gate.yaml` by hand (`ci/bin/deploy.sh` does not apply `*-gate.yaml`). Check the pod
   becomes ready, and that from it nothing is reachable (for example `kubectl exec` a `php -r` that opens a socket to
   GitLab: it must fail).
2. Add `KELPIE_GATE_URL=http://kelpie-gate:8090` to `dev-worker.yaml` and roll the worker. Push a round (or an issue
   fix) on a test project and see the gate run in the gate pod's log and the pod restart afterwards.
3. Do the same in prod with `main-gate.yaml` and `main-worker.yaml`.
4. Then make the worker refuse to gate locally when `KELPIE_GATE_URL` is unset, and remove the local path. Until that
   step, a worker without the URL still runs the gate next to its secrets.

## Known limits

- One replica serves one run at a time, and the pod restarts after each. Kubernetes backs off restarts of a container
  that keeps exiting (10 s, doubling, up to 5 minutes, reset after 10 minutes of running), even on exit 0, so runs in
  quick succession wait; past the 300-second wait the gate is "unavailable" and the fix is not pushed. More replicas,
  or a longer `waitSeconds`, are the levers.
- The checkout's `.git` is not sent (the work tree's `.git` is only a pointer to a repository outside it). A project
  gate that calls git fails in the pod.
- The pod has no network, so a gate step that needs one (`composer install` with a cold cache, a test that calls an
  API) fails there.
- The image is the worker's, so the pod has the same toolchain: a `make check` project needs `make` in the image, in
  the pod as in the worker.
- A run can make its own gate pass (it is the repository's own tests): the gate is a quality check, not a security
  boundary. What the pod protects is the worker's secrets and the network.
