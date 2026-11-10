import type { ReactNode } from 'react';
import { Conversion } from '@/componentes/conversion/Conversion';
import { Cabecera } from '@/componentes/landing/Cabecera';
import { Pie } from '@/componentes/landing/Cierre';
import { mantenimiento, type Landing } from '@/lib/landing';
import { Mantenimiento } from './Mantenimiento';

const URL_INGRESO = process.env.EDUNOVA_WEB_URL ?? 'http://localhost:5174';

/**
 * Páginas públicas fuera de la landing (políticas, Libro de Reclamaciones…): la misma cabecera,
 * el mismo footer y las piezas de conversión (demo, políticas y banner de cookies).
 */
export function MarcoPublico({ datos, children, comercial = false }: { datos: Landing | null; children: ReactNode; comercial?: boolean }) {
  // Sprint 11 · Las páginas comerciales (funcionalidades, planes, blog…) siguen el modo
  // mantenimiento y muestran el chatbot; las legales (políticas, Libro) siempre están disponibles.
  const enMantenimiento = comercial ? mantenimiento(datos) : null;
  if (enMantenimiento) return <Mantenimiento datos={enMantenimiento} />;
  const s = datos?.secciones;
  return (
    <>
      {datos && <Cabecera aviso={s?.barra_superior?.aviso ?? ''} telefono={(datos.config.telefono_central as string | null) ?? null} menu={datos.menu.principal} urlIngreso={URL_INGRESO} />}
      <main className="w-full pt-36 pb-space-2xl bg-surface">{children}</main>
      {datos && s?.footer && <Pie datos={s.footer} config={datos.config} enlaces={datos.menu.footer} />}
      <Conversion tracking={datos?.tracking ?? null} chatbot={comercial ? (datos?.chatbot ?? null) : null} />
    </>
  );
}
