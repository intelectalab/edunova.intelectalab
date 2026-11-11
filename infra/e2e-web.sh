#!/bin/sh
# EDUN-210 · Pruebas de pantalla de la web de gestión (apps/web/e2e) contra la API REAL.
#
#   sh infra/e2e-web.sh                 # todo: datos de prueba, build, vista previa y Playwright
#   sh infra/e2e-web.sh --headed        # los argumentos extra van a Playwright
#
# Requiere el entorno local levantado (docker compose up -d) con el colegio demo cargado
# (php artisan migrate --database=propietario --seed). Flujos: matrícula, pago en línea con el
# webhook de la pasarela simulada, notas y cierre del periodo, y plantilla del SIAGIE.
#
# La web debe servirse en el origen que la API acepta (CORS_ORIGENES) y al que devuelve a la
# familia tras pagar (EDUNOVA_WEB_URL): en local, http://localhost:5174 (apps/api/.env).
# Si ya hay una web en ese puerto (por ejemplo `pnpm --filter @edunova/web dev`), Playwright
# la reutiliza; si no, sirve el build con `vite preview`.
#
# Variables: E2E_API_URL (http://localhost:8100/api/v1), E2E_WEB_URL (http://localhost:5174),
# EDUNOVA_API_CONTENEDOR (edunova-api-1), PNPM (pnpm; p. ej. "npx -y pnpm@10.18.3").
set -eu

RAIZ=$(cd "$(dirname "$0")/.." && pwd)
API_URL=${E2E_API_URL:-http://localhost:8100/api/v1}
WEB_URL=${E2E_WEB_URL:-http://localhost:5174}
CONTENEDOR=${EDUNOVA_API_CONTENEDOR:-edunova-api-1}
PNPM=${PNPM:-pnpm}

echo "== 1/4 · Datos de prueba (E2eSeeder, idempotente) en $CONTENEDOR"
docker exec "$CONTENEDOR" php artisan db:seed --class=E2eSeeder --force

echo "== 2/4 · Build de la web con VITE_API_URL=$API_URL"
cd "$RAIZ/apps/web"
VITE_API_URL="$API_URL" $PNPM build

echo "== 3/4 · Navegador de Playwright (Chromium)"
$PNPM exec playwright install chromium

if curl -fsS -o /dev/null "$WEB_URL" 2>/dev/null; then
  echo "== 4/4 · Ya hay una web en $WEB_URL: se reutiliza (no es el build recién hecho)."
else
  echo "== 4/4 · Se sirve el build con vite preview en $WEB_URL"
fi
# La configuración de Playwright levanta `vite preview` si el puerto está libre.
VITE_API_URL="$API_URL" E2E_WEB_URL="$WEB_URL" $PNPM exec playwright test "$@"
