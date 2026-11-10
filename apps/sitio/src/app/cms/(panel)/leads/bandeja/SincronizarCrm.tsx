'use client';

import { useRouter } from 'next/navigation';
import { useState, useTransition } from 'react';
import { Icono, useToast } from '@/componentes/base';
import { bandeja as t } from '@/contenido/provisional';
import { sincronizarCrm } from '../../../leads-acciones';

/** EDUN-282 · Reenvía al CRM las solicitudes pendientes o con error (todas, o una desde la ficha). */
export function SincronizarCrm({ ids, etiqueta = t.sincronizar, discreto = false }: { ids?: number[]; etiqueta?: string; discreto?: boolean }) {
  const [error, setError] = useState<string | null>(null);
  const [enviando, iniciar] = useTransition();
  const router = useRouter();
  const avisar = useToast();

  function sincronizar() {
    setError(null);
    iniciar(async () => {
      const r = await sincronizarCrm(ids);
      if (!r.ok) return setError(r.mensaje || t.errorSincronizar);
      avisar(r.mensaje);
      router.refresh();
    });
  }

  return (
    <div className="flex flex-col gap-1">
      <button
        type="button"
        onClick={sincronizar}
        disabled={enviando}
        className={
          discreto
            ? 'self-start inline-flex items-center gap-1 h-9 px-space-sm rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-md text-label-md disabled:opacity-60'
            : 'inline-flex items-center gap-1 h-10 px-space-md rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-lg text-label-lg disabled:opacity-60'
        }
      >
        <Icono nombre="sync" tamano="sm" />
        {enviando ? t.sincronizando : etiqueta}
      </button>
      {error && (
        <p role="alert" className="font-label-md text-label-md text-error max-w-xs">
          {error}
        </p>
      )}
    </div>
  );
}
