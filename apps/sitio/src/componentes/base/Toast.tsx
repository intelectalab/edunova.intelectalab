'use client';

import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { Icono } from './Icono';

/** Tiempo que el aviso queda visible, igual que en el prototipo. */
export const DURACION_TOAST_MS = 2600;

const Contexto = createContext<(texto: string) => void>(() => {});

/** Aviso flotante (toast). El contenedor aria-live existe siempre para que se anuncie. */
export function ProveedorToast({ children }: { children: ReactNode }) {
  const [texto, setTexto] = useState<string | null>(null);
  const temporizador = useRef<ReturnType<typeof setTimeout>>(undefined);

  const mostrar = useCallback((t: string) => {
    setTexto(t);
    clearTimeout(temporizador.current);
    temporizador.current = setTimeout(() => setTexto(null), DURACION_TOAST_MS);
  }, []);

  useEffect(() => () => clearTimeout(temporizador.current), []);

  return (
    <Contexto.Provider value={mostrar}>
      {children}
      <div role="status" aria-live="polite" className="fixed bottom-6 right-6 z-[70]">
        {texto && (
          <div className="flex items-center gap-space-sm px-space-md py-space-sm rounded-lg bg-inverse-surface text-inverse-on-surface shadow-lg font-label-lg text-label-lg">
            <Icono nombre="check_circle" className="text-tertiary-fixed" />
            <span>{texto}</span>
          </div>
        )}
      </div>
    </Contexto.Provider>
  );
}

export function useToast() {
  return useContext(Contexto);
}
