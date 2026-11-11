import Link from 'next/link';
import { Icono } from '@/componentes/base';
import { landing, sitio } from '@/contenido/provisional';
import type { ItemMenu } from '@/lib/landing';

const A_CONFIRMAR = '[A CONFIRMAR]';

/**
 * EDUN-242 · Barra superior (aviso, Panel CMS y teléfono) y navegación fija de la landing
 * (prototipo #inicio). El teléfono se oculta en celular; sin dato muestra «[A CONFIRMAR]».
 */
export function Cabecera({ aviso, telefono, menu, urlIngreso }: { aviso: string; telefono: string | null; menu: ItemMenu[]; urlIngreso: string }) {
  return (
    <header className="fixed top-0 left-0 w-full z-50 shadow-sm">
      <div className="w-full bg-primary text-on-primary py-space-xs px-margin-mobile md:px-margin">
        <div className="max-w-7xl mx-auto flex items-center justify-between gap-space-md font-label-sm text-label-sm">
          <p className="flex items-center gap-space-sm min-w-0">
            <Icono nombre="verified" tamano="sm" className="text-tertiary-fixed" />
            <span className="truncate">{aviso}</span>
          </p>
          <div className="hidden md:flex items-center gap-space-md shrink-0">
            <Link href="/cms" className="text-primary-fixed hover:text-on-primary flex items-center gap-space-xs">
              <span>{landing.panelCms}</span>
              <Icono nombre="arrow_forward" tamano="sm" />
            </Link>
            <span aria-hidden="true" className="w-px h-3 bg-primary-container" />
            <span className="text-primary-fixed-dim">
              {landing.atencion} {telefono || A_CONFIRMAR}
            </span>
          </div>
        </div>
      </div>
      <div className="h-20 bg-surface-container-lowest/90 backdrop-blur-xl">
        <div className="max-w-7xl mx-auto h-full px-margin-mobile md:px-margin flex items-center justify-between gap-space-md">
          <div className="flex items-center gap-space-xl">
            <Link href="/" className="flex items-center gap-space-sm">
              <span aria-hidden="true" className="w-10 h-10 rounded-lg bg-primary flex items-center justify-center font-headline-md text-headline-md text-on-primary font-bold">
                {sitio.marca.charAt(0)}
              </span>
              <span className="flex flex-col">
                <span className="font-headline-sm text-headline-sm text-primary leading-tight font-semibold tracking-tight">{sitio.marca}</span>
                <span className="font-label-sm text-label-sm text-secondary uppercase tracking-widest">{landing.pais}</span>
              </span>
            </Link>
            <nav aria-label={landing.menuPrincipal} className="hidden lg:flex items-center gap-space-xs">
              {menu.map((m) => (
                <a
                  key={m.url}
                  href={m.url}
                  target={m.target === '_blank' ? '_blank' : undefined}
                  rel={m.target === '_blank' ? 'noopener' : undefined}
                  className="px-space-md py-space-sm rounded-lg font-label-lg text-label-lg text-on-surface-variant hover:text-on-surface hover:bg-surface-container transition-colors"
                >
                  {m.label}
                </a>
              ))}
            </nav>
          </div>
          <div className="flex items-center gap-space-sm md:gap-space-md shrink-0">
            <a href={urlIngreso} className="inline-flex px-space-sm sm:px-space-md py-space-sm rounded-lg font-label-lg text-label-lg text-primary hover:bg-surface-container">
              {landing.iniciarSesion}
            </a>
            <a href="#demo" className="px-space-md py-space-sm rounded-lg bg-accion hover:bg-accion-hover text-accion-texto font-label-lg text-label-lg shadow-sm whitespace-nowrap">
              {landing.solicitarDemo}
            </a>
          </div>
        </div>
      </div>
    </header>
  );
}
