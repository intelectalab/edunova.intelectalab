'use client';

import { usePathname, useSearchParams } from 'next/navigation';
import { useEffect, useState } from 'react';

/**
 * Respuesta inmediata al navegar en el panel: al tocar un enlace interno aparece al instante una
 * barra de progreso arriba (las pantallas leen la API en el servidor y pueden tardar un momento).
 * Se oculta cuando la nueva pantalla ya está en la dirección.
 */
export function BarraNavegacion() {
  const ruta = usePathname();
  const consulta = useSearchParams().toString();
  const [cargandoDe, setCargandoDe] = useState<string | null>(null);
  const actual = `${ruta}?${consulta}`;
  const cargando = cargandoDe !== null && cargandoDe === actual;

  useEffect(() => {
    const alClic = (e: MouseEvent) => {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      const a = (e.target as HTMLElement).closest('a');
      if (!a || a.target === '_blank' || a.hasAttribute('download')) return;
      const destino = new URL(a.href, location.href);
      if (destino.origin !== location.origin || !destino.pathname.startsWith('/cms')) return;
      if (destino.pathname + destino.search === location.pathname + location.search) return;
      setCargandoDe(`${location.pathname}?${location.search.replace(/^\?/, '')}`);
    };
    document.addEventListener('click', alClic, true);
    return () => document.removeEventListener('click', alClic, true);
  }, []);

  if (!cargando) return null;
  return (
    <div role="progressbar" aria-label="Cargando la pantalla" className="fixed top-0 left-0 right-0 z-[60] h-1 overflow-hidden bg-primary/15">
      <div className="h-full w-1/3 bg-primary animate-[avance_1s_ease-in-out_infinite]" />
    </div>
  );
}
