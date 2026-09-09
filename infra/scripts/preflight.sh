#!/usr/bin/env bash
# Can we reach these machines, and are we allowed to touch them?
# Writes nothing anywhere.
source "$(dirname "$0")/_common.sh"

ENVIRONMENT="${1:-}"
require_environment "$ENVIRONMENT"
INVENTORY="$(inventory_for "$ENVIRONMENT")"

echo "== preflight: $ENVIRONMENT =="

echo "-- inventory schema"
python3 "$INFRA_ROOT/scripts/validate-inventory.py" "$INFRA_ROOT"

echo "-- monitoring configuration"
python3 "$INFRA_ROOT/scripts/validate-monitoring.py" "$INFRA_ROOT"

if ! have_ansible; then
    echo "ansible-playbook is not installed; the reachability and classification"
    echo "checks below need it. Schema checks above still ran."
    exit 1
fi

echo "-- reachability and classification"
ansible-playbook -i "$INVENTORY" "$ANSIBLE_DIR/playbooks/preflight.yml"

echo
echo "preflight passed for $ENVIRONMENT."
echo "To let apply.sh run in this shell:"
echo "  export $(preflight_token_var "$ENVIRONMENT")=1"
