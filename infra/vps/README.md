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

## Respaldos (EDUN-205)

- `respaldar.sh` (diario, `edunova-respaldo.timer`): `pg_dump` de la base y copia de los buckets
  S3, todo cifrado con GPG (AES256) y subido **fuera del servidor** con rclone. Conserva
  `RESPALDO_DIAS` días en el destino y 3 en el servidor.
- `probar-restauracion.sh` (primer domingo del mes, `edunova-restauracion.timer`): baja el último
  respaldo, lo restaura en `edunova_restauracion`, corre `pruebas_aislamiento.sql` y borra todo.
- Si cualquiera falla, avisa a `ALERTAS_WEBHOOK_URL`.

Instalación (una vez, como root):

```sh
apt-get install -y rclone gnupg            # si faltan
rclone config                              # crea el remoto del destino (S3, Backblaze B2, Drive…)
install -m 600 /dev/null /etc/edunova/respaldo.env && nano /etc/edunova/respaldo.env
#   RESPALDO_CLAVE=…  RESPALDO_DESTINO=remoto:edunova-respaldos  RESPALDO_DIAS=30
#   S3_ENDPOINT=http://127.0.0.1:8391  S3_CLAVE=…  S3_SECRETO=…  S3_BUCKETS="…"
#   ALERTAS_WEBHOOK_URL=https://…
install -m 644 infra/vps/edunova-respaldo.* infra/vps/edunova-restauracion.* /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now edunova-respaldo.timer edunova-restauracion.timer
systemctl start edunova-respaldo && journalctl -u edunova-respaldo -n 20   # primera prueba
```

Guarda `RESPALDO_CLAVE` también fuera del servidor: sin ella los respaldos no se pueden abrir.

## Monitoreo (EDUN-206)

- `GET /api/v1/salud`: base de datos, Redis y cola (`ok` / `falla`, sin datos sensibles).
- La API cuenta las respuestas 5xx; `php artisan monitoreo:revisar` avisa si hay 5 o más en 5
  minutos, si la cola pasa de 200 trabajos o si fallaron trabajos en la última hora.
- `vigilar.sh` (cada 5 minutos, `edunova-vigilancia.timer`) corre esa revisión y comprueba desde
  afuera que el sitio y la API respondan.
- Los registros ocultan contraseñas, tokens, documentos y datos de salud (`LimpiarDatosSensibles`).
- Los avisos van a `ALERTAS_WEBHOOK_URL` (en `apps/api/.env` y en `/etc/edunova/respaldo.env`).

```sh
install -m 644 infra/vps/edunova-vigilancia.* /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now edunova-vigilancia.timer
```
