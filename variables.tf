variable "vultr_api_key" {}

variable "namespace" {
  description = "Kubernetes namespace to deploy WordPress into"
  type        = string
  default     = "wordpress"
}

variable "wordpress_domain" {
  description = "Canonical WordPress host (no scheme). Must match the siteurl option."
  type        = string
  default     = "qmorake.com"
}

variable "wordpress_multisite_subdomains" {
  description = "If true, new sites use subdomains (blog.example.com). If false, they use subdirectories (example.com/blog). Subdomains also need wildcard DNS and a wildcard TLS certificate."
  type        = bool
  default     = false
}

variable "wordpress_additional_domains" {
  description = "Apex domains mapped to their own Multisite blogs (not the primary site). Each needs a public Route53 zone."
  type        = list(string)
  default     = ["equinilet.com"]
}
