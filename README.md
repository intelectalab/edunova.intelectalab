# Edunova

SaaS de gestión escolar para colegios privados del Perú. Monorepo con la API, la web de gestión, el sitio público y los paquetes compartidos.

| Carpeta | Qué es | Tecnología |
|---|---|---|
| `apps/api` | API (todas las rutas bajo `/api/v1`) | Laravel 13, PHP 8.3, PostgreSQL 16, Redis 7, Sanctum |
| `apps/web` | Web de gestión (staff, padres, alumnos) | React + TypeScript + Vite |
| `apps/sitio` | Sitio público y Panel CMS | Next.js (App Router) |
| `apps/movil` | App para familias y personal (Android e iOS) | React Native + Expo |
| `packages/tokens` | Colores, tipografía, sombras y radios | JSON, TS y CSS |
| `infra/` | Entorno local, despliegue en el VPS, correo y pruebas | SQL, shell, systemd |

El kit de referencia (documentos, prototipos y SQL original) y el plan de sprints son material interno y no están en este repositorio. Tampoco las credenciales: van en `privado/` (ignorado por git), ver [Accesos](#accesos).

Pendiente: `packages/api-client` (requiere `api/openapi.yaml`).

## Requisitos

- Docker Desktop
- Node.js 22 o superior (pnpm se usa con `npx pnpm@10.18.3`, o con `corepack enable`)
- Opcional: PHP 8.3+ y Composer en la máquina, para usar `artisan` fuera de Docker

## Arranque local

```bash
docker compose up -d
```

```bash
npx pnpm@10.18.3 install
```

```bash
npx pnpm@10.18.3 dev:web
```

```bash
npx pnpm@10.18.3 dev:sitio
```

La primera vez:

1. Copia `apps/api/.env.example` a `apps/api/.env` y genera la llave con `docker compose exec api php artisan key:generate`.
2. Crea la base con el esquema, las semillas y el colegio de demostración:

```bash
docker compose exec api php artisan migrate --database=propietario --seed
```

## Base de datos

- El esquema inicial (171 tablas con RLS por colegio) está en `apps/api/database/sql/01_esquema.sql` y lo carga la primera migración. Los cambios posteriores van en migraciones nuevas, nunca editando ese archivo.
- Semillas globales (25 roles, 165 permisos, módulos, planes, currículo) en `02_semillas.sql`; colegio de demostración ficticio en `03_datos_demo.sql`, solo en local y pruebas.
- Roles de PostgreSQL:

| Conexión de Laravel | Rol | Para qué |
|---|---|---|
| `pgsql` (por defecto) | `edunova_app` | La app. Sin bypass de RLS: solo ve el colegio fijado en `app.institucion_id` |
| `propietario` | dueño de la base | Solo migraciones y semillas (`--database=propietario`) |
| `plataforma` | `edunova_plataforma` | Consola interna; ve todos los colegios |

En local, `infra/postgres/00-roles.sql` crea los roles con claves de desarrollo la primera vez que se crea el volumen.

| Servicio | Dirección en tu máquina | Variable para cambiar el puerto |
|---|---|---|
| API | http://localhost:8100/api/v1/salud | `EDUNOVA_API_PUERTO` |
| PostgreSQL 16 | localhost:5433 (usuario y clave `edunova`) | `EDUNOVA_PG_PUERTO` |
| Redis 7 | localhost:6379 | `EDUNOVA_REDIS_PUERTO` |
| S3 (SeaweedFS) | http://localhost:9000 | `EDUNOVA_S3_PUERTO` |
| Mailpit (correo de prueba) | http://localhost:8026 | `EDUNOVA_MAILPIT_PUERTO` |
| Web de gestión | http://localhost:5173 | — |
| Sitio público | http://localhost:3000 | — |

Los puertos de la API, PostgreSQL y Mailpit no son los habituales porque en esta máquina 8000, 5432, 1025 y 8025 ya estaban ocupados.

Datos de prueba para recorrer el sistema (asistencia de 15 días, justificaciones pendientes, pagos,
comunicados y conversaciones del chatbot) en el colegio ficticio:

```bash
docker compose exec api php artisan db:seed --class=DatosPruebaSeeder --database=propietario
```

En un servidor con `APP_ENV=production` solo corre con `EDUNOVA_DATOS_PRUEBA=true` en el entorno del comando.

## Accesos

- **`/ingresar`** (sitio público): elige entre personal del colegio, familias o equipo de Edunova.
- **Web de gestión**: ingreso con DNI o correo; «¿Olvidaste tu contraseña?» envía un enlace de un
  solo uso que vence en 60 minutos (`POST /auth/recuperar` y `/auth/restablecer`).
- **Demostración por rol** (`GET|POST /auth/demo`): entra sin contraseña como director, docente,
  apoderado, etc. en el colegio ficticio, por 8 horas. Solo si está encendida (Panel CMS o
  `EDUNOVA_DEMO_ACCESO`); apágala en producción.
- **Panel CMS** (`/cms`): solo el superadministrador de la plataforma.
- **Ayuda por pantalla**: botón «Ayuda» en la web (`apps/web/src/panel/ayuda.ts`) y en el CMS
  (`apps/sitio/src/contenido/ayudaCms.ts`): para qué sirve cada pantalla, qué se puede y qué no.

Las credenciales locales de demostración están en `apps/api/database/seeders/DemoSeeder.php`. Las
del servidor de pruebas, en `privado/ACCESOS.md` (fuera de git; se genera desde los archivos del
servidor y nunca se sube).

## Configuración: Panel CMS o `.env`

Casi todas las conexiones se configuran en **Panel CMS → Tracking e integraciones**, sin tocar el
servidor. Lo del panel gana a la variable del `.env`; si el panel está vacío se usa el `.env`, y la
pantalla lo indica («se usa la del servidor»). Las claves se guardan cifradas con `APP_KEY` y la API
nunca las devuelve.

| Conexión | En el panel | Variable de respaldo | Probar conexión |
|---|---|---|---|
| Medición (GTM, GA4, Meta, LinkedIn, Clarity, Search Console) | Sí | — | — |
| Blog de WordPress | Sí | — | Sí |
| Correo saliente (SMTP) | Sí | `MAIL_*` | Sí (correo a quien prueba) |
| CRM (webhook de leads) | Sí | `CRM_WEBHOOK_URL`, `CRM_WEBHOOK_TOKEN` | Sí |
| reCAPTCHA v3 (Libro de Reclamaciones) | Sí | `RECAPTCHA_SECRETO`, `RECAPTCHA_SITIO`, `RECAPTCHA_UMBRAL` | — |
| IA del asistente SEO (Anthropic) | Sí | `ANTHROPIC_API_KEY` | — |
| Webhook de alertas del monitoreo | Sí | `ALERTAS_WEBHOOK_URL` | Sí |
| Webhook de rebotes y quejas del correo | Sí | `CORREO_WEBHOOK_SECRETO` | — |
| Acceso de demostración | Sí | `EDUNOVA_DEMO_ACCESO`, `EDUNOVA_DEMO_COLEGIO` | — |
| Pasarela, facturación y WhatsApp **simulados** | Solo se ven | `PASARELA_SIMULADA`, `FACTURACION_SIMULADA`, `WHATSAPP_SIMULADO` | — |

Los conectores simulados se cambian solo en el servidor a propósito: en producción permitirían
marcar pagos o comprobantes sin cobrar ni emitir. Fuera de producción van encendidos por defecto.
Las cuentas de pasarela y facturación de **cada colegio** se configuran en la web de gestión (Pagos).

El número de WhatsApp del sitio y los datos legales se editan en Panel CMS → Configuración general.

## Comprobaciones

```bash
docker compose exec api php artisan test
```

```bash
sh infra/pruebas-aislamiento.sh
```

La segunda corre las 34 pruebas de aislamiento y reglas de la base en una base limpia.

```bash
npx pnpm@10.18.3 -r typecheck
```

```bash
npx pnpm@10.18.3 -r lint
```

```bash
npx pnpm@10.18.3 -r build
```

Pruebas de punta a punta del sitio y el Panel CMS (contra una API simulada) y de la web contra la API real:

```bash
npx pnpm@10.18.3 --filter @edunova/sitio e2e:local
```

```bash
sh infra/e2e-web.sh
```

## Decisiones técnicas tomadas al arrancar

- **Laravel 13 en vez de 11** (04/11/2026): Laravel 11 ya no recibe parches y tenía 5 avisos de seguridad sin corrección (tokens de API, URL firmadas, correo). El kit decía Laravel 11.
- **React 19** en la web y el sitio (el kit decía React 18): es lo que instalan hoy las plantillas oficiales.
- **SeaweedFS** como S3 local: la imagen de MinIO ya no se publica en Docker Hub. En producción se usa el almacenamiento S3 del proveedor de nube.
- **Pruebas de la API contra PostgreSQL** (`edunova_test`), no SQLite: el kit depende de RLS y funciones de PostgreSQL.
- Composer fijado a PHP 8.3 (`config.platform.php`), igual que el contenedor.

## Convenciones

Las del kit (`docs/13-convenciones.md`): módulos de la API en `apps/api/app/Modulos/<Codigo>/`, nombres en español, dinero en `numeric(10,2)`, zona horaria `America/Lima`, todo proceso externo en cola e idempotente.
