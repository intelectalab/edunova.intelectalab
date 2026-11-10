'use client';

import { useRouter } from 'next/navigation';
import { useId, useState, useTransition } from 'react';
import { Icono, TarjetaSeccion, useToast } from '@/componentes/base';
import { Campo, ErrorLista } from '@/componentes/cms/Formulario';
import { SelectorMedios } from '@/componentes/cms/SelectorMedios';
import { cms } from '@/contenido/provisional';
import { guardarSeo, type PaginaSeo } from '../../../panel-acciones';

const LIMITES = { meta_titulo: 70, meta_descripcion: 160 } as const;

function Contador({ valor, max }: { valor: string; max: number }) {
  const excede = valor.length > max;
  return (
    <span data-contador={excede ? 'excedido' : 'ok'} className={`font-label-md text-label-md ${excede ? 'text-error font-bold' : 'text-secondary'}`}>
      {valor.length}/{max}
      {excede && <span className="sr-only"> · {cms.seo.excede}</span>}
    </span>
  );
}

/** Campo con contador que se pone en rojo al pasar el límite (no recorta: el usuario ve el exceso). */
function CampoLimitado({ etiqueta, valor, max, filas, error, onCambio }: { etiqueta: string; valor: string; max: number; filas?: number; error?: string; onCambio: (v: string) => void }) {
  const id = useId();
  const excede = valor.length > max;
  const props = {
    id,
    value: valor,
    'aria-invalid': excede || error ? true : undefined,
    'aria-describedby': `${id}-ayuda`,
    onChange: (e: { target: { value: string } }) => onCambio(e.target.value),
    className: `w-full px-space-md py-space-sm rounded-lg border bg-surface-container-lowest font-body-md text-body-md text-on-surface ${excede || error ? 'border-error' : 'border-outline-variant'}`,
  };
  return (
    <div className="flex flex-col gap-space-xs">
      <label htmlFor={id} className="font-label-lg text-label-lg text-on-surface">
        {etiqueta}
      </label>
      {filas ? <textarea rows={filas} {...props} /> : <input {...props} />}
      <div id={`${id}-ayuda`} className="flex justify-between gap-space-sm font-label-sm text-label-sm">
        <span className="text-error">{excede ? cms.seo.excede : error}</span>
        <Contador valor={valor} max={max} />
      </div>
    </div>
  );
}

/**
 * EDUN-314 / SIT-057 · Ajustes SEO de una página: title (70), meta descripción (160),
 * canonical, robots y palabras clave; Open Graph con vista previa estilo WhatsApp/Facebook.
 * «Guardar y actualizar metadatos» publica (auditoría y revalidación del sitio).
 */
