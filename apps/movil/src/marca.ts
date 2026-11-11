// EDUN-200 · Imagen del colegio en la app: una sola app que toma el logo y el color del colegio activo.

export const VERDE_EDUNOVA = '#047857';

/** Color del colegio si es un hexadecimal válido; si no, el de Edunova. */
export function colorDeMarca(color: string | null | undefined): string {
  return color && /^#[0-9a-fA-F]{6}$/.test(color) ? color.toLowerCase() : VERDE_EDUNOVA;
}

function luminancia(hex: string): number {
  const canal = (i: number) => {
    const v = parseInt(hex.slice(i, i + 2), 16) / 255;
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * canal(1) + 0.7152 * canal(3) + 0.0722 * canal(5);
}

/** Texto blanco o casi negro según cuál contraste más sobre el color (WCAG). */
export function textoSobre(color: string): '#ffffff' | '#111827' {
  const l = luminancia(colorDeMarca(color));
  const contrasteBlanco = 1.05 / (l + 0.05);
  const contrasteNegro = (l + 0.05) / (luminancia('#111827') + 0.05);
  return contrasteBlanco >= contrasteNegro ? '#ffffff' : '#111827';
}

export function iniciales(nombre: string): string {
  return nombre
    .replace(/^(I\.?E\.?P?\.?|IEP|Colegio)\s+/i, '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]!.toUpperCase())
    .join('');
}
