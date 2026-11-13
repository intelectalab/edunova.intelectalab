# @edunova/sitio

Sitio público y Panel CMS de Edunova (Next.js, App Router). El arranque local está en el README de la raíz.

## Sitio público

- Portada, funcionalidades, planes, clientes, demo, blog (desde WordPress), preguntas frecuentes,
  Libro de Reclamaciones y políticas legales. El contenido sale de la API (`/api/v1/sitio/*`).
- `/ingresar`: elige cómo entrar (personal del colegio, familias o equipo de Edunova) con ayuda
  para quien no puede ingresar. Textos en `src/contenido/provisional.ts` (`accesos`).
- Chatbot del sitio: registra cada conversación con `navigator.sendBeacon` a `/api/chatbot-sesion`.

## Panel CMS (`/cms`)

Solo para el superadministrador de la plataforma. Cada pantalla tiene su botón «Ayuda» (textos en
`src/contenido/ayudaCms.ts`) y una barra de progreso al navegar (`componentes/cms/BarraNavegacion.tsx`).

- **Tracking e integraciones** (`/cms/integraciones`): medición, WordPress, SMTP, CRM, reCAPTCHA,
  IA, alertas, webhook de correo, acceso de demostración y el estado de los conectores simulados,
  con «Probar conexión». Ver la tabla «Configuración: Panel CMS o `.env`» del README de la raíz.
- **Configuración general**: datos legales, contacto, WhatsApp y modo mantenimiento.
- El ingreso tiene «¿Olvidaste tu contraseña?».

## Variables

En `/etc/edunova/sitio.env` en el servidor y en `.env.local` en local: la URL de la API, el secreto
de revalidación y el del proxy de formularios. `RECAPTCHA_SITIO` es opcional: la clave pública de
reCAPTCHA se carga en el panel y llega con los datos del sitio.

## Pruebas

```bash
npx pnpm@10.18.3 e2e:local
```

Compila en `.next-e2e` y corre Playwright (escritorio y celular) contra las APIs simuladas de `e2e/`.
