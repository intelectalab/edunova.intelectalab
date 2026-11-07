import { Icono } from '@/componentes/base';
import { politicas as t } from '@/contenido/provisional';
import type { Politica } from '@/lib/politicas';

/** «07/11/2026» desde «2026-11-07». */
function fechaCorta(fecha: string): string {
  const [a, m, d] = fecha.slice(0, 10).split('-');
  return `${d}/${m}/${a}`;
}

export function VersionPolitica({ politica }: { politica: Politica }) {
  return <span className="font-label-md text-label-md text-secondary">{t.version(politica.version, fechaCorta(politica.vigente_desde))}</span>;
}

/**
 * EDUN-287 · Texto de una política, igual en la ventana y en su página. El HTML viene limpio de
 * la API (solo etiquetas de texto y enlaces seguros). Un borrador lo dice antes del texto.
 */
export function ContenidoPolitica({ politica }: { politica: Politica }) {
  return (
    <div className="flex flex-col gap-space-sm">
      {politica.borrador && (
        <p className="flex items-start gap-space-xs p-space-sm rounded-lg bg-surface-container text-on-surface font-body-sm text-body-sm">
          <Icono nombre="gavel" tamano="sm" className="text-primary mt-0.5" />
          <span>
            <strong>{t.borrador}.</strong> {t.borradorDetalle}
          </span>
        </p>
      )}
      <div
        className="texto-politica flex flex-col gap-3 font-body-md text-body-md text-on-surface"
        dangerouslySetInnerHTML={{ __html: politica.contenido }}
      />
    </div>
  );
}
