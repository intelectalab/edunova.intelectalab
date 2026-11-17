#!/bin/sh
# EDUN-150 · Respaldo diario del Moodle de un colegio: base de datos y moodledata, cifrados y
# enviados fuera del servidor con rclone (mismo destino que los respaldos de Edunova).
# Variables: COLEGIO, RESPALDO_CLAVE, RCLONE_DESTINO (por ejemplo «respaldos:edunova/moodle»).
set -eu
: "${COLEGIO:?Falta COLEGIO}" "${RESPALDO_CLAVE:?Falta RESPALDO_CLAVE}" "${RCLONE_DESTINO:?Falta RCLONE_DESTINO}"
fecha=$(date +%Y%m%d-%H%M)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

docker compose -p "moodle-$COLEGIO" exec -T db pg_dump -U moodle -Fc moodle > "$tmp/moodle.dump"
docker run --rm -v "moodle-${COLEGIO}_moodledata:/datos:ro" -v "$tmp:/salida" alpine tar -czf /salida/moodledata.tgz -C /datos .
tar -cf - -C "$tmp" moodle.dump moodledata.tgz | openssl enc -aes-256-cbc -pbkdf2 -salt -pass env:RESPALDO_CLAVE > "$tmp/moodle-$COLEGIO-$fecha.tar.enc"
rclone copy "$tmp/moodle-$COLEGIO-$fecha.tar.enc" "$RCLONE_DESTINO/$COLEGIO/"
# Se conservan 30 días.
rclone delete --min-age 30d "$RCLONE_DESTINO/$COLEGIO/"
echo "Respaldo listo: moodle-$COLEGIO-$fecha.tar.enc"
