# Edunova

SaaS de gestión escolar para colegios privados del Perú. Monorepo con la API, la web de gestión, el sitio público y los paquetes compartidos.

| Carpeta | Qué es | Tecnología |
|---|---|---|
| `apps/api` | API (todas las rutas bajo `/api/v1`) | Laravel 13, PHP 8.3, PostgreSQL 16, Redis 7, Sanctum |
| `apps/web` | Web de gestión (staff, padres, alumnos) | React + TypeScript + Vite |
| `apps/sitio` | Sitio público y Panel CMS | Next.js (App Router) |
| `packages/tokens` | Colores, tipografía, sombras y radios | JSON, TS y CSS |
| `infra/` | Scripts de apoyo del entorno local | SQL |

El kit de referencia (documentos, prototipos y SQL original) y el plan de sprints son material interno y no están en este repositorio.

Pendientes de otras historias: `apps/movil` (Expo, MOB), `packages/api-client` (requiere `api/openapi.yaml`), `database/` con el SQL del kit (EDUN-416).

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

La primera vez, copia `apps/api/.env.example` a `apps/api/.env` y genera la llave con `docker compose exec api php artisan key:generate`.

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

## Comprobaciones

```bash
docker compose exec api php artisan test
```

```bash
npx pnpm@10.18.3 -r typecheck
```

```bash
npx pnpm@10.18.3 -r lint
```

```bash
npx pnpm@10.18.3 -r build
```

## Decisiones técnicas tomadas al arrancar

- **Laravel 13 en vez de 11** (04/11/2026): Laravel 11 ya no recibe parches y tenía 5 avisos de seguridad sin corrección (tokens de API, URL firmadas, correo). El kit decía Laravel 11.
- **React 19** en la web y el sitio (el kit decía React 18): es lo que instalan hoy las plantillas oficiales.
- **SeaweedFS** como S3 local: la imagen de MinIO ya no se publica en Docker Hub. En producción se usa el almacenamiento S3 del proveedor de nube.
- **Pruebas de la API contra PostgreSQL** (`edunova_test`), no SQLite: el kit depende de RLS y funciones de PostgreSQL.
- Composer fijado a PHP 8.3 (`config.platform.php`), igual que el contenedor.

## Convenciones

Las del kit (`docs/13-convenciones.md`): módulos de la API en `apps/api/app/Modulos/<Codigo>/`, nombres en español, dinero en `numeric(10,2)`, zona horaria `America/Lima`, todo proceso externo en cola e idempotente.
