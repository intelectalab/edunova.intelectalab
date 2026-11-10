'use client';

import { useId, useState } from 'react';
import { Icono, Insignia, Interruptor, TarjetaSeccion, type TonoInsignia } from '@/componentes/base';
import { AvisoBorrador, BotonesOrden, Campo, ErrorLista, Miniatura, editar, mover, useBorrador, type MedioCorto } from '@/componentes/cms/Formulario';
import { SelectorMedios } from '@/componentes/cms/SelectorMedios';
import { cms } from '@/contenido/provisional';

export type TestimonioCms = {
  id: number | null;
  nombre: string | null;
  cargo: string;
  institucion: string;
  avatar_id: number | null;
  texto: string;
  calificacion: number;
  es_ejemplo: boolean;
  consentimiento_obtenido: boolean;
  consentimiento_fecha: string | null;
  consentimiento_doc_id: number | null;
  visible: boolean;
};
export type SeccionTestimoniosCms = { insignia: string; titulo: string; leyenda_ejemplo: string; lista: TestimonioCms[] };

const TEXTOS = ['insignia', 'titulo', 'leyenda_ejemplo'] as const;
const nulo = (v: string) => (v.trim() === '' ? null : v);

/** Un testimonio real solo se publica con consentimiento, fecha y documento (CHECK de la base). */
export function consentimientoCompleto(t: TestimonioCms): boolean {
  return t.consentimiento_obtenido && !!t.consentimiento_fecha && !!t.consentimiento_doc_id;
}

function estado(t: TestimonioCms): { texto: string; tono: TonoInsignia } {
  const c = cms.testimonios;
  if (!t.visible) return { texto: c.estadoOculto, tono: 'neutro' };
  if (t.es_ejemplo) return { texto: c.estadoEjemplo, tono: 'info' };
  return consentimientoCompleto(t) ? { texto: c.estadoPublicable, tono: 'exito' } : { texto: c.estadoFalta, tono: 'error' };
}

const NUEVO: TestimonioCms = {
  id: null,
  nombre: null,
  cargo: '',
  institucion: '',
  avatar_id: null,
  texto: '',
  calificacion: 5,
  es_ejemplo: false,
  consentimiento_obtenido: false,
  consentimiento_fecha: null,
  consentimiento_doc_id: null,
  visible: false,
};

function Estrellas({ valor, onCambio }: { valor: number; onCambio: (n: number) => void }) {
  const t = cms.testimonios.testimonio;
  const id = useId();
  return (
    <div className="flex flex-col gap-space-xs">
      <label htmlFor={id} className="font-label-lg text-label-lg text-on-surface">
        {t.calificacion}
      </label>
      <select id={id} value={valor} onChange={(e) => onCambio(Number(e.target.value))} className="h-11 rounded-lg border border-outline-variant bg-surface-container-lowest px-2 font-body-md text-body-md text-on-surface">
        {[5, 4, 3, 2, 1].map((n) => (
          <option key={n} value={n}>
            {'★'.repeat(n)} {t.estrellas(n)}
          </option>
        ))}
      </select>
    </div>
  );
}

/**
 * EDUN-334 / SIT-024 · Casos de Éxito: testimonios con consentimiento verificado. Un testimonio
 * real no puede quedar visible sin consentimiento firmado, fecha y PDF (la API y la base lo
 * impiden); uno de ejemplo se muestra con su leyenda y sin nombre de persona.
 */
