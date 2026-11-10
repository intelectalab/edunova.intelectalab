import { createContext, useContext } from 'react';
import type { OpcionesApi } from '../api/cliente';

export type Colegio = {
  id: number;
  nombre: string;
  slug: string;
  roles: string[];
  // Llegan con la sesión verificada (EDUN-25, EDUN-26, EDUN-43).
  permisos?: string[];
  modulos?: string[];
  aula_opcion?: string;
  estado?: string | null;
  marca?: { color_primario: string | null; tiene_logo: boolean };
};

/** EDUN-38 · ok | no_requerido | configurar | verificar */
export type EstadoDosPasos = 'ok' | 'no_requerido' | 'configurar' | 'verificar';

export type EstadoSesion = {
  usuario: { id: number; nombres: string; apellidos: string; email: string | null };
  colegios: Colegio[];
  colegio_activo: Colegio | null;
  debe_elegir_colegio: boolean;
  roles_plataforma: string[];
  dos_pasos?: EstadoDosPasos;
  dos_pasos_activo?: boolean;
};

export type Sesion = {
  cargando: boolean;
  estado: EstadoSesion | null;
  token: string | null;
  ingresar: (usuario: string, password: string) => Promise<void>;
  elegirColegio: (id: number) => Promise<void>;
  salir: () => Promise<void>;
  /** Vuelve a pedir /auth/yo (tras el segundo factor o al cambiar la marca). */
  recargar: () => Promise<void>;
  /** Llamada a la API con el token de la sesión. */
  pedir: <T>(ruta: string, opciones?: Omit<OpcionesApi, 'token'>) => Promise<T>;
};

export const Contexto = createContext<Sesion | null>(null);

export function useSesion(): Sesion {
  const s = useContext(Contexto);
  if (!s) throw new Error('useSesion necesita ProveedorSesion');
  return s;
}

/** La sesión puede usar el colegio (segundo factor resuelto). */
export function sesionLista(estado: EstadoSesion | null): boolean {
  return !estado?.dos_pasos || estado.dos_pasos === 'ok' || estado.dos_pasos === 'no_requerido';
}

export function puede(colegio: Colegio | null | undefined, ...permisos: string[]): boolean {
  return permisos.some((p) => colegio?.permisos?.includes(p));
}

export function moduloActivo(colegio: Colegio | null | undefined, modulo: string): boolean {
  return colegio?.modulos?.includes(modulo) ?? false;
}
