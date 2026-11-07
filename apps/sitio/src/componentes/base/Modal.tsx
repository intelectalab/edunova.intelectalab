'use client';

import { useEffect, useId, useRef, type ReactNode } from 'react';
import { Icono } from './Icono';

type Props = {
  abierto: boolean;
  onCerrar: () => void;
  titulo: string;
  /** Texto accesible del botón de cierre (sale del CMS). */
  etiquetaCerrar: string;
  /** Ancho máximo: md (videos), lg (políticas) o xl (demo con calendario). */
  ancho?: 'md' | 'lg' | 'xl';
  /** Contenido junto al título (por ejemplo, la versión de una política). */
  subtitulo?: ReactNode;
  children: ReactNode;
};

const ANCHOS = { md: 'max-w-2xl', lg: 'max-w-3xl', xl: 'max-w-4xl' } as const;

// Ventanas abiertas, de abajo arriba: solo la de arriba atiende Escape y Tab
// (la política se abre encima de la solicitud de demo).
const pila: symbol[] = [];

const ENFOCABLES = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';

/** Modal (patrón del modal de video y de demo de Stitch): Escape cierra y el foco queda dentro. */
export function Modal({ abierto, onCerrar, titulo, etiquetaCerrar, ancho = 'md', subtitulo, children }: Props) {
  const idTitulo = useId();
  const caja = useRef<HTMLDivElement>(null);
  const cerrar = useRef(onCerrar);
  cerrar.current = onCerrar;

  useEffect(() => {
    if (!abierto) return;
    const yo = Symbol('modal');
    pila.push(yo);
    const previo = document.activeElement as HTMLElement | null;
    caja.current?.querySelector<HTMLElement>(ENFOCABLES)?.focus();

    function alTeclear(e: KeyboardEvent) {
      if (pila[pila.length - 1] !== yo) return;
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
      pila.splice(pila.indexOf(yo), 1);
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
        className={`bg-surface-container-lowest rounded-2xl ${ANCHOS[ancho]} w-full p-4 sm:p-6 shadow-2xl relative my-4`}
      >
        <div className="flex items-center justify-between gap-space-md pb-4 border-b border-surface-container">
          <div className="flex flex-col min-w-0">
            <h2 id={idTitulo} className="font-headline-sm text-headline-sm text-primary font-bold">
              {titulo}
            </h2>
            {subtitulo}
          </div>
          <button
            type="button"
            onClick={onCerrar}
            aria-label={etiquetaCerrar}
            className="w-8 h-8 shrink-0 rounded-full bg-surface-container flex items-center justify-center text-secondary hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
          >
            <Icono nombre="close" />
          </button>
        </div>
        <div className="pt-4">{children}</div>
      </div>
    </div>
  );
}
