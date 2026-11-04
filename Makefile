.PHONY: verify aislamiento

# Verificación completa en local (Docker arriba). En Windows sin make: sh infra/verificar.sh
verify:
	sh infra/verificar.sh

aislamiento:
	sh infra/pruebas-aislamiento.sh
