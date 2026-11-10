'use client';

import { Icono, Interruptor, TarjetaSeccion } from '@/componentes/base';
import { AvisoBorrador, BotonesOrden, Campo, ErrorLista, editar, mover, useBorrador } from '@/componentes/cms/Formulario';
import { cms } from '@/contenido/provisional';
import { soles } from '@/lib/formato';

export type PlanCms = {
  clave: string;
  nombre: string;
  rango_texto: string;
  alumnos_max: number | null;
  precio_mensual: string | null;
  descripcion: string | null;
  caracteristicas: { texto: string; incluida: boolean }[];
  recomendado: boolean;
  visible: boolean;
  cta_texto: string;
};
export type ComplementoCms = { clave: string; etiqueta: string; precio: string | null; nota: string | null; visible: boolean };
export type SeccionPlanesCms = {
  insignia: string;
  titulo: string;
  descripcion: string | null;
  mensual: string;
  anual: string;
  por_mes: string;
  personalizado: string;
  personalizado_nota: string;
  nota_legal: string;
  lista: PlanCms[];
  complementos: ComplementoCms[];
};

const TEXTOS = ['insignia', 'titulo', 'descripcion', 'mensual', 'anual', 'por_mes', 'personalizado', 'personalizado_nota'] as const;
const MAX_CARACTERISTICAS = 12;
const nulo = (v: string) => (v.trim() === '' ? null : v);

/**
 * EDUN-326 / SIT-022 · Planes y Tarifas: textos de la sección, los 4 planes (orden, precio en
 * soles como texto decimal, características, recomendado y visibilidad), complementos y nota
 * legal. Se publica con la franja del panel.
 */
