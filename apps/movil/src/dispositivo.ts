// Integración con el teléfono: token seguro, biometría y notificaciones push (EDUN-195 / EDUN-196).
import Constants from 'expo-constants';
import * as Device from 'expo-device';
import * as LocalAuthentication from 'expo-local-authentication';
import * as Notifications from 'expo-notifications';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';
import type { Cliente } from './api';

const OPCIONES: SecureStore.SecureStoreOptions = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY };
const CLAVES = { token: 'edunova.token', colegio: 'edunova.colegio', biometria: 'edunova.biometria', push: 'edunova.push' } as const;

// ── Token seguro en el dispositivo (Keychain en iOS, Keystore en Android). Nunca en AsyncStorage.

export async function leerSesion(): Promise<{ token: string | null; colegio: number | null; biometria: boolean }> {
  const [token, colegio, biometria] = await Promise.all([
    SecureStore.getItemAsync(CLAVES.token, OPCIONES),
    SecureStore.getItemAsync(CLAVES.colegio, OPCIONES),
    SecureStore.getItemAsync(CLAVES.biometria, OPCIONES),
  ]);
  return { token, colegio: colegio ? Number(colegio) : null, biometria: biometria === '1' };
}

export async function guardarSesion(token: string, colegio: number | null): Promise<void> {
  await SecureStore.setItemAsync(CLAVES.token, token, OPCIONES);
  if (colegio) await SecureStore.setItemAsync(CLAVES.colegio, String(colegio), OPCIONES);
  else await SecureStore.deleteItemAsync(CLAVES.colegio, OPCIONES);
}

export async function borrarSesion(): Promise<void> {
  await Promise.all(Object.values(CLAVES).map((k) => SecureStore.deleteItemAsync(k, OPCIONES)));
}

// ── Biometría opcional para volver a entrar.

export async function biometriaDisponible(): Promise<boolean> {
  return (await LocalAuthentication.hasHardwareAsync()) && (await LocalAuthentication.isEnrolledAsync());
}

export async function activarBiometria(activa: boolean): Promise<void> {
  if (activa) await SecureStore.setItemAsync(CLAVES.biometria, '1', OPCIONES);
  else await SecureStore.deleteItemAsync(CLAVES.biometria, OPCIONES);
}

export async function confirmarConBiometria(): Promise<boolean> {
  const r = await LocalAuthentication.authenticateAsync({ promptMessage: 'Entrar a Edunova', cancelLabel: 'Usar contraseña', disableDeviceFallback: false });
  return r.success;
}

// ── Notificaciones push (Expo Notifications entrega por APNs y FCM).

Notifications.setNotificationHandler({
  handleNotification: async () => ({ shouldShowBanner: true, shouldShowList: true, shouldPlaySound: true, shouldSetBadge: false }),
});

/** Pide permiso, obtiene el token de Expo y lo registra en la API. Devuelve el token o null. */
export async function registrarPush(api: Cliente): Promise<string | null> {
  if (!Device.isDevice || Platform.OS === 'web') return null;
  if (Platform.OS === 'android') {
    await Notifications.setNotificationChannelAsync('default', { name: 'Avisos de Edunova', importance: Notifications.AndroidImportance.DEFAULT });
  }
  let { status } = await Notifications.getPermissionsAsync();
  if (status !== 'granted') status = (await Notifications.requestPermissionsAsync()).status;
  if (status !== 'granted') return null;
  const projectId = Constants.expoConfig?.extra?.eas?.projectId ?? Constants.easConfig?.projectId;
  const token = (await Notifications.getExpoPushTokenAsync(projectId ? { projectId } : undefined)).data;
  await api.pedir('/auth/dispositivos', {
    metodo: 'POST',
    cuerpo: { token, plataforma: Platform.OS === 'ios' ? 'ios' : 'android', app_version: Constants.expoConfig?.version ?? null },
  });
  await SecureStore.setItemAsync(CLAVES.push, token, OPCIONES);
  return token;
}

/** Al cerrar sesión: el teléfono deja de recibir avisos de esa cuenta. */
export async function quitarPush(api: Cliente): Promise<void> {
  const token = await SecureStore.getItemAsync(CLAVES.push, OPCIONES);
  if (token) await api.pedir('/auth/dispositivos', { metodo: 'DELETE', cuerpo: { token } }).catch(() => undefined);
}
