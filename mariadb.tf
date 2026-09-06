resource "random_password" "mariadb_root_password" {
  length  = 24
  special = true
}

resource "kubernetes_persistent_volume_claim_v1" "mariadb" {
  provider = kubernetes.vultr
  
  metadata {
    name      = "mariadb-data"
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

resource "helm_release" "mariadb" {
  provider = helm.vultr
  
  name       = "mariadb"
  repository = "https://groundhog2k.github.io/helm-charts/"
  chart      = "mariadb"
  version    = "4.44"

  timeout         = 600
  cleanup_on_fail = true

  create_namespace = false
  namespace        = var.namespace

  depends_on = [kubernetes_namespace_v1.wordpress]

  set = [
    {
      name  = "settings.existingSecret"
      value = kubernetes_secret_v1.wordpress_db.metadata[0].name
    },
    {
      name  = "settings.rootPassword.secretKey"
      value = "MARIADB_ROOT_PASSWORD"
    },
    {
      name  = "userDatabase.existingSecret"
      value = kubernetes_secret_v1.wordpress_db.metadata[0].name
    },
    {
      name  = "userDatabase.name.secretKey"
      value = "MARIADB_DATABASE"
    },
    {
      name  = "userDatabase.user.secretKey"
      value = "MARIADB_USER"
    },
    {
      name  = "userDatabase.password.secretKey"
      value = "MARIADB_PASSWORD"
    },
    {
      name  = "storage.persistentVolumeClaimName"
      value = kubernetes_persistent_volume_claim_v1.mariadb.metadata[0].name
    },
  ]
}
