'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { Suspense, useEffect, useState, type ReactNode } from 'react';
import { salirCms } from '@/app/cms/acciones';
import { Chip, Icono } from '@/componentes/base';
import { AyudaCms } from './AyudaCms';
import { BarraNavegacion } from './BarraNavegacion';
import { BotonesEditor, FranjaEstado } from './EditorCms';
import { cms, enlacePorRuta, enlacesPara, sitio } from '@/contenido/provisional';

type Usuario = { nombre: string; rol: string };

function iniciales(nombre: string): string {
  return nombre
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p.charAt(0).toUpperCase())
    .join('');
}

/**
 * EDUN-230 · Marco del Panel CMS (prototipo #cms-administracion).
 * - 1280 px o más: menú lateral fijo de 288 px.
 * - 1024 a 1279 px: riel de íconos de 80 px (el contenido empieza después del riel).
 * - Menos de 1024 px: el menú se abre como panel superpuesto con el botón de la barra.
 */
export function MarcoCms({ usuario, sitioPublico, children }: { usuario: Usuario; sitioPublico: string | null; children: ReactNode }) {
  const ruta = usePathname();
  const [abierto, setAbierto] = useState(false);
  const grupos = enlacesPara(usuario.rol);
  const actual = enlacePorRuta(ruta);

  // Al navegar se cierra el panel superpuesto; Escape también lo cierra.
  useEffect(() => setAbierto(false), [ruta]);
  useEffect(() => {
    if (!abierto) return;
    const alTeclear = (e: KeyboardEvent) => e.key === 'Escape' && setAbierto(false);
    document.addEventListener('keydown', alTeclear);
    return () => document.removeEventListener('keydown', alTeclear);
  }, [abierto]);

  return (
    <>
      {abierto && <div aria-hidden="true" className="fixed inset-0 z-40 bg-black/40 lg:hidden" onClick={() => setAbierto(false)} />}

      <aside
        id="menu-cms"
        aria-label={cms.nombrePanel}
        className={`fixed left-0 top-0 h-screen z-50 bg-primary text-on-primary flex-col w-menu-cms lg:w-20 xl:w-menu-cms ${abierto ? 'flex' : 'hidden'} lg:flex`}
      >
        <div className="h-20 px-space-lg lg:px-space-md xl:px-space-lg flex items-center gap-space-sm shrink-0">
          <span className="w-10 h-10 shrink-0 rounded-lg bg-surface-container-lowest flex items-center justify-center font-headline-md text-headline-md text-primary font-bold">
            {sitio.marca.charAt(0)}
          </span>
          <span className="flex flex-col lg:sr-only xl:not-sr-only">
            <span className="font-title-lg text-title-lg text-surface-bright tracking-tight leading-none">{sitio.marca}</span>
            <span className="font-label-sm text-label-sm text-primary-fixed-dim uppercase tracking-wider mt-0.5">{cms.nombrePanel}</span>
          </span>
        </div>

        <nav aria-label={cms.nombrePanel} className="px-space-md lg:px-space-sm xl:px-space-md py-space-sm flex flex-col gap-space-md overflow-y-auto flex-1">
          {grupos.map((g) => (
            <div key={g.titulo} className="flex flex-col gap-space-xs">
              <span className="px-space-sm font-label-sm text-label-sm uppercase text-primary-fixed-dim tracking-wider font-semibold lg:sr-only xl:not-sr-only">
                {g.titulo}
              </span>
              {g.enlaces.map((e) => {
                const activo = ruta === e.href;
                return (
                  <Link
                    key={e.href}
                    href={e.href}
                    title={e.etiqueta}
                    aria-current={activo ? 'page' : undefined}
                    className={`flex items-center gap-space-sm px-space-md lg:justify-center xl:justify-start py-space-sm rounded-lg font-label-lg text-label-lg transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-fixed ${
                      activo ? 'bg-primary-container text-surface-bright font-semibold shadow-sm' : 'text-primary-fixed hover:bg-primary-container'
                    }`}
                  >
                    <Icono nombre={e.icono} tamano="lg" />
                    <span className="lg:sr-only xl:not-sr-only">{e.etiqueta}</span>
                  </Link>
                );
              })}
            </div>
          ))}
        </nav>

        <div className="p-space-md lg:p-space-sm xl:p-space-md shrink-0">
          <div className="flex items-center justify-between lg:flex-col xl:flex-row gap-space-sm p-space-sm rounded-lg bg-primary-container">
            <div className="flex items-center gap-space-sm min-w-0">
              <span aria-hidden="true" className="w-9 h-9 shrink-0 rounded-full bg-surface-container-lowest flex items-center justify-center font-title-md text-title-md text-primary font-bold">
                {iniciales(usuario.nombre)}
              </span>
              <span className="flex flex-col min-w-0 lg:sr-only xl:not-sr-only">
                <span className="font-title-md text-title-md text-surface-bright truncate leading-tight">{usuario.nombre}</span>
                <span className="font-label-sm text-label-sm text-primary-fixed-dim truncate">{cms.nombresRol[usuario.rol] ?? usuario.rol}</span>
              </span>
            </div>
            <form action={salirCms}>
              <button
                type="submit"
                aria-label={cms.cerrarSesion}
                title={cms.cerrarSesion}
                className="w-8 h-8 flex items-center justify-center rounded-lg text-primary-fixed-dim hover:bg-error-container hover:text-error transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-fixed"
              >
                <Icono nombre="logout" />
              </button>
            </form>
          </div>
        </div>
      </aside>

      <div className="lg:pl-20 xl:pl-menu-cms min-h-screen bg-background flex flex-col">
        <Suspense fallback={null}>
          <BarraNavegacion />
        </Suspense>
        <header className="sticky top-0 z-30 h-20 bg-surface/90 backdrop-blur-xl shadow-sm px-margin-mobile md:px-gutter flex items-center justify-between gap-space-md">
          <div className="flex items-center gap-space-md min-w-0">
            <button
              type="button"
              className="lg:hidden w-10 h-10 shrink-0 rounded-lg flex items-center justify-center text-primary hover:bg-surface-container focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
              aria-label={abierto ? cms.barra.cerrarMenu : cms.barra.abrirMenu}
              aria-expanded={abierto}
              aria-controls="menu-cms"
              onClick={() => setAbierto((a) => !a)}
            >
              <Icono nombre={abierto ? 'close' : 'menu'} tamano="lg" />
            </button>
            <nav aria-label={cms.barra.migas} className="flex items-center gap-space-xs font-body-sm text-body-sm text-on-surface-variant min-w-0">
              <Link href="/cms" className="hover:text-primary whitespace-nowrap">
                {cms.nombrePanel}
              </Link>
              {actual && (
                <>
                  <Icono nombre="chevron_right" tamano="sm" className="text-outline" />
                  <span aria-current="page" className="font-title-md text-title-md text-on-surface font-semibold truncate">
                    {actual.etiqueta}
                  </span>
                </>
              )}
            </nav>
            <span className="hidden xl:inline-flex items-center gap-space-xs px-space-sm py-0.5 rounded-full bg-surface-container-low border border-outline-variant font-label-md text-label-md text-on-surface-variant whitespace-nowrap">
              <span aria-hidden="true" className={`w-2 h-2 rounded-full ${sitioPublico ? 'bg-tertiary-container' : 'bg-aviso'}`} />
              {cms.barra.estadoSitio}
              <strong className={sitioPublico ? 'text-tertiary-container' : 'text-aviso-texto'}>{sitioPublico ? cms.barra.enLinea : cms.barra.sinDominio}</strong>
              {sitioPublico && <span className="text-secondary">({sitioPublico})</span>}
            </span>
          </div>
          <div className="flex items-center gap-space-sm shrink-0">
            <AyudaCms />
            <a
              href="/"
              target="_blank"
              rel="noopener"
              title={cms.barra.verSitio}
              className="inline-flex items-center gap-space-xs px-space-sm md:px-space-md h-10 rounded-lg text-secondary hover:bg-surface-container hover:text-on-surface font-label-lg text-label-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
            >
              <Icono nombre="open_in_new" />
              <span className="sr-only md:not-sr-only">{cms.barra.verSitio}</span>
            </a>
            <span className="hidden md:contents">
              <BotonesEditor />
            </span>
          </div>
        </header>

        <nav aria-label={cms.barra.navegacionRapida} className="px-margin-mobile md:px-margin pt-space-lg flex gap-space-sm overflow-x-auto">
          {grupos
            .flatMap((g) => g.enlaces)
            .map((e) => (
              <Chip key={e.href} href={e.href} etiqueta={e.etiqueta} icono={e.icono} activo={ruta === e.href} />
            ))}
        </nav>

        <FranjaEstado />
        <main className="flex-1">{children}</main>
      </div>
    </>
  );
}
