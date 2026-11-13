# @edunova/web

Web de gestión de Edunova (React 19 + TypeScript + Vite) para el personal del colegio, las familias
y el equipo de la plataforma. Se publica en `/app/`. El arranque local está en el README de la raíz.

## Estructura

Una carpeta por módulo de la API (`adm`, `cal`, `com`, `pag`, `pla`, `seg`, `sia`, `mdl`, `familia`)
más:

- `sesion/`: ingreso, segundo factor, elección de colegio, «¿Olvidaste tu contraseña?»,
  restablecer (`#/restablecer?token=…`) y la demostración por rol (si el servidor la ofrece).
- `panel/Marco.tsx`: menú, rutas y botón «Ayuda». Cada pantalla se carga al abrirla (`React.lazy`)
  y después del ingreso se precargan en segundo plano para que abran al instante.
- `panel/ayuda.ts`: la ayuda de cada pantalla (para qué sirve, qué se puede, qué no y un consejo).
  Al agregar una pantalla, agrega su entrada.

## Variables

`VITE_API_URL` (dirección de la API) y `VITE_BASE` (`/app/` en el servidor).

## Pruebas

```bash
npx pnpm@10.18.3 --filter @edunova/web test
```

Vitest con Testing Library. Las de punta a punta contra la API real: `sh infra/e2e-web.sh` desde la raíz.
