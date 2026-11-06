import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { almacenToken, api, ErrorApi } from '../api/cliente';
import { Contexto, type EstadoSesion, type Sesion } from './contexto';

/** EDUN-35 · Sesión de la web de gestión: token Sanctum y colegio activo. */
export function ProveedorSesion({ children }: { children: ReactNode }) {
  const [token, setToken] = useState<string | null>(() => almacenToken.leer());
  const [estado, setEstado] = useState<EstadoSesion | null>(null);
  const [cargando, setCargando] = useState<boolean>(() => almacenToken.leer() !== null);

  const fijarToken = useCallback((t: string | null) => {
    almacenToken.guardar(t);
    setToken(t);
  }, []);

  // Al abrir la web con un token guardado, se recupera la sesión.
  useEffect(() => {
    if (!token || estado) return;
    let vigente = true;
    api<EstadoSesion>('/auth/yo', { token })
      .then((e) => vigente && setEstado(e))
      .catch((e) => {
        if (vigente && e instanceof ErrorApi && e.estado === 401) fijarToken(null);
      })
      .finally(() => vigente && setCargando(false));
    return () => {
      vigente = false;
    };
  }, [token, estado, fijarToken]);

  const valor = useMemo<Sesion>(
    () => ({
      cargando,
      estado,
      async ingresar(usuario, password) {
        const r = await api<EstadoSesion & { token: string }>('/auth/ingresar', {
          metodo: 'POST',
          cuerpo: { usuario, password, dispositivo: 'web' },
        });
        fijarToken(r.token);
        setEstado(r);
      },
      async elegirColegio(id) {
        setEstado(await api<EstadoSesion>('/auth/colegio', { metodo: 'POST', cuerpo: { institucion_id: id }, token }));
      },
      async salir() {
        await api('/auth/salir', { metodo: 'POST', token }).catch(() => undefined);
        fijarToken(null);
        setEstado(null);
      },
    }),
    [cargando, estado, token, fijarToken],
  );

  return <Contexto.Provider value={valor}>{children}</Contexto.Provider>;
}
