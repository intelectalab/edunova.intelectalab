'use client';

import { useEffect, useId, useRef, useState, useTransition, type FormEvent } from 'react';
import { registrarLeadChatbot, registrarSesionChatbot } from '@/app/acciones-sitio';
import { Icono } from '@/componentes/base';
import { chatbot as t } from '@/contenido/provisional';
import { registrarEvento } from '@/lib/analitica';
import { enHorario, enlaceWhatsapp } from '@/lib/chatbot';
import type { Chatbot } from '@/lib/landing';
import { marcarConvertido } from '@/lib/popupSalida';

type Linea = { de: 'bot' | 'tu'; texto: string };

const BOTON = 'inline-flex items-center justify-center gap-1 min-h-10 px-space-sm rounded-lg font-label-md text-label-md';

/** Formulario de contacto dentro del chat (correo o WhatsApp, con consentimiento). */
function Captura({ recorrido, onListo }: { recorrido: string[]; onListo: () => void }) {
  const [error, setError] = useState<string | null>(null);
  const [enviando, iniciar] = useTransition();
  const id = useId();

  function enviar(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const d = new FormData(e.currentTarget);
    const texto = (k: string) => String(d.get(k) ?? '').trim();
    if ((!texto('email') && !texto('telefono')) || d.get('consentimiento') !== 'on') return setError(t.faltan);
    setError(null);
    iniciar(async () => {
      const r = await registrarLeadChatbot({
        nombre: texto('nombre'),
        email: texto('email'),
        telefono: texto('telefono'),
        consentimiento: true,
        recorrido,
        page_url: location.href.split('#')[0],
        sitio_web: texto('sitio_web'),
      });
      if (!r.ok) return setError(r.error);
      marcarConvertido();
      registrarEvento('form_submit', { source: 'chatbot' });
      onListo();
    });
  }

  const campo = 'h-10 w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 font-body-sm text-body-sm text-on-surface';
  return (
    <form noValidate onSubmit={enviar} className="flex flex-col gap-space-xs" aria-labelledby={`${id}-t`}>
      <span id={`${id}-t`} className="sr-only">
        {t.enviar}
      </span>
      <label className="flex flex-col gap-1 font-label-sm text-label-sm text-secondary">
        {t.nombre}
        <input name="nombre" autoComplete="name" maxLength={120} className={campo} />
      </label>
      <label className="flex flex-col gap-1 font-label-sm text-label-sm text-secondary">
        {t.email}
        <input name="email" type="email" autoComplete="email" maxLength={160} className={campo} />
      </label>
      <label className="flex flex-col gap-1 font-label-sm text-label-sm text-secondary">
        {t.telefono}
        <input name="telefono" type="tel" inputMode="tel" autoComplete="tel" className={campo} />
      </label>
      <label className="sr-only" aria-hidden="true">
        Sitio web
        <input type="text" name="sitio_web" tabIndex={-1} autoComplete="off" />
      </label>
      <label className="flex items-start gap-2 font-label-sm text-label-sm text-on-surface">
        <input type="checkbox" name="consentimiento" className="mt-0.5 w-4 h-4 shrink-0 accent-primary" />
        <span>
          {t.consentimientoAntes}
          <a href="#privacidad" className="text-primary font-semibold underline">
            {t.consentimientoEnlace}
          </a>
          {t.consentimientoDespues}
        </span>
      </label>
      <p role="alert" className="font-label-sm text-label-sm text-error empty:hidden">
        {error}
      </p>
      <button type="submit" disabled={enviando} className={`${BOTON} bg-accion hover:bg-accion-hover text-accion-texto disabled:opacity-60`}>
        {enviando ? t.enviando : t.enviar}
      </button>
    </form>
  );
}

