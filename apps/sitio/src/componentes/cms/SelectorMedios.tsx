'use client';

import { useEffect, useId, useState, type FormEvent } from 'react';
import { listarMedios, subirMedio, type Medio } from '@/app/cms/medios-acciones';
import { Boton, Icono, Modal } from '@/componentes/base';
import { cms } from '@/contenido/provisional';

export const MAX_BYTES = 5 * 1024 * 1024;
const ACEPTA = 'image/jpeg,image/png,image/webp,image/svg+xml,application/pdf';

function tamano(bytes: number): string {
  return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/** Vista previa de un medio: la imagen con su texto alternativo, o el ícono del PDF. */
export function VistaMedio({ medio, compacta = false }: { medio: Medio; compacta?: boolean }) {
  const t = cms.medios;
  return (
    <figure className="flex flex-col gap-space-xs min-w-0">
      <div className={`${compacta ? 'h-28' : 'h-44'} rounded-lg bg-surface-container-low border border-surface-container flex items-center justify-center overflow-hidden`}>
        {medio.tipo === 'pdf' ? (
          <Icono nombre="picture_as_pdf" className="text-[3rem] text-error" />
        ) : (
          // eslint-disable-next-line @next/next/no-img-element -- archivos de la Biblioteca (bucket del sitio)
          <img src={medio.url} alt={medio.alt ?? ''} className="max-h-full max-w-full object-contain" />
        )}
      </div>
      <figcaption className="font-label-sm text-label-sm text-secondary truncate">
        {medio.nombre} · {medio.ancho && medio.alto ? `${medio.ancho} × ${medio.alto} · ` : ''}
        {tamano(medio.bytes)}
        {medio.tipo !== 'pdf' && !medio.alt && <span className="block text-aviso-texto">{t.sinAlt}</span>}
      </figcaption>
    </figure>
  );
}

/**
 * EDUN-237 · Selector reutilizable «Subir o elegir de la Biblioteca» (zonas «Subir imagen» de
 * Módulos Web, Casos de Éxito y Ajustes SEO). Valida 5 MB antes de subir.
 */
export function SelectorMedios({ etiqueta, tipo = 'imagenes', onElegir }: { etiqueta: string; tipo?: 'imagenes' | 'pdf'; onElegir: (m: Medio) => void }) {
  const t = cms.medios;
  const [abierto, setAbierto] = useState(false);
  const [lista, setLista] = useState<Medio[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [subiendo, setSubiendo] = useState(false);
  const idArchivo = useId();
  const idAlt = useId();

  useEffect(() => {
    if (!abierto || lista) return;
    let vigente = true;
    listarMedios(tipo).then((l) => vigente && setLista(l));
    return () => {
      vigente = false;
    };
  }, [abierto, lista, tipo]);

  async function alSubir(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setError(null);
    const datos = new FormData(e.currentTarget);
    const archivo = datos.get('archivo');
    if (!(archivo instanceof File) || archivo.size === 0) return setError(t.elegirArchivo);
    if (archivo.size > MAX_BYTES) return setError(t.superaLimite);
    setSubiendo(true);
    try {
      const r = await subirMedio(datos);
      if (!r.ok) return setError(r.mensaje);
      setLista((l) => [r.medio, ...(l ?? [])]);
      onElegir(r.medio);
      setAbierto(false);
    } finally {
      setSubiendo(false);
    }
  }

  return (
    <>
      <Boton variante="secundario" icono="upload" onClick={() => setAbierto(true)}>
        {etiqueta}
      </Boton>
      <Modal abierto={abierto} onCerrar={() => setAbierto(false)} titulo={t.tituloSelector} etiquetaCerrar={t.cerrar}>
        <div className="flex flex-col gap-space-lg">
          <form onSubmit={alSubir} className="flex flex-col gap-space-sm p-space-md rounded-lg border border-dashed border-outline-variant">
            <label htmlFor={idArchivo} className="font-label-lg text-label-lg text-on-surface">
              {t.subir}
            </label>
            <input id={idArchivo} name="archivo" type="file" accept={ACEPTA} className="font-body-sm text-body-sm" />
            <p className="font-label-sm text-label-sm text-secondary">{t.reglas}</p>
            <label htmlFor={idAlt} className="font-label-lg text-label-lg text-on-surface">
              {t.alt}
            </label>
            <input id={idAlt} name="alt" maxLength={250} className="h-10 px-space-md rounded-lg border border-outline-variant font-body-md text-body-md" />
            {error && (
              <p role="alert" className="px-space-md py-space-sm rounded-lg bg-error-container text-on-error-container font-body-sm text-body-sm">
                {error}
              </p>
            )}
            <div>
              <Boton type="submit" variante="institucional" icono="cloud_upload" disabled={subiendo}>
                {subiendo ? t.subiendo : t.botonSubir}
              </Boton>
            </div>
          </form>

          <section aria-label={t.biblioteca} className="flex flex-col gap-space-sm">
            <h3 className="font-title-md text-title-md text-primary">{t.biblioteca}</h3>
            {lista === null ? (
              <p className="font-body-sm text-body-sm text-secondary">{t.cargando}</p>
            ) : lista.length === 0 ? (
              <p className="font-body-sm text-body-sm text-secondary">{t.vacia}</p>
            ) : (
              <ul className="grid grid-cols-2 sm:grid-cols-3 gap-space-sm">
                {lista.map((m) => (
                  <li key={m.id}>
                    <button
                      type="button"
                      onClick={() => {
                        onElegir(m);
                        setAbierto(false);
                      }}
                      className="w-full text-left p-space-xs rounded-lg hover:bg-surface-container-low focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
                    >
                      <VistaMedio medio={m} compacta />
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </div>
      </Modal>
    </>
  );
}
