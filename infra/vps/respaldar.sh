#!/usr/bin/env bash
# EDUN-205 · Respaldo diario de la base de datos y de los archivos (almacenamiento S3).
#
# Lo corre edunova-respaldo.timer cada día (como root). Hace:
#   1. pg_dump de la base «edunova» (formato custom) y copia de los buckets S3 de Edunova.
#   2. Empaqueta y cifra todo con GPG simétrico (AES256) usando RESPALDO_CLAVE.
#   3. Sube el archivo cifrado FUERA del servidor con rclone (RESPALDO_DESTINO).
#   4. Borra los respaldos remotos de más de RESPALDO_DIAS días y los locales de más de 3.
# Si algo falla, avisa al equipo técnico (ALERTAS_WEBHOOK_URL) y termina con error.
#
# Configuración en /etc/edunova/respaldo.env (solo root, chmod 600):
#   RESPALDO_CLAVE=…            frase para cifrar (guárdala también fuera del servidor)
#   RESPALDO_DESTINO=remoto:edunova-respaldos   remoto de rclone ya configurado (S3, B2, Drive…)
#   RESPALDO_DIAS=30
#   S3_ENDPOINT=http://127.0.0.1:8391  S3_CLAVE=…  S3_SECRETO=…  S3_BUCKETS="edunova-objetos edunova-medios"
#   ALERTAS_WEBHOOK_URL=https://…      (opcional)
set -euo pipefail
export HOME="${HOME:-/root}"

CONFIG=/etc/edunova/respaldo.env
[ -f "$CONFIG" ] || { echo "[respaldo] falta $CONFIG"; exit 1; }
# shellcheck disable=SC1090
. "$CONFIG"
: "${RESPALDO_CLAVE:?falta RESPALDO_CLAVE}" "${RESPALDO_DESTINO:?falta RESPALDO_DESTINO}"
DIAS="${RESPALDO_DIAS:-30}"
BASE="${RESPALDO_BASE:-edunova}"
LOCAL=/var/backups/edunova
FECHA=$(date -u +%Y%m%dT%H%M%SZ)
TRABAJO=$(mktemp -d)
ARCHIVO="$LOCAL/edunova-$FECHA.tar.gz.gpg"

log() { echo "[respaldo] $*"; }
alertar() {
  log "ERROR: $*"
  if [ -n "${ALERTAS_WEBHOOK_URL:-}" ]; then
    curl -fsS -m 10 -H 'Content-Type: application/json' -d "{\"text\":\"Edunova: falló el respaldo diario ($FECHA): $*\"}" "$ALERTAS_WEBHOOK_URL" > /dev/null || true
  fi
}
trap 'alertar "línea $LINENO"; rm -rf "$TRABAJO"' ERR
trap 'rm -rf "$TRABAJO"' EXIT

mkdir -p "$LOCAL" && chmod 700 "$LOCAL"

log "base de datos $BASE"
sudo -u postgres pg_dump --format=custom --no-owner --no-privileges "$BASE" > "$TRABAJO/base.dump"
[ -s "$TRABAJO/base.dump" ] || { alertar "pg_dump vacío"; exit 1; }

if [ -n "${S3_BUCKETS:-}" ]; then
  log "archivos S3: $S3_BUCKETS"
  mkdir -p "$TRABAJO/s3"
  for b in $S3_BUCKETS; do
    RCLONE_CONFIG_LOCALS3_TYPE=s3 RCLONE_CONFIG_LOCALS3_PROVIDER=Other RCLONE_CONFIG_LOCALS3_ENDPOINT="$S3_ENDPOINT" \
    RCLONE_CONFIG_LOCALS3_ACCESS_KEY_ID="$S3_CLAVE" RCLONE_CONFIG_LOCALS3_SECRET_ACCESS_KEY="$S3_SECRETO" \
      rclone copy "localS3:$b" "$TRABAJO/s3/$b" --quiet
  done
fi

log "cifrando"
tar -C "$TRABAJO" -czf - . | gpg --batch --yes --quiet --symmetric --cipher-algo AES256 --passphrase-fd 3 -o "$ARCHIVO" 3<<<"$RESPALDO_CLAVE"
chmod 600 "$ARCHIVO"

log "subiendo a $RESPALDO_DESTINO"
rclone copy "$ARCHIVO" "$RESPALDO_DESTINO" --quiet
rclone check "$LOCAL" "$RESPALDO_DESTINO" --one-way --include "$(basename "$ARCHIVO")" --quiet

log "limpiando respaldos viejos (remotos > $DIAS días, locales > 3 días)"
rclone delete "$RESPALDO_DESTINO" --min-age "${DIAS}d" --include 'edunova-*.tar.gz.gpg' --quiet || true
find "$LOCAL" -name 'edunova-*.tar.gz.gpg' -mtime +3 -delete

log "listo: $(basename "$ARCHIVO") ($(du -h "$ARCHIVO" | cut -f1))"
