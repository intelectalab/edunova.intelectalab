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

  constructor(estado: number, mensaje: string, errores: Record<string, string[]> = {}) {
    super(mensaje);
    this.estado = estado;
    this.errores = errores;
  }

  /** Primer mensaje de validación, o el mensaje general. */
  get paraMostrar(): string {
    return Object.values(this.errores)[0]?.[0] ?? this.message;
  }
}

export async function api<T>(ruta: string, opciones: { metodo?: string; cuerpo?: unknown; token?: string | null } = {}): Promise<T> {
  let respuesta: Response;
  try {
    respuesta = await fetch(API_URL + ruta, {
      method: opciones.metodo ?? 'GET',
      headers: {
        Accept: 'application/json',
        ...(opciones.cuerpo !== undefined ? { 'Content-Type': 'application/json' } : {}),
        ...(opciones.token ? { Authorization: `Bearer ${opciones.token}` } : {}),
      },
      body: opciones.cuerpo !== undefined ? JSON.stringify(opciones.cuerpo) : undefined,
    });
  } catch {
    throw new ErrorApi(0, 'No pudimos conectar con Edunova. Revisa tu conexión e inténtalo de nuevo.');
  }
  const datos = await respuesta.json().catch(() => ({}));
  if (!respuesta.ok) {
    throw new ErrorApi(respuesta.status, datos.message ?? 'Ocurrió un error. Inténtalo de nuevo.', datos.errors ?? {});
  }
  return datos as T;
}
