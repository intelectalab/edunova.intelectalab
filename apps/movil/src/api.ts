// Cliente de la API de Edunova para la app (EDUN-195). Sin dependencias de React Native para
// poder probarlo con Vitest.

export type Colegio = {
  id: number;
  nombre: string;
  slug: string;
  roles: string[];
  permisos?: string[];
  estado?: string | null;
  marca?: { color_primario: string | null; tiene_logo: boolean };
};

export type EstadoSesion = {
  usuario: { id: number; nombres: string; apellidos: string; email: string | null };
  colegios: Colegio[];
  colegio_activo: Colegio | null;
  debe_elegir_colegio: boolean;
  dos_pasos: 'ok' | 'no_requerido' | 'verificar' | 'configurar';
};

export class ErrorApi extends Error {
  constructor(
    message: string,
    public estado: number,
    public errores: Record<string, string[]> = {},
  ) {
    super(message);
  }
}

type Opciones = { metodo?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'; cuerpo?: unknown };

export type Cliente = {
  base: string;
  pedir<T>(ruta: string, opciones?: Opciones): Promise<T>;
  cabeceras(): Record<string, string>;
};

/** Primer mensaje legible de una respuesta de error de Laravel. */
export function mensajeDe(estado: number, json: unknown): string {
  const j = (json ?? {}) as { message?: string; errors?: Record<string, string[]> };
  const primero = j.errors ? Object.values(j.errors)[0]?.[0] : undefined;
  if (primero) return primero;
  if (estado === 401) return 'Tu sesión terminó. Vuelve a ingresar.';
  if (estado === 403) return j.message || 'No tienes permiso para esto.';
  if (estado === 429) return 'Demasiados intentos. Espera un minuto.';
  if (estado >= 500) return 'Edunova no responde en este momento. Intenta de nuevo.';
  return j.message || 'No se pudo completar la acción.';
}

export function crearCliente(base: string, obtener: () => { token: string | null; colegio: number | null }, fetcher: typeof fetch = fetch): Cliente {
  const raiz = base.replace(/\/+$/, '');
  const cabeceras = () => {
    const { token, colegio } = obtener();
    const h: Record<string, string> = { Accept: 'application/json' };
    if (token) h.Authorization = `Bearer ${token}`;
    if (colegio) h['X-Institucion'] = String(colegio);
    return h;
  };
  return {
    base: raiz,
    cabeceras,
    async pedir<T>(ruta: string, { metodo = 'GET', cuerpo }: Opciones = {}): Promise<T> {
      const h = cabeceras();
      // Un FormData (fotos, archivos) va como multipart: el sistema pone su propio Content-Type.
      const esFormulario = typeof FormData !== 'undefined' && cuerpo instanceof FormData;
      if (cuerpo !== undefined && !esFormulario) h['Content-Type'] = 'application/json';
      let r: Response;
      try {
        r = await fetcher(`${raiz}${ruta}`, { method: metodo, headers: h, body: cuerpo === undefined ? undefined : esFormulario ? (cuerpo as FormData) : JSON.stringify(cuerpo) });
      } catch {
        throw new ErrorApi('Sin conexión con Edunova. Revisa tu internet.', 0);
      }
      const json = await r.json().catch(() => null);
      if (!r.ok) {
        throw new ErrorApi(mensajeDe(r.status, json), r.status, (json as { errors?: Record<string, string[]> } | null)?.errors ?? {});
      }
      return json as T;
    },
  };
}

/** Qué pantalla toca después de ingresar o al volver a abrir la app. */
export function siguientePaso(e: EstadoSesion): 'dos_pasos' | 'configurar_dos_pasos' | 'elegir_colegio' | 'sin_colegio' | 'inicio' {
  if (e.dos_pasos === 'verificar') return 'dos_pasos';
  if (e.dos_pasos === 'configurar') return 'configurar_dos_pasos';
  if (e.colegios.length === 0) return 'sin_colegio';
  if (!e.colegio_activo) return 'elegir_colegio';
  return 'inicio';
}
