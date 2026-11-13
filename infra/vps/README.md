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

## Servidor de pruebas

`APP_ENV=production` también en el servidor de pruebas, así que los conectores simulados y la demo
se encienden a propósito en `apps/api/.env`:

```sh
PASARELA_SIMULADA=true
FACTURACION_SIMULADA=true
WHATSAPP_SIMULADO=true
EDUNOVA_DEMO_ACCESO=true      # o desde Panel CMS → Tracking e integraciones
```

En un servidor de producción real no se definen (o van en `false`). Lo demás (SMTP, CRM,
WordPress, reCAPTCHA, IA, alertas) se carga en el Panel CMS.

Datos de prueba en el colegio ficticio (idempotente):

```sh
cd /var/www/edunova/apps/api
sudo -u www-data env EDUNOVA_DATOS_PRUEBA=true php artisan db:seed --class=DatosPruebaSeeder --database=propietario --force
```

APK de pruebas de la app: se sirve desde `/var/www/edunova-descargas/` con un bloque
`location = /descargas/edunova-pruebas.apk` en el sitio de nginx (ver `apps/movil/README.md` para
compilarlo). No se actualiza con el despliegue: hay que compilarlo y copiarlo de nuevo.

Las credenciales de acceso se guardan solo en el servidor (root) y en `privado/` de cada PC del
equipo, nunca en el repositorio.

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
- Los avisos van al webhook de alertas: el del Panel CMS (Tracking e integraciones, con «Probar
  conexión») o `ALERTAS_WEBHOOK_URL` en `apps/api/.env`. Los scripts de respaldo leen el de
  `/etc/edunova/respaldo.env`.

```sh
install -m 644 infra/vps/edunova-vigilancia.* /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now edunova-vigilancia.timer
```

## Programador de tareas (Sprint 15)

`edunova-programador.timer` corre `php artisan schedule:run` cada minuto como `www-data`:

- `soporte:vigilar-sla` cada 5 minutos: avisa al staff los tickets al 75 % del plazo y escala los vencidos (EDUN-31).
- `plataforma:suspensiones` cada día a las 07:00 (Lima): avisos de 7 y 3 días al promotor y suspensiones programadas (EDUN-28).

Instalación (una vez, como root):

```sh
install -m 644 infra/vps/edunova-programador.* /etc/systemd/system/
systemctl daemon-reload && systemctl enable --now edunova-programador.timer
```
