'use client';

import { useRouter } from 'next/navigation';
import { useState, useTransition } from 'react';
import { useToast } from '@/componentes/base';
import { cms } from '@/contenido/provisional';
import { restaurarCambio } from '../../panel-acciones';

/** EDUN-342 · «Restaurar el valor anterior» con confirmación en la pantalla (no en un diálogo del navegador). */
export function Restaurar({ id }: { id: number }) {
  const t = cms.auditoria;
  const [confirmando, setConfirmando] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [trabajando, iniciar] = useTransition();
  const router = useRouter();
  const avisar = useToast();

  function restaurar() {
    setError(null);
    iniciar(async () => {
      const r = await restaurarCambio(id);
      if (!r.ok) return setError(Object.values(r.errores)[0] ?? r.mensaje);
      setConfirmando(false);
      avisar(r.mensaje || t.restaurado);
      router.refresh();
    });
  }

  if (!confirmando) {
    return (
      <button type="button" onClick={() => setConfirmando(true)} className="self-start h-10 px-space-md rounded-lg bg-primary hover:bg-primary-container text-on-primary font-label-lg text-label-lg">
        {t.restaurar}
      </button>
    );
  }
  return (
    <div role="alertdialog" aria-labelledby={`confirmar-${id}`} className="flex flex-col gap-space-sm p-space-md rounded-lg bg-aviso-fondo border border-aviso-borde">
      <p id={`confirmar-${id}`} className="font-body-sm text-body-sm text-aviso-texto">
        {t.confirmar}
      </p>
      <span className="flex flex-wrap gap-space-xs">
        <button type="button" autoFocus disabled={trabajando} onClick={restaurar} className="h-10 px-space-md rounded-lg bg-primary hover:bg-primary-container text-on-primary font-label-lg text-label-lg disabled:opacity-60">
          {t.confirmarSi}
        </button>
        <button type="button" onClick={() => setConfirmando(false)} className="h-10 px-space-md rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-lg text-label-lg">
          {t.cancelar}
        </button>
      </span>
      {error && (
        <p role="alert" className="font-label-md text-label-md text-error">
          {error}
        </p>
      )}
    </div>
  );
}
