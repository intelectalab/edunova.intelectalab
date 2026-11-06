# Correo transaccional: registros DNS (EDUN-208)

El código de envío, la bitácora y la supresión de rebotes y quejas ya están en la API
(`app/Modulos/Compartido/Correo`). Lo que falta depende del dominio de envío y del proveedor
de correo, que se definen con los entornos (EDUN-203).

## Registros a crear en el dominio de envío

Usar un subdominio solo para correo transaccional (por ejemplo `correo.<dominio>`), para no
mezclar su reputación con el correo de las personas.

| Registro | Tipo | Valor | Nota |
|---|---|---|---|
| `correo.<dominio>` | TXT | `v=spf1 include:<spf-del-proveedor> -all` | SPF: solo el proveedor puede enviar |
| `<selector>._domainkey.correo.<dominio>` | TXT o CNAME | Lo entrega el proveedor | DKIM: firma de cada correo |
| `_dmarc.correo.<dominio>` | TXT | `v=DMARC1; p=quarantine; rua=mailto:dmarc@<dominio>; adkim=s; aspf=s` | Empezar con `p=none` dos semanas, revisar reportes y subir a `quarantine` |

## Verificación

- `dig TXT correo.<dominio>` y `dig TXT _dmarc.correo.<dominio>` devuelven los valores de arriba.
- Un correo de prueba (`php artisan correo:probar <tu-correo>`) llega con `spf=pass`, `dkim=pass` y
  `dmarc=pass` en las cabeceras `Authentication-Results`.

## Rebotes y quejas

Configurar en el proveedor el aviso de rebotes duros y quejas hacia
`POST /api/v1/correo/eventos`, firmado con `CORREO_WEBHOOK_SECRETO` (ver
`EventosCorreoController`). Si el proveedor usa otro formato, se agrega un adaptador.
