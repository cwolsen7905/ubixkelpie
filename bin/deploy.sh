#!/usr/bin/env bash
# deploy.sh <dev|staging|main> — apply app/KelpieApi/k8s/<env>-{deploy,service,ingress}.yaml
# and wait for the rollout. Modelled on kitg's bin/deploy.sh, minus migrations,
# cronjobs, the sandbox tier and Discord (Kelpie has none of those).
#
# Secrets are never applied from here: the pod reads them from uBixVault at start
# (VAULT_APP_KV_PATH in the manifest). The `main` branch deploys to kelpie-prod.
set -euo pipefail

ENVIRONMENT="${1:?usage: deploy.sh <dev|staging|main>}"
case "$ENVIRONMENT" in
    dev)     NAMESPACE=kelpie-dev ;;
    staging) NAMESPACE=kelpie-staging ;;
    main)    NAMESPACE=kelpie-prod ;;
    *) echo "unknown environment '$ENVIRONMENT' (dev|staging|main)" >&2; exit 1 ;;
esac

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
APP_DIR="$SCRIPT_DIR/app/KelpieApi/k8s"
DATE_STAMP="$(date +%s)"
# The runner's cluster credentials; dev, staging and prod namespaces share the one
# cluster, as kitg's do (kitg points all three at devstageconfig).
KUBECONFIG_PATH="${KELPIE_KUBECONFIG:-/home/gitlab-runner/.kube/devstageconfig}"
KUBECTL=(kubectl --kubeconfig "$KUBECONFIG_PATH")

for kind in deploy service ingress; do
    [ -f "$APP_DIR/${ENVIRONMENT}-${kind}.yaml" ] || { echo "missing $APP_DIR/${ENVIRONMENT}-${kind}.yaml" >&2; exit 1; }
done

echo "Deploying KelpieApi to ${ENVIRONMENT} (namespace ${NAMESPACE})"

# {{DATE_STAMP}} changes the pod template, so an unchanged mutable image tag
# (:dev, :staging, :main) still rolls.
sed "s/{{DATE_STAMP}}/${DATE_STAMP}/g" "$APP_DIR/${ENVIRONMENT}-deploy.yaml" | "${KUBECTL[@]}" apply -f -
"${KUBECTL[@]}" apply -f "$APP_DIR/${ENVIRONMENT}-service.yaml"
"${KUBECTL[@]}" apply -f "$APP_DIR/${ENVIRONMENT}-ingress.yaml"

# `apply` succeeding says nothing about the pod. Without this a crashlooping pod
# (e.g. a missing Vault key) counts as a green deploy and promotion runs on it.
if ! "${KUBECTL[@]}" -n "$NAMESPACE" rollout status deployment/kelpie-api --timeout=300s; then
    echo "Rollout of kelpie-api in ${NAMESPACE} did not complete" >&2
    "${KUBECTL[@]}" -n "$NAMESPACE" get pods -l app.kubernetes.io/name=kelpie-api >&2 || true
    exit 1
fi
echo "Deployed ${ENVIRONMENT}."
