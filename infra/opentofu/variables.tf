variable "environment" {
  description = "Which Lynomia environment these resources belong to."
  type        = string

  validation {
    condition     = contains(["staging", "production"], var.environment)
    error_message = "environment must be staging or production."
  }
}

variable "cloudflare_account_id" {
  description = "Cloudflare account. Not a secret; the token that acts on it is."
  type        = string
}

variable "dns_test_zone" {
  description = "The one zone Phase 30B publishes records into, to prove public resolution."
  type        = string
  default     = ""
}

# The Cloudflare API token is deliberately absent from this file. The provider
# reads CLOUDFLARE_API_TOKEN from the environment, which the deployment
# controller supplies. A token in a .tfvars file is a token in somebody's shell
# history.
