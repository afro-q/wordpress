resource "kubernetes_namespace_v1" "wordpress" {
  provider = kubernetes.vultr
  
  metadata {
    name = var.namespace
  }
}

resource "random_password" "wordpress_db_password" {
  length  = 24
  special = true
}

resource "kubernetes_secret_v1" "wordpress_db" {
  provider = kubernetes.vultr
  
  metadata {
    name      = "wordpress-db"
    namespace = var.namespace
  }

  data = {
    MARIADB_ROOT_PASSWORD = random_password.mariadb_root_password.result
    MARIADB_DATABASE      = "wordpress"
    MARIADB_USER          = "wordpress"
    MARIADB_PASSWORD      = random_password.wordpress_db_password.result
  }
}

resource "kubernetes_persistent_volume_claim_v1" "wordpress" {
  provider = kubernetes.vultr
  
  metadata {
    name      = "wordpress-data"
    namespace = var.namespace
  }

  spec {
    access_modes       = ["ReadWriteOnce"]
    storage_class_name = "vultr-block-storage"

    resources {
      requests = {
        storage = "20Gi"
      }
    }
  }
}

resource "helm_release" "wordpress" {
  provider = helm.vultr
  
  name       = "wordpress"
  repository = "https://groundhog2k.github.io/helm-charts/"
  chart      = "wordpress"
  version    = "0.16.4"

  timeout         = 600
  cleanup_on_fail = true

  create_namespace = false
  namespace        = var.namespace

  depends_on = [
    kubernetes_namespace_v1.wordpress,
    helm_release.mariadb,
  ]

  set = [
    {
      name  = "externalDatabase.name"
      value = "wordpress"
    },
    {
      name  = "externalDatabase.user"
      value = "wordpress"
    },
    {
      # MariaDB service name inside the namespace (release name == fullname).
      name  = "externalDatabase.host"
      value = "mariadb"
    },
    {
      name  = "storage.persistentVolumeClaimName"
      value = kubernetes_persistent_volume_claim_v1.wordpress.metadata[0].name
    },
  ]
    
  set_sensitive = [
    {
      name  = "externalDatabase.password"
      value = random_password.wordpress_db_password.result
    },
  ]

  // going to manage ingress ourselves
  values = [
    yamlencode({
      ingress = {
        enabled   = false
      }
    })
  ]  
}

resource "kubernetes_service_v1" "ts_wordpress" {
  provider = kubernetes.vultr
  
  metadata {
    name      = "ts-wordpress"
    namespace = kubernetes_namespace_v1.wordpress.metadata[0].name

    annotations = {
      "tailscale.com/expose" = "true"
    }
  }

  spec {
    load_balancer_class = "tailscale"
    type                = "LoadBalancer"

    selector = {
      "app.kubernetes.io/name" = "wordpress"
    }

    port {
      name        = "http"
      port        = 80
      target_port = 8000
      protocol    = "TCP"
    }
  }
}

resource "kubernetes_ingress_v1" "wordpress" {
  provider = kubernetes.vultr
  
  metadata {
    name      = "wordpress"
    namespace = kubernetes_namespace_v1.wordpress.metadata[0].name

    annotations = {
      "cert-manager.io/cluster-issuer" = "letsencrypt-prod"
    }
  }

  spec {    
    ingress_class_name = "nginx"

    rule {
      host = "qmorake.com"
      http {        
        path {
          path = "/"
          
          backend {
            service {
              name = "wordpress"
              port {
                number = 80
              }
            }
          }
        }
      }
    }

    rule {
      host = "www.qmorake.com"
      http {        
        path {
          path = "/"
          
          backend {
            service {
              name = "wordpress"
              port {
                number = 80
              }
            }
          }
        }
      }
    }
    
    tls {
      secret_name = "wordpress-tls"
      hosts      = [ "qmorake.com", "www.qmorake.com" ]
    }
  }
}
