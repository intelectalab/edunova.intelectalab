#!/usr/bin/env sh
# Verificación completa en local (equivale a lo que corre CI). Requiere Docker arriba.
#   sh infra/verificar.sh      (o «make verify» donde haya make)
#
# Partes del «make verify» del kit que aún no aplican:
#   OpenAPI (falta api/openapi.yaml), Jest (app móvil).
# La primera vez: (cd apps/sitio && npx playwright install chromium)
set -e
PNPM="${PNPM:-npx -y pnpm@10.18.3}"

echo "== API: pruebas (Pest) y auditoría de dependencias"
docker compose exec -T api ./vendor/bin/pint --test
docker compose exec -T api php artisan test
docker compose exec -T api composer audit

echo "== Base de datos: aislamiento y reglas (34 pruebas)"
sh infra/pruebas-aislamiento.sh

echo "== CMS: reglas y permisos (21 pruebas)"
sh infra/pruebas-cms.sh

echo "== Web y sitio: tipos, lint, pruebas y build"
$PNPM -r typecheck
$PNPM -r lint
$PNPM -r test
$PNPM -r build

echo "== Sitio: pruebas de pantalla (Playwright + axe)"
(cd apps/sitio && $PNPM e2e:local)

echo "Verificación completa: todo OK"