export function EditorPlanes({ inicial, conBorrador }: { inicial: SeccionPlanesCms; conBorrador: boolean }) {
  const t = cms.planes;
  const { valores, cambiar, errores } = useBorrador({ planes: inicial });
  const v = valores.planes as SeccionPlanesCms;
  const e = errores.planes ?? {};
  const set = (cambios: Partial<SeccionPlanesCms>) => cambiar('planes', (x) => ({ ...x, ...cambios }));
  const setPlan = (i: number, cambios: Partial<PlanCms>) => set({ lista: editar(v.lista, i, cambios) });
  const setComp = (i: number, cambios: Partial<ComplementoCms>) => set({ complementos: editar(v.complementos, i, cambios) });

  return (
    <div className="flex flex-col gap-space-md">
      <AvisoBorrador visible={conBorrador}>{cms.hero.borradorPendiente}</AvisoBorrador>
      {Object.keys(e).length > 0 && <ErrorLista error={e._ ?? cms.lista.errores} />}

      <TarjetaSeccion icono="title" titulo={cms.lista.textos} descripcion={t.descripcion}>
        <div className="grid gap-space-md md:grid-cols-2">
          {TEXTOS.map((c) => (
            <Campo
              key={c}
              etiqueta={t.textos[c].etiqueta}
              max={t.textos[c].max}
              valor={v[c] ?? ''}
              error={e[c]}
              onCambio={(x) => set({ [c]: c === 'descripcion' ? nulo(x) : x })}
            />
          ))}
          <div className="md:col-span-2">
            <Campo etiqueta={t.notaLegal.etiqueta} max={t.notaLegal.max} valor={v.nota_legal ?? ''} error={e.nota_legal} onCambio={(x) => set({ nota_legal: x })} />
          </div>
        </div>
      </TarjetaSeccion>

      <TarjetaSeccion icono="payments" titulo={t.planesTitulo}>
        <ErrorLista error={e.lista} />
        <ol className="flex flex-col gap-space-md">
          {v.lista.map((p, i) => {
            const err = (c: string) => e[`lista.${i}.${c}`];
            return (
              <li key={p.clave} data-plan={p.clave} className="p-space-md rounded-xl border border-surface-container flex flex-col gap-space-md">
                <div className="flex flex-wrap items-center justify-between gap-space-sm">
                  <h3 className="font-title-md text-title-md text-primary flex items-center gap-space-xs">
                    {p.nombre || p.clave}
                    <span className="font-label-md text-label-md text-secondary">{p.precio_mensual ? `${soles(p.precio_mensual)} ${v.por_mes}` : v.personalizado}</span>
                  </h3>
                  <span className="flex flex-wrap items-center gap-space-md">
                    <Interruptor etiqueta={t.plan.recomendado} activo={p.recomendado} onCambio={(x) => set({ lista: v.lista.map((q, k) => ({ ...q, recomendado: x ? k === i : k === i ? false : q.recomendado })) })} />
                    <Interruptor etiqueta={cms.lista.visible} activo={p.visible} onCambio={(x) => setPlan(i, { visible: x })} />
                    <BotonesOrden nombre={p.nombre || p.clave} i={i} total={v.lista.length} onMover={(paso) => set({ lista: mover(v.lista, i, paso) })} />
                  </span>
                </div>
                <div className="grid gap-space-md md:grid-cols-2 xl:grid-cols-3">
                  <Campo etiqueta={t.plan.nombre.etiqueta} max={t.plan.nombre.max} valor={p.nombre} error={err('nombre')} onCambio={(x) => setPlan(i, { nombre: x })} />
                  <Campo etiqueta={t.plan.rango_texto.etiqueta} max={t.plan.rango_texto.max} valor={p.rango_texto} error={err('rango_texto')} onCambio={(x) => setPlan(i, { rango_texto: x })} />
                  <Campo
                    etiqueta={t.plan.alumnos_max}
                    tipo="number"
                    inputMode="numeric"
                    valor={p.alumnos_max === null ? '' : String(p.alumnos_max)}
                    error={err('alumnos_max')}
                    onCambio={(x) => setPlan(i, { alumnos_max: x === '' ? null : Number(x) })}
                  />
                  <Campo
                    etiqueta={t.plan.precio_mensual}
                    inputMode="decimal"
                    ayuda={t.plan.precioAyuda}
                    valor={p.precio_mensual ?? ''}
                    error={err('precio_mensual')}
                    onCambio={(x) => setPlan(i, { precio_mensual: nulo(x.replace(',', '.')) })}
                  />
                  <Campo etiqueta={t.plan.cta_texto.etiqueta} max={t.plan.cta_texto.max} valor={p.cta_texto} error={err('cta_texto')} onCambio={(x) => setPlan(i, { cta_texto: x })} />
                  <Campo etiqueta={t.plan.descripcion.etiqueta} max={t.plan.descripcion.max} valor={p.descripcion ?? ''} error={err('descripcion')} onCambio={(x) => setPlan(i, { descripcion: nulo(x) })} />
                </div>
                <fieldset className="flex flex-col gap-space-sm">
                  <legend className="font-label-lg text-label-lg text-on-surface mb-space-xs">{t.plan.caracteristicas}</legend>
                  {p.caracteristicas.map((c, k) => (
                    <div key={k} className="flex flex-wrap md:flex-nowrap items-start gap-space-sm">
                      <div className="flex-1 min-w-[12rem]">
                        <Campo
                          etiqueta={t.plan.caracteristica(k + 1)}
                          max={90}
                          valor={c.texto}
                          error={err(`caracteristicas.${k}.texto`)}
                          onCambio={(x) => setPlan(i, { caracteristicas: editar(p.caracteristicas, k, { texto: x }) })}
                        />
                      </div>
                      <span className="pt-9">
                        <Interruptor etiqueta={t.plan.incluida} activo={c.incluida} onCambio={(x) => setPlan(i, { caracteristicas: editar(p.caracteristicas, k, { incluida: x }) })} />
                      </span>
                      <button
                        type="button"
                        aria-label={t.plan.quitarCaracteristica(k + 1)}
                        onClick={() => setPlan(i, { caracteristicas: p.caracteristicas.filter((_, j) => j !== k) })}
                        className="mt-8 w-9 h-9 rounded-lg flex items-center justify-center text-error hover:bg-error-container"
                      >
                        <Icono nombre="delete" tamano="sm" />
                      </button>
                    </div>
                  ))}
                  <button
                    type="button"
                    disabled={p.caracteristicas.length >= MAX_CARACTERISTICAS}
                    title={p.caracteristicas.length >= MAX_CARACTERISTICAS ? t.plan.maxCaracteristicas : undefined}
                    onClick={() => setPlan(i, { caracteristicas: [...p.caracteristicas, { texto: '', incluida: true }] })}
                    className="self-start inline-flex items-center gap-1 h-9 px-space-sm rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-md text-label-md disabled:opacity-50"
                  >
                    <Icono nombre="add" tamano="sm" />
                    {t.plan.agregarCaracteristica}
                  </button>
                </fieldset>
              </li>
            );
          })}
        </ol>
      </TarjetaSeccion>

      <TarjetaSeccion icono="add_shopping_cart" titulo={t.complementosTitulo}>
        <ol className="flex flex-col gap-space-sm">
          {v.complementos.map((c, i) => {
            const err = (k: string) => e[`complementos.${i}.${k}`];
            return (
              <li key={c.clave} className="p-space-sm rounded-lg border border-surface-container grid gap-space-sm md:grid-cols-[1fr_12rem_1fr_auto] items-start">
                <Campo etiqueta={t.complemento.etiqueta.etiqueta} max={t.complemento.etiqueta.max} valor={c.etiqueta} error={err('etiqueta')} onCambio={(x) => setComp(i, { etiqueta: x })} />
                <Campo etiqueta={t.complemento.precio} inputMode="decimal" valor={c.precio ?? ''} error={err('precio')} onCambio={(x) => setComp(i, { precio: nulo(x.replace(',', '.')) })} />
                <Campo etiqueta={t.complemento.nota.etiqueta} max={t.complemento.nota.max} valor={c.nota ?? ''} error={err('nota')} onCambio={(x) => setComp(i, { nota: nulo(x) })} />
                <span className="flex items-center gap-space-sm md:pt-8">
                  <Interruptor etiqueta={cms.lista.visible} ocultarEtiqueta activo={c.visible} onCambio={(x) => setComp(i, { visible: x })} />
                  <BotonesOrden nombre={c.etiqueta} i={i} total={v.complementos.length} onMover={(paso) => set({ complementos: mover(v.complementos, i, paso) })} />
                </span>
              </li>
            );
          })}
        </ol>
      </TarjetaSeccion>
    </div>
  );
}
