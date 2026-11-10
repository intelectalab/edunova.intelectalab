// Cliente mínimo de la API de Edunova (el paquete packages/api-client llegará con más módulos).
export const API_URL: string = import.meta.env.VITE_API_URL ?? 'http://localhost:8100/api/v1';

const CLAVE_TOKEN = 'edunova.token';

export const almacenToken = {
  leer(): string | null {
    try {
      return localStorage.getItem(CLAVE_TOKEN);
    } catch {
      return null;
    }
  },
  guardar(token: string | null) {
    try {
      if (token) localStorage.setItem(CLAVE_TOKEN, token);
      else localStorage.removeItem(CLAVE_TOKEN);
    } catch {
      // Sin almacenamiento (modo privado): la sesión dura lo que la pestaña.
    }
  },
};

/** Error de la API con el mensaje para la persona (formato { message, errors }). */
export class ErrorApi extends Error {
  estado: number;
  errores: Record<string, string[]>;
  codigo: string | null;

  constructor(estado: number, mensaje: string, errores: Record<string, string[]> = {}, codigo: string | null = null) {
    super(mensaje);
    this.estado = estado;
    this.errores = errores;
    this.codigo = codigo;
  }

  /** Primer mensaje de validación, o el mensaje general. */
  get paraMostrar(): string {
    return Object.values(this.errores)[0]?.[0] ?? this.message;
  }
}

export type OpcionesApi = { metodo?: string; cuerpo?: unknown; token?: string | null };

async function pedir(ruta: string, opciones: OpcionesApi): Promise<Response> {
  const esFormulario = typeof FormData !== 'undefined' && opciones.cuerpo instanceof FormData;
  try {
    return await fetch(API_URL + ruta, {
      method: opciones.metodo ?? 'GET',
      headers: {
        Accept: 'application/json',
        ...(opciones.cuerpo !== undefined && !esFormulario ? { 'Content-Type': 'application/json' } : {}),
        ...(opciones.token ? { Authorization: `Bearer ${opciones.token}` } : {}),
      },
      body: opciones.cuerpo === undefined ? undefined : esFormulario ? (opciones.cuerpo as FormData) : JSON.stringify(opciones.cuerpo),
    });
  } catch {
    throw new ErrorApi(0, 'No pudimos conectar con Edunova. Revisa tu conexión e inténtalo de nuevo.');
  }
}

async function error(respuesta: Response): Promise<ErrorApi> {
  const datos = await respuesta.json().catch(() => ({}));
  return new ErrorApi(respuesta.status, datos.message ?? 'Ocurrió un error. Inténtalo de nuevo.', datos.errors ?? {}, datos.codigo ?? null);
}

export async function api<T>(ruta: string, opciones: OpcionesApi = {}): Promise<T> {
  const respuesta = await pedir(ruta, opciones);
  if (!respuesta.ok) throw await error(respuesta);
  return (await respuesta.json().catch(() => ({}))) as T;
}

/** Descarga binaria con sesión (logo, exportaciones). */
export async function apiArchivo(ruta: string, token: string | null): Promise<Blob> {
  const respuesta = await pedir(ruta, { token });
  if (!respuesta.ok) throw await error(respuesta);
  return respuesta.blob();
}

/** Guarda un Blob como archivo en el equipo de la persona. */
export function guardarArchivo(blob: Blob, nombre: string) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = nombre;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
