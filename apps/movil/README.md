# Edunova · App móvil

Una sola app (React Native + Expo SDK 57) para todos los colegios: toma el logo y el color del
colegio activo. Esta primera versión trae la base (EDUN-195, 196 y 200):

- Ingreso con DNI o correo, segundo factor cuando el rol lo exige y elección de colegio.
- Token guardado solo en el almacén seguro del teléfono (Keychain / Keystore).
- Biometría opcional (huella o rostro) para volver a entrar.
- Notificaciones push con Expo Notifications: registro del token en la API, preferencias por
  tipo y apertura de la pantalla relacionada al tocar el aviso.

## Desarrollo

Está fuera del workspace de pnpm (React Native necesita `node_modules` sin enlaces).

```bash
cd apps/movil
npm ci
cp .env.example .env.local   # EXPO_PUBLIC_API_URL apunta a la API
npx expo start               # abre en Expo Go o en un build de desarrollo
```

Verificaciones: `npm run typecheck`, `npm run lint`, `npm test`.

Las notificaciones push reales necesitan un build de desarrollo (no Expo Go) y el `projectId`
de EAS en `app.json` (`expo.extra.eas.projectId`); se configura al publicar en las tiendas (MOB-H04).

## Sin conexión (EDUN-197, 215, 217, 218)

La asistencia y las lecturas de QR del carné se guardan en el teléfono cuando no hay señal:
cifradas con XChaCha20-Poly1305 (la clave vive en el almacén seguro, nunca junto a los datos),
y se envían solas al recuperar la conexión, en orden y en bloques (`POST /asistencias/lote`).
Un error del servidor se reintenta hasta 5 veces; un rechazo por datos queda «con error» para
reintentar o descartar. El indicador de la pantalla de inicio muestra en línea / sin conexión y
cuántos registros esperan. Los conflictos los resuelve la hora real de cada registro.

## Tiendas y actualizaciones OTA (EDUN-198)

Perfiles de EAS en `eas.json` y `expo-updates` por versión de la app. Los pasos que dependen de
las cuentas de la empresa y las fichas de tienda están en `tienda/`.
