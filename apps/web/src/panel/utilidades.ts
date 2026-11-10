import { useCallback, useEffect, useRef, useState } from 'react';
import { apiArchivo, ErrorApi } from '../api/cliente';
import { useSesion } from '../sesion/contexto';

// Ayudas de la web de gestión que no son componentes (hooks, formatos y nombres).

/** Mensaje para la persona a partir de cualquier error. */
export function mensajeError(e: unknown): string {
  return e instanceof ErrorApi ? e.paraMostrar : 'Ocurrió un error. Inténtalo de nuevo.';
}

/** Carga datos al montar (y cuando cambian las dependencias) y permite recargar. */
export function useCarga<T>(cargar: () => Promise<T>, dependencias: unknown[] = []) {
  const [datos, setDatos] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [cargando, setCargando] = useState(true);
  const cargarActual = useRef(cargar);
  const clave = JSON.stringify(dependencias);

  useEffect(() => {
    cargarActual.current = cargar;
  });

  const [version, setVersion] = useState(0);

  // Vuelve a pedir los datos (se llama desde eventos: guardar, crear…).
  const recargar = useCallback(() => {
    setCargando(true);
    setVersion((v) => v + 1);
  }, []);

  useEffect(() => {
    // Si cambian las dependencias antes de responder, se ignora la respuesta vieja.
    const pedido = { vigente: true };
    cargarActual
      .current()
      .then((d) => {
        if (!pedido.vigente) return;
        setDatos(d);
        setError(null);
      })
      .catch((e) => pedido.vigente && setError(mensajeError(e)))
      .finally(() => pedido.vigente && setCargando(false));
    return () => {
      pedido.vigente = false;
    };
  }, [clave, version]);

  return { datos, setDatos, error, cargando, recargar };
}

/** Acción con estado de envío, error y mensaje de éxito. */
export function useAccion() {
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [ok, setOk] = useState<string | null>(null);

  const ejecutar = useCallback(async (accion: () => Promise<unknown>, mensajeOk?: string) => {
    setEnviando(true);
    setError(null);
    setOk(null);
    try {
      await accion();
      if (mensajeOk) setOk(mensajeOk);
      return true;
    } catch (e) {
      setError(mensajeError(e));
      return false;
    } finally {
      setEnviando(false);
    }
  }, []);

  return { enviando, error, ok, ejecutar, setError, setOk };
}

export function fechaHora(valor: string | null | undefined): string {
  if (!valor) return '—';
  // PostgreSQL entrega «2026-11-10 13:52:48.71-05»: el navegador necesita «T» y la zona «-05:00».
  const d = new Date(valor.replace(' ', 'T').replace(/([+-]\d{2})$/, '$1:00'));
  if (Number.isNaN(d.getTime())) return valor;
  return d.toLocaleString('es-PE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export function fecha(valor: string | null | undefined): string {
  if (!valor) return '—';
  const [a, m, d] = valor.slice(0, 10).split('-');
  return `${d}/${m}/${a}`;
}

export const NOMBRE_ROL: Record<string, string> = {
  promotor: 'Promotor(a)',
  director: 'Director(a)',
  subdirector: 'Subdirector(a)',
  coordinador: 'Coordinador(a)',
  secretaria: 'Secretaría',
  tesoreria: 'Tesorería',
  docente: 'Docente',
  tutor: 'Tutor(a)',
  auxiliar: 'Auxiliar',
  psicologia: 'Psicología',
  enfermeria: 'Enfermería',
  bibliotecario: 'Biblioteca',
  movilidad: 'Movilidad',
  comedor: 'Comedor',
  contador: 'Contador(a)',
  admision: 'Admisión',
  porteria: 'Portería',
  conductor: 'Conductor(a)',
  laboratorio: 'Laboratorio',
  nutricionista: 'Nutrición',
  tienda: 'Tienda',
  apoderado: 'Apoderado',
  alumno: 'Alumno',
  superadmin: 'Superadmin',
  soporte: 'Soporte',
};

/**
 * Imagen que se pide a la API con la sesión (p. ej. el logo, porque el bucket es privado).
 * Devuelve una URL blob: o null; «version» obliga a pedirla de nuevo cuando cambia.
 */
export function useImagenApi(ruta: string, activa: boolean, version: string): string | null {
  const { token } = useSesion();
  const [imagen, setImagen] = useState<{ clave: string; url: string } | null>(null);
  const clave = `${ruta}|${version}`;
  useEffect(() => {
    if (!activa) return;
    let vigente = true;
    let creada: string | null = null;
    apiArchivo(ruta, token)
      .then((b) => {
        if (!vigente) return;
        creada = URL.createObjectURL(b);
        setImagen({ clave, url: creada });
      })
      .catch(() => undefined);
    return () => {
      vigente = false;
      if (creada) URL.revokeObjectURL(creada);
    };
  }, [ruta, activa, token, clave]);
  return activa && imagen?.clave === clave ? imagen.url : null;
}
