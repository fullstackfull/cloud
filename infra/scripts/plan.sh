#!/usr/bin/env bash
# What would change? Writes nothing. This is the step that exists so somebody
# can still say no.
source "$(dirname "$0")/_common.sh"

ENVIRONMENT="${1:-}"
PLAYBOOK="${2:-deploy-control-plane.yml}"
require_environment "$ENVIRONMENT"

if ! have_ansible; then
    echo "ansible-playbook is not installed" >&2
    exit 1
fi

echo "== plan: $ENVIRONMENT / $PLAYBOOK (check mode, no writes) =="
ansible-playbook -i "$(inventory_for "$ENVIRONMENT")" \
    "$ANSIBLE_DIR/playbooks/$PLAYBOOK" --check --diff

if [ -d "$INFRA_ROOT/opentofu/environments/$ENVIRONMENT" ]; then
    echo "== tofu plan: $ENVIRONMENT =="
    ( cd "$INFRA_ROOT/opentofu/environments/$ENVIRONMENT" && tofu plan -input=false )
fi
