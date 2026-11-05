import type { ReactNode } from 'react';
import { Icono } from './Icono';

export type TonoInsignia = 'exito' | 'aviso' | 'error' | 'info' | 'neutro';

const TONOS: Record<TonoInsignia, string> = {
  exito: 'bg-tertiary-fixed text-on-tertiary-fixed',
  aviso: 'bg-aviso-fondo text-aviso-texto border border-aviso-borde',
  error: 'bg-error-container text-on-error-container',
  info: 'bg-primary-fixed text-on-primary-fixed',
  neutro: 'bg-surface-container text-on-surface-variant',
};

/** Insignia de estado (Publicado, Borrador, Pendiente…). */
export function Insignia({ tono = 'neutro', icono, children }: { tono?: TonoInsignia; icono?: string; children: ReactNode }) {
  return (
    <span className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full font-label-md text-label-md ${TONOS[tono]}`}>
      {icono && <Icono nombre={icono} tamano="sm" />}
      {children}
    </span>
  );
}
