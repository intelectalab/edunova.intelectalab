// EDUN-165 · Ubicación del recorrido de movilidad en segundo plano.
//
// - Solo mientras hay un recorrido en curso: al finalizarlo se detiene (las familias no ven la
//   ubicación fuera del recorrido).
// - Cada posición se guarda cifrada en la cola y se envía por lote; sin señal se acumula (con tope)
//   y sale al volver la conexión con su hora real.
// - Con permiso «siempre», sigue con la pantalla apagada o la app en segundo plano (en Android con
//   un aviso fijo de servicio en primer plano). Sin ese permiso, se toma cada 20 s con la app abierta.
import AsyncStorage from '@react-native-async-storage/async-storage';
import * as Location from 'expo-location';
import * as TaskManager from 'expo-task-manager';
import type { Posicion } from './cola';
import { guardarOperacion } from './colaDispositivo';

export const TAREA_RECORRIDO = 'edunova-recorrido';
const CLAVE_ACTIVO = 'edunova.recorrido.activo';
export const SEGUNDOS_POSICION = 20;

export const aPosicion = (recorrido: number, l: Pick<Location.LocationObject, 'coords' | 'timestamp'>): Posicion => ({
  recorrido_id: recorrido,
  latitud: l.coords.latitude,
  longitud: l.coords.longitude,
  velocidad_kmh: l.coords.speed !== null && l.coords.speed >= 0 ? Math.round(l.coords.speed * 36) / 10 : null,
  precision_m: l.coords.accuracy !== null ? Math.round(l.coords.accuracy) : null,
  registrado_at: new Date(l.timestamp).toISOString(),
});

async function recorridoActivo(): Promise<number | null> {
  const v = await AsyncStorage.getItem(CLAVE_ACTIVO).catch(() => null);
  return v ? Number(v) : null;
}

/** Guarda las posiciones (la última dispara el envío si hay señal). */
export async function guardarPosiciones(recorrido: number, ubicaciones: Pick<Location.LocationObject, 'coords' | 'timestamp'>[]) {
  for (const [i, l] of ubicaciones.entries()) await guardarOperacion('mov_pos', aPosicion(recorrido, l), i === ubicaciones.length - 1);
}

// La tarea se define al cargar el módulo (index.ts lo importa antes que la app), para que el
// sistema la encuentre aunque la app se haya reabierto en segundo plano.
TaskManager.defineTask<{ locations: Location.LocationObject[] }>(TAREA_RECORRIDO, async ({ data, error }) => {
  if (error || !data?.locations?.length) return;
  const recorrido = await recorridoActivo();
  if (recorrido) await guardarPosiciones(recorrido, data.locations).catch(() => undefined);
});

/** Empieza a compartir la ubicación. Devuelve si quedó en segundo plano o solo con la app abierta. */
export async function iniciarSeguimiento(recorrido: number): Promise<'segundo_plano' | 'primer_plano'> {
  await AsyncStorage.setItem(CLAVE_ACTIVO, String(recorrido));
  try {
    const fondo = await Location.requestBackgroundPermissionsAsync();
    if (fondo.status !== 'granted' || !(await TaskManager.isAvailableAsync())) return 'primer_plano';
    await Location.startLocationUpdatesAsync(TAREA_RECORRIDO, {
      accuracy: Location.Accuracy.Balanced,
      timeInterval: SEGUNDOS_POSICION * 1000,
      distanceInterval: 25,
      deferredUpdatesInterval: SEGUNDOS_POSICION * 1000,
      pausesUpdatesAutomatically: false,
      showsBackgroundLocationIndicator: true,
      activityType: Location.ActivityType.AutomotiveNavigation,
      foregroundService: {
        notificationTitle: 'Recorrido en curso',
        notificationBody: 'Edunova comparte la ubicación de la movilidad con las familias hasta que finalices el recorrido.',
        killServiceOnDestroy: false,
      },
    });
    return 'segundo_plano';
  } catch {
    return 'primer_plano';
  }
}

export async function detenerSeguimiento() {
  await AsyncStorage.removeItem(CLAVE_ACTIVO).catch(() => undefined);
  if (await Location.hasStartedLocationUpdatesAsync(TAREA_RECORRIDO).catch(() => false)) {
    await Location.stopLocationUpdatesAsync(TAREA_RECORRIDO).catch(() => undefined);
  }
}

/** Si la app se cerró con un recorrido en curso, al volver se retoma (devuelve su id). */
export async function recorridoEnCurso(): Promise<number | null> {
  return recorridoActivo();
}
