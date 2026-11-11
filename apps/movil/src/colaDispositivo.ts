// EDUN-197 / EDUN-217 / EDUN-218 · La cola sin conexión en el teléfono: AsyncStorage cifrado con
// una clave guardada en SecureStore, el estado de la red (NetInfo) y la sincronización que se
// dispara solo al recuperar la conexión (no se consulta al servidor en bucle).
import { useEffect, useSyncExternalStore } from 'react';
import AsyncStorage from '@react-native-async-storage/async-storage';
import NetInfo from '@react-native-community/netinfo';
import * as SecureStore from 'expo-secure-store';
import { getRandomBytes } from 'expo-crypto';
import { bytesToHex, hexToBytes } from '@noble/ciphers/utils.js';
import type { Cliente } from './api';
import { crearCifrado, type Cifrado } from './cifrado';
import * as cola from './cola';

const CLAVE_LLAVERO = 'edunova.cola.clave';
let cifradoListo: Promise<Cifrado> | null = null;

function cifrado(): Promise<Cifrado> {
  cifradoListo ??= (async () => {
    let hex = await SecureStore.getItemAsync(CLAVE_LLAVERO);
    if (!hex) {
      hex = bytesToHex(getRandomBytes(32));
      await SecureStore.setItemAsync(CLAVE_LLAVERO, hex, { keychainAccessible: SecureStore.AFTER_FIRST_UNLOCK_THIS_DEVICE_ONLY });
    }
    return crearCifrado(hexToBytes(hex), (n) => getRandomBytes(n));
  })();
  return cifradoListo;
}

export type EstadoCola = { enLinea: boolean; pendientes: number; errores: cola.Operacion[]; sincronizando: boolean; mensaje: string | null };
let estado: EstadoCola = { enLinea: true, pendientes: 0, errores: [], sincronizando: false, mensaje: null };
const oyentes = new Set<() => void>();
const cambiar = (parcial: Partial<EstadoCola>) => {
  estado = { ...estado, ...parcial };
  oyentes.forEach((o) => o());
};
const reflejar = (c: cola.Operacion[]) => cambiar({ pendientes: cola.pendientes(c), errores: cola.conError(c) });

export function useEstadoCola(): EstadoCola {
  return useSyncExternalStore((o) => {
    oyentes.add(o);
    return () => oyentes.delete(o);
  }, () => estado);
}

const envioDe = (api: Cliente): cola.Envio => ({
  lista: async (l) => {
    await api.pedir(`/asistencia/secciones/${l.seccion_id}`, { metodo: 'PUT', cuerpo: { fecha: l.fecha, origen: 'offline', registros: l.registros } });
  },
  qr: async (lecturas) => (await api.pedir<{ resultados: cola.ResultadoQr[] }>('/asistencias/lote', { metodo: 'POST', cuerpo: { lecturas } })).resultados,
});

let envioActual: cola.Envio | null = null;

export async function sincronizarAhora(): Promise<void> {
  if (!envioActual || !estado.enLinea) return;
  cambiar({ sincronizando: true });
  try {
    const r = await cola.sincronizar(AsyncStorage, await cifrado(), envioActual);
    reflejar(await cola.leerCola(AsyncStorage, await cifrado()));
    if (r.enviados) cambiar({ mensaje: `Se sincronizaron ${r.enviados} registros guardados sin conexión.` });
  } finally {
    cambiar({ sincronizando: false });
  }
}

export async function guardarLista(lote: cola.Lote) {
  reflejar(await cola.encolarLista(AsyncStorage, await cifrado(), lote));
}
export async function guardarLectura(lectura: cola.Lectura) {
  reflejar(await cola.encolarLectura(AsyncStorage, await cifrado(), lectura));
}
export async function descartarOperacion(id: string) {
  reflejar(await cola.descartar(AsyncStorage, await cifrado(), id));
}
export async function reintentarOperacion(id: string) {
  reflejar(await cola.reintentar(AsyncStorage, await cifrado(), id));
  await sincronizarAhora();
}

/** Se monta una vez con la sesión iniciada: escucha la red y sincroniza al reconectar. */
export function useSincronizacion(api: Cliente) {
  useEffect(() => {
    envioActual = envioDe(api);
    cifrado().then((c) => cola.leerCola(AsyncStorage, c)).then(reflejar).catch(() => undefined);
    let anterior: boolean | null = null;
    const quitar = NetInfo.addEventListener((s) => {
      const enLinea = !!s.isConnected && s.isInternetReachable !== false;
      cambiar({ enLinea });
      // Solo al pasar de sin conexión a con conexión (o al abrir la app con red).
      if (enLinea && anterior !== true) sincronizarAhora().catch(() => undefined);
      anterior = enLinea;
    });
    return () => {
      quitar();
      envioActual = null;
    };
  }, [api]);
}
