data "kubernetes_service_v1" "ingress_nginx" {
  provider = kubernetes.vultr
  
  metadata {
    name      = "ingress-nginx-controller"
    namespace = "ingress-nginx"
  }
}

data "aws_route53_zone" "qmorake_com" {
  name         = "qmorake.com."
  private_zone = false
}

resource "aws_route53_record" "root" {
  zone_id = data.aws_route53_zone.qmorake_com.zone_id
  name    = "${data.aws_route53_zone.qmorake_com.name}"
  type    = "A"
  ttl     = "300"
  records = [ data.kubernetes_service_v1.ingress_nginx.status[0].load_balancer[0].ingress[0].ip ] 
}

resource "aws_route53_record" "www" {
  zone_id = data.aws_route53_zone.qmorake_com.zone_id
  name    = "www.${data.aws_route53_zone.qmorake_com.name}"
  type    = "A"
  ttl     = "300"
  records = [ data.kubernetes_service_v1.ingress_nginx.status[0].load_balancer[0].ingress[0].ip ] 
}
