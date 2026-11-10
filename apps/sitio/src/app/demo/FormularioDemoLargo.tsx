'use client';

import { useRouter } from 'next/navigation';
import { useState, useTransition, type FormEvent } from 'react';
import { solicitarDemo } from '@/app/acciones-sitio';
import { Icono } from '@/componentes/base';
import { CalendarioDemo } from '@/componentes/conversion/CalendarioDemo';
import { demo as t, paginas } from '@/contenido/provisional';
import { registrarEvento } from '@/lib/analitica';
import { hoyLima, mesInicial, textoFecha } from '@/lib/calendario';
import { marcarConvertido } from '@/lib/popupSalida';

const CAMPO =
  'h-11 rounded-lg border bg-surface-container-lowest px-3 font-body-md text-body-md text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container aria-[invalid=true]:border-error border-outline-variant';
const ETIQUETA = 'flex flex-col gap-1 font-label-md text-label-md text-secondary min-w-0';

function leerUtm(): Record<string, string> {
  try {
    return JSON.parse(sessionStorage.getItem('edunova_utm') ?? '{}');
  } catch {
    return {};
  }
}

/**
 * EDUN-359 / SIT-064 · Formulario largo de demo: los campos de la ventana corta más ciudad,
 * sistema actual y urgencia, con el mismo calendario. Al registrarse lleva a /gracias.
 */
