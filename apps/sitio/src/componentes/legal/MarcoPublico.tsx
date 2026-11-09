import type { ReactNode } from 'react';
import { Conversion } from '@/componentes/conversion/Conversion';
import { Cabecera } from '@/componentes/landing/Cabecera';
import { Pie } from '@/componentes/landing/Cierre';
import type { Landing } from '@/lib/landing';

const URL_INGRESO = process.env.EDUNOVA_WEB_URL ?? 'http://localhost:5174';

/**
 * Páginas públicas fuera de la landing (políticas, Libro de Reclamaciones…): la misma cabecera,
 * el mismo footer y las piezas de conversión (demo, políticas y banner de cookies).
 */
export function MarcoPublico({ datos, children }: { datos: Landing | null; children: ReactNode }) {
  const s = datos?.secciones;
  return (
    <>
      {datos && <Cabecera aviso={s?.barra_superior?.aviso ?? ''} telefono={(datos.config.telefono_central as string | null) ?? null} menu={datos.menu.principal} urlIngreso={URL_INGRESO} />}
      <main className="w-full pt-36 pb-space-2xl bg-surface">{children}</main>
      {datos && s?.footer && <Pie datos={s.footer} config={datos.config} enlaces={datos.menu.footer} />}
      <Conversion gtm={datos?.tracking?.gtm ?? null} />
    </>
  );
}
