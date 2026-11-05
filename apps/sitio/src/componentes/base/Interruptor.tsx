'use client';

import { useId } from 'react';

type Props = {
  /** Texto accesible del interruptor (sale del CMS). */
  etiqueta: string;
  /** Si es true, la etiqueta solo la leen los lectores de pantalla (como en las tarjetas del CMS). */
  ocultarEtiqueta?: boolean;
  activo: boolean;
  onCambio: (activo: boolean) => void;
  deshabilitado?: boolean;
};

/** Interruptor de visibilidad: el mismo en todas las pantallas del CMS. */
export function Interruptor({ etiqueta, ocultarEtiqueta = false, activo, onCambio, deshabilitado = false }: Props) {
  const id = useId();
  return (
    <label htmlFor={id} className="inline-flex items-center gap-space-sm cursor-pointer select-none">
      <span className="relative inline-flex items-center">
        <input
          id={id}
          type="checkbox"
          role="switch"
          className="sr-only peer"
          checked={activo}
          disabled={deshabilitado}
          onChange={(e) => onCambio(e.target.checked)}
        />
        <span
          aria-hidden="true"
          className="w-10 h-6 bg-outline rounded-full transition-colors peer-checked:bg-tertiary-container peer-disabled:opacity-50 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-full"
        />
      </span>
      <span className={ocultarEtiqueta ? 'sr-only' : 'font-label-lg text-label-lg text-on-surface'}>{etiqueta}</span>
    </label>
  );
}
