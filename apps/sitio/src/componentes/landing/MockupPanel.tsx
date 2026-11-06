import { Icono } from '@/componentes/base';
import { landing } from '@/contenido/provisional';

/**
 * Ilustración del panel de Edunova en el hero (prototipo: «mockup del panel con KPIs»).
 * Es decorativa: los datos son de un colegio de ejemplo y se ocultan a lectores de pantalla.
 */
export function MockupPanel() {
  const m = landing.mockup;
  return (
    <div aria-hidden="true" className="relative">
      <div className="absolute -top-12 -right-12 w-96 h-96 bg-primary-fixed/30 rounded-full blur-3xl pointer-events-none" />
      <div className="relative bg-surface-container-lowest rounded-2xl shadow-xl overflow-hidden border border-outline-variant">
        <div className="bg-primary px-space-md py-space-sm flex items-center justify-between gap-space-sm text-on-primary">
          <div className="flex items-center gap-space-xs min-w-0">
            <span className="w-7 h-7 rounded-md bg-primary-container flex items-center justify-center font-label-lg text-label-lg">E</span>
            <span className="flex flex-col min-w-0">
              <span className="font-label-md text-label-md leading-none">{m.producto}</span>
              <span className="font-label-sm text-label-sm text-primary-fixed-dim truncate">{m.colegio}</span>
            </span>
          </div>
          <span className="hidden sm:inline-flex items-center gap-1 bg-primary-container px-space-sm py-1 rounded font-label-sm text-label-sm text-primary-fixed">
            <Icono nombre="calendar_today" tamano="sm" />
            {m.periodo}
          </span>
        </div>
        <div className="p-space-lg bg-surface flex flex-col gap-space-md">
          <div>
            <p className="font-headline-sm text-headline-sm text-primary font-bold">{m.saludo}</p>
            <p className="font-body-sm text-body-sm text-secondary">{m.resumen}</p>
          </div>
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-space-sm">
            {m.kpis.map((k) => (
              <div key={k.etiqueta} className="rounded-xl bg-surface-container-lowest border border-surface-container p-space-sm flex flex-col gap-1">
                <span className="flex items-center justify-between font-label-sm text-label-sm text-secondary">
                  {k.etiqueta}
                  <Icono nombre={k.icono} tamano="sm" className="text-primary" />
                </span>
                <span className="font-headline-sm text-headline-sm text-primary font-bold">{k.valor}</span>
              </div>
            ))}
          </div>
          <div className="rounded-xl bg-surface-container-lowest border border-surface-container p-space-md">
            <p className="font-label-md text-label-md text-on-surface mb-space-sm">{m.grafico}</p>
            <div className="flex items-end gap-space-xs h-24">
              {m.barras.map((b) => (
                <div key={b.mes} className="flex-1 h-full flex flex-col justify-end items-center gap-1">
                  <div className="w-full rounded-t bg-primary-container" style={{ height: `${b.valor}%` }} />
                  <span className="font-label-sm text-label-sm text-secondary">{b.mes}</span>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
