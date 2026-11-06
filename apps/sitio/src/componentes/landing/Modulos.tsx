'use client';

import { useId, useState } from 'react';
import { Icono } from '@/componentes/base';
import type { ModuloWeb, SeccionModulos } from '@/lib/landing';

/** Imagen del módulo, o una vista por defecto con su ícono cuando no tiene (decorativa). */
function ImagenModulo({ modulo }: { modulo: ModuloWeb }) {
  if (modulo.imagen) {
    return (
      // eslint-disable-next-line @next/next/no-img-element -- imágenes de la Biblioteca de medios (EDUN-237), dominio variable
      <img
        src={modulo.imagen.url}
        alt={modulo.imagen.alt ?? ''}
        width={modulo.imagen.ancho ?? 1200}
        height={modulo.imagen.alto ?? 800}
        loading="lazy"
        className="w-full h-44 object-cover"
      />
    );
  }
  return (
    <div aria-hidden="true" className="h-44 bg-surface-container-low flex items-center justify-center">
      <span className="w-20 h-20 rounded-2xl bg-surface-container-lowest shadow-sm flex items-center justify-center text-primary">
        <Icono nombre={modulo.icono} className="text-[2.5rem]" />
      </span>
    </div>
  );
}

function Tarjeta({ modulo }: { modulo: ModuloWeb }) {
  return (
    <a
      href={`/funcionalidades#modulo-${modulo.clave}`}
      className="group bg-surface-container-lowest rounded-2xl shadow-sm hover:shadow-md transition-shadow flex flex-col overflow-hidden focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
    >
      <span className="border-b border-surface-container">
        <ImagenModulo modulo={modulo} />
      </span>
      <span className="p-space-lg flex flex-col gap-space-sm flex-1">
        <span className="w-12 h-12 rounded-xl bg-primary-fixed text-primary flex items-center justify-center">
          <Icono nombre={modulo.icono} className="text-[1.75rem]" />
        </span>
        <span className="font-title-lg text-title-lg text-primary">{modulo.nombre}</span>
        <span className="font-body-sm text-body-sm text-secondary">{modulo.descripcion}</span>
        {modulo.etiqueta && (
          <span className="mt-auto pt-space-sm flex items-center gap-space-xs font-label-md text-label-md text-accion">
            {modulo.etiqueta}
            <Icono nombre="arrow_forward" tamano="sm" />
          </span>
        )}
      </span>
    </a>
  );
}

/**
 * EDUN-251 · Grid de los 6 módulos principales y catálogo desplegable con el resto,
 * en el orden publicado en el CMS. El botón cuenta los módulos visibles.
 */
export function Modulos({ seccion, modulos }: { seccion: SeccionModulos; modulos: ModuloWeb[] }) {
  const [abierto, setAbierto] = useState(false);
  const idCatalogo = useId();
  const grid = modulos.filter((m) => m.en_grid).slice(0, 6);
  const resto = modulos.filter((m) => !grid.includes(m));
  const textoVer = seccion.boton_ver.replace('{n}', String(modulos.length));

  return (
    <section id="funcionalidades" aria-labelledby="titulo-modulos" className="w-full py-space-2xl bg-surface-container-low scroll-mt-28">
      <div className="max-w-7xl mx-auto px-margin-mobile md:px-margin">
        <div className="flex flex-col md:flex-row md:items-end justify-between mb-space-xl gap-space-md">
          <div>
            <span className="px-space-md py-1 rounded-full bg-secondary-container text-on-secondary-container font-label-sm text-label-sm uppercase tracking-wider">
              {seccion.insignia}
            </span>
            <h2 id="titulo-modulos" className="font-headline-lg-mobile text-headline-lg-mobile md:font-headline-lg md:text-headline-lg text-primary mt-2">
              {seccion.titulo}
            </h2>
          </div>
          {seccion.descripcion && <p className="font-body-md text-body-md text-secondary max-w-md">{seccion.descripcion}</p>}
        </div>

        <ul className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-space-lg">
          {grid.map((m) => (
            <li key={m.clave} className="flex">
              <Tarjeta modulo={m} />
            </li>
          ))}
        </ul>

        {resto.length > 0 && (
          <div className="mt-space-xl flex flex-col items-center gap-space-lg">
            <button
              type="button"
              aria-expanded={abierto}
              aria-controls={idCatalogo}
              onClick={() => setAbierto((a) => !a)}
              className="inline-flex items-center gap-space-xs px-space-lg h-11 rounded-lg bg-surface-container-lowest border border-outline-variant text-primary font-label-lg text-label-lg hover:bg-surface-container focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
            >
              <span>{abierto ? seccion.boton_ocultar : textoVer}</span>
              <Icono nombre={abierto ? 'expand_less' : 'expand_more'} />
            </button>
            <ul id={idCatalogo} className={`w-full grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-space-md ${abierto ? 'grid' : 'hidden'}`}>
              {resto.map((m) => (
                <li key={m.clave}>
                  <a
                    href={`/funcionalidades#modulo-${m.clave}`}
                    className="h-full bg-surface-container-lowest rounded-xl p-space-md flex gap-space-sm hover:shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                  >
                    <Icono nombre={m.icono} tamano="lg" className="text-primary shrink-0" />
                    <span className="flex flex-col gap-1">
                      <span className="font-title-md text-title-md text-primary">{m.nombre}</span>
                      <span className="font-body-sm text-body-sm text-secondary">{m.descripcion}</span>
                    </span>
                  </a>
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </section>
  );
}
