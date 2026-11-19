import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { ACCESIBILIDAD_INICIAL, leerAccesibilidad, type Accesibilidad } from './aulaLogica';
import type { Tono } from './inicioFamiliaLogica';

// S36 · Tamaño de letra y alto contraste para toda la app (antes solo el aula). Misma clave que el aula
// para que la preferencia que ya eligió el alumno se conserve.
export const CLAVE_ACCESIBILIDAD = 'edunova.aula.accesibilidad';

export type Paleta = { fondo: string; tarjeta: string; texto: string; suave: string; borde: string; acento: string; sobreAcento: string; suaveAcento: string };

type Tema = {
  acc: Accesibilidad;
  cambiar: (a: Accesibilidad) => void;
  /** Tamaño de letra escalado según la preferencia. */
  t: (base: number) => number;
  c: Paleta;
};

const Contexto = createContext<Tema>({ acc: ACCESIBILIDAD_INICIAL, cambiar: () => undefined, t: (b) => b, c: paleta(ACCESIBILIDAD_INICIAL, '#047857', '#ffffff') });

export function paleta(a: Accesibilidad, color: string, sobre: string): Paleta {
  return a.contraste
    ? { fondo: '#000000', tarjeta: '#111111', texto: '#ffffff', suave: '#e5e7eb', borde: '#ffffff', acento: '#ffd400', sobreAcento: '#000000', suaveAcento: '#262626' }
    : { fondo: '#f4f6fa', tarjeta: '#ffffff', texto: '#111827', suave: '#6b7280', borde: '#e5e7eb', acento: color, sobreAcento: sobre, suaveAcento: `${color}14` };
}

export const TONOS: Record<Tono, { texto: string; fondo: string }> = {
  bien: { texto: '#15803d', fondo: '#dcfce7' },
  atencion: { texto: '#b45309', fondo: '#fef3c7' },
  mal: { texto: '#b91c1c', fondo: '#fee2e2' },
  neutro: { texto: '#374151', fondo: '#f3f4f6' },
};

export function TemaProvider({ color, sobre, children }: { color: string; sobre: string; children: ReactNode }) {
  const [acc, setAcc] = useState<Accesibilidad>(ACCESIBILIDAD_INICIAL);
  useEffect(() => {
    AsyncStorage.getItem(CLAVE_ACCESIBILIDAD).then((t) => setAcc(leerAccesibilidad(t))).catch(() => undefined);
  }, []);
  const valor = useMemo<Tema>(() => ({
    acc,
    cambiar: (a) => {
      setAcc(a);
      void AsyncStorage.setItem(CLAVE_ACCESIBILIDAD, JSON.stringify(a)).catch(() => undefined);
    },
    t: (b) => Math.round(b * acc.escala),
    c: paleta(acc, color, sobre),
  }), [acc, color, sobre]);
  return <Contexto.Provider value={valor}>{children}</Contexto.Provider>;
}

export const useTema = () => useContext(Contexto);
