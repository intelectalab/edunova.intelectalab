'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { Modal } from '@/componentes/base';
import { politicas as t } from '@/contenido/provisional';
import type { Politica } from '@/lib/politicas';
import { ContenidoPolitica, VersionPolitica } from './ContenidoPolitica';

const TIPOS = new Set(['privacidad', 'terminos', 'seguridad', 'cookies']);

type Estado = { tipo: string; politica: Politica | null; error: boolean };

/** Enlace a una política: «#privacidad», «/#terminos»… del mismo sitio. */
function tipoDeEnlace(href: string): string | null {
  try {
    const u = new URL(href, location.href);
    const tipo = u.hash.slice(1);
    return u.origin === location.origin && TIPOS.has(tipo) ? tipo : null;
  } catch {
    return null;
  }
}

/**
 * EDUN-287 / SIT-053 · Ventana de políticas (prototipo #privacidad, #terminos, #seguridad):
 * título, versión, vigencia y el texto de la versión vigente. Se abre desde el footer, las
 * casillas de consentimiento y el banner de cookies, y sola al llegar con /#privacidad.
 */
export function VentanaPolitica() {
  const [estado, setEstado] = useState<Estado | null>(null);
  const cache = useRef(new Map<string, Politica>());

  const abrir = useCallback(async (tipo: string) => {
    const guardada = cache.current.get(tipo);
    setEstado({ tipo, politica: guardada ?? null, error: false });
    if (guardada) return;
    try {
      const r = await fetch(`/api/politicas/${tipo}`, { headers: { Accept: 'application/json' } });
      if (!r.ok) throw new Error(String(r.status));
      const politica = (await r.json()) as Politica;
      cache.current.set(tipo, politica);
      setEstado((e) => (e?.tipo === tipo ? { tipo, politica, error: false } : e));
    } catch {
      setEstado((e) => (e?.tipo === tipo ? { tipo, politica: null, error: true } : e));
    }
  }, []);

  useEffect(() => {
    function alHacerClic(e: MouseEvent) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      const a = (e.target as HTMLElement | null)?.closest?.('a[href]');
      const tipo = a ? tipoDeEnlace(a.getAttribute('href') ?? '') : null;
      if (!tipo) return;
      e.preventDefault();
      void abrir(tipo);
    }
    function porHash() {
      const tipo = TIPOS.has(location.hash.slice(1)) ? location.hash.slice(1) : null;
      if (tipo) void abrir(tipo);
    }
    // En captura: antes que el onClick de <Link> de Next, que navegaría en vez de abrir la ventana.
    document.addEventListener('click', alHacerClic, true);
    window.addEventListener('hashchange', porHash);
    porHash();
    return () => {
      document.removeEventListener('click', alHacerClic, true);
      window.removeEventListener('hashchange', porHash);
    };
  }, [abrir]);

  function cerrar() {
    setEstado(null);
    if (TIPOS.has(location.hash.slice(1))) history.replaceState(null, '', location.pathname + location.search);
  }

  const p = estado?.politica;
  return (
    <Modal
      abierto={estado !== null}
      onCerrar={cerrar}
      titulo={p?.titulo ?? t.titulos[estado?.tipo ?? ''] ?? ''}
      etiquetaCerrar={t.cerrar}
      ancho="lg"
      subtitulo={p ? <VersionPolitica politica={p} /> : null}
    >
      <div className="max-h-[65vh] overflow-y-auto pr-2">
        {p ? (
          <ContenidoPolitica politica={p} />
        ) : estado?.error ? (
          <p role="alert" className="font-body-md text-body-md text-error">
            {t.error}
          </p>
        ) : (
          <p aria-live="polite" className="font-body-md text-body-md text-secondary">
            {t.cargando}
          </p>
        )}
      </div>
    </Modal>
  );
}