export function FormularioDemoLargo({ planInicial }: { planInicial: string }) {
  const [hoy] = useState(() => hoyLima());
  const [vista, setVista] = useState(() => mesInicial(hoyLima()));
  const [fecha, setFecha] = useState<string | null>(null);
  const [hora, setHora] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [invalidos, setInvalidos] = useState<Set<string>>(new Set());
  const [enviando, iniciar] = useTransition();
  const router = useRouter();
  const p = paginas.demo;

  function enviar(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const f = e.currentTarget;
    const malos = new Set([...f.querySelectorAll<HTMLInputElement | HTMLSelectElement>('[required]')].filter((c) => !c.checkValidity()).map((c) => c.name));
    setInvalidos(malos);
    if (malos.size > 0) {
      registrarEvento('form_error', { source: 'demo_largo', campos: [...malos] });
      return setError(t.errorIncompleto);
    }
    if (!fecha || !hora) return setError(t.errorCalendario);
    setError(null);
    const d = new FormData(f);
    const texto = (k: string) => String(d.get(k) ?? '').trim();
    const solicitud = {
      nombre: texto('nombre'),
      cargo: texto('cargo'),
      telefono: texto('telefono'),
      email: texto('email'),
      colegio: texto('colegio'),
      ciudad: texto('ciudad') || null,
      alumnos: Number(texto('alumnos')) || 0,
      plan_interes: texto('plan_interes') || null,
      sistema_actual: texto('sistema_actual') || null,
      urgencia: texto('urgencia') || null,
      demo_fecha: fecha,
      demo_hora: hora,
      consentimiento: d.get('consentimiento') === 'on',
      origen: 'demo_largo',
      page_url: location.href.split('#')[0],
      sitio_web: texto('sitio_web'),
      utm: leerUtm(),
    };
    iniciar(async () => {
      const r = await solicitarDemo(solicitud);
      if (!r.ok) {
        registrarEvento('form_error', { source: 'demo_largo', campos: r.campos });
        setInvalidos(new Set(r.campos));
        return setError(r.error);
      }
      marcarConvertido();
      registrarEvento('form_submit', { source: 'demo_largo', plan: solicitud.plan_interes ?? '' });
      router.push(`/gracias?fecha=${encodeURIComponent(fecha)}&hora=${encodeURIComponent(hora)}${solicitud.plan_interes ? `&plan=${solicitud.plan_interes}` : ''}`);
    });
  }

  const marcar = (n: string) => (invalidos.has(n) ? true : undefined);
  const etiquetaHora = hora ? (t.horas.find((h) => h.valor === hora)?.etiqueta ?? hora) : null;

  return (
    <form onSubmit={enviar} noValidate className="grid grid-cols-1 lg:grid-cols-2 gap-space-xl p-space-lg rounded-2xl bg-surface-container-lowest border border-surface-container">
      <fieldset className="flex flex-col gap-space-sm min-w-0">
        <legend className="font-title-md text-title-md text-primary mb-space-sm">{t.pasoContacto}</legend>
        <label className={ETIQUETA}>
          {t.nombre}
          <input name="nombre" required minLength={3} maxLength={120} autoComplete="name" aria-invalid={marcar('nombre')} className={CAMPO} />
        </label>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
          <label className={ETIQUETA}>
            {t.cargo}
            <select name="cargo" required defaultValue="" aria-invalid={marcar('cargo')} className={`${CAMPO} px-2`}>
              <option value="">{t.elegir}</option>
              {t.cargos.map((c) => (
                <option key={c}>{c}</option>
              ))}
            </select>
          </label>
          <label className={ETIQUETA}>
            {t.telefono}
            <input name="telefono" required inputMode="tel" autoComplete="tel" pattern={'\\+?[0-9 \\(\\)\\-]{6,20}'} placeholder={t.telefonoEjemplo} aria-invalid={marcar('telefono')} className={CAMPO} />
          </label>
        </div>
        <label className={ETIQUETA}>
          {t.email}
          <input name="email" type="email" required maxLength={160} autoComplete="email" aria-invalid={marcar('email')} className={CAMPO} />
        </label>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
          <label className={ETIQUETA}>
            {t.colegio}
            <input name="colegio" required minLength={2} maxLength={160} autoComplete="organization" aria-invalid={marcar('colegio')} className={CAMPO} />
          </label>
          <label className={ETIQUETA}>
            {t.ciudad}
            <input name="ciudad" maxLength={80} autoComplete="address-level2" className={CAMPO} />
          </label>
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
          <label className={ETIQUETA}>
            {t.alumnos}
            <select name="alumnos" required defaultValue="" aria-invalid={marcar('alumnos')} className={`${CAMPO} px-2`}>
              <option value="">{t.elegir}</option>
              {t.rangosAlumnos.map((r) => (
                <option key={r.valor} value={r.valor}>
                  {r.etiqueta}
                </option>
              ))}
            </select>
          </label>
          <label className={ETIQUETA}>
            {t.plan}
            <select name="plan_interes" defaultValue={planInicial} className={`${CAMPO} px-2`}>
              <option value="">{t.aunNoSe}</option>
              {t.planes.map((x) => (
                <option key={x.valor} value={x.valor}>
                  {x.etiqueta}
                </option>
              ))}
            </select>
          </label>
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
          <label className={ETIQUETA}>
            {p.sistemaActual}
            <input name="sistema_actual" maxLength={80} placeholder={p.sistemaEjemplo} className={CAMPO} />
          </label>
          <label className={ETIQUETA}>
            {p.urgencia}
            <select name="urgencia" defaultValue="" aria-invalid={marcar('urgencia')} className={`${CAMPO} px-2`}>
              <option value="">{t.elegir}</option>
              {Object.entries(p.urgencias).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </select>
          </label>
        </div>
        <label className="absolute -left-[9999px] w-px h-px overflow-hidden" aria-hidden="true">
          {t.trampa}
          <input name="sitio_web" tabIndex={-1} autoComplete="off" />
        </label>
      </fieldset>

      <fieldset className="flex flex-col gap-space-sm min-w-0">
        <legend className="font-title-md text-title-md text-primary mb-space-sm">{t.pasoCalendario}</legend>
        <CalendarioDemo hoy={hoy} vista={vista} setVista={setVista} fecha={fecha} setFecha={setFecha} hora={hora} setHora={setHora} invalido={invalidos.has('demo_fecha')} />
        <p aria-live="polite" className="font-label-md text-label-md text-secondary">
          {fecha ? t.seleccionado(textoFecha(fecha), etiquetaHora) : t.resumenInicial}
        </p>
        <label className="flex items-start gap-2 font-body-sm text-body-sm text-on-surface mt-1">
          <input type="checkbox" name="consentimiento" required aria-invalid={marcar('consentimiento')} className="mt-0.5 w-4 h-4 shrink-0 accent-primary" />
          <span>
            {t.consentimientoAntes}
            <a href="#privacidad" className="text-primary font-semibold underline">
              {t.consentimientoEnlace}
            </a>
            {t.consentimientoDespues}
          </span>
        </label>
        <p role="alert" className="font-label-md text-label-md text-error font-semibold empty:hidden">
          {error}
        </p>
        <button type="submit" disabled={enviando} className="mt-1 w-full py-3 rounded-lg bg-accion hover:bg-accion-hover text-accion-texto font-title-md text-title-md font-bold shadow flex items-center justify-center gap-space-xs disabled:opacity-60">
          <Icono nombre="event_available" />
          <span>{enviando ? t.enviando : t.agendar}</span>
        </button>
      </fieldset>
    </form>
  );
}
