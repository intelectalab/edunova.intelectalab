#!/usr/bin/env sh
# Corre las pruebas de reglas y permisos del esquema cms (database/sql/cms/pruebas_cms.sql)
# sobre una base nueva «edunova_cms_pruebas» con el esquema del SaaS, el esquema cms y las
# semillas neutras. Deben salir «TODAS LAS PRUEBAS PASARON» y «TODAS LAS PRUEBAS DE PERMISOS PASARON».
#
# Uso local, desde la raíz del repositorio y con Docker arriba:
#   sh infra/pruebas-cms.sh
# En CI se pasa el comando psql:  PSQL="psql" sh infra/pruebas-cms.sh
set -e
SQL=apps/api/database/sql
PSQL="${PSQL:-docker compose exec -T postgres psql -U edunova}"
P="$PSQL -v ON_ERROR_STOP=1 -q"
BASE=edunova_cms_pruebas

$P -d edunova -c "DROP DATABASE IF EXISTS $BASE" -c "CREATE DATABASE $BASE OWNER edunova" > /dev/null
$P -d $BASE -f - < "$SQL/01_esquema.sql" > /dev/null
$P -d $BASE -f - < "$SQL/cms/01_cms_esquema.sql" > /dev/null
$P -d $BASE -f - < "$SQL/cms/03_cms_conversion.sql" > /dev/null
$P -d $BASE -f - < "$SQL/cms/04_cms_reclamos_y_bandeja.sql" > /dev/null
$P -d $BASE -f - < "$SQL/cms/05_cms_crm_y_reclamaciones.sql" > /dev/null
$P -d $BASE -f - < "$SQL/cms/06_cms_panel_completo.sql" > /dev/null
$P -d $BASE -f - < "$SQL/cms/07_cms_paginas_y_canales.sql" > /dev/null
$P -d $BASE -f - < "$SQL/cms/02_cms_semillas_base.sql" > /dev/null
SALIDA=$($P -d $BASE -f - < "$SQL/cms/pruebas_cms.sql" 2>&1) || { echo "$SALIDA"; exit 1; }
echo "$SALIDA" | grep -E "OK|PASARON|FALLO" | sed 's/^.*NOTICE: *//'
echo "$SALIDA" | grep -q "TODAS LAS PRUEBAS PASARON" && echo "$SALIDA" | grep -q "TODAS LAS PRUEBAS DE PERMISOS PASARON" || { echo "Faltan resultados de pruebas_cms.sql"; exit 1; }
echo "Pruebas del CMS: todas OK"
