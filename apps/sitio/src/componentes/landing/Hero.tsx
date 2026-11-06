import { Icono } from '@/componentes/base';
import { landing } from '@/contenido/provisional';
import type { Hero as DatosHero } from '@/lib/landing';
import { MockupPanel } from './MockupPanel';

/** EDUN-242 · Hero de la landing en 5 + 7 columnas: mensaje y conversión, y mockup del panel. */
export function Hero({ datos }: { datos: DatosHero }) {
  return (
    <section aria-labelledby="titulo-hero" className="relative w-full bg-gradient-to-b from-surface-container-low via-surface to-background overflow-hidden py-space-2xl">
      <div className="max-w-7xl mx-auto px-margin-mobile md:px-margin">
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-space-xl items-center">
          <div className="lg:col-span-5 flex flex-col gap-space-md z-10 min-w-0">
            <p className="inline-flex items-center gap-space-xs px-space-md py-1 rounded-full bg-secondary-container text-on-secondary-container self-start shadow-sm">
              <Icono nombre="domain_verification" className="text-tertiary-container" />
              <span className="font-label-md text-label-md tracking-wide">{datos.insignia}</span>
            </p>
            <h1 id="titulo-hero" className="font-headline-lg-mobile text-headline-lg-mobile sm:font-display-lg sm:text-display-lg text-primary tracking-tight break-words">
              {datos.titulo}
            </h1>
            <p className="font-body-lg text-body-lg text-secondary">{datos.subtitulo}</p>
            <div className="flex flex-wrap items-center gap-space-md pt-space-xs">
              <a href="#demo" className="px-space-lg py-space-sm rounded-lg bg-accion hover:bg-accion-hover text-accion-texto font-label-lg text-label-lg shadow-md flex items-center gap-space-xs">
                <Icono nombre="event_available" tamano="lg" />
                <span>{datos.cta_principal}</span>
              </a>
              {datos.cta_secundario && (
                <a href="#videos" className="px-space-lg py-space-sm rounded-lg bg-surface-container hover:bg-surface-container-high text-primary font-label-lg text-label-lg flex items-center gap-space-xs">
                  <Icono nombre="play_circle" tamano="lg" />
                  <span>{datos.cta_secundario}</span>
                </a>
              )}
            </div>
            {datos.sellos && (
              <p className="pt-space-sm flex items-center gap-space-sm text-secondary font-label-sm text-label-sm">
                <Icono nombre="verified" className="text-accion" />
                <span>{datos.sellos}</span>
              </p>
            )}
          </div>
          <div className="lg:col-span-7 relative min-w-0">
            <MockupPanel />
          </div>
        </div>
      </div>
      <span className="sr-only">{landing.mockupDescripcion}</span>
    </section>
  );
}
