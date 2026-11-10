'use client';

import { useState } from 'react';
import { Icono, Insignia, Interruptor, TarjetaSeccion } from '@/componentes/base';
import { AvisoBorrador, BotonesOrden, Campo, ErrorLista, Miniatura, editar, mover, useBorrador, type MedioCorto } from '@/componentes/cms/Formulario';
import { SelectorMedios } from '@/componentes/cms/SelectorMedios';
import { cms } from '@/contenido/provisional';

export type ModuloCms = {
  clave: string;
  nombre: string;
  icono: string;
  descripcion: string;
  etiqueta: string | null;
  imagen_id: number | null;
  en_grid: boolean;
  visible: boolean;
};
export type SeccionModulosCms = {
  insignia: string;
  titulo: string;
  descripcion: string | null;
  boton_ver: string;
  boton_ocultar: string;
  lista: ModuloCms[];
};

const TEXTOS = ['insignia', 'titulo', 'descripcion', 'boton_ver', 'boton_ocultar'] as const;
export const MAX_GRID = 6;
const nulo = (v: string) => (v.trim() === '' ? null : v);

/**
 * EDUN-330 / SIT-059 · Módulos Web: orden de los módulos, textos, ícono, imagen de la
 * Biblioteca, visibilidad y los 6 del grid principal (la base también limita a 6).
 */
export function EditorModulos({ inicial, medios, conBorrador }: { inicial: SeccionModulosCms; medios: Record<string, MedioCorto>; conBorrador: boolean }) {
  const t = cms.modulos;
  const { valores, cambiar, errores } = useBorrador({ modulos: inicial });
  const [conocidos, setConocidos] = useState<Record<string, MedioCorto>>(medios);
  const v = valores.modulos as SeccionModulosCms;
  const e = errores.modulos ?? {};
  const set = (cambios: Partial<SeccionModulosCms>) => cambiar('modulos', (x) => ({ ...x, ...cambios }));
  const setModulo = (i: number, cambios: Partial<ModuloCms>) => set({ lista: editar(v.lista, i, cambios) });
  const enGrid = v.lista.filter((m) => m.en_grid).length;

  return (
    <div className="flex flex-col gap-space-md">
      <AvisoBorrador visible={conBorrador}>{cms.hero.borradorPendiente}</AvisoBorrador>
      {Object.keys(e).length > 0 && <ErrorLista error={e._ ?? cms.lista.errores} />}

      <TarjetaSeccion icono="title" titulo={cms.lista.textos} descripcion={t.descripcion}>
        <div className="grid gap-space-md md:grid-cols-2">
          {TEXTOS.map((c) => (
            <Campo key={c} etiqueta={t.textos[c].etiqueta} max={t.textos[c].max} valor={v[c] ?? ''} error={e[c]} onCambio={(x) => set({ [c]: c === 'descripcion' ? nulo(x) : x })} />
          ))}
        </div>
      </TarjetaSeccion>

      <TarjetaSeccion icono="widgets" titulo={t.listaTitulo}>
        <p aria-live="polite" className="font-label-lg text-label-lg text-primary" data-contador-grid>
          {t.grid(enGrid, MAX_GRID)}
        </p>
        <ErrorLista error={e.lista} />
        <ol className="flex flex-col gap-space-sm">
          {v.lista.map((m, i) => {
            const err = (c: string) => e[`lista.${i}.${c}`];
            const lleno = !m.en_grid && enGrid >= MAX_GRID;
            return (
              <li key={m.clave} data-modulo={m.clave} className={`p-space-md rounded-xl border flex flex-col gap-space-sm ${m.visible ? 'border-surface-container' : 'border-dashed border-outline-variant opacity-80'}`}>
                <div className="flex flex-wrap items-center justify-between gap-space-sm">
                  <h3 className="font-title-md text-title-md text-primary flex items-center gap-space-xs">
                    <span className="w-6 text-right font-label-md text-label-md text-secondary">{i + 1}.</span>
                    <Icono nombre={/^[a-z0-9_]{2,60}$/.test(m.icono) ? m.icono : 'help'} />
                    {m.nombre}
                    {m.en_grid && <Insignia tono="exito">{t.enGrid}</Insignia>}
                    {!m.visible && <Insignia tono="neutro">{cms.lista.oculto}</Insignia>}
                  </h3>
                  <span className="flex flex-wrap items-center gap-space-md">
                    <span title={lleno ? t.gridLleno(MAX_GRID) : undefined}>
                      <Interruptor etiqueta={t.enGrid} activo={m.en_grid} deshabilitado={lleno} onCambio={(x) => setModulo(i, { en_grid: x, visible: x ? true : m.visible })} />
                    </span>
                    <Interruptor etiqueta={cms.lista.visible} activo={m.visible} onCambio={(x) => setModulo(i, { visible: x, en_grid: x ? m.en_grid : false })} />
                    <BotonesOrden nombre={m.nombre} i={i} total={v.lista.length} onMover={(paso) => set({ lista: mover(v.lista, i, paso) })} />
                  </span>
                </div>
                <details className="group">
                  <summary className="cursor-pointer font-label-md text-label-md text-primary">{t.editar}</summary>
                  <div className="grid gap-space-md md:grid-cols-2 pt-space-sm">
                    <Campo etiqueta={t.modulo.nombre.etiqueta} max={t.modulo.nombre.max} valor={m.nombre} error={err('nombre')} onCambio={(x) => setModulo(i, { nombre: x })} />
                    <Campo etiqueta={t.modulo.icono.etiqueta} max={t.modulo.icono.max} valor={m.icono} error={err('icono')} onCambio={(x) => setModulo(i, { icono: x.trim().toLowerCase() })} />
                    <div className="md:col-span-2">
                      <Campo etiqueta={t.modulo.descripcion.etiqueta} max={t.modulo.descripcion.max} filas={2} valor={m.descripcion} error={err('descripcion')} onCambio={(x) => setModulo(i, { descripcion: x })} />
                    </div>
                    <Campo etiqueta={t.modulo.etiqueta.etiqueta} max={t.modulo.etiqueta.max} valor={m.etiqueta ?? ''} error={err('etiqueta')} onCambio={(x) => setModulo(i, { etiqueta: nulo(x) })} />
                    <div className="flex flex-col gap-space-xs">
                      <span className="font-label-lg text-label-lg text-on-surface">{t.modulo.imagen}</span>
                      <Miniatura medio={m.imagen_id ? conocidos[m.imagen_id] : undefined} onQuitar={m.imagen_id ? () => setModulo(i, { imagen_id: null }) : undefined} />
                      <SelectorMedios
                        etiqueta={cms.medios.abrirSelector}
                        onElegir={(medio) => {
                          setConocidos((x) => ({ ...x, [medio.id]: medio }));
                          setModulo(i, { imagen_id: medio.id });
                        }}
                      />
                      {err('imagen_id') && <span className="font-label-sm text-label-sm text-error">{err('imagen_id')}</span>}
                    </div>
                  </div>
                </details>
                {err('en_grid') && <span className="font-label-sm text-label-sm text-error">{err('en_grid')}</span>}
              </li>
            );
          })}
        </ol>
      </TarjetaSeccion>
    </div>
  );
}
