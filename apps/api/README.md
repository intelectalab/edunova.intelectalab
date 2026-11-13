# @edunova/api

API de Edunova (Laravel 13, PHP 8.3, PostgreSQL 16 con RLS, Redis, Sanctum). Todas las rutas van
bajo `/api/v1` (`routes/api.php`). El arranque local y las comprobaciones están en el README de la raíz.

## Módulos

Cada módulo vive en `app/Modulos/<Codigo>/` (ver `app/Modulos/README.md`):

| Código | Qué cubre |
|---|---|
| `PLA` | Plataforma: colegios, planes, suscripciones, cobro mensual del SaaS, soporte |
| `SEG` | Sesión, segundo factor, roles y permisos, auditoría, familia, recuperar contraseña, demo por rol |
| `ADM` | Admisión, alumnos, matrícula y ratificación |
| `CAL` | Notas, asistencia (QR y sin conexión), justificación de inasistencias, tutoría, libretas |
| `PAG` | Cargos, caja, pagos en línea, convenios, recaudación bancaria, facturación electrónica |
| `COM` | Comunicados, mensajería, agenda, citas, notificaciones (push, correo, WhatsApp), reclamos |
| `SIA` | Exportación a SIAGIE |
| `MDL` | Conector con Moodle |
| `MOV` | Movilidad escolar |
| `SIT` | Sitio público y Panel CMS: contenido, leads, chatbot, blog de WordPress, SEO, integraciones |
| `Compartido` | Contexto del colegio, archivos en S3, correo transaccional, monitoreo |

## Conexiones a la base

| Conexión | Rol | Uso |
|---|---|---|
| `pgsql` | `edunova_app` | La app; solo ve el colegio fijado en `app.institucion_id` (RLS forzado) |
| `propietario` | dueño | Migraciones y semillas: `php artisan migrate --database=propietario` |
| `plataforma` | `edunova_plataforma` | Consola interna; ve todos los colegios |

## Semillas

- `DatabaseSeeder`: esquema, semillas globales y el colegio de demostración ficticio (solo local y pruebas).
- `CmsSeeder`: contenido de ejemplo del sitio y del Panel CMS.
- `DemoSeeder`: claves de demostración locales (`director@demo.edunova.pe` y `superadmin@demo.edunova.pe`).
- `DatosPruebaSeeder`: datos para recorrer el sistema en el colegio ficticio (idempotente). En
  producción exige `EDUNOVA_DATOS_PRUEBA=true`.
- `E2eSeeder`: datos de las pruebas de punta a punta de la web.

## Configuración

`.env.example` documenta todas las variables. Las conexiones externas (SMTP, CRM, WordPress,
reCAPTCHA, IA, alertas, webhook de correo y demo) se pueden cargar también desde el Panel CMS
(`App\Modulos\SIT\Services\Integraciones`), que gana al `.env`. Los conectores simulados de
pasarela, facturación y WhatsApp solo se cambian en el `.env` (ver el README de la raíz).

## Pruebas

```bash
docker compose exec api php artisan test
```

Pest contra PostgreSQL (`edunova_test`). La base de pruebas se rehace una vez por corrida y no se
limpia entre pruebas: cada archivo crea sus propios datos y no reutiliza años escolares de otros.
