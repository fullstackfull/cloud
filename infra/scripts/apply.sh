#!/usr/bin/env bash
# Make the change. Refuses unless preflight passed for this environment in this
# same shell, so the reachability and classification checks are never stale.
source "$(dirname "$0")/_common.sh"

ENVIRONMENT="${1:-}"
PLAYBOOK="${2:-deploy-control-plane.yml}"
require_environment "$ENVIRONMENT"

TOKEN="$(preflight_token_var "$ENVIRONMENT")"
if [ "${!TOKEN:-}" != "1" ]; then
    echo "refusing to apply: preflight has not passed for $ENVIRONMENT in this shell." >&2
    echo "  run: scripts/preflight.sh $ENVIRONMENT" >&2
    exit 3
fi

if ! have_ansible; then
    echo "ansible-playbook is not installed" >&2
    exit 1
fi

echo "== apply: $ENVIRONMENT / $PLAYBOOK =="
ansible-playbook -i "$(inventory_for "$ENVIRONMENT")" "$ANSIBLE_DIR/playbooks/$PLAYBOOK"

echo
echo "applied. Now run: scripts/verify.sh $ENVIRONMENT"
