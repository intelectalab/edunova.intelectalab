'use client';

import { useId, useState } from 'react';
import { Interruptor, TarjetaSeccion } from '@/componentes/base';
import { AvisoBorrador, Campo, ErrorLista, Miniatura, useBorrador, type MedioCorto } from '@/componentes/cms/Formulario';
import { SelectorMedios } from '@/componentes/cms/SelectorMedios';
import { cms, popupSalida } from '@/contenido/provisional';

export type PopupCms = {
  activo: boolean;
  titulo: string;
  texto: string | null;
  imagen_id: number | null;
  cta: string;
  campo: 'email' | 'telefono' | 'ambos';
  espera_seg: number;
  inactividad_seg: number;
  frecuencia: 'sesion' | 'dia' | 'siempre';
};

function Opciones({ etiqueta, valor, opciones, onCambio }: { etiqueta: string; valor: string; opciones: Record<string, string>; onCambio: (v: string) => void }) {
  const id = useId();
  return (
    <div className="flex flex-col gap-space-xs">
      <label htmlFor={id} className="font-label-lg text-label-lg text-on-surface">
        {etiqueta}
      </label>
      <select id={id} value={valor} onChange={(e) => onCambio(e.target.value)} className="h-11 rounded-lg border border-outline-variant bg-surface-container-lowest px-2 font-body-md text-body-md text-on-surface">
        {Object.entries(opciones).map(([k, v]) => (
          <option key={k} value={k}>
            {v}
          </option>
        ))}
      </select>
    </div>
  );
}

/**
 * EDUN-391 / SIT-025 · Configurador del popup de salida: activo, textos, imagen, dato a
 * capturar y reglas (espera, inactividad y frecuencia), con una vista previa al lado.
 */
export function EditorPopup({ inicial, medios, conBorrador }: { inicial: PopupCms; medios: Record<string, MedioCorto>; conBorrador: boolean }) {
  const t = cms.popup;
  const { valores, cambiar, errores } = useBorrador({ _popup: inicial });
  const [conocidos, setConocidos] = useState(medios);
  const p = valores._popup as PopupCms;
  const e = errores._popup ?? {};
  const set = (c: Partial<PopupCms>) => cambiar('_popup', (x) => ({ ...x, ...c }));
  const imagen = p.imagen_id ? conocidos[p.imagen_id] : undefined;
  const numero = (v: string) => Math.max(0, Math.min(600, Math.round(Number(v) || 0)));

  return (
    <div className="grid gap-space-md xl:grid-cols-[minmax(0,1fr)_24rem] items-start">
      <div className="flex flex-col gap-space-md min-w-0">
        <AvisoBorrador visible={conBorrador}>{cms.hero.borradorPendiente}</AvisoBorrador>
        {Object.keys(e).length > 0 && <ErrorLista error={e._ ?? cms.lista.errores} />}
        <TarjetaSeccion icono="ads_click" titulo={t.textos} descripcion={t.descripcion}>
          <Interruptor etiqueta={t.activo} activo={p.activo} onCambio={(x) => set({ activo: x })} />
          <Campo etiqueta={t.tituloCampo.etiqueta} max={t.tituloCampo.max} valor={p.titulo} error={e.titulo} onCambio={(x) => set({ titulo: x })} />
          <Campo etiqueta={t.texto.etiqueta} max={t.texto.max} filas={2} valor={p.texto ?? ''} error={e.texto} onCambio={(x) => set({ texto: x.trim() === '' ? null : x })} />
          <Campo etiqueta={t.cta.etiqueta} max={t.cta.max} valor={p.cta} error={e.cta} onCambio={(x) => set({ cta: x })} />
          <div className="flex flex-col gap-space-xs">
            <span className="font-label-lg text-label-lg text-on-surface">{t.imagen}</span>
            <Miniatura medio={imagen} onQuitar={p.imagen_id ? () => set({ imagen_id: null }) : undefined} />
            <SelectorMedios
              etiqueta={cms.medios.abrirSelector}
              onElegir={(m) => {
                setConocidos((x) => ({ ...x, [m.id]: m }));
                set({ imagen_id: m.id });
              }}
            />
            {e.imagen_id && <span className="font-label-sm text-label-sm text-error">{e.imagen_id}</span>}
          </div>
        </TarjetaSeccion>
        <TarjetaSeccion icono="tune" titulo={t.reglas}>
          <div className="grid gap-space-md md:grid-cols-2">
            <Opciones etiqueta={t.campo} valor={p.campo} opciones={t.campos} onCambio={(v) => set({ campo: v as PopupCms['campo'] })} />
            <Opciones etiqueta={t.frecuencia} valor={p.frecuencia} opciones={t.frecuencias} onCambio={(v) => set({ frecuencia: v as PopupCms['frecuencia'] })} />
            <Campo etiqueta={t.espera} tipo="number" inputMode="numeric" valor={String(p.espera_seg)} error={e.espera_seg} onCambio={(v) => set({ espera_seg: numero(v) })} />
            <Campo etiqueta={t.inactividad} tipo="number" inputMode="numeric" valor={String(p.inactividad_seg)} error={e.inactividad_seg} onCambio={(v) => set({ inactividad_seg: numero(v) })} />
          </div>
        </TarjetaSeccion>
      </div>

      <section aria-labelledby="titulo-vista-popup" className="xl:sticky xl:top-24 p-space-md rounded-xl bg-surface-container-low border border-surface-container flex flex-col gap-space-sm">
        <h2 id="titulo-vista-popup" className="font-title-md text-title-md text-primary">
          {t.vista}
        </h2>
        <div aria-hidden="true" className={`rounded-2xl bg-surface-container-lowest shadow-lg p-space-md flex flex-col gap-space-sm ${p.activo ? '' : 'opacity-50'}`}>
          <p className="font-title-lg text-title-lg text-primary font-bold">{p.titulo || '—'}</p>
          {imagen && (
            // eslint-disable-next-line @next/next/no-img-element -- imagen de la Biblioteca (bucket del sitio)
            <img src={imagen.url} alt="" className="w-full max-h-32 object-contain rounded-lg" />
          )}
          {p.texto && <p className="font-body-sm text-body-sm text-secondary">{p.texto}</p>}
          {p.campo !== 'telefono' && <span className="h-10 rounded-lg border border-outline-variant px-3 flex items-center font-label-md text-label-md text-secondary">{popupSalida.email}</span>}
          {p.campo !== 'email' && <span className="h-10 rounded-lg border border-outline-variant px-3 flex items-center font-label-md text-label-md text-secondary">{popupSalida.telefono}</span>}
          <span className="h-10 rounded-lg bg-accion text-accion-texto flex items-center justify-center font-label-lg text-label-lg">{p.cta || '—'}</span>
        </div>
      </section>
    </div>
  );
}
