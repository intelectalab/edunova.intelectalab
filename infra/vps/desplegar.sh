#!/usr/bin/env bash
# EDUN-211 · Despliegue continuo del sitio, la API y la web en el VPS de pruebas.
#
# Lo corre el timer edunova-despliegue.timer cada 5 minutos (como root). Si la rama principal
# tiene un commit nuevo y TODAS sus verificaciones de CI terminaron en verde, lo despliega:
# dependencias, migraciones, cachés, builds y reinicio de servicios; al final comprueba que el
# sitio y la API respondan. Un commit con CI en rojo no se despliega nunca.
#
#   sudo infra/vps/desplegar.sh            # despliega si hay un commit nuevo con CI en verde
#   sudo infra/vps/desplegar.sh --forzar   # vuelve a desplegar el commit actual
#
# Configuración (opcional) en /etc/edunova/despliegue.env: EDUNOVA_DIR, EDUNOVA_RAMA, EDUNOVA_REPO.
# Las variables del sitio (URL pública, URL de la API) salen de /etc/edunova/sitio.env.
set -euo pipefail

[ -f /etc/edunova/despliegue.env ] && . /etc/edunova/despliegue.env
DIR="${EDUNOVA_DIR:-/var/www/edunova}"
RAMA="${EDUNOVA_RAMA:-main}"
REPO="${EDUNOVA_REPO:-intelectalab/edunova.intelectalab}"
PNPM="npx -y pnpm@10.18.3"
ESTADO=/var/lib/edunova-despliegue
FORZAR="${1:-}"

log() { echo "[despliegue] $*"; }

mkdir -p "$ESTADO"
exec 9>"$ESTADO/candado"
flock -n 9 || { log "ya hay un despliegue en curso"; exit 0; }

cd "$DIR"
git fetch -q origin "$RAMA"
ACTUAL=$(git rev-parse HEAD)
NUEVO=$(git rev-parse "origin/$RAMA")

if [ "$ACTUAL" = "$NUEVO" ] && [ "$FORZAR" != "--forzar" ]; then
  exit 0
fi
if [ "$FORZAR" != "--forzar" ] && [ "$(cat "$ESTADO/descartado" 2>/dev/null)" = "$NUEVO" ]; then
  exit 0
fi

# Estado de CI del commit (API pública de GitHub; el repositorio es público).
CI=$(curl -fsS -H 'Accept: application/vnd.github+json' "https://api.github.com/repos/$REPO/commits/$NUEVO/check-runs?per_page=100" | python3 -c '
import json, sys
corridas = json.load(sys.stdin).get("check_runs", [])
if not corridas or any(c["status"] != "completed" for c in corridas):
    print("pendiente")
elif all(c["conclusion"] in ("success", "skipped", "neutral") for c in corridas):
    print("verde")
else:
    print("rojo")
')
case "$CI" in
  verde) ;;
  pendiente) log "CI de ${NUEVO:0:7} aún corre; se reintenta en el próximo ciclo"; exit 0 ;;
  *) log "CI de ${NUEVO:0:7} en rojo: no se despliega"; echo "$NUEVO" > "$ESTADO/descartado"; exit 0 ;;
esac

log "desplegando ${ACTUAL:0:7} → ${NUEVO:0:7}"
git reset -q --hard "$NUEVO"

# API (Laravel)
cd "$DIR/apps/api"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --quiet
php artisan migrate --database=propietario --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache

# Sitio (Next.js) y web de gestión (Vite), con las variables del sitio.
cd "$DIR"
set -a
. /etc/edunova/sitio.env
set +a
$PNPM install --frozen-lockfile
$PNPM --filter @edunova/sitio build
VITE_API_URL="$EDUNOVA_API_URL" VITE_BASE=/app/ $PNPM --filter @edunova/web build

# www-data lee el código, las dependencias y los builds; escribe la caché de Next.js.
chgrp -R www-data node_modules apps/*/node_modules apps/web/dist apps/api/vendor
chmod -R g+rX node_modules apps/*/node_modules apps/web/dist apps/api/vendor
chown -R www-data:www-data apps/sitio/.next

systemctl restart edunova-sitio edunova-cola
systemctl reload php8.3-fpm

# Humo: el sitio y la API responden.
for i in $(seq 1 30); do
  if curl -fsS -o /dev/null "$EDUNOVA_SITIO_URL/" && curl -fsS -o /dev/null "$EDUNOVA_API_URL/salud"; then
    log "listo: ${NUEVO:0:7} en línea"
    rm -f "$ESTADO/descartado"
    exit 0
  fi
  sleep 2
done
log "el sitio o la API no responden tras desplegar ${NUEVO:0:7}"
exit 1
