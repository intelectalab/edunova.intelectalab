'use client';

import Link from 'next/link';
import { useState } from 'react';
import { Icono } from '@/componentes/base';
import { precioAnual, soles } from '@/lib/formato';
import { landing } from '@/contenido/provisional';
import type { Complemento, Oferta, Plan, SeccionPlanes } from '@/lib/landing';
import { Encabezado } from './Encabezado';

type Props = {
  datos: SeccionPlanes;
  planes: Plan[];
  complementos: Complemento[];
  oferta: Oferta | null;
  notaLegal: string;
  selectorAnual: boolean;
  descuentoAnual: number;
  textoAnio: string;
};

/**
 * EDUN-260 · Planes y precios. Nota legal siempre visible, formato S/ 1,234, plan recomendado
 * con borde verde, complementos, banner de migración (si no venció) y selector Mensual/Anual.
 */
export function Planes({ datos, planes, complementos, oferta, notaLegal, selectorAnual, descuentoAnual, textoAnio }: Props) {
  const [anual, setAnual] = useState(false);
  if (planes.length === 0) return null;
  const conSelector = selectorAnual && descuentoAnual > 0;

  function precio(p: Plan): { monto: string; periodo: string } | null {
    if (p.precio_mensual === null) return null;
    return anual && conSelector
      ? { monto: soles(precioAnual(p.precio_mensual, descuentoAnual)), periodo: textoAnio }
      : { monto: soles(p.precio_mensual), periodo: datos.por_mes };
  }

  return (
    <section id="planes" aria-labelledby="titulo-planes" className="w-full py-space-2xl bg-surface scroll-mt-28">
      <div className="max-w-7xl mx-auto px-margin-mobile md:px-margin">
        <Encabezado id="titulo-planes" insignia={datos.insignia} titulo={datos.titulo} descripcion={datos.descripcion} centrado />

        {conSelector && (
          <div role="group" aria-label={`${datos.mensual} / ${datos.anual}`} className="mx-auto mb-space-xl flex w-fit rounded-full bg-surface-container p-1">
            {[false, true].map((esAnual) => (
              <button
                key={String(esAnual)}
                type="button"
                aria-pressed={anual === esAnual}
                onClick={() => setAnual(esAnual)}
                className={`px-space-lg h-9 rounded-full font-label-lg text-label-lg ${anual === esAnual ? 'bg-primary text-on-primary' : 'text-primary'}`}
              >
                {esAnual ? `${datos.anual} (−${descuentoAnual} %)` : datos.mensual}
              </button>
            ))}
          </div>
        )}

        <ul className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-space-lg items-stretch">
          {planes.map((p) => {
            const pr = precio(p);
            return (
              <li
                key={p.clave}
                data-plan={p.clave}
                className={`relative bg-surface-container-lowest rounded-2xl p-space-lg flex flex-col gap-space-md ${p.recomendado ? 'border-2 border-accion shadow-md' : 'border border-surface-container shadow-sm'}`}
              >
                {p.recomendado && (
                  <span className="absolute -top-3 left-1/2 -translate-x-1/2 px-space-md py-0.5 rounded-full bg-accion text-accion-texto font-label-md text-label-md whitespace-nowrap">
                    {landing.recomendado}
                  </span>
                )}
                <div>
                  <h3 className="font-headline-sm text-headline-sm text-primary">{p.nombre}</h3>
                  <p className="font-label-md text-label-md text-secondary">{p.rango_texto}</p>
                </div>
                <div>
                  {pr ? (
                    <p className="flex items-baseline gap-space-xs">
                      <span className="font-headline-lg text-headline-lg text-primary font-bold">{pr.monto}</span>
                      <span className="font-body-md text-body-md text-secondary">{pr.periodo}</span>
                    </p>
                  ) : (
                    <p className="font-headline-md text-headline-md text-primary font-bold">{datos.personalizado}</p>
                  )}
                  <p className="font-label-sm text-label-sm text-secondary">{pr ? notaLegal : datos.personalizado_nota}</p>
                </div>
                {p.descripcion && <p className="font-body-sm text-body-sm text-on-surface-variant">{p.descripcion}</p>}
                <ul className="flex flex-col gap-space-xs">
                  {p.caracteristicas.map((c) => (
                    <li key={c.texto} className={`flex items-start gap-space-xs font-body-sm text-body-sm ${c.incluida ? 'text-on-surface' : 'text-secondary line-through'}`}>
                      <Icono nombre={c.incluida ? 'done' : 'close'} tamano="sm" className={`mt-0.5 ${c.incluida ? 'text-accion' : 'text-outline'}`} />
                      <span>
                        <span className="sr-only">{c.incluida ? landing.incluye : landing.noIncluye} </span>
                        {c.texto}
                      </span>
                    </li>
                  ))}
                </ul>
                <Link
                  href={`/?plan=${encodeURIComponent(p.clave)}#demo`}
                  className={`mt-auto text-center px-space-lg py-space-sm rounded-lg font-label-lg text-label-lg ${p.recomendado ? 'bg-accion hover:bg-accion-hover text-accion-texto' : 'bg-surface-container hover:bg-surface-container-high text-primary'}`}
                >
                  {p.cta_texto}
                </Link>
              </li>
            );
          })}
        </ul>

        {complementos.length > 0 && (
          <ul className="mt-space-xl grid grid-cols-1 md:grid-cols-3 gap-space-md">
            {complementos.map((c) => (
              <li key={c.clave} className="flex items-start gap-space-sm p-space-md rounded-xl bg-surface-container-low font-body-sm text-body-sm text-on-surface">
                <Icono nombre="add_circle" className="text-primary" />
                <span>
                  <strong>{c.etiqueta}:</strong> {c.precio !== null ? soles(c.precio) : ''} {c.nota}
                </span>
              </li>
            ))}
          </ul>
        )}

        {oferta && (
          <div className="mt-space-lg flex flex-col md:flex-row md:items-center justify-between gap-space-md p-space-lg rounded-2xl bg-primary text-on-primary">
            <p className="flex items-start gap-space-sm">
              <Icono nombre="swap_horiz" tamano="lg" className="text-tertiary-fixed" />
              <span>
                {oferta.titulo && <strong className="block font-title-lg text-title-lg">{oferta.titulo}</strong>}
                <span className="font-body-md text-body-md">{oferta.texto}</span>
              </span>
            </p>
            {oferta.cta && (
              <Link href="/?origen=migracion#demo" className="shrink-0 px-space-lg py-space-sm rounded-lg bg-surface-container-lowest text-primary font-label-lg text-label-lg hover:bg-surface-container-low">
                {oferta.cta}
              </Link>
            )}
          </div>
        )}

        <p className="mt-space-md text-center font-label-sm text-label-sm text-secondary">{notaLegal}</p>
      </div>
    </section>
  );
}
