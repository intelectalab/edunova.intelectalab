# Despliegue en el VPS de pruebas (EDUN-211)

El sitio público, el Panel CMS, la API y la web de gestión corren en un VPS propio detrás de
nginx (destino acordado en lugar de Cloudflare Pages: el sitio usa SSR, middleware y acciones
de servidor de Next.js).

- **Despliegue continuo:** `edunova-despliegue.timer` corre `desplegar.sh` cada 5 minutos. Si
  `main` tiene un commit nuevo y todas sus verificaciones de CI están en verde, lo despliega y
  comprueba que el sitio y la API respondan. Un commit con CI en rojo no se despliega.
- **HTTPS:** nginx redirige `http` a `https` (301) con certificado de Let's Encrypt.
- **Sin indexar:** mientras no esté el dominio final, `EDUNOVA_INDEXAR` no se define: robots.txt
  bloquea todo y cada página lleva `noindex`.
- **Vistas previas por pull request:** no aplican mientras se trabaje directo sobre `main`.

Instalación (una vez, como root, con el repositorio en `/var/www/edunova`):

```sh
install -m 644 infra/vps/edunova-despliegue.service infra/vps/edunova-despliegue.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now edunova-despliegue.timer
journalctl -u edunova-despliegue -f   # ver los despliegues
```
