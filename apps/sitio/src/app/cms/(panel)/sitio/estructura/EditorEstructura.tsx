'use client';

import Link from 'next/link';
import { useState } from 'react';
import { Icono, Insignia, Interruptor, TarjetaSeccion } from '@/componentes/base';
import { AvisoBorrador, BotonesOrden, ErrorLista, editar, mover, useBorrador } from '@/componentes/cms/Formulario';
import { cms } from '@/contenido/provisional';

export type SeccionLista = { clave: string; nombre: string; visible: boolean };
export type Estructura = { lista: SeccionLista[] };

/** Siempre en su lugar (cabecera, portada y pie). */
const FIJAS = new Set(['barra_superior', 'hero', 'footer']);
/** No se pueden ocultar (la API también lo impide). */
const NO_OCULTABLES = new Set(['hero', 'footer']);
/** Pantalla donde se edita el contenido de cada sección. */
const PANTALLAS: Record<string, string> = {
  barra_superior: '/cms/sitio/secciones',
  hero: '/cms/sitio/secciones',
  modulos: '/cms/contenido/modulos',
  testimonios: '/cms/contenido/testimonios',
  logos: '/cms/contenido/logos',
  planes: '/cms/conversion/planes',
};

/**
 * EDUN-387 / SIT-020 · Editor de secciones: ordenar (arrastrar y soltar, o flechas con
 * teclado) y ocultar secciones de la landing. Se publica con la franja del panel.
 */
export function EditorEstructura({ inicial, conBorrador }: { inicial: Estructura; conBorrador: boolean }) {
  const t = cms.estructura;
  const { valores, cambiar, errores } = useBorrador({ _estructura: inicial });
  const lista = (valores._estructura as Estructura).lista;
  const e = errores._estructura ?? {};
  const [arrastrada, setArrastrada] = useState<number | null>(null);
  const [anuncio, setAnuncio] = useState('');
  const setLista = (l: SeccionLista[]) => cambiar('_estructura', () => ({ lista: l }));

  function moverA(desde: number, hasta: number) {
    if (desde === hasta || FIJAS.has(lista[hasta]?.clave ?? '')) return;
    const copia = [...lista];
    const [s] = copia.splice(desde, 1);
    copia.splice(hasta, 0, s);
    setLista(copia);
    setAnuncio(t.movida(s.nombre, hasta + 1));
  }

  return (
    <div className="flex flex-col gap-space-md">
      <AvisoBorrador visible={conBorrador}>{cms.hero.borradorPendiente}</AvisoBorrador>
      <TarjetaSeccion icono="reorder" titulo={t.lista} descripcion={t.descripcion}>
        <ErrorLista error={e.lista ?? e._} />
        <p aria-live="polite" className="sr-only">
          {anuncio}
        </p>
        <ol className="flex flex-col gap-space-xs">
          {lista.map((s, i) => {
            const fija = FIJAS.has(s.clave);
            const err = e[`lista.${i}.visible`];
            return (
              <li
                key={s.clave}
                data-seccion={s.clave}
                draggable={!fija}
                onDragStart={(ev) => {
                  setArrastrada(i);
                  ev.dataTransfer.effectAllowed = 'move';
                }}
                onDragOver={(ev) => {
                  if (arrastrada !== null && !fija) ev.preventDefault();
                }}
                onDrop={(ev) => {
                  ev.preventDefault();
                  if (arrastrada !== null) moverA(arrastrada, i);
                  setArrastrada(null);
                }}
                onDragEnd={() => setArrastrada(null)}
                className={`flex flex-wrap items-center gap-space-sm p-space-sm rounded-lg border ${arrastrada === i ? 'opacity-50' : ''} ${s.visible ? 'border-surface-container bg-surface-container-lowest' : 'border-dashed border-outline-variant bg-surface-container-low'}`}
              >
                <span className={`w-8 h-8 flex items-center justify-center ${fija ? 'text-outline' : 'text-secondary cursor-grab'}`} aria-hidden="true" title={fija ? undefined : t.arrastrar(s.nombre)}>
                  <Icono nombre={fija ? 'lock' : 'drag_indicator'} tamano="sm" />
                </span>
                <span className="w-6 text-right font-label-md text-label-md text-secondary">{i + 1}.</span>
                <span className="flex-1 min-w-[10rem] font-title-md text-title-md text-on-surface flex flex-wrap items-center gap-space-xs">
                  {s.nombre}
                  {fija && <Insignia tono="neutro">{t.fija}</Insignia>}
                  {!s.visible && <Insignia tono="neutro">{cms.lista.oculto}</Insignia>}
                </span>
                <span className="flex flex-wrap items-center gap-space-md">
                  <Interruptor etiqueta={`${cms.lista.visible}: ${s.nombre}`} ocultarEtiqueta activo={s.visible} deshabilitado={NO_OCULTABLES.has(s.clave)} onCambio={(x) => setLista(editar(lista, i, { visible: x }))} />
                  {!fija && (
                    <BotonesOrden
                      nombre={s.nombre}
                      i={i}
                      total={lista.length}
                      onMover={(paso) => {
                        if (FIJAS.has(lista[i + paso]?.clave ?? '')) return;
                        setLista(mover(lista, i, paso));
                        setAnuncio(t.movida(s.nombre, i + paso + 1));
                      }}
                    />
                  )}
                  {PANTALLAS[s.clave] ? (
                    <Link href={PANTALLAS[s.clave]} aria-label={t.configurar(s.nombre)} className="w-9 h-9 rounded-lg flex items-center justify-center text-primary hover:bg-surface-container">
                      <Icono nombre="settings" tamano="sm" />
                    </Link>
                  ) : (
                    <span className="w-9" title={t.sinPantalla} />
                  )}
                </span>
                {err && <span className="basis-full font-label-sm text-label-sm text-error">{err}</span>}
              </li>
            );
          })}
        </ol>
      </TarjetaSeccion>
    </div>
  );
}
