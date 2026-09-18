data "kubernetes_service_v1" "ingress_nginx" {
  provider = kubernetes.vultr

  metadata {
    name      = "ingress-nginx-controller"
    namespace = "ingress-nginx"
  }
}

locals {
  wordpress_ingress_ip = data.kubernetes_service_v1.ingress_nginx.status[0].load_balancer[0].ingress[0].ip

  wordpress_hosts = concat(
    [var.wordpress_domain, "www.${var.wordpress_domain}"],
    flatten([for d in var.wordpress_additional_domains : [d, "www.${d}"]]),
  )

  wordpress_tls = concat(
    [{
      secret_name = "wordpress-tls"
      hosts       = [var.wordpress_domain, "www.${var.wordpress_domain}"]
    }],
    [for d in var.wordpress_additional_domains : {
      secret_name = "wordpress-tls-${replace(d, ".", "-")}"
      hosts       = [d, "www.${d}"]
    }],
  )
}

data "aws_route53_zone" "qmorake_com" {
  name         = "qmorake.com."
  private_zone = false
}

resource "aws_route53_record" "root" {
  zone_id = data.aws_route53_zone.qmorake_com.zone_id
  name    = data.aws_route53_zone.qmorake_com.name
  type    = "A"
  ttl     = "300"
  records = [local.wordpress_ingress_ip]
}

resource "aws_route53_record" "www" {
  zone_id = data.aws_route53_zone.qmorake_com.zone_id
  name    = "www.${data.aws_route53_zone.qmorake_com.name}"
  type    = "A"
  ttl     = "300"
  records = [local.wordpress_ingress_ip]
}

data "aws_route53_zone" "additional" {
  for_each     = toset(var.wordpress_additional_domains)
  name         = "${each.value}."
  private_zone = false
}

resource "aws_route53_record" "additional_root" {
  for_each = toset(var.wordpress_additional_domains)
  zone_id  = data.aws_route53_zone.additional[each.value].zone_id
  name     = data.aws_route53_zone.additional[each.value].name
  type     = "A"
  ttl      = "300"
  records  = [local.wordpress_ingress_ip]
}

resource "aws_route53_record" "additional_www" {
  for_each = toset(var.wordpress_additional_domains)
  zone_id  = data.aws_route53_zone.additional[each.value].zone_id
  name     = "www.${data.aws_route53_zone.additional[each.value].name}"
  type     = "A"
  ttl      = "300"
  records  = [local.wordpress_ingress_ip]
}
