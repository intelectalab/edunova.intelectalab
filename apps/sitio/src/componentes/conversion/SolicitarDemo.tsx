'use client';

import { useCallback, useEffect, useRef, useState, useTransition, type FormEvent } from 'react';
import { solicitarDemo } from '@/app/acciones-sitio';
import { Icono, Modal } from '@/componentes/base';
import { demo as t } from '@/contenido/provisional';
import { marcarConvertido } from '@/lib/popupSalida';
import { hoyLima, mesCalendario, mesInicial, textoFecha } from '@/lib/calendario';
import { registrarEvento } from '@/lib/analitica';

const PLANES = new Set(t.planes.map((p) => p.valor));
const CLAVE_UTM = 'edunova_utm';

const CAMPO =
  'h-11 rounded-lg border bg-surface-container-lowest px-3 font-body-md text-body-md text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container aria-[invalid=true]:border-error border-outline-variant';
const ETIQUETA = 'flex flex-col gap-1 font-label-md text-label-md text-secondary min-w-0';

/** Botón o enlace que abre la demo: «#demo» con ?plan= y ?origen= opcionales. */
function datosDeEnlace(href: string): { plan: string | null; origen: string } | null {
  try {
    const u = new URL(href, location.href);
    if (u.origin !== location.origin || u.hash !== '#demo') return null;
    const plan = u.searchParams.get('plan');
    return { plan: plan && PLANES.has(plan) ? plan : null, origen: u.searchParams.get('origen') ?? (plan ? 'plan' : 'landing') };
  } catch {
    return null;
  }
}

function leerUtm(): Record<string, string> {
  try {
    return JSON.parse(sessionStorage.getItem(CLAVE_UTM) ?? '{}');
  } catch {
    return {};
  }
}

type Envio = { nombre: string; fecha: string; hora: string; email: string };

/**
 * EDUN-267 / SIT-002 · Ventana «Solicitar demo gratuita» (prototipo #demo): datos de contacto,
 * calendario mensual con días hábiles desde mañana y horarios fijos en hora de Lima, y la
 * casilla de consentimiento (EDUN-291). Se abre desde todo enlace a #demo, incluido /#demo.
 */
