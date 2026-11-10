'use client';

import { useCallback, useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { guardarBorrador } from '@/app/cms/editor-acciones';
import { Icono } from '@/componentes/base';
import { cms } from '@/contenido/provisional';
import { useEditorCms } from './EditorCms';

/*
 * Sprint 9 · Piezas comunes de las pantallas de edición del CMS: el estado del borrador de una o
 * varias secciones (registrado en la franja «Guardar borrador / Publicar»), campos con contador,
 * botones para ordenar listas y miniaturas de la Biblioteca de medios.
 */

export type Errores = Record<string, string>;

/** Medio tal como lo devuelve GET /cms/secciones/{clave} (miniaturas del editor). */
export type MedioCorto = { id: number; tipo: string; nombre: string; url: string; alt: string | null };

/**
 * Borrador de una o varias secciones. «Guardar» las guarda en orden; los errores de la API
 * quedan por sección con su ruta («lista.2.nombre»).
 */
export function useBorrador<T extends Record<string, Record<string, unknown>>>(inicial: T) {
  const { registrar, marcarSucio } = useEditorCms();
  const [valores, setValores] = useState<T>(inicial);
  const [errores, setErrores] = useState<Partial<Record<keyof T, Errores>>>({});
  const actuales = useRef(valores);
  actuales.current = valores;
  const claves = useRef(Object.keys(inicial));

  useEffect(() => {
    registrar({
      claves: claves.current,
      guardar: async () => {
        const nuevos: Partial<Record<keyof T, Errores>> = {};
        let ok = true;
        for (const clave of claves.current) {
          const r = await guardarBorrador(clave, actuales.current[clave]);
          if (!r.ok) {
            ok = false;
            nuevos[clave as keyof T] = Object.fromEntries(Object.entries(r.errores ?? { _: [r.mensaje] }).map(([k, v]) => [k, v[0]]));
          }
        }
        setErrores(nuevos);
        return ok;
      },
    });
    return () => registrar(null);
  }, [registrar]);

  const cambiar = useCallback(
    <K extends keyof T>(clave: K, cambio: (v: T[K]) => T[K]) => {
      setValores((x) => ({ ...x, [clave]: cambio(x[clave]) }));
      marcarSucio();
    },
    [marcarSucio],
  );

  return { valores, cambiar, errores };
}

/** Mueve un elemento de la lista una posición (−1 arriba, +1 abajo). */
export function mover<T>(lista: T[], i: number, paso: -1 | 1): T[] {
  const j = i + paso;
  if (j < 0 || j >= lista.length) return lista;
  const copia = [...lista];
  [copia[i], copia[j]] = [copia[j], copia[i]];
  return copia;
}

/** Reemplaza el elemento i de una lista con algunos campos cambiados. */
export function editar<T>(lista: T[], i: number, cambios: Partial<T>): T[] {
  return lista.map((x, k) => (k === i ? { ...x, ...cambios } : x));
}

const ENTRADA =
  'w-full px-space-md py-space-sm rounded-lg border bg-surface-container-lowest font-body-md text-body-md text-on-surface focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary';

type PropsCampo = {
  etiqueta: string;
  valor: string;
  onCambio: (v: string) => void;
  error?: string;
  max?: number;
  filas?: number;
  tipo?: 'text' | 'number' | 'date' | 'time';
  ayuda?: string;
  inputMode?: 'decimal' | 'numeric';
  requerido?: boolean;
};

/** Campo con etiqueta, contador de caracteres y error accesible. */
export function Campo({ etiqueta, valor, onCambio, error, max, filas, tipo = 'text', ayuda, inputMode, requerido }: PropsCampo) {
  const id = useId();
  const props = {
    id,
    value: valor,
    maxLength: max,
    required: requerido,
    'aria-invalid': error ? true : undefined,
    'aria-describedby': `${id}-ayuda`,
    className: `${ENTRADA} ${error ? 'border-error' : 'border-outline-variant'}`,
    onChange: (e: { target: { value: string } }) => onCambio(e.target.value),
  };
  return (
    <div className="flex flex-col gap-space-xs min-w-0">
      <label htmlFor={id} className="font-label-lg text-label-lg text-on-surface">
        {etiqueta}
      </label>
      {filas ? <textarea rows={filas} {...props} /> : <input type={tipo} inputMode={inputMode} {...props} />}
      <div id={`${id}-ayuda`} className="flex justify-between gap-space-sm font-label-sm text-label-sm">
        <span className={error ? 'text-error' : 'text-secondary'}>{error ?? ayuda}</span>
        {max ? (
          <span className="text-secondary shrink-0">
            {valor.length}/{max}
          </span>
        ) : null}
      </div>
    </div>
  );
}

/** Botones «Subir» y «Bajar» de una fila (sin arrastrar: funcionan con teclado). */
export function BotonesOrden({ nombre, i, total, onMover }: { nombre: string; i: number; total: number; onMover: (paso: -1 | 1) => void }) {
  const t = cms.lista;
  const clase = 'w-9 h-9 rounded-lg flex items-center justify-center text-primary hover:bg-surface-container disabled:opacity-30 disabled:cursor-not-allowed';
  return (
    <span className="flex items-center gap-1">
      <button type="button" className={clase} aria-label={t.subir(nombre)} disabled={i === 0} onClick={() => onMover(-1)}>
        <Icono nombre="arrow_upward" tamano="sm" />
      </button>
      <button type="button" className={clase} aria-label={t.bajar(nombre)} disabled={i === total - 1} onClick={() => onMover(1)}>
        <Icono nombre="arrow_downward" tamano="sm" />
      </button>
    </span>
  );
}

/** Miniatura de un medio elegido (imagen o PDF), con botón para quitarlo. */
export function Miniatura({ medio, onQuitar, etiquetaQuitar }: { medio: MedioCorto | undefined; onQuitar?: () => void; etiquetaQuitar?: string }) {
  if (!medio) return <span className="font-label-md text-label-md text-on-surface-variant">{cms.lista.sinImagen}</span>;
  return (
    <span className="flex items-center gap-space-sm min-w-0">
      <span className="w-14 h-14 shrink-0 rounded-lg bg-surface-container-low border border-surface-container flex items-center justify-center overflow-hidden">
        {medio.tipo === 'pdf' ? (
          <Icono nombre="picture_as_pdf" className="text-error" />
        ) : (
          // eslint-disable-next-line @next/next/no-img-element -- archivos de la Biblioteca (bucket del sitio)
          <img src={medio.url} alt={medio.alt ?? ''} className="max-h-full max-w-full object-contain" />
        )}
      </span>
      <span className="font-label-md text-label-md text-on-surface truncate">{medio.nombre}</span>
      {onQuitar && (
        <button type="button" onClick={onQuitar} className="shrink-0 h-8 px-space-sm rounded-lg text-error hover:bg-error-container font-label-md text-label-md">
          {etiquetaQuitar ?? cms.lista.quitarImagen}
        </button>
      )}
    </span>
  );
}

/** Error general de una lista (reglas entre filas: un solo recomendado, máximo 6 en el grid…). */
export function ErrorLista({ error }: { error?: string }) {
  if (!error) return null;
  return (
    <p role="alert" className="px-space-md py-space-sm rounded-lg bg-error-container text-on-error-container font-body-sm text-body-sm">
      {error}
    </p>
  );
}

/** Aviso de que hay un borrador sin publicar. */
export function AvisoBorrador({ visible, children }: { visible: boolean; children: ReactNode }) {
  if (!visible) return null;
  return <p className="px-space-md py-space-sm rounded-lg bg-aviso-fondo border border-aviso-borde text-aviso-texto font-body-sm text-body-sm">{children}</p>;
}
