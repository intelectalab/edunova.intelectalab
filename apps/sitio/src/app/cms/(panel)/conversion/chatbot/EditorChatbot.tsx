'use client';

import { useId } from 'react';
import { Icono, Interruptor, TarjetaSeccion } from '@/componentes/base';
import { AvisoBorrador, Campo, ErrorLista, editar, useBorrador } from '@/componentes/cms/Formulario';
import { cms } from '@/contenido/provisional';
import type { NodoChatbot } from '@/lib/landing';

type Nodo = Omit<NodoChatbot, 'url'> & { url: string | null };
export type ChatbotCms = {
  activo: boolean;
  modo: 'flujo' | 'whatsapp';
  titulo: string;
  saludo: string;
  fuera_horario: string;
  whatsapp_mensaje: string | null;
  horario: { dias: number[]; desde: string; hasta: string };
  nodos: Nodo[];
};

const SELECT = 'h-11 rounded-lg border border-outline-variant bg-surface-container-lowest px-2 font-body-md text-body-md text-on-surface';

function Selector({ etiqueta, valor, opciones, onCambio }: { etiqueta: string; valor: string; opciones: [string, string][]; onCambio: (v: string) => void }) {
  const id = useId();
  return (
    <div className="flex flex-col gap-space-xs min-w-0">
      <label htmlFor={id} className="font-label-lg text-label-lg text-on-surface">
        {etiqueta}
      </label>
      <select id={id} value={valor} onChange={(e) => onCambio(e.target.value)} className={SELECT}>
        {opciones.map(([k, v]) => (
          <option key={k} value={k}>
            {v}
          </option>
        ))}
      </select>
    </div>
  );
}

/**
 * EDUN-375 / SIT-008 · Configuración del chatbot: activo, modo (flujo o WhatsApp), textos,
 * horario de atención y los pasos del flujo (borrador → publicar).
 */
