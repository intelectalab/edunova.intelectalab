'use client';

import { usePathname } from 'next/navigation';
import { useState } from 'react';
import { Icono } from '@/componentes/base';
import { ayudaCms } from '@/contenido/ayudaCms';

/**
 * Ayuda por pantalla del Panel CMS: qué hace la pantalla, qué se puede hacer y qué no. Botón en la
 * barra superior que abre un panel lateral; se cierra solo al cambiar de pantalla.
 */
export function AyudaCms() {
  const ruta = usePathname();
  const [abiertaEn, setAbiertaEn] = useState<string | null>(null);
  const texto = ayudaCms[ruta] ?? ayudaCms[Object.keys(ayudaCms).filter((r) => r !== '/cms' && ruta.startsWith(r)).sort((a, b) => b.length - a.length)[0] ?? ''];
  if (!texto) return null;
  const abierta = abiertaEn === ruta;
  return (
    <>
      <button
        type="button"
        onClick={() => setAbiertaEn(abierta ? null : ruta)}
        aria-expanded={abierta}
        aria-controls="ayuda-cms"
        className="inline-flex items-center gap-1 h-10 pl-1.5 pr-3 rounded-full border border-outline-variant font-label-lg text-label-lg text-primary hover:border-primary hover:bg-surface-container-low focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary print:hidden"
      >
        <span className="w-7 h-7 rounded-full bg-primary text-on-primary flex items-center justify-center" aria-hidden="true">?</span>
        <span className="sr-only md:not-sr-only">Ayuda</span>
      </button>
      {abierta && (
        <aside id="ayuda-cms" aria-labelledby="titulo-ayuda-cms" className="fixed top-0 right-0 z-50 w-full max-w-sm h-full overflow-y-auto bg-surface-container-lowest border-l border-surface-container shadow-xl p-space-lg flex flex-col gap-space-sm">
          <div className="flex items-start justify-between gap-space-sm">
            <h2 id="titulo-ayuda-cms" className="font-title-lg text-title-lg text-primary">{texto.titulo}</h2>
            <button type="button" onClick={() => setAbiertaEn(null)} aria-label="Cerrar la ayuda" className="w-9 h-9 rounded-lg flex items-center justify-center hover:bg-surface-container">
              <Icono nombre="close" tamano="sm" />
            </button>
          </div>
          <p className="font-body-md text-body-md text-on-surface">{texto.para}</p>
          <h3 className="mt-space-sm font-label-md text-label-md uppercase tracking-wider text-secondary">Puedes</h3>
          <ul className="flex flex-col gap-space-xs">
            {texto.puedes.map((x) => (
              <li key={x} className="flex gap-2 font-body-md text-body-md text-on-surface"><Icono nombre="check_circle" tamano="sm" className="text-[#047857] shrink-0" />{x}</li>
            ))}
          </ul>
          {texto.noPuedes && (
            <>
              <h3 className="mt-space-sm font-label-md text-label-md uppercase tracking-wider text-secondary">No se puede</h3>
              <ul className="flex flex-col gap-space-xs">
                {texto.noPuedes.map((x) => (
                  <li key={x} className="flex gap-2 font-body-md text-body-md text-on-surface"><Icono nombre="block" tamano="sm" className="text-error shrink-0" />{x}</li>
                ))}
              </ul>
            </>
          )}
          {texto.consejo && <p className="mt-space-sm px-space-md py-space-sm rounded-lg bg-surface-container-low font-body-md text-body-md text-on-surface">{texto.consejo}</p>}
        </aside>
      )}
    </>
  );
}
