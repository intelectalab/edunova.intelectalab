#!/usr/bin/env sh
# Corre las 34 pruebas de aislamiento y reglas de la base (database/sql/pruebas_aislamiento.sql)
# sobre una base nueva «edunova_aislamiento» con solo el esquema y las semillas.
#
# Uso local, desde la raíz del repositorio y con Docker arriba:
#   sh infra/pruebas-aislamiento.sh
# En CI (PostgreSQL como servicio), se pasa el comando psql:
#   PSQL="psql -h localhost -U edunova" PGPASSWORD=edunova sh infra/pruebas-aislamiento.sh
set -e
SQL=apps/api/database/sql
PSQL="${PSQL:-docker compose exec -T postgres psql -U edunova}"
P="$PSQL -v ON_ERROR_STOP=1 -q"

$P -d edunova -c "DROP DATABASE IF EXISTS edunova_aislamiento" -c "CREATE DATABASE edunova_aislamiento OWNER edunova" > /dev/null
$P -d edunova_aislamiento -f - < "$SQL/01_esquema.sql" > /dev/null
$P -d edunova_aislamiento -f - < "$SQL/02_semillas.sql" > /dev/null
$P -d edunova_aislamiento -f - < "$SQL/pruebas_aislamiento.sql"
echo "Pruebas de aislamiento: todas OK"
