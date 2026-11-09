'use client';

import Link from 'next/link';
import { useEffect, useRef, useState, useTransition, type FormEvent, type ReactNode } from 'react';
import { registrarReclamo } from '@/app/acciones-sitio';
import { Icono } from '@/componentes/base';
import { reclamos as t } from '@/contenido/provisional';

declare global {
  interface Window {
    grecaptcha?: { ready: (f: () => void) => void; execute: (clave: string, o: { action: string }) => Promise<string> };
  }
}

const CAMPO =
  'h-11 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 font-body-md text-body-md text-on-surface focus:outline-none focus:ring-2 focus:ring-primary-container aria-[invalid=true]:border-error w-full';
const AREA = CAMPO.replace('h-11', 'min-h-28 py-2');
const ETIQUETA = 'flex flex-col gap-1 font-label-md text-label-md text-secondary min-w-0';

const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'setiembre', 'octubre', 'noviembre', 'diciembre'];
const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
function fechaLarga(f: string) {
  const [a, m, d] = f.split('-').map(Number);
  const dia = new Date(Date.UTC(a, m - 1, d)).getUTCDay();
  return `${DIAS[dia]} ${d} de ${MESES[m - 1]} de ${a}`;
}

function Paso({ n, titulo, children }: { n: number; titulo: string; children: ReactNode }) {
  return (
    <fieldset className="flex flex-col gap-space-sm p-space-md sm:p-space-lg rounded-2xl bg-surface-container-lowest border border-surface-container min-w-0">
      <legend className="flex items-center gap-space-sm font-title-lg text-title-lg text-primary px-1">
        <span aria-hidden="true" className="w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center font-label-lg text-label-lg">
          {n}
        </span>
        {titulo}
      </legend>
      {children}
    </fieldset>
  );
}

function Error({ texto }: { texto?: string }) {
  return texto ? <span className="font-label-sm text-label-sm text-error">{texto}</span> : null;
}

type Listo = { expediente: string; vence_en: string; email: string; tipo: string };

/**
 * EDUN-294 / SIT-010 · Hoja de reclamación (Stitch S-WEB-06): consumidor, bien o servicio,
 * detalle (mínimo 50 caracteres), pedido, adjuntos, declaración y consentimiento.
 * reCAPTCHA v3 solo si hay clave del sitio.
 */
