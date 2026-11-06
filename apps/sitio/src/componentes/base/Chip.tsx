import Link from 'next/link';
import { Icono } from './Icono';

/** Chip de navegación rápida entre secciones del CMS. */
export function Chip({ href, etiqueta, icono, activo = false }: { href: string; etiqueta: string; icono?: string; activo?: boolean }) {
  return (
    <Link
      href={href}
      aria-current={activo ? 'page' : undefined}
      className={`inline-flex shrink-0 whitespace-nowrap items-center gap-space-xs px-space-md h-8 rounded-full font-label-md text-label-md border transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
        activo
          ? 'bg-primary text-on-primary border-primary'
          : 'bg-surface-container-lowest text-primary border-outline-variant hover:bg-surface-container-low'
      }`}
    >
      {icono && <Icono nombre={icono} tamano="sm" />}
      {etiqueta}
    </Link>
  );
}
