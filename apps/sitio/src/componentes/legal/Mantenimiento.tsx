import Link from 'next/link';
import { Icono } from '@/componentes/base';
import { sitio } from '@/contenido/provisional';
import type { Mantenimiento as DatosMantenimiento } from '@/lib/landing';

const fechaHora = (v: string) =>
  new Intl.DateTimeFormat('es-PE', { timeZone: 'America/Lima', weekday: 'long', day: 'numeric', month: 'long', hour: 'numeric', minute: '2-digit' }).format(new Date(`${v}:00-05:00`));

/**
 * EDUN-399 / SIT-062 · Página de mantenimiento: el mensaje y la hora estimada de vuelta salen
 * de Configuración general. Reemplaza la landing; el Panel CMS, las políticas y el Libro de
 * Reclamaciones (obligatorio por ley) siguen disponibles.
 */
export function Mantenimiento({ datos }: { datos: DatosMantenimiento }) {
  return (
    <main className="min-h-screen w-full bg-surface flex items-center justify-center px-margin-mobile py-space-2xl">
      <div className="max-w-xl w-full flex flex-col items-center text-center gap-space-md p-space-lg rounded-2xl bg-surface-container-lowest border border-surface-container shadow-sm">
        <span className="w-16 h-16 rounded-full bg-primary/10 text-primary flex items-center justify-center">
          <Icono nombre="construction" tamano="lg" />
        </span>
        <h1 className="font-headline-md text-headline-md text-primary">{sitio.mantenimientoTitulo}</h1>
        {datos.mensaje && <p className="font-body-lg text-body-lg text-secondary whitespace-pre-line">{datos.mensaje}</p>}
        {datos.vuelta && <p className="font-label-lg text-label-lg text-on-surface">{sitio.mantenimientoVuelta(fechaHora(datos.vuelta))}</p>}
        <Link href="/libro-de-reclamaciones" className="font-label-md text-label-md text-primary underline">
          {sitio.libroReclamaciones}
        </Link>
      </div>
    </main>
  );
}
