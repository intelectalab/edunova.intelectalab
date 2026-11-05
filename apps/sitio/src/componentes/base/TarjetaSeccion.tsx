import type { ReactNode } from 'react';
import { Icono } from './Icono';

type Props = {
  icono: string;
  titulo: string;
  descripcion?: string;
  /** Insignia u otra marca a la derecha del título. */
  insignia?: ReactNode;
  children?: ReactNode;
};

/** Tarjeta de sección del CMS: ícono + título + descripción + insignia. */
export function TarjetaSeccion({ icono, titulo, descripcion, insignia, children }: Props) {
  return (
    <section className="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-md">
      <header className="flex items-start justify-between gap-space-md">
        <div className="flex items-start gap-space-sm">
          <span className="w-10 h-10 shrink-0 rounded-lg bg-primary-fixed text-primary flex items-center justify-center">
            <Icono nombre={icono} tamano="lg" />
          </span>
          <div className="flex flex-col gap-0.5">
            <h2 className="font-title-lg text-title-lg text-primary">{titulo}</h2>
            {descripcion && <p className="font-body-sm text-body-sm text-on-surface-variant">{descripcion}</p>}
          </div>
        </div>
        {insignia}
      </header>
      {children}
    </section>
  );
}
