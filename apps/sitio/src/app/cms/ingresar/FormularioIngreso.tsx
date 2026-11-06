'use client';

import { useActionState } from 'react';
import { clasesBoton } from '@/componentes/base';
import { cmsIngreso } from '@/contenido/provisional';
import { ingresarCms, type EstadoIngreso } from '../acciones';

const CAMPO =
  'h-11 px-space-md rounded-lg border border-outline-variant bg-surface-container-lowest font-body-md text-body-md text-on-surface ' +
  'focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary';

export function FormularioIngreso({ volver }: { volver: string }) {
  const [estado, accion, enviando] = useActionState<EstadoIngreso, FormData>(ingresarCms, { error: null });

  return (
    <form action={accion} className="flex flex-col gap-space-md" noValidate>
      <input type="hidden" name="volver" value={volver} />
      <label className="flex flex-col gap-space-xs font-label-lg text-label-lg text-on-surface">
        {cmsIngreso.usuario}
        <input name="usuario" autoComplete="username" required className={CAMPO} />
      </label>
      <label className="flex flex-col gap-space-xs font-label-lg text-label-lg text-on-surface">
        {cmsIngreso.password}
        <input name="password" type="password" autoComplete="current-password" required className={CAMPO} />
      </label>
      {estado.error && (
        <p role="alert" className="px-space-md py-space-sm rounded-lg bg-error-container text-on-error-container font-body-sm text-body-sm">
          {estado.error}
        </p>
      )}
      <button type="submit" disabled={enviando} className={clasesBoton('institucional')}>
        {enviando ? cmsIngreso.enviando : cmsIngreso.boton}
      </button>
    </form>
  );
}
