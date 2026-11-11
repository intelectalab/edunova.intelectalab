'use client';

import { useRouter } from 'next/navigation';
import { useState, useTransition } from 'react';
import { useToast } from '@/componentes/base';
import { cms } from '@/contenido/provisional';
import { cambiarVisibilidadPost, sincronizarBlog } from '../../panel-acciones';

const t = cms.blogCms;

/** EDUN-369 · Traer lo último de WordPress sin esperar los 5 minutos. */
export function SincronizarBlog() {
  const [pendiente, iniciar] = useTransition();
  const [error, setError] = useState<string | null>(null);
  const router = useRouter();
  const avisar = useToast();
  return (
    <div className="flex flex-col gap-space-xs">
      <button
        type="button"
        disabled={pendiente}
        onClick={() =>
          iniciar(async () => {
            setError(null);
            const r = await sincronizarBlog();
            if (!r.ok) return setError(r.mensaje || t.error);
            avisar(t.sincronizado);
            router.refresh();
          })
        }
        className="h-11 px-space-lg rounded-lg bg-primary hover:bg-primary-container text-on-primary font-label-lg text-label-lg disabled:opacity-50"
      >
        {pendiente ? t.sincronizando : t.sincronizar}
      </button>
      {error && (
        <span role="alert" className="font-label-sm text-label-sm text-error">
          {error}
        </span>
      )}
    </div>
  );
}

/** EDUN-369 · Mostrar u ocultar un post en el sitio: se publica al instante y queda en la auditoría. */
export function VisibilidadPost({ slug, oculto, titulo }: { slug: string; oculto: boolean; titulo: string }) {
  const [pendiente, iniciar] = useTransition();
  const [error, setError] = useState<string | null>(null);
  const router = useRouter();
  const avisar = useToast();
  return (
    <span className="inline-flex flex-col gap-0.5">
      <button
        type="button"
        disabled={pendiente}
        aria-label={`${oculto ? t.mostrar : t.ocultar}: ${titulo}`}
        onClick={() =>
          iniciar(async () => {
            setError(null);
            const r = await cambiarVisibilidadPost(slug, !oculto);
            if (!r.ok) return setError(r.mensaje || t.error);
            avisar(oculto ? t.mostrado : t.ocultado);
            router.refresh();
          })
        }
        className={`h-9 px-space-sm rounded-lg font-label-md text-label-md disabled:opacity-50 ${oculto ? 'bg-primary text-on-primary hover:bg-primary-container' : 'bg-surface-container-low text-primary hover:bg-surface-container-high'}`}
      >
        {oculto ? t.mostrar : t.ocultar}
      </button>
      {error && (
        <span role="alert" className="font-label-sm text-label-sm text-error">
          {error}
        </span>
      )}
    </span>
  );
}
