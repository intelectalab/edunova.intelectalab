'use client';

import { useEffect, useId, useRef, type ReactNode } from 'react';
import { Icono } from './Icono';

type Props = {
  abierto: boolean;
  onCerrar: () => void;
  titulo: string;
  /** Texto accesible del botón de cierre (sale del CMS). */
  etiquetaCerrar: string;
  children: ReactNode;
};

const ENFOCABLES = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';

/** Modal (patrón del modal de video y de demo de Stitch): Escape cierra y el foco queda dentro. */
export function Modal({ abierto, onCerrar, titulo, etiquetaCerrar, children }: Props) {
  const idTitulo = useId();
  const caja = useRef<HTMLDivElement>(null);
  const cerrar = useRef(onCerrar);
  cerrar.current = onCerrar;

  useEffect(() => {
    if (!abierto) return;
    const previo = document.activeElement as HTMLElement | null;
    caja.current?.querySelector<HTMLElement>(ENFOCABLES)?.focus();

    function alTeclear(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        e.preventDefault();
        cerrar.current();
        return;
      }
      if (e.key !== 'Tab' || !caja.current) return;
      const lista = [...caja.current.querySelectorAll<HTMLElement>(ENFOCABLES)];
      if (lista.length === 0) return;
      const primero = lista[0];
      const ultimo = lista[lista.length - 1];
      if (e.shiftKey && document.activeElement === primero) {
        e.preventDefault();
        ultimo.focus();
      } else if (!e.shiftKey && document.activeElement === ultimo) {
        e.preventDefault();
        primero.focus();
      }
    }
    document.addEventListener('keydown', alTeclear);
    return () => {
      document.removeEventListener('keydown', alTeclear);
      previo?.focus();
    };
  }, [abierto]);

  if (!abierto) return null;

  return (
    <div
      className="fixed inset-0 z-[60] bg-black/70 backdrop-blur-sm flex items-start md:items-center justify-center p-4 overflow-y-auto"
      onMouseDown={(e) => e.target === e.currentTarget && onCerrar()}
    >
      <div
        ref={caja}
        role="dialog"
        aria-modal="true"
        aria-labelledby={idTitulo}
        className="bg-surface-container-lowest rounded-2xl max-w-2xl w-full p-6 shadow-2xl relative my-4"
      >
        <div className="flex items-center justify-between gap-space-md pb-4 border-b border-surface-container">
          <h2 id={idTitulo} className="font-headline-sm text-headline-sm text-primary font-bold">
            {titulo}
          </h2>
          <button
            type="button"
            onClick={onCerrar}
            aria-label={etiquetaCerrar}
            className="w-8 h-8 rounded-full bg-surface-container flex items-center justify-center text-secondary hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
          >
            <Icono nombre="close" />
          </button>
        </div>
        <div className="pt-4">{children}</div>
      </div>
    </div>
  );
}