/**
 * EDUN-375 / SIT-008 · Chatbot propio: botón flotante y panel. Modo «flujo» (pasos del CMS:
 * mensajes, opciones, captura de contacto, enlaces y WhatsApp) o modo «whatsapp» (pasa directo a
 * un asesor). Fuera del horario muestra el mensaje del CMS y pide el contacto. Los contactos
 * llegan a la bandeja como source=chatbot con su consentimiento. El modo IA queda para después.
 * EDUN-380: las conversaciones sin contacto se registran (solo el recorrido) para el historial del CMS.
 */
export function ChatbotWidget({ config }: { config: Chatbot }) {
  const [abierto, setAbierto] = useState(false);
  const [lineas, setLineas] = useState<Linea[]>([]);
  const [paso, setPaso] = useState<number | null>(null);
  const [listo, setListo] = useState(false);
  const [atendiendo, setAtendiendo] = useState(true);
  const lista = useRef<HTMLOListElement>(null);
  // EDUN-380 · Una conversación = una sesión del historial (sin datos personales: solo el recorrido).
  const sesion = useRef<{ uuid: string; enviada: boolean } | null>(null);
  const idTitulo = useId();
  const wa = config.whatsapp ? enlaceWhatsapp(config.whatsapp, config.whatsapp_mensaje) : null;

  useEffect(() => {
    lista.current?.lastElementChild?.scrollIntoView({ block: 'end' });
  }, [lineas, paso]);

  /** Muestra un paso; los mensajes sueltos avanzan solos al siguiente. */
  function irA(i: number | null, previas: Linea[]) {
    let actuales = previas;
    let indice = i;
    let seguridad = 0;
    while (indice !== null && config.nodos[indice] && seguridad++ < 40) {
      const n = config.nodos[indice];
      actuales = [...actuales, { de: 'bot', texto: n.texto }];
      if (n.tipo === 'mensaje' && n.siguiente !== null) {
        indice = n.siguiente;
        continue;
      }
      break;
    }
    setLineas(actuales);
    setPaso(indice);
    if (indice !== null && config.nodos[indice]?.tipo === 'fin') registrar('fin', actuales);
  }

  function registrar(resultado: 'whatsapp' | 'url' | 'fin' | 'abandono', actuales: Linea[] = lineas) {
    const s = sesion.current;
    if (!s || s.enviada || listo || actuales.length < 2) return;
    s.enviada = true;
    const datos = { sesion_uuid: s.uuid, resultado, recorrido: actuales.map((l) => `${l.de === 'bot' ? t.bot : t.tu}: ${l.texto}`), page_url: location.href.split('#')[0] };
    // sendBeacon sobrevive a la navegación (al abrir un enlace o WhatsApp); si no existe, la acción del servidor.
    const enviado = typeof navigator !== 'undefined' && 'sendBeacon' in navigator && navigator.sendBeacon('/api/chatbot-sesion', new Blob([JSON.stringify(datos)], { type: 'application/json' }));
    if (!enviado) void registrarSesionChatbot(datos);
  }

  function cerrar() {
    registrar('abandono');
    setAbierto(false);
  }

  function abrir() {
    sesion.current = { uuid: typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : '', enviada: false };
    const ahora = enHorario(config.horario);
    setAtendiendo(ahora);
    setAbierto(true);
    setListo(false);
    registrarEvento('cta_click', { cta_text: 'chatbot', ubicacion: 'chatbot' });
    const inicio: Linea[] = [{ de: 'bot', texto: ahora ? config.saludo : config.fuera_horario }];
    if (ahora && config.modo === 'flujo' && config.nodos.length > 0) irA(0, inicio);
    else {
      setLineas(inicio);
      setPaso(null);
    }
  }

  const nodo = paso !== null ? config.nodos[paso] : null;
  const recorrido = lineas.map((l) => `${l.de === 'bot' ? t.bot : t.tu}: ${l.texto}`);
  const pedirContacto = !listo && (!atendiendo || nodo?.tipo === 'captura' || (config.modo === 'whatsapp' && !wa));

  return (
    <div className="fixed bottom-4 right-4 z-[55] flex flex-col items-end gap-space-sm print:hidden">
      {abierto && (
        <section role="dialog" aria-modal="false" aria-labelledby={idTitulo} className="w-[min(22rem,calc(100vw-2rem))] max-h-[min(34rem,calc(100vh-7rem))] flex flex-col rounded-2xl bg-surface-container-lowest border border-surface-container shadow-xl overflow-hidden">
          <header className="flex items-center justify-between gap-space-sm px-space-md py-space-sm bg-primary text-on-primary">
            <h2 id={idTitulo} className="font-title-md text-title-md">
              {config.titulo}
            </h2>
            <button type="button" onClick={cerrar} aria-label={t.cerrar} className="w-9 h-9 rounded-full flex items-center justify-center hover:bg-white/10">
              <Icono nombre="close" tamano="sm" />
            </button>
          </header>
          <div className="flex-1 overflow-y-auto p-space-md flex flex-col gap-space-sm">
            <ol ref={lista} aria-label={t.conversacion} aria-live="polite" className="flex flex-col gap-space-xs">
              {lineas.map((l, i) => (
                <li key={i} className={`max-w-[85%] px-space-sm py-space-xs rounded-xl font-body-sm text-body-sm whitespace-pre-line ${l.de === 'bot' ? 'self-start bg-surface-container text-on-surface' : 'self-end bg-primary text-on-primary'}`}>
                  <span className="sr-only">{l.de === 'bot' ? t.bot : t.tu}: </span>
                  {l.texto}
                </li>
              ))}
              {listo && <li className="self-start max-w-[85%] px-space-sm py-space-xs rounded-xl bg-surface-container font-body-sm text-body-sm">{t.gracias}</li>}
            </ol>

            {atendiendo && config.modo === 'flujo' && nodo?.tipo === 'opciones' && (
              <div className="flex flex-wrap gap-space-xs">
                {nodo.opciones.map((o) => (
                  <button key={o.texto} type="button" onClick={() => irA(o.destino, [...lineas, { de: 'tu', texto: o.texto }])} className={`${BOTON} border border-primary text-primary hover:bg-primary/5`}>
                    {o.texto}
                  </button>
                ))}
              </div>
            )}
            {atendiendo && nodo?.tipo === 'url' && nodo.url && (
              <a href={nodo.url} onClick={() => registrar('url')} className={`${BOTON} self-start bg-primary text-on-primary`}>
                {t.irPagina}
              </a>
            )}
            {wa && (config.modo === 'whatsapp' || nodo?.tipo === 'whatsapp' || !atendiendo) && !listo && (
              <a href={wa} target="_blank" rel="noopener" onClick={() => registrar('whatsapp')} className={`${BOTON} self-start bg-[#128C7E] text-white`}>
                <Icono nombre="chat" tamano="sm" />
                {t.escribirWhatsapp}
              </a>
            )}
            {pedirContacto && (
              <Captura
                recorrido={recorrido}
                onListo={() => {
                  if (sesion.current) sesion.current.enviada = true; // la sesión ya quedó con el contacto
                  setListo(true);
                }}
              />
            )}
            {atendiendo && config.modo === 'flujo' && (nodo?.tipo === 'fin' || listo) && (
              <button type="button" onClick={abrir} className={`${BOTON} self-start text-primary underline`}>
                {t.reiniciar}
              </button>
            )}
          </div>
        </section>
      )}
      <button
        type="button"
        onClick={() => (abierto ? cerrar() : abrir())}
        aria-expanded={abierto}
        aria-label={abierto ? t.cerrar : t.abrir}
        className="w-14 h-14 rounded-full bg-primary text-on-primary shadow-lg flex items-center justify-center hover:bg-primary-container"
      >
        <Icono nombre={abierto ? 'close' : 'forum'} />
      </button>
    </div>
  );
}
