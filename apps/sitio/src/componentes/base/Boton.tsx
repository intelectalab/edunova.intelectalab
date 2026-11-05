import Link from 'next/link';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { Icono } from './Icono';

export type VarianteBoton = 'accion' | 'institucional' | 'secundario' | 'fantasma';

// Clases tomadas de los botones del prototipo (barra del CMS y CTA de la landing).
const BASE =
  'inline-flex items-center justify-center gap-space-xs px-space-md h-10 rounded-lg font-label-lg text-label-lg ' +
  'transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ' +
  'disabled:opacity-50 disabled:cursor-not-allowed';

const VARIANTES: Record<VarianteBoton, string> = {
  accion: 'bg-accion hover:bg-accion-hover text-accion-texto shadow-sm',
  institucional: 'bg-primary hover:bg-primary-container text-on-primary shadow-sm',
  secundario: 'bg-surface-container-low hover:bg-surface-container-high text-primary',
  fantasma: 'bg-transparent hover:bg-surface-container-low text-primary',
};

export function clasesBoton(variante: VarianteBoton = 'secundario') {
  return `${BASE} ${VARIANTES[variante]}`;
}

type Comunes = { variante?: VarianteBoton; icono?: string; children: ReactNode };

export function Boton({
  variante,
  icono,
  children,
  type = 'button',
  className = '',
  ...resto
}: Comunes & ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button type={type} className={`${clasesBoton(variante)} ${className}`.trim()} {...resto}>
      {icono && <Icono nombre={icono} />}
      <span>{children}</span>
    </button>
  );
}

export function BotonEnlace({ variante, icono, children, href }: Comunes & { href: string }) {
  return (
    <Link href={href} className={clasesBoton(variante)}>
      {icono && <Icono nombre={icono} />}
      <span>{children}</span>
    </Link>
  );
}
