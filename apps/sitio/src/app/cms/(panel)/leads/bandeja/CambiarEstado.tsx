'use client';

import { useRouter } from 'next/navigation';
import { useId, useState, useTransition } from 'react';
import { useToast } from '@/componentes/base';
import { bandeja as t } from '@/contenido/provisional';
import { cambiarEstadoLead, type EstadoLead } from '../../../leads-acciones';

/** EDUN-278 · Cambio de estado desde la ficha rápida. La API deja la auditoría. */
export function CambiarEstado({ id, estado }: { id: number; estado: EstadoLead }) {
  const [valor, setValor] = useState<EstadoLead>(estado);
  const [error, setError] = useState<string | null>(null);
  const [guardando, iniciar] = useTransition();
  const router = useRouter();
  const avisar = useToast();
  const idCampo = useId();

  function guardar() {
    setError(null);
    iniciar(async () => {
      const r = await cambiarEstadoLead(id, valor);
      if (!r.ok) return setError(r.mensaje ?? t.errorEstado);
      avisar(t.estadoGuardado);
      router.refresh();
    });
  }

  return (
    <div className="flex flex-col gap-space-xs pt-space-sm border-t border-surface-container">
      <label htmlFor={idCampo} className="font-label-md text-label-md text-secondary">
        {t.cambiarEstado}
      </label>
      <div className="flex gap-space-xs">
        <select
          id={idCampo}
          value={valor}
          onChange={(e) => setValor(e.target.value as EstadoLead)}
          className="flex-1 h-10 rounded-lg border border-outline-variant bg-surface-container-lowest px-2 font-body-md text-body-md text-on-surface"
        >
          {Object.entries(t.estados).map(([k, v]) => (
            <option key={k} value={k}>
              {v}
            </option>
          ))}
        </select>
        <button
          type="button"
          onClick={guardar}
          disabled={guardando || valor === estado}
          className="h-10 px-space-md rounded-lg bg-primary hover:bg-primary-container text-on-primary font-label-lg text-label-lg disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {guardando ? t.guardando : t.guardarEstado}
        </button>
      </div>
      {error && (
        <p role="alert" className="font-label-md text-label-md text-error">
          {error}
        </p>
      )}
    </div>
  );
}
