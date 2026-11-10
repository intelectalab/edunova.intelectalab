import { useEffect, useState, type CSSProperties } from 'react';

// Navegación por hash (#/usuarios) y marca del colegio. Sin enrutador: la web es pequeña
// y se publica en /app/ junto al sitio, así que el hash evita reglas en el servidor.

export function rutaActual(): string {
  return window.location.hash.replace(/^#\/?/, '');
}

/** Ruta del hash; se actualiza al navegar. */
export function useRuta(): string {
  const [ruta, setRuta] = useState(rutaActual);
  useEffect(() => {
    const cambio = () => setRuta(rutaActual());
    window.addEventListener('hashchange', cambio);
    return () => window.removeEventListener('hashchange', cambio);
  }, []);
  return ruta;
}

/** El color del colegio se aplica a botones, enlaces y acentos (EDUN-43). */
export function estiloMarca(color: string | null | undefined): CSSProperties {
  if (!color) return {};
  return {
    '--en-primario': color,
    '--en-primario-hover': `color-mix(in srgb, ${color} 85%, #000)`,
    '--en-primario-activo': `color-mix(in srgb, ${color} 75%, #000)`,
  } as CSSProperties;
}
