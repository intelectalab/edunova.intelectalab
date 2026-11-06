'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { salirCms } from '@/app/cms/acciones';
import { Icono } from '@/componentes/base';
import { cms, sitio } from '@/contenido/provisional';

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
 * Menú lateral fijo del Panel CMS (288 px), como en Stitch, con el pie de usuario (EDUN-226).
 * Por debajo de 1280 px pasa a la parte superior; el riel de íconos llega con EDUN-230.
 */
export function MenuCms({ usuario }: { usuario: Usuario }) {
  const ruta = usePathname();
  return (
    <aside className="bg-primary text-on-primary xl:fixed xl:left-0 xl:top-0 xl:h-screen xl:w-menu-cms z-50 flex flex-col">
      <div className="h-20 px-space-lg flex items-center gap-space-sm">
        <span className="w-10 h-10 rounded-lg bg-surface-container-lowest flex items-center justify-center font-headline-md text-headline-md text-primary font-bold">
          {sitio.marca.charAt(0)}
        </span>
        <span className="flex flex-col">
          <span className="font-title-lg text-title-lg text-surface-bright tracking-tight leading-none">{sitio.marca}</span>
          <span className="font-label-sm text-label-sm text-primary-fixed-dim uppercase tracking-wider mt-0.5">{cms.nombrePanel}</span>
        </span>
      </div>
      <nav aria-label={cms.nombrePanel} className="px-space-md py-space-sm flex flex-col gap-space-md overflow-y-auto flex-1">
        {cms.grupos.map((g) => (
          <div key={g.titulo} className="flex flex-col gap-space-xs">
            <span className="px-space-sm font-label-sm text-label-sm uppercase text-primary-fixed-dim tracking-wider font-semibold">
              {g.titulo}
            </span>
            {g.enlaces.map((e) => {
              const activo = ruta === e.href;
              return (
                <Link
                  key={e.href}
                  href={e.href}
                  aria-current={activo ? 'page' : undefined}
                  className={`flex items-center gap-space-sm px-space-md py-space-sm rounded-lg font-label-lg text-label-lg transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-fixed ${
                    activo ? 'bg-primary-container text-surface-bright font-semibold shadow-sm' : 'text-primary-fixed hover:bg-primary-container'
                  }`}
                >
                  <Icono nombre={e.icono} tamano="lg" />
                  <span>{e.etiqueta}</span>
                </Link>
              );
            })}
          </div>
        ))}
      </nav>
      <div className="p-space-md">
        <div className="flex items-center justify-between gap-space-sm p-space-sm rounded-lg bg-primary-container">
          <div className="flex items-center gap-space-sm min-w-0">
            <span aria-hidden="true" className="w-9 h-9 shrink-0 rounded-full bg-surface-container-lowest flex items-center justify-center font-title-md text-title-md text-primary font-bold">
              {iniciales(usuario.nombre)}
            </span>
            <span className="flex flex-col min-w-0">
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
  );
}
