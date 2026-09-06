terraform {
  backend "local" {
    path = "./.terraform-state"
  }
  
  required_providers {
    helm = {
      source = "hashicorp/helm"
      version = "3.2.0"
    }

    kubernetes = {
      source = "hashicorp/kubernetes"
      version = "3.2.1"
    }

    vultr = {
      source = "vultr/vultr"
      version = "2.32.0"
    }

    random = {
      source  = "hashicorp/random"
      version = "3.7.2"
    }
  }
}

provider "kubernetes" {
  alias = "vultr"
  config_path = "~/.kube/eqinilet/main.config"
}

provider "helm" {
  alias = "vultr"
  
  kubernetes = {
    config_path = "~/.kube/eqinilet/main.config"    
  }
}

provider "vultr" {
  api_key = var.vultr_api_key
  
  rate_limit = 100
  retry_limit = 3
}
