#!/usr/bin/env bash
# EDUN-206 · Vigilancia cada 5 minutos (edunova-vigilancia.timer):
#   1. «php artisan monitoreo:revisar»: salud de base, Redis y cola, 5xx de la API y trabajos
#      fallidos (avisa al webhook de ALERTAS_WEBHOOK_URL de la API).
#   2. Que el sitio público y la ruta de salud respondan desde afuera (nginx incluido).
set -uo pipefail
DIR="${EDUNOVA_DIR:-/var/www/edunova}"
[ -f /etc/edunova/sitio.env ] && . /etc/edunova/sitio.env
[ -f /etc/edunova/respaldo.env ] && . /etc/edunova/respaldo.env

alertar() {
  echo "[vigilancia] $*"
  if [ -n "${ALERTAS_WEBHOOK_URL:-}" ]; then
    curl -fsS -m 10 -H 'Content-Type: application/json' -d "{\"text\":\"Edunova: $*\"}" "$ALERTAS_WEBHOOK_URL" > /dev/null || true
  fi
}

sudo -u www-data php "$DIR/apps/api/artisan" monitoreo:revisar --no-interaction || echo "[vigilancia] la API reportó problemas (ver arriba)"

for url in "${EDUNOVA_SITIO_URL:-}/" "${EDUNOVA_API_URL:-}/salud"; do
  [ "$url" = "/" ] || [ "$url" = "/salud" ] && continue
  codigo=$(curl -s -o /dev/null -m 15 -w '%{http_code}' "$url")
  [ "$codigo" = "200" ] || alertar "$url responde $codigo"
done
exit 0
