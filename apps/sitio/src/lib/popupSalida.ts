// EDUN-271 / SIT-009 · Reglas del popup de salida (sin React, para probarlas con Vitest).
//
// Se muestra una sola vez según la frecuencia del CMS (por sesión, por día o en cada visita),
// nunca a quien ya pidió una demo o dejó sus datos, nunca antes de que el visitante elija sus
// cookies y nunca encima de otra ventana. Se dispara al intentar salir (el puntero sale por el
// borde superior, en escritorio) o tras un rato sin actividad (también en celulares).

export type ConfigPopup = {
  titulo: string;
  texto: string | null;
  cta: string;
  campo: 'email' | 'telefono' | 'ambos';
  espera_seg: number;
  inactividad_seg: number;
  frecuencia: 'sesion' | 'dia' | 'siempre';
  /** EDUN-391 · Imagen opcional de la Biblioteca de medios. */
  imagen?: { url: string; alt: string } | null;
};

export const CLAVE_VISTO = 'edunova_popup_visto';
export const CLAVE_CONVERTIDO = 'edunova_convertido';
const UN_DIA_MS = 24 * 60 * 60 * 1000;

function leer(almacen: 'local' | 'sesion', clave: string): string | null {
  try {
    return (almacen === 'local' ? localStorage : sessionStorage).getItem(clave);
  } catch {
    return null;
  }
}

function escribir(almacen: 'local' | 'sesion', clave: string, valor: string): void {
  try {
    (almacen === 'local' ? localStorage : sessionStorage).setItem(clave, valor);
  } catch {
    // Sin almacenamiento: vale solo para esta página.
  }
}

/** Quien pidió una demo o dejó sus datos no vuelve a ver el popup. */
export function marcarConvertido(): void {
  escribir('local', CLAVE_CONVERTIDO, new Date().toISOString());
}

export function yaConvertido(): boolean {
  return leer('local', CLAVE_CONVERTIDO) !== null;
}

export function puedeMostrarse(frecuencia: ConfigPopup['frecuencia'], ahora = Date.now()): boolean {
  if (yaConvertido()) return false;
  if (frecuencia === 'sesion') return leer('sesion', CLAVE_VISTO) === null;
  if (frecuencia === 'dia') {
    const visto = Number(leer('local', CLAVE_VISTO) ?? 0);
    return !visto || ahora - visto >= UN_DIA_MS;
  }
  return true;
}

export function marcarVisto(frecuencia: ConfigPopup['frecuencia'], ahora = Date.now()): void {
  if (frecuencia === 'sesion') escribir('sesion', CLAVE_VISTO, '1');
  if (frecuencia === 'dia') escribir('local', CLAVE_VISTO, String(ahora));
}

/** El puntero salió de la página por el borde superior (hacia las pestañas o la barra). */
export function esIntentoDeSalida(e: Pick<MouseEvent, 'clientY' | 'relatedTarget'>): boolean {
  return e.relatedTarget === null && e.clientY <= 0;
}
