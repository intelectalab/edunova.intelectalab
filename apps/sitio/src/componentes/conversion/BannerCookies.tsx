'use client';

import { useEffect, useState } from 'react';
import { registrarCookies } from '@/app/acciones-sitio';
import { Icono, Interruptor, Modal } from '@/componentes/base';
import { cookies as t } from '@/contenido/provisional';
import { aplicarPreferencias, CATEGORIAS, EVENTO_PREFERENCIAS, guardarPreferencias, leerPreferencias, type Categoria } from '@/lib/consentimiento';

/**
 * EDUN-306 / SIT-055 · Banner de cookies en la primera visita: Aceptar todo, Solo esenciales o
 * Configurar (esenciales, analítica, marketing y personalización). La elección se recuerda en el
 * navegador, controla Consent Mode v2 y se puede cambiar desde «Preferencias de cookies» del footer.
 */
export function BannerCookies() {
  const [visible, setVisible] = useState(false);
  const [configurando, setConfigurando] = useState(false);
  const [elegidas, setElegidas] = useState<Categoria[]>(['esenciales']);

  useEffect(() => {
    if (!leerPreferencias()) setVisible(true);
    function abrirPreferencias() {
      setElegidas(leerPreferencias()?.categorias ?? ['esenciales']);
      setConfigurando(true);
    }
    window.addEventListener(EVENTO_PREFERENCIAS, abrirPreferencias);
    return () => window.removeEventListener(EVENTO_PREFERENCIAS, abrirPreferencias);
  }, []);

  function decidir(categorias: readonly string[]) {
    const p = guardarPreferencias(categorias);
    aplicarPreferencias(p.categorias);
    setVisible(false);
    setConfigurando(false);
    void registrarCookies(p.categorias);
  }

  const alternar = (c: Categoria, activa: boolean) => setElegidas((ant) => (activa ? [...ant, c] : ant.filter((x) => x !== c)));

  return (
    <>
      {visible && !configurando && (
        <section
          role="region"
          aria-label={t.titulo}
          className="fixed inset-x-0 bottom-0 z-[55] p-space-sm sm:p-space-md pointer-events-none"
        >
          <div className="pointer-events-auto max-w-5xl mx-auto rounded-2xl bg-surface-container-lowest shadow-2xl border border-surface-container p-space-md sm:p-space-lg flex flex-col lg:flex-row lg:items-center gap-space-md">
            <div className="flex items-start gap-space-sm min-w-0">
              <span className="w-10 h-10 shrink-0 rounded-lg bg-secondary-container text-primary flex items-center justify-center">
                <Icono nombre="cookie" />
              </span>
              <div className="flex flex-col gap-1 min-w-0">
                <h2 className="font-title-md text-title-md text-primary font-bold">{t.titulo}</h2>
                <p className="font-body-sm text-body-sm text-on-surface-variant">
                  {t.texto}{' '}
                  <a href="#cookies" className="text-primary font-semibold underline">
                    {t.verPolitica}
                  </a>
                </p>
              </div>
            </div>
            <div className="flex flex-col sm:flex-row gap-space-sm shrink-0">
              <button type="button" onClick={() => setConfigurando(true)} className="h-10 px-space-md rounded-lg bg-transparent hover:bg-surface-container-low text-primary font-label-lg text-label-lg">
                {t.configurar}
              </button>
              <button type="button" onClick={() => decidir(['esenciales'])} className="h-10 px-space-md rounded-lg bg-surface-container hover:bg-surface-container-high text-primary font-label-lg text-label-lg">
                {t.soloEsenciales}
              </button>
              <button type="button" onClick={() => decidir([...CATEGORIAS])} className="h-10 px-space-md rounded-lg bg-accion hover:bg-accion-hover text-accion-texto font-label-lg text-label-lg shadow-sm">
                {t.aceptarTodo}
              </button>
            </div>
          </div>
        </section>
      )}

      <Modal abierto={configurando} onCerrar={() => setConfigurando(false)} titulo={t.tituloConfigurar} etiquetaCerrar={t.cerrar}>
        <ul className="flex flex-col divide-y divide-surface-container">
          {t.categorias.map((c) => {
            const clave = c.clave as Categoria;
            const fija = clave === 'esenciales';
            return (
              <li key={c.clave} className="py-space-sm flex items-start justify-between gap-space-md">
                <div className="flex flex-col gap-1 min-w-0">
                  <span className="font-title-md text-title-md text-on-surface">{c.titulo}</span>
                  <span className="font-body-sm text-body-sm text-on-surface-variant">{c.descripcion}</span>
                </div>
                {fija ? (
                  <span className="shrink-0 font-label-md text-label-md text-accion">{t.siempreActivas}</span>
                ) : (
                  <Interruptor etiqueta={c.titulo} ocultarEtiqueta activo={elegidas.includes(clave)} onCambio={(v) => alternar(clave, v)} />
                )}
              </li>
            );
          })}
        </ul>
        <div className="pt-space-md flex flex-col sm:flex-row sm:justify-end gap-space-sm">
          <button type="button" onClick={() => decidir(['esenciales'])} className="h-10 px-space-md rounded-lg bg-surface-container hover:bg-surface-container-high text-primary font-label-lg text-label-lg">
            {t.soloEsenciales}
          </button>
          <button type="button" onClick={() => decidir(elegidas)} className="h-10 px-space-md rounded-lg bg-primary hover:bg-primary-container text-on-primary font-label-lg text-label-lg">
            {t.guardar}
          </button>
          <button type="button" onClick={() => decidir([...CATEGORIAS])} className="h-10 px-space-md rounded-lg bg-accion hover:bg-accion-hover text-accion-texto font-label-lg text-label-lg">
            {t.aceptarTodo}
          </button>
        </div>
      </Modal>
    </>
  );
}

/** Enlace «Preferencias de cookies» del footer: reabre la configuración. */
export function BotonPreferenciasCookies({ className = '' }: { className?: string }) {
  return (
    <button type="button" onClick={() => window.dispatchEvent(new Event(EVENTO_PREFERENCIAS))} className={className}>
      {t.preferencias}
    </button>
  );
}
