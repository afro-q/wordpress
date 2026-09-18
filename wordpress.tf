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
      # RWO block storage cannot attach to two nodes during a rolling update.
      strategy = {
        type = "Recreate"
      }
      ingress = {
        enabled = false
      }
      settings = {
        # Injected into wp-config.php via WORDPRESS_CONFIG_EXTRA (eval'd by the official image).
        configExtra = join("\n", [
          "if (!defined('WP_ALLOW_MULTISITE')) { define('WP_ALLOW_MULTISITE', true); }",
          "if (!defined('MULTISITE')) { define('MULTISITE', true); }",
          "if (!defined('SUBDOMAIN_INSTALL')) { define('SUBDOMAIN_INSTALL', ${var.wordpress_multisite_subdomains}); }",
          "if (!isset($base)) { $base = '/'; }",
          "if (!defined('DOMAIN_CURRENT_SITE')) { define('DOMAIN_CURRENT_SITE', '${var.wordpress_domain}'); }",
          "if (!defined('PATH_CURRENT_SITE')) { define('PATH_CURRENT_SITE', '/'); }",
          "if (!defined('SITE_ID_CURRENT_SITE')) { define('SITE_ID_CURRENT_SITE', 1); }",
          "if (!defined('BLOG_ID_CURRENT_SITE')) { define('BLOG_ID_CURRENT_SITE', 1); }",
        ])
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

    dynamic "rule" {
      for_each = local.wordpress_hosts
      content {
        host = rule.value
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
    }

    dynamic "tls" {
      for_each = local.wordpress_tls
      content {
        secret_name = tls.value.secret_name
        hosts       = tls.value.hosts
      }
    }
  }
}