export function EditorChatbot({ inicial, conBorrador }: { inicial: ChatbotCms; conBorrador: boolean }) {
  const t = cms.chatbotCms;
  const { valores, cambiar, errores } = useBorrador({ _chatbot: inicial });
  const c = valores._chatbot as ChatbotCms;
  const e = errores._chatbot ?? {};
  const set = (x: Partial<ChatbotCms>) => cambiar('_chatbot', (v) => ({ ...v, ...x }));
  const setNodo = (i: number, x: Partial<Nodo>) => set({ nodos: editar(c.nodos, i, x) });
  const pasos: [string, string][] = c.nodos.map((_, i) => [String(i), t.paso(i + 1)]);

  function quitarPaso(i: number) {
    // Los enlaces a pasos posteriores se corren una posición; los que apuntaban al quitado se sueltan.
    const ajustar = (d: number | null) => (d === null || d === i ? null : d > i ? d - 1 : d);
    set({
      nodos: c.nodos
        .filter((_, k) => k !== i)
        .map((n) => ({ ...n, siguiente: ajustar(n.siguiente), opciones: n.opciones.map((o) => ({ ...o, destino: ajustar(o.destino) ?? 0 })) })),
    });
  }

  return (
    <div className="flex flex-col gap-space-md">
      <AvisoBorrador visible={conBorrador}>{cms.hero.borradorPendiente}</AvisoBorrador>
      {Object.keys(e).length > 0 && <ErrorLista error={e.modo ?? e.nodos ?? e._ ?? cms.lista.errores} />}

      <TarjetaSeccion icono="forum" titulo={t.general} descripcion={t.descripcion}>
        <Interruptor etiqueta={t.activo} activo={c.activo} onCambio={(x) => set({ activo: x })} />
        <div className="grid gap-space-md md:grid-cols-2">
          <Selector etiqueta={t.modo} valor={c.modo} opciones={Object.entries(t.modos)} onCambio={(v) => set({ modo: v as ChatbotCms['modo'] })} />
          <Campo etiqueta={t.tituloCampo.etiqueta} max={t.tituloCampo.max} valor={c.titulo} error={e.titulo} onCambio={(x) => set({ titulo: x })} />
          <Campo etiqueta={t.saludo.etiqueta} max={t.saludo.max} filas={2} valor={c.saludo} error={e.saludo} onCambio={(x) => set({ saludo: x })} />
          <Campo etiqueta={t.fueraHorario.etiqueta} max={t.fueraHorario.max} filas={2} valor={c.fuera_horario} error={e.fuera_horario} onCambio={(x) => set({ fuera_horario: x })} />
          <Campo etiqueta={t.whatsappMensaje.etiqueta} max={t.whatsappMensaje.max} ayuda={t.whatsappNota} valor={c.whatsapp_mensaje ?? ''} error={e.whatsapp_mensaje} onCambio={(x) => set({ whatsapp_mensaje: x || null })} />
        </div>
        <fieldset className="flex flex-col gap-space-sm">
          <legend className="font-label-lg text-label-lg text-on-surface mb-space-xs">{t.horario}</legend>
          <div className="flex flex-wrap gap-space-sm">
            {t.dias.map((d, i) => (
              <label key={d} className="inline-flex items-center gap-1 font-label-md text-label-md text-on-surface">
                <input
                  type="checkbox"
                  checked={c.horario.dias.includes(i + 1)}
                  onChange={(ev) => set({ horario: { ...c.horario, dias: ev.target.checked ? [...c.horario.dias, i + 1].sort() : c.horario.dias.filter((x) => x !== i + 1) } })}
                  className="w-4 h-4 accent-primary"
                />
                {d}
              </label>
            ))}
          </div>
          <div className="grid grid-cols-2 gap-space-md max-w-sm">
            <Campo etiqueta={t.desde} tipo="time" valor={c.horario.desde} error={e['horario.desde']} onCambio={(x) => set({ horario: { ...c.horario, desde: x } })} />
            <Campo etiqueta={t.hasta} tipo="time" valor={c.horario.hasta} error={e['horario.hasta']} onCambio={(x) => set({ horario: { ...c.horario, hasta: x } })} />
          </div>
          {(e['horario.dias'] || e['horario']) && <span className="font-label-sm text-label-sm text-error">{e['horario.dias'] ?? e['horario']}</span>}
        </fieldset>
      </TarjetaSeccion>

      {c.modo === 'flujo' && (
        <TarjetaSeccion icono="account_tree" titulo={t.flujo} descripcion={t.flujoNota}>
          <ol className="flex flex-col gap-space-md">
            {c.nodos.map((n, i) => {
              const err = (k: string) => e[`nodos.${i}.${k}`];
              return (
                <li key={i} data-paso={i + 1} className="p-space-md rounded-xl border border-surface-container flex flex-col gap-space-sm">
                  <div className="flex items-center justify-between gap-space-sm">
                    <h3 className="font-title-md text-title-md text-primary">{t.paso(i + 1)}</h3>
                    <button type="button" aria-label={t.quitarPaso(i + 1)} onClick={() => quitarPaso(i)} className="w-9 h-9 rounded-lg flex items-center justify-center text-error hover:bg-error-container">
                      <Icono nombre="delete" tamano="sm" />
                    </button>
                  </div>
                  <div className="grid gap-space-md md:grid-cols-[14rem_1fr]">
                    <Selector etiqueta={t.tipo} valor={n.tipo} opciones={Object.entries(t.tipos)} onCambio={(v) => setNodo(i, { tipo: v as Nodo['tipo'], opciones: v === 'opciones' ? (n.opciones.length ? n.opciones : [{ texto: '', destino: 0 }]) : [] })} />
                    <Campo etiqueta={t.texto.etiqueta} max={t.texto.max} filas={2} valor={n.texto} error={err('texto')} onCambio={(x) => setNodo(i, { texto: x })} />
                  </div>
                  {n.tipo === 'url' && <Campo etiqueta={t.url} valor={n.url ?? ''} error={err('url')} onCambio={(x) => setNodo(i, { url: x || null })} />}
                  {n.tipo === 'mensaje' && (
                    <Selector etiqueta={t.siguiente} valor={n.siguiente === null ? '' : String(n.siguiente)} opciones={[['', t.ninguno], ...pasos.filter(([k]) => k !== String(i))]} onCambio={(v) => setNodo(i, { siguiente: v === '' ? null : Number(v) })} />
                  )}
                  {n.tipo === 'opciones' && (
                    <fieldset className="flex flex-col gap-space-sm">
                      <legend className="font-label-lg text-label-lg text-on-surface mb-space-xs">{t.opciones}</legend>
                      {err('opciones') && <span className="font-label-sm text-label-sm text-error">{err('opciones')}</span>}
                      {n.opciones.map((o, k) => (
                        <div key={k} className="grid gap-space-sm md:grid-cols-[1fr_12rem_auto] items-end">
                          <Campo etiqueta={t.opcion(k + 1)} max={60} valor={o.texto} error={err(`opciones.${k}.texto`)} onCambio={(x) => setNodo(i, { opciones: editar(n.opciones, k, { texto: x }) })} />
                          <Selector etiqueta={t.destino} valor={String(o.destino)} opciones={pasos} onCambio={(v) => setNodo(i, { opciones: editar(n.opciones, k, { destino: Number(v) }) })} />
                          <button type="button" aria-label={t.quitarOpcion(k + 1)} onClick={() => setNodo(i, { opciones: n.opciones.filter((_, j) => j !== k) })} className="mb-1 w-9 h-9 rounded-lg flex items-center justify-center text-error hover:bg-error-container">
                            <Icono nombre="delete" tamano="sm" />
                          </button>
                        </div>
                      ))}
                      {n.opciones.length < 6 && (
                        <button type="button" onClick={() => setNodo(i, { opciones: [...n.opciones, { texto: '', destino: 0 }] })} className="self-start inline-flex items-center gap-1 h-9 px-space-sm rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-md text-label-md">
                          <Icono nombre="add" tamano="sm" />
                          {t.agregarOpcion}
                        </button>
                      )}
                    </fieldset>
                  )}
                </li>
              );
            })}
          </ol>
          {c.nodos.length < 40 && (
            <button
              type="button"
              onClick={() => set({ nodos: [...c.nodos, { tipo: 'mensaje', texto: '', url: null, siguiente: null, opciones: [] }] })}
              className="self-start inline-flex items-center gap-1 h-10 px-space-md rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-lg text-label-lg"
            >
              <Icono nombre="add" tamano="sm" />
              {t.agregarPaso}
            </button>
          )}
        </TarjetaSeccion>
      )}
    </div>
  );
}
