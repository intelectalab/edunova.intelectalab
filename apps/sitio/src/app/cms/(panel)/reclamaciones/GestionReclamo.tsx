'use client';

import { useRouter } from 'next/navigation';
import { useId, useState, useTransition } from 'react';
import { useToast } from '@/componentes/base';
import { gestionReclamos as t } from '@/contenido/provisional';
import { actualizarReclamo, type EstadoReclamo } from '../../reclamaciones-acciones';

type Props = {
  id: number;
  estado: EstadoReclamo;
  siguientes: EstadoReclamo[];
  respuesta: string | null;
  respondido: boolean;
  notas: string | null;
};

const CAMPO =
  'w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 font-body-md text-body-md text-on-surface aria-[invalid=true]:border-error focus:outline-none focus:ring-2 focus:ring-primary-container';

/**
 * EDUN-302 · Cambio de estado, respuesta al cliente y notas internas. Al pasar a «Respondido»
 * la API envía la respuesta por correo y la deja fija (la base impide modificarla después).
 */
export function GestionReclamo({ id, estado, siguientes, respuesta, respondido, notas }: Props) {
  const [nuevo, setNuevo] = useState<EstadoReclamo | ''>('');
  const [texto, setTexto] = useState(respuesta ?? '');
  const [notasInternas, setNotas] = useState(notas ?? '');
  const [errores, setErrores] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [guardando, iniciar] = useTransition();
  const router = useRouter();
  const avisar = useToast();
  const ids = { estado: useId(), respuesta: useId(), notas: useId(), ayudaRespuesta: useId(), ayudaNotas: useId() };

  if (estado === 'cerrado') {
    return <p className="font-body-sm text-body-sm text-on-surface-variant pt-space-sm border-t border-surface-container">{t.cerrado}</p>;
  }

  const respondiendo = nuevo === 'respondido';
  const hayCambios = nuevo !== '' || notasInternas !== (notas ?? '') || (!respondido && texto !== (respuesta ?? ''));

  function guardar() {
    if (respondiendo && !window.confirm(t.confirmarRespuesta)) return;
    setError(null);
    setErrores({});
    iniciar(async () => {
      const r = await actualizarReclamo(id, {
        ...(nuevo ? { estado: nuevo } : {}),
        ...(!respondido ? { respuesta_cliente: texto } : {}),
        notas_internas: notasInternas,
      });
      if (!r.ok) {
        setErrores(r.errores);
        return setError(Object.values(r.errores)[0] ?? r.mensaje ?? t.error);
      }
      avisar(r.mensaje || t.guardado);
      setNuevo('');
      router.refresh();
    });
  }

  return (
    <section aria-label={t.gestion} className="flex flex-col gap-space-sm pt-space-sm border-t border-surface-container">
      <h3 className="font-title-md text-title-md text-primary">{t.gestion}</h3>
      {siguientes.length > 0 && (
        <div className="flex flex-col gap-1">
          <label htmlFor={ids.estado} className="font-label-md text-label-md text-secondary">
            {t.cambiarEstado}
          </label>
          <select
            id={ids.estado}
            value={nuevo}
            onChange={(e) => setNuevo(e.target.value as EstadoReclamo | '')}
            aria-invalid={errores.estado ? true : undefined}
            className="h-10 rounded-lg border border-outline-variant bg-surface-container-lowest px-2 font-body-md text-body-md text-on-surface"
          >
            <option value="">{t.mantener(t.estados[estado])}</option>
            {siguientes.map((s) => (
              <option key={s} value={s}>
                {t.estados[s]}
              </option>
            ))}
          </select>
        </div>
      )}

      {respondido ? (
        <div className="flex flex-col gap-1">
          <span className="font-label-md text-label-md text-secondary">{t.respuestaEnviada}</span>
          <p className="font-body-sm text-body-sm text-on-surface whitespace-pre-line p-space-sm rounded-lg bg-surface-container-low" data-respuesta>
            {respuesta}
          </p>
        </div>
      ) : (
        <div className="flex flex-col gap-1">
          <label htmlFor={ids.respuesta} className="font-label-md text-label-md text-secondary">
            {t.respuesta}
          </label>
          <textarea
            id={ids.respuesta}
            rows={6}
            maxLength={5000}
            value={texto}
            onChange={(e) => setTexto(e.target.value)}
            required={respondiendo}
            aria-invalid={errores.respuesta_cliente ? true : undefined}
            aria-describedby={ids.ayudaRespuesta}
            className={CAMPO}
          />
          <span id={ids.ayudaRespuesta} className="font-label-sm text-label-sm text-on-surface-variant">
            {t.respuestaAyuda}
          </span>
        </div>
      )}

      <div className="flex flex-col gap-1">
        <label htmlFor={ids.notas} className="font-label-md text-label-md text-secondary">
          {t.notas}
        </label>
        <textarea id={ids.notas} rows={3} maxLength={5000} value={notasInternas} onChange={(e) => setNotas(e.target.value)} aria-describedby={ids.ayudaNotas} className={CAMPO} />
        <span id={ids.ayudaNotas} className="font-label-sm text-label-sm text-on-surface-variant">
          {t.notasAyuda}
        </span>
      </div>

      {error && (
        <p role="alert" className="font-label-md text-label-md text-error">
          {error}
        </p>
      )}
      <button
        type="button"
        onClick={guardar}
        disabled={guardando || !hayCambios}
        className={`h-10 px-space-md rounded-lg font-label-lg text-label-lg disabled:opacity-50 disabled:cursor-not-allowed ${respondiendo ? 'bg-accion hover:bg-accion-hover text-accion-texto' : 'bg-primary hover:bg-primary-container text-on-primary'}`}
      >
        {guardando ? t.guardando : respondiendo ? t.responder : t.guardar}
      </button>
    </section>
  );
}
