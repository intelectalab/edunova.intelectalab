import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import AsyncStorage from '@react-native-async-storage/async-storage';
import type { Cliente, Colegio, EstadoSesion } from './api';
import { hijoElegido, type HijoResumen, type ResumenFamilia } from './inicioFamiliaLogica';
import type { Grupo } from './navegacionLogica';

// S36 · Lo que comparten todas las pantallas de la app (sesión, colegio, color y acciones del perfil).

export type ContextoApp = {
  api: Cliente;
  estado: EstadoSesion;
  colegio: Colegio;
  color: string;
  grupo: Grupo;
  grupos: Grupo[];
  cambiarGrupo: (g: Grupo) => void;
  aviso: string | null;
  biometria: { disponible: boolean; activa: boolean };
  alBiometria: (v: boolean) => void;
  alCambiarColegio: () => void;
  alSalir: () => void;
};

const App = createContext<ContextoApp | null>(null);

export function AppProvider({ valor, children }: { valor: ContextoApp; children: ReactNode }) {
  return <App.Provider value={valor}>{children}</App.Provider>;
}

export function useApp(): ContextoApp {
  const v = useContext(App);
  if (!v) throw new Error('useApp fuera de AppProvider');
  return v;
}

// ── Familia: resumen de inicio y el hijo elegido (se recuerda en el teléfono).

const CLAVE_HIJO = 'edunova.familia.hijo';

type ContextoFamilia = {
  resumen: ResumenFamilia | null;
  hijo: HijoResumen | null;
  error: string | null;
  cargando: boolean;
  elegir: (id: number) => void;
  recargar: () => Promise<void>;
};

const Familia = createContext<ContextoFamilia>({ resumen: null, hijo: null, error: null, cargando: false, elegir: () => undefined, recargar: async () => undefined });

export function FamiliaProvider({ api, children }: { api: Cliente; children: ReactNode }) {
  const [resumen, setResumen] = useState<ResumenFamilia | null>(null);
  const [elegido, setElegido] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [cargando, setCargando] = useState(false);

  const recargar = useCallback(async () => {
    setCargando(true);
    try {
      setResumen(await api.pedir<ResumenFamilia>('/app/familia'));
      setError(null);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'No se pudo cargar el inicio.');
    } finally {
      setCargando(false);
    }
  }, [api]);

  useEffect(() => {
    AsyncStorage.getItem(CLAVE_HIJO).then((v) => v && setElegido(Number(v))).catch(() => undefined);
    void recargar();
  }, [recargar]);

  const valor = useMemo<ContextoFamilia>(() => ({
    resumen,
    hijo: resumen ? hijoElegido(resumen.hijos, elegido) : null,
    error,
    cargando,
    elegir: (id) => {
      setElegido(id);
      void AsyncStorage.setItem(CLAVE_HIJO, String(id)).catch(() => undefined);
    },
    recargar,
  }), [resumen, elegido, error, cargando, recargar]);

  return <Familia.Provider value={valor}>{children}</Familia.Provider>;
}

export const useFamilia = () => useContext(Familia);
