import { createContext, useContext } from 'react';

export type Colegio = { id: number; nombre: string; slug: string; roles: string[] };

export type EstadoSesion = {
  usuario: { id: number; nombres: string; apellidos: string; email: string | null };
  colegios: Colegio[];
  colegio_activo: Colegio | null;
  debe_elegir_colegio: boolean;
  roles_plataforma: string[];
};

export type Sesion = {
  cargando: boolean;
  estado: EstadoSesion | null;
  ingresar: (usuario: string, password: string) => Promise<void>;
  elegirColegio: (id: number) => Promise<void>;
  salir: () => Promise<void>;
};

export const Contexto = createContext<Sesion | null>(null);

export function useSesion(): Sesion {
  const s = useContext(Contexto);
  if (!s) throw new Error('useSesion necesita ProveedorSesion');
  return s;
}