export function SolicitarDemo() {
  const [abierto, setAbierto] = useState(false);
  const [plan, setPlan] = useState('');
  const [origen, setOrigen] = useState('landing');
  const [hoy, setHoy] = useState(() => hoyLima());
  const [vista, setVista] = useState(() => mesInicial(hoyLima()));
  const [fecha, setFecha] = useState<string | null>(null);
  const [hora, setHora] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [invalidos, setInvalidos] = useState<Set<string>>(new Set());
  const [listo, setListo] = useState<Envio | null>(null);
  const [enviando, iniciar] = useTransition();
  const formulario = useRef<HTMLFormElement>(null);

  const abrir = useCallback((planInicial: string | null, desde: string) => {
    const h = hoyLima();
    setHoy(h);
    setVista(mesInicial(h));
    setPlan(planInicial ?? '');
    setOrigen(desde);
    setError(null);
    setInvalidos(new Set());
    setListo(null);
    setAbierto(true);
    if (planInicial) registrarEvento('plan_click', { plan: planInicial });
    registrarEvento('demo_modal_open', { source: desde, plan: planInicial ?? '' });
  }, []);

  useEffect(() => {
    // UTM de la primera página de la visita.
    const p = new URLSearchParams(location.search);
    const utm = Object.fromEntries([...p.entries()].filter(([k]) => k.startsWith('utm_')));
    if (Object.keys(utm).length > 0) {
      try {
        sessionStorage.setItem(CLAVE_UTM, JSON.stringify(utm));
      } catch {}
    }

    function alHacerClic(e: MouseEvent) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      const a = (e.target as HTMLElement | null)?.closest?.('a[href]');
      const datos = a ? datosDeEnlace(a.getAttribute('href') ?? '') : null;
      if (!datos) return;
      e.preventDefault();
      abrir(datos.plan, datos.origen);
    }
    function porHash() {
      const datos = location.hash === '#demo' ? datosDeEnlace(location.href) : null;
      if (datos) abrir(datos.plan, datos.origen === 'landing' ? 'enlace' : datos.origen);
    }
    // En captura: antes que el onClick de <Link> de Next, que navegaría en vez de abrir la ventana.
    document.addEventListener('click', alHacerClic, true);
    window.addEventListener('hashchange', porHash);
    porHash();
    return () => {
      document.removeEventListener('click', alHacerClic, true);
      window.removeEventListener('hashchange', porHash);
    };
  }, [abrir]);

  function cerrar() {
    setAbierto(false);
    if (location.hash === '#demo') history.replaceState(null, '', location.pathname + location.search);
  }

  function enviar(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const f = e.currentTarget;
    const malos = new Set([...f.querySelectorAll<HTMLInputElement | HTMLSelectElement>('[required]')].filter((c) => !c.checkValidity()).map((c) => c.name));
    setInvalidos(malos);
    if (malos.size > 0) {
      registrarEvento('form_error', { source: 'demo', campos: [...malos] });
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
      plan_interes: plan || null,
      demo_fecha: fecha,
      demo_hora: hora,
      consentimiento: d.get('consentimiento') === 'on',
      origen,
      page_url: location.href.split('#')[0],
      sitio_web: texto('sitio_web'),
      utm: leerUtm(),
    };
    iniciar(async () => {
      const r = await solicitarDemo(solicitud);
      if (!r.ok) {
        registrarEvento('form_error', { source: 'demo', campos: r.campos });
        setInvalidos(new Set(r.campos));
        setError(r.error);
        return;
      }
      registrarEvento('form_submit', { source: 'demo', plan: solicitud.plan_interes ?? '', utm_source: solicitud.utm.utm_source ?? '', utm_campaign: solicitud.utm.utm_campaign ?? '' });
      marcarConvertido();
      registrarEvento('demo_request', { plan: solicitud.plan_interes ?? '', alumnos: solicitud.alumnos });
      setListo({
        nombre: solicitud.nombre.split(/\s+/)[0],
        fecha: textoFecha(fecha),
        hora: t.horas.find((h) => h.valor === hora)?.etiqueta ?? hora,
        email: r.email,
      });
      setFecha(null);
      setHora(null);
      formulario.current?.reset();
    });
  }

  const { huecos, dias } = mesCalendario(vista.anio, vista.mes, hoy);
  const inicio = mesInicial(hoy);
  const enInicio = vista.anio === inicio.anio && vista.mes === inicio.mes;
  const tope = vista.anio * 12 + vista.mes >= inicio.anio * 12 + inicio.mes + 2; // hasta 3 meses
  const moverMes = (n: number) => setVista(({ anio, mes }) => ({ anio: anio + Math.floor((mes + n) / 12), mes: (((mes + n) % 12) + 12) % 12 }));
  const etiquetaHora = hora ? (t.horas.find((h) => h.valor === hora)?.etiqueta ?? hora) : null;
  const marcar = (nombre: string) => (invalidos.has(nombre) ? true : undefined);

  return (
    <Modal abierto={abierto} onCerrar={cerrar} titulo={t.titulo} etiquetaCerrar={t.cerrar} ancho="xl">
      {listo ? (
        <div role="status" className="bg-primary/5 rounded-xl p-8 flex flex-col items-center justify-center text-center gap-space-sm">
          <span className="w-16 h-16 rounded-full bg-accion text-accion-texto flex items-center justify-center shadow-lg">
            <Icono nombre="check" tamano="lg" />
          </span>
          <h3 className="font-title-lg text-title-lg text-primary font-bold">{t.listoTitulo}</h3>
          <p className="font-body-md text-body-md text-secondary max-w-md">{t.listo(listo.nombre, listo.fecha, listo.hora, listo.email)}</p>
          <button type="button" onClick={cerrar} className="mt-space-sm px-space-lg py-space-sm rounded-lg bg-surface-container hover:bg-surface-container-high text-primary font-label-lg text-label-lg">
            {t.cerrarListo}
          </button>
        </div>
      ) : (
        <form ref={formulario} onSubmit={enviar} noValidate className="grid grid-cols-1 md:grid-cols-2 gap-space-lg">
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
                <input
                  name="telefono"
                  required
                  inputMode="tel"
                  autoComplete="tel"
                  pattern={'\\+?[0-9 \\(\\)\\-]{6,20}'}
                  placeholder={t.telefonoEjemplo}
                  aria-invalid={marcar('telefono')}
                  className={CAMPO}
                />
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
                <select name="plan_interes" value={plan} onChange={(e) => setPlan(e.target.value)} aria-invalid={marcar('plan_interes')} className={`${CAMPO} px-2`}>
                  <option value="">{t.aunNoSe}</option>
                  {t.planes.map((p) => (
                    <option key={p.valor} value={p.valor}>
                      {p.etiqueta}
                    </option>
                  ))}
                </select>
              </label>
            </div>
            {/* Campo trampa para robots: fuera de la vista y del orden de tabulación. */}
            <label className="absolute -left-[9999px] w-px h-px overflow-hidden" aria-hidden="true">
              {t.trampa}
              <input name="sitio_web" tabIndex={-1} autoComplete="off" />
            </label>
          </fieldset>

          <fieldset className="flex flex-col gap-space-sm min-w-0">
            <legend className="font-title-md text-title-md text-primary mb-space-sm">{t.pasoCalendario}</legend>
            <div className={`rounded-xl border p-3 ${invalidos.has('demo_fecha') ? 'border-error' : 'border-outline-variant'}`}>
              <div className="flex items-center justify-between mb-2">
                <button
                  type="button"
                  onClick={() => moverMes(-1)}
                  disabled={enInicio}
                  aria-label={t.mesAnterior}
                  className="w-8 h-8 rounded-full hover:bg-surface-container flex items-center justify-center text-primary disabled:opacity-35 disabled:cursor-not-allowed"
                >
                  <Icono nombre="chevron_left" />
                </button>
                <p aria-live="polite" className="font-title-md text-title-md text-primary capitalize">
                  {t.meses[vista.mes]} {vista.anio}
                </p>
                <button
                  type="button"
                  onClick={() => moverMes(1)}
                  disabled={tope}
                  aria-label={t.mesSiguiente}
                  className="w-8 h-8 rounded-full hover:bg-surface-container flex items-center justify-center text-primary disabled:opacity-35 disabled:cursor-not-allowed"
                >
                  <Icono nombre="chevron_right" />
                </button>
              </div>
              <div aria-hidden="true" className="grid grid-cols-7 text-center font-label-sm text-label-sm text-secondary uppercase">
                {t.diasSemana.map((d) => (
                  <span key={d}>{d}</span>
                ))}
              </div>
              <div className="grid grid-cols-7 gap-1 mt-1" role="group" aria-label={`${t.meses[vista.mes]} ${vista.anio}`}>
                {Array.from({ length: huecos }, (_, i) => (
                  <span key={`h${i}`} />
                ))}
                {dias.map((d) => {
                  const sel = d.fecha === fecha;
                  return (
                    <button
                      key={d.fecha}
                      type="button"
                      disabled={!d.disponible}
                      aria-pressed={sel}
                      aria-label={`${textoFecha(d.fecha)}${d.disponible ? '' : `, ${t.noDisponible}`}`}
                      data-fecha={d.fecha}
                      onClick={() => setFecha(d.fecha)}
                      className={`h-9 rounded-lg font-label-lg text-label-lg ${
                        sel ? 'bg-primary text-on-primary' : d.disponible ? 'text-primary hover:bg-secondary-container' : 'text-outline cursor-not-allowed line-through decoration-1'
                      }`}
                    >
                      {d.dia}
                    </button>
                  );
                })}
              </div>
            </div>
            <div className="grid grid-cols-3 gap-2" role="group" aria-label={t.horasDisponibles}>
              {t.horas.map((h) => (
                <button
                  key={h.valor}
                  type="button"
                  aria-pressed={hora === h.valor}
                  onClick={() => setHora(h.valor)}
                  className={`py-2 rounded-lg font-label-lg text-label-lg ${hora === h.valor ? 'bg-primary text-on-primary' : 'bg-surface-container text-primary hover:bg-surface-container-high'}`}
                >
                  {h.etiqueta}
                </button>
              ))}
            </div>
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
            <button
              type="submit"
              disabled={enviando}
              className="mt-1 w-full py-3 rounded-lg bg-accion hover:bg-accion-hover text-accion-texto font-title-md text-title-md font-bold shadow flex items-center justify-center gap-space-xs disabled:opacity-60"
            >
              <Icono nombre="event_available" />
              <span>{enviando ? t.enviando : t.agendar}</span>
            </button>
          </fieldset>
        </form>
      )}
    </Modal>
  );
}
