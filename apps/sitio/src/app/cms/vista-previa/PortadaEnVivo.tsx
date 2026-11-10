'use client';

import { useEffect, useState } from 'react';
import { Cabecera } from '@/componentes/landing/Cabecera';
import { Hero } from '@/componentes/landing/Hero';
import { cms } from '@/contenido/provisional';
import type { Hero as DatosHero } from '@/lib/landing';

export const MENSAJE_VISTA_PREVIA = 'edunova:vista-previa';

type Mensaje = { tipo: typeof MENSAJE_VISTA_PREVIA; hero: DatosHero; aviso: string };

function esMensaje(d: unknown): d is Mensaje {
  return typeof d === 'object' && d !== null && (d as Mensaje).tipo === MENSAJE_VISTA_PREVIA && typeof (d as Mensaje).hero === 'object';
}

/**
 * EDUN-322 · Portada en vivo: escucha al editor (solo mensajes del mismo origen y de la ventana
 * que la contiene) y pinta la barra superior y el hero con los componentes de la landing.
 * Los enlaces no navegan: es una vista previa.
 */
export function PortadaEnVivo() {
  const [datos, setDatos] = useState<Mensaje | null>(null);

  useEffect(() => {
    function alRecibir(e: MessageEvent) {
      if (e.origin !== location.origin || e.source !== window.parent || !esMensaje(e.data)) return;
      setDatos(e.data);
    }
    window.addEventListener('message', alRecibir);
    // Avisa al editor que ya puede enviar el contenido.
    window.parent.postMessage({ tipo: `${MENSAJE_VISTA_PREVIA}:lista` }, location.origin);
    return () => window.removeEventListener('message', alRecibir);
  }, []);

  if (!datos) {
    return <p className="p-space-lg font-body-md text-body-md text-secondary">{cms.vistaPrevia.esperando}</p>;
  }

  return (
    <div
      onClickCapture={(e) => {
        if ((e.target as Element).closest('a, button')) e.preventDefault();
      }}
    >
      <Cabecera aviso={datos.aviso} telefono={null} menu={[]} urlIngreso="#" />
      <main className="w-full pt-36 bg-background">
        <Hero datos={datos.hero} />
      </main>
    </div>
  );
}
