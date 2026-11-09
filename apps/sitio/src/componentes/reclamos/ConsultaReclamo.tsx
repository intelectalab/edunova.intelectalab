'use client';

import { useState, useTransition, type FormEvent } from 'react';
import { consultarReclamo, type ResultadoSeguimiento } from '@/app/acciones-sitio';
import { Icono, Insignia, type TonoInsignia } from '@/componentes/base';
import { seguimiento as t } from '@/contenido/provisional';

const TONOS: Record<string, TonoInsignia> = { recibido: 'info', en_revision: 'aviso', respondido: 'exito', cerrado: 'neutro' };
const CAMPO =
  'h-11 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 font-body-md text-body-md text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container w-full uppercase';

const fechaHora = (iso: string) =>
  new Intl.DateTimeFormat('es-PE', { timeZone: 'America/Lima', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
const fechaDia = (d: string) => d.slice(0, 10).split('-').reverse().join('/');

/**
 * EDUN-298 / SIT-011 · Consulta del estado con expediente + documento. Muestra solo el estado,
 * el tipo y las fechas: nunca datos personales. Sin coincidencia, un mensaje genérico.
 */
export function ConsultaReclamo({ expedienteInicial }: { expedienteInicial: string }) {
  const [resultado, setResultado] = useState<ResultadoSeguimiento | null>(null);
  const [consultando, iniciar] = useTransition();

  function consultar(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const d = new FormData(e.currentTarget);
    iniciar(async () => setResultado(await consultarReclamo(String(d.get('expediente') ?? ''), String(d.get('documento') ?? ''))));
  }

  return (
    <div className="flex flex-col gap-space-md">
      <form onSubmit={consultar} className="grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-space-sm items-end p-space-md sm:p-space-lg rounded-2xl bg-surface-container-lowest border border-surface-container">
        <label className="flex flex-col gap-1 font-label-md text-label-md text-secondary">
          {t.expediente}
          <input name="expediente" required defaultValue={expedienteInicial} placeholder={t.expedienteEjemplo} maxLength={20} autoComplete="off" className={CAMPO} />
        </label>
        <label className="flex flex-col gap-1 font-label-md text-label-md text-secondary">
          {t.documento}
          <input name="documento" required maxLength={15} autoComplete="off" inputMode="numeric" className={CAMPO} />
        </label>
        <button type="submit" disabled={consultando} className="h-11 px-space-lg rounded-lg bg-primary hover:bg-primary-container text-on-primary font-label-lg text-label-lg inline-flex items-center justify-center gap-space-xs disabled:opacity-60">
          <Icono nombre="manage_search" />
          {consultando ? t.consultando : t.consultar}
        </button>
      </form>

      <div aria-live="polite">
        {resultado && !resultado.ok && (
          <p role="alert" className="flex items-center gap-space-xs p-space-md rounded-xl bg-error-container text-on-error-container font-body-md text-body-md">
            <Icono nombre="info" />
            {resultado.error}
          </p>
        )}
        {resultado?.ok && (
          <section aria-labelledby="titulo-resultado" className="p-space-md sm:p-space-lg rounded-2xl bg-surface-container-lowest border border-surface-container flex flex-col gap-space-sm">
            <h2 id="titulo-resultado" className="font-label-md text-label-md text-secondary uppercase tracking-wider">
              {t.resultado}
            </h2>
            <p className="flex flex-wrap items-center gap-space-sm">
              <span className="font-headline-sm text-headline-sm text-primary font-bold">{resultado.expediente}</span>
              <span className="font-label-md text-label-md text-secondary">{t.tipo[resultado.tipo] ?? resultado.tipo}</span>
              <Insignia tono={TONOS[resultado.estado] ?? 'neutro'}>{t.estados[resultado.estado] ?? resultado.estado}</Insignia>
            </p>
            <p className="font-body-md text-body-md text-on-surface">{t.estadoDetalle[resultado.estado]}</p>
            <dl className="grid grid-cols-1 sm:grid-cols-2 gap-space-sm font-body-sm text-body-sm">
              <div>
                <dt className="font-label-sm text-label-sm text-secondary uppercase tracking-wider">{t.actualizado}</dt>
                <dd>{fechaHora(resultado.actualizado_en)}</dd>
              </div>
              <div>
                <dt className="font-label-sm text-label-sm text-secondary uppercase tracking-wider">{t.vence}</dt>
                <dd>{fechaDia(resultado.vence_en)}</dd>
              </div>
            </dl>
          </section>
        )}
      </div>
    </div>
  );
}
