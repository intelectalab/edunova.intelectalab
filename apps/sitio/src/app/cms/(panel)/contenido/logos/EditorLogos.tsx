'use client';

import { useState } from 'react';
import { Icono, Interruptor, TarjetaSeccion } from '@/componentes/base';
import { AvisoBorrador, BotonesOrden, Campo, ErrorLista, Miniatura, editar, mover, useBorrador, type MedioCorto } from '@/componentes/cms/Formulario';
import { SelectorMedios } from '@/componentes/cms/SelectorMedios';
import { cms } from '@/contenido/provisional';

export type LogoCms = { id: number | null; nombre: string; logo_id: number | null; sitio_web: string | null; autorizado: boolean; visible: boolean };
export type SeccionLogosCms = { titulo: string; velocidad: number; columnas: number; mostrar: boolean; lista: LogoCms[] };

/**
 * EDUN-338 / SIT-060 · Logos de clientes («Confían en Edunova»). Un logo solo se muestra con
 * autorización de uso (la API y el CHECK de cms.clientes lo impiden) y la sección no se puede
 * mostrar sin logos visibles.
 */
export function EditorLogos({ inicial, medios, conBorrador }: { inicial: SeccionLogosCms; medios: Record<string, MedioCorto>; conBorrador: boolean }) {
  const t = cms.logos;
  const { valores, cambiar, errores } = useBorrador({ logos: inicial });
  const [conocidos, setConocidos] = useState(medios);
  const [llaves, setLlaves] = useState(() => inicial.lista.map((l, i) => (l.id ? `l${l.id}` : `n${i}`)));
  const v = valores.logos as SeccionLogosCms;
  const e = errores.logos ?? {};
  const set = (c: Partial<SeccionLogosCms>) => cambiar('logos', (x) => ({ ...x, ...c }));
  const visibles = v.lista.filter((l) => l.visible && l.autorizado).length;
  const setL = (i: number, c: Partial<LogoCms>) => {
    const lista = editar(v.lista, i, c);
    const quedan = lista.filter((l) => l.visible && l.autorizado).length;
    set({ lista, mostrar: quedan === 0 ? false : v.mostrar });
  };

  function agregar() {
    set({ lista: [...v.lista, { id: null, nombre: '', logo_id: null, sitio_web: null, autorizado: false, visible: false }] });
    setLlaves((l) => [...l, `n${Date.now()}`]);
  }
  function quitar(i: number) {
    if (!window.confirm(t.confirmarEliminar)) return;
    const lista = v.lista.filter((_, k) => k !== i);
    set({ lista, mostrar: lista.some((l) => l.visible && l.autorizado) ? v.mostrar : false });
    setLlaves((l) => l.filter((_, k) => k !== i));
  }

  return (
    <div className="flex flex-col gap-space-md">
      <AvisoBorrador visible={conBorrador}>{cms.hero.borradorPendiente}</AvisoBorrador>
      {Object.keys(e).length > 0 && <ErrorLista error={e._ ?? cms.lista.errores} />}

      <TarjetaSeccion icono="handshake" titulo={t.seccion} descripcion={t.descripcion}>
        <span title={visibles === 0 ? t.mostrarDeshabilitado : undefined} className="self-start">
          <Interruptor etiqueta={t.mostrar} activo={v.mostrar && visibles > 0} deshabilitado={visibles === 0} onCambio={(x) => set({ mostrar: x })} />
        </span>
        {visibles === 0 && <p className="font-label-md text-label-md text-on-surface-variant">{t.mostrarDeshabilitado}</p>}
        {e.mostrar && <span className="font-label-sm text-label-sm text-error">{e.mostrar}</span>}
        <div className="grid gap-space-md md:grid-cols-3">
          <Campo etiqueta={t.tituloCampo.etiqueta} max={t.tituloCampo.max} valor={v.titulo} error={e.titulo} onCambio={(x) => set({ titulo: x })} />
          <Campo etiqueta={t.velocidad} tipo="number" inputMode="numeric" valor={String(v.velocidad)} error={e.velocidad} onCambio={(x) => set({ velocidad: Math.round(Number(x) || 0) })} />
          <Campo etiqueta={t.columnas} tipo="number" inputMode="numeric" valor={String(v.columnas)} error={e.columnas} onCambio={(x) => set({ columnas: Math.round(Number(x) || 0) })} />
        </div>
      </TarjetaSeccion>

      <TarjetaSeccion icono="image" titulo={t.lista}>
        <ErrorLista error={e.lista} />
        {v.lista.length === 0 && <p className="font-body-md text-body-md text-on-surface-variant">{t.vacio}</p>}
        <ol className="flex flex-col gap-space-md">
          {v.lista.map((l, i) => {
            const err = (k: string) => e[`lista.${i}.${k}`];
            const nombre = l.nombre || t.nuevo;
            return (
              <li key={llaves[i] ?? i} data-logo={l.id ?? 'nuevo'} className="p-space-md rounded-xl border border-surface-container flex flex-col gap-space-sm">
                <div className="flex flex-wrap items-center justify-between gap-space-sm">
                  <h3 className="font-title-md text-title-md text-primary">{nombre}</h3>
                  <span className="flex flex-wrap items-center gap-space-md">
                    <span title={!l.autorizado ? t.autorizado : undefined}>
                      <Interruptor etiqueta={cms.lista.visible} activo={l.visible && l.autorizado} deshabilitado={!l.autorizado} onCambio={(x) => setL(i, { visible: x })} />
                    </span>
                    <BotonesOrden
                      nombre={nombre}
                      i={i}
                      total={v.lista.length}
                      onMover={(paso) => {
                        set({ lista: mover(v.lista, i, paso) });
                        setLlaves((k) => mover(k, i, paso));
                      }}
                    />
                    <button type="button" aria-label={cms.lista.eliminar(nombre)} onClick={() => quitar(i)} className="w-9 h-9 rounded-lg flex items-center justify-center text-error hover:bg-error-container">
                      <Icono nombre="delete" tamano="sm" />
                    </button>
                  </span>
                </div>
                {err('visible') && <span className="font-label-sm text-label-sm text-error">{err('visible')}</span>}
                <div className="grid gap-space-md md:grid-cols-2">
                  <Campo etiqueta={t.nombre.etiqueta} max={t.nombre.max} valor={l.nombre} error={err('nombre')} onCambio={(x) => setL(i, { nombre: x })} />
                  <Campo etiqueta={t.sitioWeb.etiqueta} max={t.sitioWeb.max} valor={l.sitio_web ?? ''} error={err('sitio_web')} onCambio={(x) => setL(i, { sitio_web: x.trim() === '' ? null : x.trim() })} />
                  <div className="flex flex-col gap-space-xs">
                    <span className="font-label-lg text-label-lg text-on-surface">{t.logo}</span>
                    <Miniatura medio={l.logo_id ? conocidos[l.logo_id] : undefined} />
                    <SelectorMedios
                      etiqueta={cms.medios.abrirSelector}
                      onElegir={(m) => {
                        setConocidos((x) => ({ ...x, [m.id]: m }));
                        setL(i, { logo_id: m.id });
                      }}
                    />
                    {err('logo_id') && <span className="font-label-sm text-label-sm text-error">{err('logo_id')}</span>}
                  </div>
                  <label className="flex items-center gap-space-sm font-label-lg text-label-lg text-on-surface self-start md:pt-8">
                    <input type="checkbox" checked={l.autorizado} onChange={(ev) => setL(i, { autorizado: ev.target.checked, visible: ev.target.checked ? l.visible : false })} className="w-4 h-4 accent-primary" />
                    {t.autorizado}
                  </label>
                </div>
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