export function FormularioReclamo({ claveRecaptcha }: { claveRecaptcha: string | null }) {
  const [tipo, setTipo] = useState('reclamo');
  const [menor, setMenor] = useState(false);
  const [largo, setLargo] = useState(0);
  const [errores, setErrores] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [listo, setListo] = useState<Listo | null>(null);
  const [enviando, iniciar] = useTransition();
  const formulario = useRef<HTMLFormElement>(null);
  const resultado = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!claveRecaptcha || document.getElementById('recaptcha')) return;
    const s = document.createElement('script');
    s.id = 'recaptcha';
    s.src = `https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(claveRecaptcha)}`;
    s.async = true;
    document.head.appendChild(s);
  }, [claveRecaptcha]);

  useEffect(() => {
    if (listo) resultado.current?.focus();
  }, [listo]);

  async function tokenRecaptcha(): Promise<string> {
    const g = window.grecaptcha;
    if (!claveRecaptcha || !g) return '';
    return new Promise((ok) => g.ready(() => g.execute(claveRecaptcha, { action: 'reclamo' }).then(ok, () => ok(''))));
  }

  function enviar(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const f = e.currentTarget;
    const locales: Record<string, string> = {};
    f.querySelectorAll<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>('[required]').forEach((c) => {
      if (!c.checkValidity()) locales[c.name] = c.name === 'descripcion' && c.value.trim().length > 0 ? 'Describe los hechos con al menos 50 caracteres.' : 'Completa este campo.';
    });
    setErrores(locales);
    if (Object.keys(locales).length > 0) {
      setError(t.errorIncompleto);
      f.querySelector<HTMLElement>('[aria-invalid="true"], :invalid')?.focus();
      return;
    }
    setError(null);
    const datos = new FormData(f);
    iniciar(async () => {
      datos.set('recaptcha', await tokenRecaptcha());
      const r = await registrarReclamo(datos);
      if (!r.ok) {
        setErrores(r.errores);
        setError(Object.values(r.errores)[0] ?? r.error);
        return;
      }
      setListo({ expediente: r.expediente, vence_en: r.vence_en, email: r.email, tipo: String(datos.get('tipo')) });
      f.reset();
      setLargo(0);
      setMenor(false);
    });
  }

  const marca = (n: string) => (errores[n] ? true : undefined);

  if (listo) {
    return (
      <div ref={resultado} tabIndex={-1} role="status" className="p-space-lg rounded-2xl bg-surface-container-lowest border border-surface-container flex flex-col items-center text-center gap-space-sm outline-none">
        <span className="w-16 h-16 rounded-full bg-accion text-accion-texto flex items-center justify-center shadow-lg">
          <Icono nombre="check" tamano="lg" />
        </span>
        <h2 className="font-headline-sm text-headline-sm text-primary font-bold">{t.listoTitulo(listo.tipo)}</h2>
        <p className="font-label-md text-label-md text-secondary uppercase tracking-wider">{t.listoNumero}</p>
        <p className="font-headline-md text-headline-md text-primary font-bold" data-expediente>
          {listo.expediente}
        </p>
        <p className="font-body-md text-body-md text-on-surface max-w-xl">{t.listo(listo.email, fechaLarga(listo.vence_en))}</p>
        <p className="font-body-sm text-body-sm text-secondary max-w-xl">{t.guardaNumero}</p>
        <div className="flex flex-wrap justify-center gap-space-sm pt-space-sm">
          <Link href={`/libro-de-reclamaciones/seguimiento?expediente=${encodeURIComponent(listo.expediente)}`} className="h-10 px-space-md rounded-lg bg-primary hover:bg-primary-container text-on-primary font-label-lg text-label-lg inline-flex items-center gap-space-xs">
            <Icono nombre="manage_search" />
            {t.verSeguimiento}
          </Link>
          <button type="button" onClick={() => window.print()} className="h-10 px-space-md rounded-lg bg-surface-container hover:bg-surface-container-high text-primary font-label-lg text-label-lg inline-flex items-center gap-space-xs">
            <Icono nombre="print" />
            {t.imprimir}
          </button>
          <button type="button" onClick={() => setListo(null)} className="h-10 px-space-md rounded-lg text-primary hover:bg-surface-container-low font-label-lg text-label-lg">
            {t.otro}
          </button>
        </div>
      </div>
    );
  }

  return (
    <form ref={formulario} onSubmit={enviar} noValidate className="flex flex-col gap-space-md">
      <p className="font-label-md text-label-md text-secondary">{t.obligatorios}</p>

      <Paso n={1} titulo={t.pasos[0]}>
        <label className={ETIQUETA}>
          {t.nombre}
          <input name="nombre" required minLength={3} maxLength={160} autoComplete="name" aria-invalid={marca('nombre')} className={CAMPO} />
          <Error texto={errores.nombre} />
        </label>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
          <label className={ETIQUETA}>
            {t.tipoDoc}
            <select name="tipo_doc" required defaultValue="DNI" aria-invalid={marca('tipo_doc')} className={`${CAMPO} px-2`}>
              {t.documentos.map((d) => (
                <option key={d.valor} value={d.valor}>
                  {d.etiqueta}
                </option>
              ))}
            </select>
          </label>
          <label className={ETIQUETA}>
            {t.numeroDoc}
            <input name="numero_doc" required minLength={6} maxLength={15} inputMode="numeric" aria-invalid={marca('numero_doc')} className={CAMPO} />
            <Error texto={errores.numero_doc} />
          </label>
          <label className={ETIQUETA}>
            {t.email}
            <input name="email" type="email" required maxLength={160} autoComplete="email" aria-invalid={marca('email')} className={CAMPO} />
            <span className="font-label-sm text-label-sm text-on-surface-variant">{t.emailNota}</span>
            <Error texto={errores.email} />
          </label>
          <label className={ETIQUETA}>
            {t.telefono}
            <input name="telefono" inputMode="tel" autoComplete="tel" maxLength={20} aria-invalid={marca('telefono')} className={CAMPO} />
            <Error texto={errores.telefono} />
          </label>
        </div>
        <label className={ETIQUETA}>
          {t.direccion}
          <input name="direccion" required minLength={5} maxLength={250} autoComplete="street-address" aria-invalid={marca('direccion')} className={CAMPO} />
          <Error texto={errores.direccion} />
        </label>
        <label className="flex items-center gap-2 font-body-md text-body-md text-on-surface">
          <input type="checkbox" name="menor_edad" checked={menor} onChange={(e) => setMenor(e.target.checked)} className="w-4 h-4 accent-primary" />
          {t.menor}
        </label>
        {menor && (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
            <label className={ETIQUETA}>
              {t.apoderadoNombre}
              <input name="apoderado_nombre" required minLength={3} maxLength={160} aria-invalid={marca('apoderado_nombre')} className={CAMPO} />
              <Error texto={errores.apoderado_nombre} />
            </label>
            <label className={ETIQUETA}>
              {t.apoderadoDoc}
              <input name="apoderado_doc" required minLength={8} maxLength={12} inputMode="numeric" aria-invalid={marca('apoderado_doc')} className={CAMPO} />
              <Error texto={errores.apoderado_doc} />
            </label>
          </div>
        )}
      </Paso>

      <Paso n={2} titulo={t.pasos[1]}>
        <div className="grid grid-cols-1 sm:grid-cols-[1fr_12rem] gap-space-sm">
          <label className={ETIQUETA}>
            {t.producto}
            <input name="producto" required minLength={3} maxLength={250} placeholder={t.productoEjemplo} aria-invalid={marca('producto')} className={CAMPO} />
            <Error texto={errores.producto} />
          </label>
          <label className={ETIQUETA}>
            {t.monto}
            <input name="monto" type="number" min="0" step="0.01" inputMode="decimal" aria-invalid={marca('monto')} className={CAMPO} />
            <Error texto={errores.monto} />
          </label>
        </div>
      </Paso>

      <Paso n={3} titulo={t.pasos[2]}>
        <div role="radiogroup" aria-label={t.tipo} className="grid grid-cols-1 sm:grid-cols-2 gap-space-sm">
          {t.tipos.map((o) => (
            <label key={o.valor} className={`flex items-start gap-space-sm p-space-md rounded-xl border cursor-pointer ${tipo === o.valor ? 'border-primary bg-primary/5' : 'border-outline-variant'}`}>
              <input type="radio" name="tipo" value={o.valor} checked={tipo === o.valor} onChange={() => setTipo(o.valor)} className="mt-1 w-4 h-4 accent-primary" />
              <span className="flex flex-col gap-1">
                <span className="flex items-center gap-space-xs font-title-md text-title-md text-primary">
                  <Icono nombre={o.icono} tamano="sm" />
                  {o.titulo}
                </span>
                <span className="font-body-sm text-body-sm text-on-surface-variant">{o.descripcion}</span>
              </span>
            </label>
          ))}
        </div>
        <label className={ETIQUETA}>
          {t.descripcion}
          <textarea name="descripcion" required minLength={50} maxLength={2000} onChange={(e) => setLargo(e.target.value.length)} aria-invalid={marca('descripcion')} className={AREA} />
          <span className={`font-label-sm text-label-sm ${largo > 0 && largo < 50 ? 'text-error' : 'text-on-surface-variant'}`}>{t.descripcionNota(largo)}</span>
          <Error texto={errores.descripcion} />
        </label>
        <label className={ETIQUETA}>
          {t.pedido}
          <textarea name="pedido" required minLength={5} maxLength={1000} aria-invalid={marca('pedido')} className={AREA} />
          <Error texto={errores.pedido} />
        </label>
        <label className={ETIQUETA}>
          {t.adjuntos}
          <input
            name="adjuntos"
            type="file"
            multiple
            accept="image/jpeg,image/png,image/webp,application/pdf"
            aria-invalid={marca('adjuntos')}
            className="font-body-sm text-body-sm text-on-surface file:mr-space-sm file:h-10 file:px-space-md file:rounded-lg file:border-0 file:bg-surface-container file:text-primary file:font-label-lg"
          />
          <span className="font-label-sm text-label-sm text-on-surface-variant">{t.adjuntosNota}</span>
          <Error texto={errores.adjuntos} />
        </label>
      </Paso>

      <Paso n={4} titulo={t.pasos[3]}>
        <label className="flex items-start gap-2 font-body-md text-body-md text-on-surface">
          <input type="checkbox" name="declaracion" required aria-invalid={marca('declaracion')} className="mt-1 w-4 h-4 shrink-0 accent-primary" />
          <span>{t.declaracion}</span>
        </label>
        <Error texto={errores.declaracion} />
        <label className="flex items-start gap-2 font-body-md text-body-md text-on-surface">
          <input type="checkbox" name="consentimiento" required aria-invalid={marca('consentimiento')} className="mt-1 w-4 h-4 shrink-0 accent-primary" />
          <span>
            {t.consentimientoAntes}
            <a href="#privacidad" className="text-primary font-semibold underline">
              {t.consentimientoEnlace}
            </a>
            {t.consentimientoDespues}
          </span>
        </label>
        <Error texto={errores.consentimiento} />
        <label className="absolute -left-[9999px] w-px h-px overflow-hidden" aria-hidden="true">
          {t.trampa}
          <input name="sitio_web" tabIndex={-1} autoComplete="off" />
        </label>
        <p role="alert" className="font-label-md text-label-md text-error font-semibold empty:hidden">
          {error}
        </p>
        <button
          type="submit"
          disabled={enviando}
          className="self-start h-12 px-space-lg rounded-lg bg-accion hover:bg-accion-hover text-accion-texto font-title-md text-title-md font-bold shadow inline-flex items-center gap-space-xs disabled:opacity-60"
        >
          <Icono nombre="send" />
          {enviando ? t.enviando : t.enviar}
        </button>
        {claveRecaptcha && <p className="font-label-sm text-label-sm text-on-surface-variant">{t.recaptcha}</p>}
      </Paso>
    </form>
  );
}
