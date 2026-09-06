variable "vultr_api_key" {}

variable "namespace" {
  description = "Kubernetes namespace to deploy WordPress into"
  type        = string
  default     = "wordpress"
}
