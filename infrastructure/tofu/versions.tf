# =============================================================================
# Provider and tool versions — all pinned.
# =============================================================================
#
# Every constraint here is a pin rather than a floor. A provider that upgrades
# itself between a `plan` and an `apply` has invalidated the plan an operator
# just reviewed, and the whole review step in this repository assumes the plan
# is what runs. `~>` allows patch releases only, and the lock file — see the
# note in infrastructure/README.md — is what makes the choice reproducible on
# another machine.

terraform {
  required_version = "~> 1.10"

  required_providers {
    # bpg/proxmox rather than the older Telmate provider: it speaks the current
    # Proxmox API, supports cloud-init properly, and its resources report drift
    # instead of silently re-creating machines. "Re-create on drift" is an
    # acceptable behaviour for a stateless web server and an unacceptable one
    # for a machine with a customer's data on it.
    proxmox = {
      source  = "bpg/proxmox"
      version = "~> 0.66"
    }

    cloudflare = {
      source  = "cloudflare/cloudflare"
      version = "~> 5.0"
    }
  }

  # The backend is supplied per environment with `-backend-config`, so the same
  # root module cannot accidentally write staging's state over production's.
  # See environments/*/backend.hcl.example.
  #
  # STATE IS A SECRET. It contains cloud-init user data, generated passwords and
  # the full topology of the provider's networks, in plaintext. It belongs in an
  # encrypted, versioned, access-controlled and LOCKING backend — never in git,
  # never on a laptop, and never in a bucket without object locking, because two
  # concurrent applies against unlocked state produce two machines and one
  # record of them.
  backend "s3" {}
}
