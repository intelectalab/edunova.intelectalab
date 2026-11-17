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

## Justificar inasistencias (EDUN-96)

Los apoderados envían la justificación con una foto del sustento (cámara o galería, con
`expo-image-picker`); el tutor la acepta o rechaza en la web y la falta pasa de F a J.

## Puerta, portería, tópico y movilidad (EDUN-186, 214, 176, 163, 165)

- **Puerta:** además del QR del carné, se registra la asistencia con el DNI del alumno si olvidó el carné.
- **Portería:** al inicio del turno se descarga (cifrada) la lista de quién puede recoger a cada
  alumno; la salida se verifica con el documento de quien recoge, también sin señal, y se envía sola.
- **Tópico:** si el alumno se va, se elige quién lo retira entre sus apoderados y autorizados.
- **Movilidad:** las marcas de subida y bajada, la ubicación y el fin del recorrido se guardan en la
  cola cifrada y salen al volver la señal con su hora real. La ubicación sigue en segundo plano
  (`src/ubicacion.ts`, `expo-task-manager`) solo mientras el recorrido está en curso; en Android
  muestra un aviso fijo. Necesita un build (no funciona en Expo Go).

## Fases 3 y 4 en la app

- **Familia:** trámites con fotos de los requisitos y descarga del documento firmado; autorizaciones y
  documentos del colegio; autorizados a recoger y salidas anticipadas; tareas del aula de los hijos;
  movilidad en vivo (llegada estimada y mapa del teléfono); ficha de salud; menú del comedor;
  biblioteca (reservas y libros digitales); tienda del colegio (opcional).
- **Alumno:** aula virtual (avance en orden, entregas con foto, cuestionarios, encuesta) con letra
  grande y alto contraste; «Entrar a Moodle» si el colegio lo usa; biblioteca.
- **Personal:** pase de comedor con QR; marca de subida y bajada con el QR del carné (también sin
  señal, con la ruta guardada); atención básica del tópico sin señal. Al cerrar sesión se envía lo
  pendiente y se borra la base local cifrada.
- Los videos subidos al aula registran el porcentaje visto en la web; en la app se abren aparte.

## APK de pruebas (Android)

Para instalar en un celular sin pasar por la tienda. Se compila en la PC con Android Studio
(su JDK y el SDK de Android), sin cuenta de Expo:

```bash
npx expo prebuild --platform android --no-install
```

En `android/gradle.properties` (la carpeta `android/` no se sube a git):

```properties
org.gradle.jvmargs=-Xmx4096m -XX:MaxMetaspaceSize=1536m
reactNativeArchitectures=arm64-v8a
```

Luego, con `JAVA_HOME` apuntando al `jbr` de Android Studio y la dirección de la API:

```bash
cd android && EXPO_PUBLIC_API_URL=https://<servidor>/api/v1 NODE_ENV=production ./gradlew assembleRelease
```

El APK queda en `android/app/build/outputs/apk/release/app-release.apk`, firmado con la clave de
depuración: sirve para pruebas, no para Google Play. Con cuenta de Expo, `eas build -p android
--profile pruebas` hace lo mismo en la nube. Las notificaciones push necesitan el `projectId` de EAS.

`npx expo prebuild` cambia los scripts `android` e `ios` de `package.json`: no subas ese cambio.

## Tiendas y actualizaciones OTA (EDUN-198)

Perfiles de EAS en `eas.json` y `expo-updates` por versión de la app. Los pasos que dependen de
las cuentas de la empresa y las fichas de tienda están en `tienda/`.
