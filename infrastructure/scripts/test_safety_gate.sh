#!/usr/bin/env bash
#
# Proof that safety_gate refuses what it claims to refuse.
#
# The classification is only worth having if the role actually enforces it, and
# a role that has never been observed refusing anything is an assertion rather
# than a gate. This runs the role against a throwaway inventory pointing at
# localhost, once for every class-and-action pair, and checks that the play
# reaches its tasks exactly when it should.
#
# Needs ansible-core. Touches nothing outside a temporary directory.

set -euo pipefail

ANSIBLE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../ansible" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

write_inventory() {
    local class="$1" reimage="$2"
    {
        echo "all:"
        echo "  children:"
        echo "    control_plane:"
        echo "      hosts:"
        echo "        gate-test:"
        echo "          ansible_host: 127.0.0.1"
        echo "          ansible_connection: local"
        echo "          safety_class: $class"
        # An if, not a short-circuit: under `set -e` a false test as a
        # function's last statement takes the whole script down with it.
        if [ "$reimage" = "yes" ]; then
            echo "          allow_reimage: true"
        fi
    } > "$WORK/hosts.yml"
}

write_play() {
    cat > "$WORK/play.yml" <<PLAY
---
- name: Gate test
  hosts: control_plane
  gather_facts: false
  roles:
    - role: safety_gate
      vars:
        safety_gate_action: $1
  tasks:
    - name: Reached the play body
      ansible.builtin.debug:
        msg: "GATE_ALLOWED"
PLAY
}

failures=0
checked=0

expect() {
    local class="$1" action="$2" reimage="$3" want="$4"
    write_inventory "$class" "$reimage"
    write_play "$action"
    checked=$((checked + 1))

    # From the ansible directory: ansible.cfg's roles_path is relative, and
    # resolved against the working directory rather than the config's own
    # location. Run this from anywhere else and every case "fails" because the
    # role was never found — which looks exactly like the gate refusing.
    # Captured rather than piped into grep. `grep -q` exits on its first match
    # and closes the pipe, ansible-playbook takes SIGPIPE, and `set -o pipefail`
    # turns that into a failed pipeline — so every allowed case would read as
    # refused, which is the direction of error that hides a broken gate.
    local output=""
    output=$( cd "$ANSIBLE_DIR" \
              && ansible-playbook -i "$WORK/hosts.yml" "$WORK/play.yml" 2>&1 ) || true

    local got=refused
    case "$output" in
        *GATE_ALLOWED*) got=allowed ;;
    esac

    local label="$class/$action"
    if [ "$reimage" = "yes" ]; then
        label="$label +allow_reimage"
    fi

    if [ "$got" = "$want" ]; then
        printf 'PASS  %-45s %s\n' "$label" "$want"
    else
        printf 'FAIL  %-45s wanted %s, got %s\n' "$label" "$want" "$got"
        failures=$((failures + 1))
    fi
}

# The default refuses everything, including a read.
expect DO_NOT_TOUCH          read      no  refused
expect DO_NOT_TOUCH          configure no  refused

# Discovery may look and may not touch.
expect DISCOVERY_ONLY        read      yes allowed
expect DISCOVERY_ONLY        configure no  refused

# Configuration may change settings, never wipe.
expect CONFIGURATION_ALLOWED read      no  allowed
expect CONFIGURATION_ALLOWED configure no  allowed
expect CONFIGURATION_ALLOWED reimage   no  refused

# A reimageable class still needs the per-host flag for the destructive action,
# and still permits ordinary configuration without it.
expect REIMAGE_ALLOWED       configure no  allowed
expect REIMAGE_ALLOWED       reimage   no  refused
expect REIMAGE_ALLOWED       reimage   yes allowed

echo
echo "$((checked - failures))/$checked passed"
exit $((failures > 0))
