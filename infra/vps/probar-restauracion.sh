#!/usr/bin/env bash
# EDUN-205 · Prueba mensual de restauración: baja el último respaldo cifrado del destino remoto,
# lo descifra, lo restaura en una base aparte («edunova_restauracion») y corre las pruebas de
# aislamiento (pruebas_aislamiento.sql) sobre ella. Al terminar borra la base y los archivos.
# Si algo falla, avisa al equipo técnico. Lo corre edunova-restauracion.timer una vez al mes.
#
# Usa /etc/edunova/respaldo.env (mismas variables que respaldar.sh).
set -euo pipefail
export HOME="${HOME:-/root}"

CONFIG=/etc/edunova/respaldo.env
# shellcheck disable=SC1090
. "$CONFIG"
: "${RESPALDO_CLAVE:?falta RESPALDO_CLAVE}" "${RESPALDO_DESTINO:?falta RESPALDO_DESTINO}"
DIR="${EDUNOVA_DIR:-/var/www/edunova}"
PRUEBA=edunova_restauracion
TRABAJO=$(mktemp -d)

log() { echo "[restauración] $*"; }
alertar() {
  log "ERROR: $*"
  if [ -n "${ALERTAS_WEBHOOK_URL:-}" ]; then
    curl -fsS -m 10 -H 'Content-Type: application/json' -d "{\"text\":\"Edunova: falló la prueba mensual de restauración: $*\"}" "$ALERTAS_WEBHOOK_URL" > /dev/null || true
  fi
}
limpiar() { sudo -u postgres dropdb --if-exists "$PRUEBA" || true; rm -rf "$TRABAJO"; }
trap 'alertar "línea $LINENO"; limpiar' ERR
trap limpiar EXIT

ULTIMO=$(rclone lsf "$RESPALDO_DESTINO" --include 'edunova-*.tar.gz.gpg' | sort | tail -1)
[ -n "$ULTIMO" ] || { alertar "no hay respaldos en $RESPALDO_DESTINO"; exit 1; }
log "último respaldo: $ULTIMO"
rclone copy "$RESPALDO_DESTINO/$ULTIMO" "$TRABAJO" --quiet
gpg --batch --quiet --decrypt --passphrase-fd 3 "$TRABAJO/$ULTIMO" 3<<<"$RESPALDO_CLAVE" | tar -C "$TRABAJO" -xzf -
[ -s "$TRABAJO/base.dump" ] || { alertar "el respaldo no trae base.dump"; exit 1; }

log "restaurando en $PRUEBA"
sudo -u postgres dropdb --if-exists "$PRUEBA"
sudo -u postgres createdb -O edunova "$PRUEBA"
sudo -u postgres pg_restore --no-owner --role=edunova -d "$PRUEBA" "$TRABAJO/base.dump"

log "verificando con pruebas_aislamiento.sql"
SALIDA=$(sudo -u postgres psql -v ON_ERROR_STOP=1 -q -d "$PRUEBA" -f "$DIR/apps/api/database/sql/pruebas_aislamiento.sql" 2>&1) || { alertar "pruebas de aislamiento con error"; echo "$SALIDA" | tail -20; exit 1; }
echo "$SALIDA" | grep -qi "fallo" && { alertar "pruebas de aislamiento fallidas"; echo "$SALIDA" | tail -20; exit 1; }
TABLAS=$(sudo -u postgres psql -tA -d "$PRUEBA" -c "select count(*) from information_schema.tables where table_schema in ('public','cms')")
log "listo: $ULTIMO restaurado ($TABLAS tablas) y verificado"
