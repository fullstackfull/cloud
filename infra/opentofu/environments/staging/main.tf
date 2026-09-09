# Staging.
#
# Empty of resources. No Cloudflare account has been made available to the
# environment that runs OpenTofu — see
# docs/phase-30b-real-infrastructure-inventory.md — and a declaration written
# against an account nobody has tried to reach is a guess with a plan file.
#
# When a zone exists, it is declared here and applied through
# infra/scripts/plan.sh then a person running `tofu apply`.

terraform {
  required_version = ">= 1.8.0"
}