export function EditorSeo({ inicial, dominio }: { inicial: PaginaSeo; dominio: string }) {
  const t = cms.seo;
  const [v, setV] = useState(inicial);
  const [keywords, setKeywords] = useState(inicial.keywords.join(', '));
  const [errores, setErrores] = useState<Record<string, string>>({});
  const [guardando, iniciar] = useTransition();
  const router = useRouter();
  const avisar = useToast();
  const idRobots = useId();
  const set = (c: Partial<PaginaSeo>) => setV((x) => ({ ...x, ...c }));
  const excede = v.meta_titulo.length > LIMITES.meta_titulo || v.meta_descripcion.length > LIMITES.meta_descripcion;
  const cambios = JSON.stringify({ ...v, keywords }) !== JSON.stringify({ ...inicial, keywords: inicial.keywords.join(', ') });
  const ogTitulo = v.og_titulo || v.meta_titulo;
  const ogDescripcion = v.og_descripcion || v.meta_descripcion;
  const medida = v.og_imagen_ancho && v.og_imagen_alto && (v.og_imagen_ancho !== 1200 || v.og_imagen_alto !== 630) ? t.ogMedida(v.og_imagen_ancho, v.og_imagen_alto) : null;

  function guardar() {
    if (excede) return;
    setErrores({});
    iniciar(async () => {
      const r = await guardarSeo(v.slug, {
        meta_titulo: v.meta_titulo,
        meta_descripcion: v.meta_descripcion,
        canonical_url: v.canonical_url || null,
        robots: v.robots,
        keywords: keywords.split(',').map((k) => k.trim()).filter(Boolean),
        og_titulo: v.og_titulo || null,
        og_descripcion: v.og_descripcion || null,
        og_imagen_id: v.og_imagen_id,
      });
      if (!r.ok) return setErrores(Object.keys(r.errores).length ? r.errores : { _: r.mensaje });
      avisar(t.guardado);
      router.refresh();
    });
  }

  return (
    <div className="grid gap-space-md xl:grid-cols-[minmax(0,1fr)_26rem] items-start">
      <div className="flex flex-col gap-space-md min-w-0">
        {Object.keys(errores).length > 0 && <ErrorLista error={errores._ ?? t.error} />}
        <TarjetaSeccion icono="travel_explore" titulo={t.buscadores} descripcion={t.descripcion}>
          <CampoLimitado etiqueta={t.metaTitulo.etiqueta} max={t.metaTitulo.max} valor={v.meta_titulo} error={errores.meta_titulo} onCambio={(x) => set({ meta_titulo: x })} />
          <CampoLimitado etiqueta={t.metaDescripcion.etiqueta} max={t.metaDescripcion.max} filas={3} valor={v.meta_descripcion} error={errores.meta_descripcion} onCambio={(x) => set({ meta_descripcion: x })} />
          <div className="grid gap-space-md md:grid-cols-2">
            <Campo etiqueta={t.canonical} valor={v.canonical_url ?? ''} error={errores.canonical_url} onCambio={(x) => set({ canonical_url: x.trim() || null })} />
            <div className="flex flex-col gap-space-xs">
              <label htmlFor={idRobots} className="font-label-lg text-label-lg text-on-surface">
                {t.robots}
              </label>
              <select id={idRobots} value={v.robots} onChange={(e) => set({ robots: e.target.value })} className="h-11 rounded-lg border border-outline-variant bg-surface-container-lowest px-2 font-body-md text-body-md text-on-surface">
                {Object.entries(t.robotsOpciones).map(([k, x]) => (
                  <option key={k} value={k}>
                    {x} ({k})
                  </option>
                ))}
              </select>
            </div>
          </div>
          <Campo etiqueta={t.keywords} valor={keywords} error={errores.keywords} onCambio={setKeywords} />
        </TarjetaSeccion>

        <TarjetaSeccion icono="share" titulo={t.social}>
          <Campo etiqueta={t.ogTitulo.etiqueta} max={t.ogTitulo.max} valor={v.og_titulo ?? ''} error={errores.og_titulo} onCambio={(x) => set({ og_titulo: x || null })} />
          <Campo etiqueta={t.ogDescripcion.etiqueta} max={t.ogDescripcion.max} filas={2} valor={v.og_descripcion ?? ''} error={errores.og_descripcion} onCambio={(x) => set({ og_descripcion: x || null })} />
          <div className="flex flex-col gap-space-xs">
            <span className="font-label-lg text-label-lg text-on-surface">{t.ogImagen}</span>
            <SelectorMedios
              etiqueta={cms.medios.abrirSelector}
              onElegir={(m) => set({ og_imagen_id: m.id, og_imagen_url: m.url, og_imagen_alt: m.alt, og_imagen_ancho: m.ancho, og_imagen_alto: m.alto })}
            />
            {medida && <span className="font-label-sm text-label-sm text-aviso-texto">{medida}</span>}
            {errores.og_imagen_id && <span className="font-label-sm text-label-sm text-error">{errores.og_imagen_id}</span>}
          </div>
        </TarjetaSeccion>

        <div className="flex flex-wrap gap-space-sm">
          <button
            type="button"
            onClick={guardar}
            disabled={guardando || excede || !cambios}
            className="h-11 px-space-lg rounded-lg bg-primary hover:bg-primary-container text-on-primary font-label-lg text-label-lg disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {guardando ? t.guardando : t.guardar}
          </button>
          <button
            type="button"
            disabled={!cambios || guardando}
            onClick={() => {
              setV(inicial);
              setKeywords(inicial.keywords.join(', '));
              setErrores({});
            }}
            className="h-11 px-space-lg rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-lg text-label-lg disabled:opacity-50"
          >
            {t.descartar}
          </button>
        </div>
      </div>

      <div className="xl:sticky xl:top-24 flex flex-col gap-space-md">
        <section aria-labelledby="vista-google" className="p-space-md rounded-xl bg-surface-container-lowest border border-surface-container flex flex-col gap-1">
          <h2 id="vista-google" className="font-title-md text-title-md text-primary mb-space-xs">
            {t.vistaGoogle}
          </h2>
          <span className="font-label-sm text-label-sm text-secondary truncate">{v.canonical_url || dominio}</span>
          <span className="text-[1.1rem] leading-snug text-[#1a0dab] line-clamp-1">{v.meta_titulo || '—'}</span>
          <span className="font-body-sm text-body-sm text-on-surface-variant line-clamp-2">{v.meta_descripcion}</span>
          {v.robots !== 'index,follow' && <span className="mt-space-xs font-label-sm text-label-sm text-aviso-texto">{v.robots}</span>}
        </section>
        <section aria-labelledby="vista-social" className="p-space-md rounded-xl bg-surface-container-lowest border border-surface-container flex flex-col gap-space-xs">
          <h2 id="vista-social" className="font-title-md text-title-md text-primary">
            {t.vistaSocial}
          </h2>
          <div className="rounded-lg overflow-hidden border border-surface-container bg-[#f0f2f5]">
            {v.og_imagen_url ? (
              // eslint-disable-next-line @next/next/no-img-element -- imagen de la Biblioteca (bucket del sitio)
              <img src={v.og_imagen_url} alt={v.og_imagen_alt ?? ''} className="w-full aspect-[1200/630] object-cover" />
            ) : (
              <div className="w-full aspect-[1200/630] flex flex-col items-center justify-center gap-1 text-secondary">
                <Icono nombre="image" />
                <span className="font-label-sm text-label-sm">{t.sinImagen}</span>
              </div>
            )}
            <div className="p-space-sm flex flex-col gap-0.5">
              <span className="font-label-sm text-label-sm uppercase text-secondary truncate">{dominio.replace(/^https?:\/\//, '')}</span>
              <span className="font-label-lg text-label-lg text-on-surface line-clamp-2">{ogTitulo}</span>
              <span className="font-label-sm text-label-sm text-secondary line-clamp-2">{ogDescripcion}</span>
            </div>
          </div>
        </section>
      </div>
    </div>
  );
}
