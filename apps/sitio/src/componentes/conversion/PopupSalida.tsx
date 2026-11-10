'use client';

import Link from 'next/link';
import { useCallback, useEffect, useRef, useState, useTransition, type FormEvent } from 'react';
import { registrarLeadPopup } from '@/app/acciones-sitio';
import { Icono, Modal } from '@/componentes/base';
import { popupSalida as t } from '@/contenido/provisional';
import { registrarEvento } from '@/lib/analitica';
import { leerPreferencias } from '@/lib/consentimiento';
import { esIntentoDeSalida, marcarConvertido, marcarVisto, puedeMostrarse, type ConfigPopup } from '@/lib/popupSalida';

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

/** Ya hay otra ventana abierta (demo, política, video, preferencias de cookies). */
const hayOtraVentana = () => document.querySelector('[role="dialog"]') !== null;

/**
 * EDUN-271 / SIT-009 · Popup de salida (prototipo #popup-salida). Textos, dato que pide y
 * cuándo aparece salen del CMS (cms.popup_config). Deja un lead source=popup con su
 * consentimiento (EDUN-291) y no vuelve a aparecer a quien ya dejó sus datos.
 */
export function PopupSalida({ config }: { config: ConfigPopup }) {
  const [abierto, setAbierto] = useState(false);
  const [listo, setListo] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [invalidos, setInvalidos] = useState<Set<string>>(new Set());
  const [enviando, iniciar] = useTransition();
  const mostrado = useRef(false);

  const mostrar = useCallback(
    (disparador: 'salida' | 'inactividad') => {
      if (mostrado.current || hayOtraVentana() || !leerPreferencias() || !puedeMostrarse(config.frecuencia)) return;
      mostrado.current = true;
      marcarVisto(config.frecuencia);
      setAbierto(true);
      registrarEvento('exit_popup_view', { disparador });
    },
    [config.frecuencia],
  );

  useEffect(() => {
    if (!puedeMostrarse(config.frecuencia)) return;
    const inicio = Date.now();
    const listoParaMostrar = () => Date.now() - inicio >= config.espera_seg * 1000;

    function alSalir(e: MouseEvent) {
      if (esIntentoDeSalida(e) && listoParaMostrar()) mostrar('salida');
    }

    let reloj: ReturnType<typeof setTimeout> | undefined;
    function reiniciar() {
      clearTimeout(reloj);
      if (config.inactividad_seg <= 0 || mostrado.current) return;
      reloj = setTimeout(() => (listoParaMostrar() ? mostrar('inactividad') : reiniciar()), config.inactividad_seg * 1000);
    }
    const actividad = ['pointerdown', 'keydown', 'scroll', 'touchstart'] as const;

    document.addEventListener('mouseout', alSalir);
    actividad.forEach((ev) => window.addEventListener(ev, reiniciar, { passive: true }));
    reiniciar();
    return () => {
      clearTimeout(reloj);
      document.removeEventListener('mouseout', alSalir);
      actividad.forEach((ev) => window.removeEventListener(ev, reiniciar));
    };
  }, [config.espera_seg, config.inactividad_seg, config.frecuencia, mostrar]);

  function cerrar() {
    if (!listo) registrarEvento('exit_popup_close');
    setAbierto(false);
  }

  function enviar(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const d = new FormData(e.currentTarget);
    const texto = (k: string) => String(d.get(k) ?? '').trim();
    const email = texto('email');
    const telefono = texto('telefono');
    const consentimiento = d.get('consentimiento') === 'on';
    const malos = new Set<string>();
    const campos = [...e.currentTarget.querySelectorAll<HTMLInputElement>('input[name="email"], input[name="telefono"]')];
    campos.filter((c) => c.value.trim() !== '' && !c.checkValidity()).forEach((c) => malos.add(c.name));
    if (!email && !telefono) campos.forEach((c) => malos.add(c.name));
    if (!consentimiento) malos.add('consentimiento');
    setInvalidos(malos);
    if (malos.size > 0) {
      registrarEvento('form_error', { source: 'popup', campos: [...malos] });
      return setError(t.errorIncompleto);
    }
    setError(null);

    iniciar(async () => {
      const r = await registrarLeadPopup({ email, telefono, consentimiento, page_url: location.href.split('#')[0], sitio_web: texto('sitio_web'), utm: leerUtm() });
      if (!r.ok) {
        registrarEvento('form_error', { source: 'popup', campos: r.campos });
        setInvalidos(new Set(r.campos));
        return setError(r.error);
      }
      marcarConvertido();
      registrarEvento('form_submit', { source: 'popup' });
      registrarEvento('exit_popup_submit', { campo: email && telefono ? 'ambos' : email ? 'email' : 'telefono' });
      setListo(true);
    });
  }

  const marcar = (n: string) => (invalidos.has(n) ? true : undefined);
  const pideEmail = config.campo !== 'telefono';
  const pideTelefono = config.campo !== 'email';

  return (
    <Modal abierto={abierto} onCerrar={cerrar} titulo={config.titulo} etiquetaCerrar={t.cerrar} ancho="sm">
      {listo ? (
        <div role="status" className="flex flex-col items-center text-center gap-space-sm py-space-md">
          <span className="w-14 h-14 rounded-full bg-accion text-accion-texto flex items-center justify-center shadow-lg">
            <Icono nombre="check" tamano="lg" />
          </span>
          <h3 className="font-title-lg text-title-lg text-primary font-bold">{t.listoTitulo}</h3>
          <p className="font-body-md text-body-md text-secondary">{t.listo}</p>
          <Link href="/?origen=popup#demo" onClick={() => setAbierto(false)} className="mt-space-xs text-primary font-label-lg text-label-lg underline">
            {t.demo}
          </Link>
        </div>
      ) : (
        <form noValidate onSubmit={enviar} className="flex flex-col gap-space-sm" data-popup-salida>
          {config.imagen && (
            // eslint-disable-next-line @next/next/no-img-element -- imagen de la Biblioteca (bucket del sitio)
            <img src={config.imagen.url} alt={config.imagen.alt} className="w-full max-h-40 object-contain rounded-lg" />
          )}
          {config.texto && <p className="font-body-md text-body-md text-secondary">{config.texto}</p>}
          {pideEmail && (
            <label className={ETIQUETA}>
              {t.email}
              <input type="email" name="email" autoComplete="email" maxLength={160} required={config.campo === 'email'} aria-invalid={marcar('email')} className={CAMPO} />
            </label>
          )}
          {pideTelefono && (
            <label className={ETIQUETA}>
              {t.telefono}
              <input
                type="tel"
                name="telefono"
                autoComplete="tel"
                inputMode="tel"
                placeholder={t.telefonoEjemplo}
                pattern={'\\+?[0-9 \\(\\)\\-]{6,20}'}
                required={config.campo === 'telefono'}
                aria-invalid={marcar('telefono')}
                className={CAMPO}
              />
            </label>
          )}
          {pideEmail && pideTelefono && <p className="font-label-sm text-label-sm text-on-surface-variant -mt-1">{t.unoDeLosDos}</p>}
          <label className="sr-only" aria-hidden="true">
            {t.trampa}
            <input type="text" name="sitio_web" tabIndex={-1} autoComplete="off" />
          </label>
          <label className="flex items-start gap-2 font-body-sm text-body-sm text-on-surface">
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
          <button type="submit" disabled={enviando} className="h-11 rounded-lg bg-accion hover:bg-accion-hover text-accion-texto font-label-lg text-label-lg shadow-md disabled:opacity-60">
            {enviando ? t.enviando : config.cta}
          </button>
          <Link href="/?origen=popup#demo" onClick={() => setAbierto(false)} className="self-center text-primary font-label-md text-label-md underline">
            {t.demo}
          </Link>
        </form>
      )}
    </Modal>
  );
}
