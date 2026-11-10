'use client';

import { useState } from 'react';
import { Icono, Interruptor, TarjetaSeccion } from '@/componentes/base';
import { AvisoBorrador, BotonesOrden, Campo, ErrorLista, editar, mover, useBorrador } from '@/componentes/cms/Formulario';
import { cms } from '@/contenido/provisional';

export type PreguntaCms = { id: number | null; pregunta: string; respuesta: string; categoria: string | null; visible: boolean };
export type FaqCms = { lista: PreguntaCms[] };

/** EDUN-371 / SIT-007 · Gestión de las preguntas frecuentes (borrador → publicar). */
export function EditorFaq({ inicial, conBorrador }: { inicial: FaqCms; conBorrador: boolean }) {
  const t = cms.faqCms;
  const { valores, cambiar, errores } = useBorrador({ _faq: inicial });
  const [llaves, setLlaves] = useState(() => inicial.lista.map((p, i) => (p.id ? `p${p.id}` : `n${i}`)));
  const lista = (valores._faq as FaqCms).lista;
  const e = errores._faq ?? {};
  const set = (l: PreguntaCms[]) => cambiar('_faq', () => ({ lista: l }));
  const categorias = [...new Set(lista.map((p) => p.categoria).filter(Boolean))] as string[];

  function agregar() {
    set([...lista, { id: null, pregunta: '', respuesta: '', categoria: null, visible: true }]);
    setLlaves((l) => [...l, `n${Date.now()}`]);
  }
  function quitar(i: number) {
    if (!window.confirm(t.confirmarEliminar)) return;
    set(lista.filter((_, k) => k !== i));
    setLlaves((l) => l.filter((_, k) => k !== i));
  }

  return (
    <div className="flex flex-col gap-space-md">
      <AvisoBorrador visible={conBorrador}>{cms.hero.borradorPendiente}</AvisoBorrador>
      {Object.keys(e).length > 0 && <ErrorLista error={e._ ?? cms.lista.errores} />}
      <TarjetaSeccion icono="quiz" titulo={t.lista} descripcion={t.descripcion}>
        {lista.length === 0 && <p className="font-body-md text-body-md text-on-surface-variant">{t.vacio}</p>}
        <datalist id="categorias-faq">
          {categorias.map((c) => (
            <option key={c} value={c} />
          ))}
        </datalist>
        <ol className="flex flex-col gap-space-md">
          {lista.map((p, i) => {
            const err = (k: string) => e[`lista.${i}.${k}`];
            const nombre = p.pregunta || t.nueva;
            return (
              <li key={llaves[i] ?? i} data-pregunta={p.id ?? 'nueva'} className="p-space-md rounded-xl border border-surface-container flex flex-col gap-space-sm">
                <div className="flex flex-wrap items-center justify-between gap-space-sm">
                  <h3 className="font-title-md text-title-md text-primary line-clamp-1">{nombre}</h3>
                  <span className="flex flex-wrap items-center gap-space-md">
                    <Interruptor etiqueta={cms.lista.visible} activo={p.visible} onCambio={(x) => set(editar(lista, i, { visible: x }))} />
                    <BotonesOrden
                      nombre={nombre}
                      i={i}
                      total={lista.length}
                      onMover={(paso) => {
                        set(mover(lista, i, paso));
                        setLlaves((l) => mover(l, i, paso));
                      }}
                    />
                    <button type="button" aria-label={cms.lista.eliminar(nombre)} onClick={() => quitar(i)} className="w-9 h-9 rounded-lg flex items-center justify-center text-error hover:bg-error-container">
                      <Icono nombre="delete" tamano="sm" />
                    </button>
                  </span>
                </div>
                <div className="grid gap-space-md md:grid-cols-[1fr_16rem]">
                  <Campo etiqueta={t.pregunta.etiqueta} max={t.pregunta.max} valor={p.pregunta} error={err('pregunta')} onCambio={(x) => set(editar(lista, i, { pregunta: x }))} />
                  <div className="flex flex-col gap-space-xs">
                    <label htmlFor={`cat-${llaves[i]}`} className="font-label-lg text-label-lg text-on-surface">
                      {t.categoria.etiqueta}
                    </label>
                    <input
                      id={`cat-${llaves[i]}`}
                      list="categorias-faq"
                      maxLength={t.categoria.max}
                      value={p.categoria ?? ''}
                      onChange={(ev) => set(editar(lista, i, { categoria: ev.target.value || null }))}
                      className="w-full px-space-md py-space-sm rounded-lg border border-outline-variant bg-surface-container-lowest font-body-md text-body-md text-on-surface"
                    />
                  </div>
                </div>
                <Campo etiqueta={t.respuesta.etiqueta} max={t.respuesta.max} filas={4} valor={p.respuesta} error={err('respuesta')} onCambio={(x) => set(editar(lista, i, { respuesta: x }))} />
              </li>
            );
          })}
        </ol>
        <button type="button" onClick={agregar} className="self-start inline-flex items-center gap-1 h-10 px-space-md rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-lg text-label-lg">
          <Icono nombre="add" tamano="sm" />
          {t.agregar}
        </button>
      </TarjetaSeccion>
    </div>
  );
}
