#!/usr/bin/env bash
# Ask the machines what is actually true now, rather than assuming apply
# succeeded because it exited zero.
source "$(dirname "$0")/_common.sh"

ENVIRONMENT="${1:-}"
require_environment "$ENVIRONMENT"

if ! have_ansible; then
    echo "ansible-playbook is not installed" >&2
    exit 1
fi

echo "== verify: $ENVIRONMENT =="
ansible-playbook -i "$(inventory_for "$ENVIRONMENT")" "$ANSIBLE_DIR/playbooks/verify.yml"
