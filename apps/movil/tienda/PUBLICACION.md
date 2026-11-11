# Publicación en App Store y Google Play (EDUN-198 · MOB-H07)

La app ya trae lo necesario para compilar con EAS y recibir actualizaciones OTA. Lo que queda
depende de cuentas y datos de la empresa, que **no van en este repositorio** (es público).

## Lo que ya está en el código

- `eas.json` con tres perfiles: `desarrollo` (build de desarrollo, canal `desarrollo`),
  `pruebas` (APK interno, canal `pruebas`) y `produccion` (canal `produccion`, número de build
  automático en EAS).
- `expo-updates` con `runtimeVersion` por versión de la app: un cambio solo de JavaScript se
  publica con `eas update --channel produccion`; un cambio nativo (módulo nuevo, permisos)
  exige subir la versión en `app.json` y un build nuevo.
- Permisos de Android declarados y explicados (cámara, ubicación mientras se usa, biometría);
  se bloquean la ubicación en segundo plano y el micrófono.
- Textos de las fichas y respuestas de privacidad: `ficha-tiendas.md`.

## Pasos pendientes (los hace la empresa)

1. Crear las cuentas de desarrollador: Apple Developer Program y Google Play Console, a nombre
   de la empresa.
2. `npx eas login` y `npx eas init` en `apps/movil`: crea el proyecto y escribe
   `expo.extra.eas.projectId` y `expo.updates.url` en `app.json` (las notificaciones push
   necesitan ese `projectId`).
3. Definir la URL de la API de producción como variable de EAS (no en el repositorio):
   `npx eas env:create --name EXPO_PUBLIC_API_URL --environment production`.
4. Credenciales de firma: dejar que EAS las genere (`eas credentials`) y guardar el respaldo del
   keystore de Android fuera del repositorio.
5. Notificaciones: subir la clave APNs (iOS) y el archivo de FCM v1 (Android) en EAS.
6. Compilar y subir: `npx eas build --profile produccion --platform all` y
   `npx eas submit --profile produccion`. En Play empieza en la pista interna como borrador.
7. Completar en cada consola la ficha, las capturas (ver abajo), la clasificación de contenido,
   la sección de seguridad de los datos (Play) y la etiqueta de privacidad (App Store) con las
   respuestas de `ficha-tiendas.md`, y la URL de la política de privacidad del sitio
   (`/politica-de-privacidad`).
8. App Store: responder el cuestionario de cumplimiento de exportación. La app cifra la cola
   sin conexión en el teléfono (XChaCha20-Poly1305) además del HTTPS; revisar con quien
   corresponda si aplica la exención.
9. Cuentas de prueba para la revisión de Apple y Google (un apoderado y un docente del colegio
   de demostración), cargadas en la consola, nunca en el repositorio.

## Capturas sugeridas

Teléfono de 6,7" (iOS) y 1080 × 1920 (Android), con datos de demostración:
ingreso con DNI, inicio de la familia (hijos, avisos y pagos), notas y libreta, comunicados con
confirmación de lectura, asistencia del docente sin conexión y puerta con QR del carné.