export function EditorTestimonios({ inicial, medios, conBorrador }: { inicial: SeccionTestimoniosCms; medios: Record<string, MedioCorto>; conBorrador: boolean }) {
  const c = cms.testimonios;
  const { valores, cambiar, errores } = useBorrador({ testimonios: inicial });
  const [conocidos, setConocidos] = useState<Record<string, MedioCorto>>(medios);
  // Clave estable de cada fila (los nuevos aún no tienen id).
  const [llaves, setLlaves] = useState<string[]>(() => inicial.lista.map((x, i) => (x.id ? `t${x.id}` : `n${i}`)));
  const v = valores.testimonios as SeccionTestimoniosCms;
  const e = errores.testimonios ?? {};
  const set = (cambios: Partial<SeccionTestimoniosCms>) => cambiar('testimonios', (x) => ({ ...x, ...cambios }));
  const setT = (i: number, cambios: Partial<TestimonioCms>) => set({ lista: editar(v.lista, i, cambios) });
  const conocer = (m: MedioCorto) => setConocidos((x) => ({ ...x, [m.id]: m }));

  function agregar() {
    set({ lista: [...v.lista, NUEVO] });
    setLlaves((l) => [...l, `n${Date.now()}`]);
  }
  function quitar(i: number) {
    if (!window.confirm(c.confirmarEliminar)) return;
    set({ lista: v.lista.filter((_, k) => k !== i) });
    setLlaves((l) => l.filter((_, k) => k !== i));
  }
  function moverFila(i: number, paso: -1 | 1) {
    set({ lista: mover(v.lista, i, paso) });
    setLlaves((l) => mover(l, i, paso));
  }

  return (
    <div className="flex flex-col gap-space-md">
      <AvisoBorrador visible={conBorrador}>{cms.hero.borradorPendiente}</AvisoBorrador>
      {Object.keys(e).length > 0 && <ErrorLista error={e._ ?? cms.lista.errores} />}

      <TarjetaSeccion icono="title" titulo={cms.lista.textos} descripcion={c.descripcion}>
        <div className="grid gap-space-md md:grid-cols-3">
          {TEXTOS.map((k) => (
            <Campo key={k} etiqueta={c.textos[k].etiqueta} max={c.textos[k].max} valor={v[k] ?? ''} error={e[k]} onCambio={(x) => set({ [k]: x })} />
          ))}
        </div>
      </TarjetaSeccion>

      <TarjetaSeccion icono="reviews" titulo={c.listaTitulo}>
        <ErrorLista error={e.lista} />
        {v.lista.length === 0 && <p className="font-body-md text-body-md text-on-surface-variant">{c.vacio}</p>}
        <ol className="flex flex-col gap-space-md">
          {v.lista.map((t, i) => {
            const err = (k: string) => e[`lista.${i}.${k}`];
            const titulo = t.institucion || c.nuevo;
            const est = estado(t);
            const bloqueado = !t.es_ejemplo && !consentimientoCompleto(t);
            return (
              <li key={llaves[i] ?? i} data-testimonio={t.id ?? 'nuevo'} className="p-space-md rounded-xl border border-surface-container flex flex-col gap-space-md">
                <div className="flex flex-wrap items-center justify-between gap-space-sm">
                  <h3 className="font-title-md text-title-md text-primary flex flex-wrap items-center gap-space-xs">
                    {titulo}
                    <Insignia tono={est.tono}>{est.texto}</Insignia>
                  </h3>
                  <span className="flex flex-wrap items-center gap-space-md">
                    <span title={bloqueado && !t.visible ? c.estadoFalta : undefined}>
                      <Interruptor etiqueta={cms.lista.visible} activo={t.visible} deshabilitado={bloqueado && !t.visible} onCambio={(x) => setT(i, { visible: x })} />
                    </span>
                    <BotonesOrden nombre={titulo} i={i} total={v.lista.length} onMover={(paso) => moverFila(i, paso)} />
                    <button type="button" aria-label={cms.lista.eliminar(titulo)} onClick={() => quitar(i)} className="w-9 h-9 rounded-lg flex items-center justify-center text-error hover:bg-error-container">
                      <Icono nombre="delete" tamano="sm" />
                    </button>
                  </span>
                </div>
                {err('visible') && <span className="font-label-sm text-label-sm text-error">{err('visible')}</span>}

                <div className="grid gap-space-md md:grid-cols-2 xl:grid-cols-3">
                  <Campo etiqueta={c.testimonio.cargo.etiqueta} max={c.testimonio.cargo.max} valor={t.cargo} error={err('cargo')} onCambio={(x) => setT(i, { cargo: x })} />
                  <Campo etiqueta={c.testimonio.institucion.etiqueta} max={c.testimonio.institucion.max} valor={t.institucion} error={err('institucion')} onCambio={(x) => setT(i, { institucion: x })} />
                  <Estrellas valor={t.calificacion} onCambio={(n) => setT(i, { calificacion: n })} />
                  <div className="md:col-span-2 xl:col-span-3">
                    <Campo etiqueta={c.testimonio.texto.etiqueta} max={c.testimonio.texto.max} filas={3} valor={t.texto} error={err('texto')} onCambio={(x) => setT(i, { texto: x })} />
                  </div>
                </div>

                <div className="flex flex-col gap-space-xs">
                  <Interruptor
                    etiqueta={c.testimonio.esEjemplo}
                    activo={t.es_ejemplo}
                    onCambio={(x) => setT(i, { es_ejemplo: x, nombre: x ? null : t.nombre, visible: !x && !consentimientoCompleto(t) ? false : t.visible })}
                  />
                  <span className="font-label-sm text-label-sm text-secondary">{c.testimonio.esEjemploAyuda}</span>
                </div>

                {!t.es_ejemplo && (
                  <fieldset className="flex flex-col gap-space-md p-space-md rounded-lg bg-surface-container-low">
                    <legend className="px-1 font-label-lg text-label-lg text-on-surface">{c.consentimiento}</legend>
                    <div className="grid gap-space-md md:grid-cols-2">
                      <Campo etiqueta={c.testimonio.nombre.etiqueta} max={c.testimonio.nombre.max} valor={t.nombre ?? ''} error={err('nombre')} onCambio={(x) => setT(i, { nombre: nulo(x) })} />
                      <div className="flex flex-col gap-space-xs">
                        <span className="font-label-lg text-label-lg text-on-surface">{c.testimonio.avatar}</span>
                        <Miniatura medio={t.avatar_id ? conocidos[t.avatar_id] : undefined} onQuitar={t.avatar_id ? () => setT(i, { avatar_id: null }) : undefined} />
                        <SelectorMedios
                          etiqueta={cms.medios.abrirSelector}
                          onElegir={(m) => {
                            conocer(m);
                            setT(i, { avatar_id: m.id });
                          }}
                        />
                        {err('avatar_id') && <span className="font-label-sm text-label-sm text-error">{err('avatar_id')}</span>}
                      </div>
                    </div>
                    <label className="flex items-center gap-space-sm font-label-lg text-label-lg text-on-surface">
                      <input
                        type="checkbox"
                        checked={t.consentimiento_obtenido}
                        aria-invalid={err('consentimiento_obtenido') ? true : undefined}
                        onChange={(ev) => setT(i, { consentimiento_obtenido: ev.target.checked, visible: ev.target.checked ? t.visible : false })}
                        className="w-4 h-4 accent-primary"
                      />
                      {c.consentimientoObtenido}
                    </label>
                    {err('consentimiento_obtenido') && <span className="font-label-sm text-label-sm text-error">{err('consentimiento_obtenido')}</span>}
                    <div className="grid gap-space-md md:grid-cols-2">
                      <Campo
                        etiqueta={c.consentimientoFecha}
                        tipo="date"
                        valor={t.consentimiento_fecha ?? ''}
                        error={err('consentimiento_fecha')}
                        onCambio={(x) => setT(i, { consentimiento_fecha: nulo(x), visible: x ? t.visible : false })}
                      />
                      <div className="flex flex-col gap-space-xs">
                        <span className="font-label-lg text-label-lg text-on-surface">{c.consentimientoDoc}</span>
                        <Miniatura
                          medio={t.consentimiento_doc_id ? conocidos[t.consentimiento_doc_id] : undefined}
                          onQuitar={t.consentimiento_doc_id ? () => setT(i, { consentimiento_doc_id: null, visible: false }) : undefined}
                        />
                        <SelectorMedios
                          etiqueta={cms.medios.abrirSelector}
                          tipo="pdf"
                          onElegir={(m) => {
                            conocer(m);
                            setT(i, { consentimiento_doc_id: m.id });
                          }}
                        />
                        {err('consentimiento_doc_id') && <span className="font-label-sm text-label-sm text-error">{err('consentimiento_doc_id')}</span>}
                      </div>
                    </div>
                  </fieldset>
                )}
              </li>
            );
          })}
        </ol>
        <button
          type="button"
          onClick={agregar}
          className="self-start inline-flex items-center gap-1 h-10 px-space-md rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-lg text-label-lg"
        >
          <Icono nombre="add" tamano="sm" />
          {c.agregar}
        </button>
      </TarjetaSeccion>
    </div>
  );
}
