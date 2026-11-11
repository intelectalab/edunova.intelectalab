'use client';

import { useEffect, useId, useState, useTransition } from 'react';
import { Insignia, TarjetaSeccion, useToast } from '@/componentes/base';
import { cms } from '@/contenido/provisional';
import { analizarSeo, guardarAsistenteSeo, sugerirSeo, type AnalisisSeo, type ConfigAsistenteSeo, type SugerenciasSeo } from '../../../panel-acciones';

const t = cms.asistenteSeo;
const tono = (estado: string) => (estado === 'bien' ? 'exito' : estado === 'vacio' ? 'neutro' : 'aviso');

/**
 * EDUN-413 / SIT-030 · Asistente SEO con IA dentro de Ajustes SEO. Boceto propio con el sistema
 * de diseño del panel. Propone 3 meta descripciones, un título y palabras clave; revisa largo,
 * legibilidad y repetidos de lo escrito. Elegir una opción solo llena el formulario: publicar
 * sigue siendo «Guardar y actualizar metadatos».
 */
export function AsistenteSeo({ slug, titulo, descripcion, keywords, config, onUsarDescripcion, onUsarTitulo, onAgregarKeywords }: {
  slug: string;
  titulo: string;
  descripcion: string;
  keywords: string[];
  config: ConfigAsistenteSeo | null;
  onUsarDescripcion: (d: string) => void;
  onUsarTitulo: (x: string) => void;
  onAgregarKeywords: (k: string[]) => void;
}) {
  const [contexto, setContexto] = useState('');
  const [sugerencias, setSugerencias] = useState<SugerenciasSeo | null>(null);
  const [analisis, setAnalisis] = useState<AnalisisSeo | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [generando, iniciar] = useTransition();
  const [proveedor, setProveedor] = useState(config?.proveedor ?? 'simulado');
  const [modelo, setModelo] = useState(config?.modelo ?? '');
  const [guardandoProveedor, iniciarProveedor] = useTransition();
  const [actual, setActual] = useState({ proveedor: config?.proveedor, modelo: config?.modelo });
  const avisar = useToast();
  const idContexto = useId();
  const idProveedor = useId();
  const idModelo = useId();

  // Revisión de lo escrito (sin IA), con una pequeña espera para no consultar en cada tecla.
  useEffect(() => {
    let vigente = true;
    const reloj = setTimeout(async () => {
      const r = await analizarSeo(slug, titulo, descripcion);
      if (vigente && r.ok) setAnalisis(r.datos);
    }, 600);
    return () => {
      vigente = false;
      clearTimeout(reloj);
    };
  }, [slug, titulo, descripcion]);

  const generar = () =>
    iniciar(async () => {
      setError(null);
      const r = await sugerirSeo(slug, contexto);
      if (!r.ok) return setError(r.errores.proveedor ?? r.errores.contexto ?? (r.mensaje || t.error));
      setSugerencias(r.datos);
    });

  const nuevas = sugerencias?.keywords.filter((k) => !keywords.includes(k)) ?? [];

  return (
    <TarjetaSeccion icono="auto_awesome" titulo={t.titulo} descripcion={t.descripcion}>
      {analisis && (
        <section aria-labelledby="analisis-seo" className="flex flex-col gap-space-xs p-space-sm rounded-lg bg-surface-container-low">
          <h3 id="analisis-seo" className="font-label-lg text-label-lg text-on-surface">
            {t.analisis}
          </h3>
          <div className="flex flex-wrap items-center gap-space-xs font-label-sm text-label-sm text-on-surface-variant">
            <span>{cms.seo.metaTitulo.etiqueta}:</span>
            <Insignia tono={tono(analisis.titulo.estado)}>{t.estados[analisis.titulo.estado]}</Insignia>
            <span>{t.largo(analisis.titulo.largo, ...analisis.titulo.ideal)}</span>
          </div>
          <div className="flex flex-wrap items-center gap-space-xs font-label-sm text-label-sm text-on-surface-variant">
            <span>{cms.seo.metaDescripcion.etiqueta}:</span>
            <Insignia tono={tono(analisis.descripcion.estado)}>{t.estados[analisis.descripcion.estado]}</Insignia>
            <span>{t.largo(analisis.descripcion.largo, ...analisis.descripcion.ideal)}</span>
            {analisis.descripcion.largo > 0 && <span>· {t.legibilidad(analisis.descripcion.legibilidad, analisis.descripcion.nivel)}</span>}
          </div>
          {[...analisis.titulo.duplicado_en, ...analisis.descripcion.duplicado_en].length > 0 ? (
            <p className="font-label-sm text-label-sm text-aviso-texto">{t.duplicado([...new Set([...analisis.titulo.duplicado_en, ...analisis.descripcion.duplicado_en])].map((s) => cms.seo.paginas[s] ?? s).join(', '))}</p>
          ) : (
            <p className="font-label-sm text-label-sm text-secondary">{t.sinDuplicados}</p>
          )}
        </section>
      )}

      <div className="flex flex-col gap-space-xs">
        <label htmlFor={idContexto} className="font-label-lg text-label-lg text-on-surface">
          {t.contexto}
        </label>
        <textarea
          id={idContexto}
          rows={2}
          maxLength={2000}
          value={contexto}
          aria-describedby={`${idContexto}-ayuda`}
          onChange={(e) => setContexto(e.target.value)}
          className="w-full px-space-md py-space-sm rounded-lg border border-outline-variant bg-surface-container-lowest font-body-md text-body-md text-on-surface"
        />
        <span id={`${idContexto}-ayuda`} className="font-label-sm text-label-sm text-secondary">
          {t.contextoAyuda}
        </span>
      </div>
      <button type="button" onClick={generar} disabled={generando} className="self-start h-11 px-space-lg rounded-lg bg-primary hover:bg-primary-container text-on-primary font-label-lg text-label-lg disabled:opacity-50">
        {generando ? t.generando : t.generar}
      </button>
      {error && (
        <p role="alert" className="font-label-md text-label-md text-error">
          {error}
        </p>
      )}

      {sugerencias && (
        <div className="flex flex-col gap-space-sm" aria-live="polite">
          {sugerencias.proveedor === 'simulado' && <p className="font-label-sm text-label-sm text-secondary">{t.simulado}</p>}
          <h3 className="font-label-lg text-label-lg text-on-surface">{t.opciones}</h3>
          <ol className="flex flex-col gap-space-sm">
            {sugerencias.descripciones.map((d, i) => (
              <li key={i} className="p-space-sm rounded-lg border border-surface-container flex flex-col gap-space-xs">
                <p className="font-body-md text-body-md text-on-surface">{d.texto}</p>
                <div className="flex flex-wrap items-center gap-space-xs font-label-sm text-label-sm text-on-surface-variant">
                  <Insignia tono={tono(d.estado)}>{t.estados[d.estado]}</Insignia>
                  <span>{t.largo(d.largo, ...d.ideal)}</span>
                  <span>· {t.legibilidad(d.legibilidad, d.nivel)}</span>
                  <button
                    type="button"
                    disabled={d.texto === descripcion}
                    onClick={() => onUsarDescripcion(d.texto)}
                    className="ml-auto h-9 px-space-sm rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-md text-label-md disabled:opacity-60"
                  >
                    {d.texto === descripcion ? t.usada : t.usar}
                  </button>
                </div>
              </li>
            ))}
          </ol>
          <div className="flex flex-wrap items-center gap-space-sm">
            <span className="font-label-md text-label-md text-on-surface-variant">{t.tituloSugerido}:</span>
            <span className="font-body-md text-body-md text-on-surface break-words">{sugerencias.titulo}</span>
            <button type="button" disabled={sugerencias.titulo === titulo} onClick={() => onUsarTitulo(sugerencias.titulo)} className="h-9 px-space-sm rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-md text-label-md disabled:opacity-60">
              {t.usarTitulo}
            </button>
          </div>
          {nuevas.length > 0 && (
            <div className="flex flex-col gap-space-xs">
              <span className="font-label-md text-label-md text-on-surface-variant">{t.keywords}</span>
              <div className="flex flex-wrap gap-space-xs">
                {nuevas.map((k) => (
                  <button key={k} type="button" aria-label={t.agregar(k)} onClick={() => onAgregarKeywords([k])} className="h-8 px-space-sm rounded-full border border-outline-variant text-primary font-label-md text-label-md hover:bg-surface-container-low">
                    + {k}
                  </button>
                ))}
                <button type="button" onClick={() => onAgregarKeywords(nuevas)} className="h-8 px-space-sm rounded-full text-primary underline font-label-md text-label-md">
                  {t.agregarTodas}
                </button>
              </div>
            </div>
          )}
        </div>
      )}

      {config && (
        <details className="rounded-lg border border-surface-container p-space-sm">
          <summary className="cursor-pointer font-label-lg text-label-lg text-on-surface">{t.proveedor}</summary>
          <div className="mt-space-sm grid gap-space-sm md:grid-cols-2">
            <div className="flex flex-col gap-space-xs">
              <label htmlFor={idProveedor} className="font-label-md text-label-md text-on-surface">
                {t.proveedor}
              </label>
              <select id={idProveedor} value={proveedor} onChange={(e) => setProveedor(e.target.value)} className="h-11 rounded-lg border border-outline-variant bg-surface-container-lowest px-2 font-body-md text-body-md text-on-surface">
                {Object.entries(config.proveedores).map(([k, x]) => (
                  <option key={k} value={k}>
                    {x}
                  </option>
                ))}
              </select>
            </div>
            {proveedor === 'claude' && (
              <div className="flex flex-col gap-space-xs">
                <label htmlFor={idModelo} className="font-label-md text-label-md text-on-surface">
                  {t.modelo}
                </label>
                <select id={idModelo} value={modelo} onChange={(e) => setModelo(e.target.value)} className="h-11 rounded-lg border border-outline-variant bg-surface-container-lowest px-2 font-body-md text-body-md text-on-surface">
                  {config.modelos.map((m) => (
                    <option key={m} value={m}>
                      {m}
                    </option>
                  ))}
                </select>
              </div>
            )}
          </div>
          {proveedor === 'claude' && !config.clave_configurada && <p className="mt-space-xs font-label-sm text-label-sm text-aviso-texto">{t.sinClave}</p>}
          <button
            type="button"
            disabled={guardandoProveedor || (proveedor === actual.proveedor && modelo === actual.modelo)}
            onClick={() =>
              iniciarProveedor(async () => {
                const r = await guardarAsistenteSeo(proveedor, modelo);
                if (!r.ok) return setError(r.mensaje || t.error);
                setActual({ proveedor: r.datos.proveedor, modelo: r.datos.modelo });
                setSugerencias(null);
                avisar(t.proveedorGuardado);
              })
            }
            className="mt-space-sm h-10 px-space-md rounded-lg bg-surface-container-low hover:bg-surface-container-high text-primary font-label-md text-label-md disabled:opacity-50"
          >
            {t.guardarProveedor}
          </button>
        </details>
      )}
    </TarjetaSeccion>
  );
}
