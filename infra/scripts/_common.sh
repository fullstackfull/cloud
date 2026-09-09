# Shared by the four verbs. Sourced, not executed.
set -euo pipefail

INFRA_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ANSIBLE_DIR="$INFRA_ROOT/ansible"

# Environments a verb will act on. "examples" is deliberately absent: it is a
# fictional inventory that exists to exercise the validator, and naming it here
# is the only way it could ever become a target.
DEPLOYABLE_ENVIRONMENTS="staging production"

require_environment() {
    local env="${1:-}"
    if [ -z "$env" ]; then
        echo "usage: $(basename "$0") <environment>   # one of: $DEPLOYABLE_ENVIRONMENTS" >&2
        exit 2
    fi
    for known in $DEPLOYABLE_ENVIRONMENTS; do
        [ "$env" = "$known" ] && return 0
    done
    echo "refusing environment '$env'; deployable environments are: $DEPLOYABLE_ENVIRONMENTS" >&2
    exit 2
}

inventory_for() {
    echo "$ANSIBLE_DIR/inventories/$1/hosts.yml"
}

# apply.sh will not run unless preflight.sh passed for this environment in this
# shell. The token is deliberately not a file: it does not survive the session,
# so a preflight from last week cannot authorise today's change.
preflight_token_var() {
    echo "LYNOMIA_PREFLIGHT_PASSED_${1}"
}

have_ansible() {
    command -v ansible-playbook >/dev/null 2>&1
}
