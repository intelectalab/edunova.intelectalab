'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { MENSAJE_VISTA_PREVIA } from '@/app/cms/vista-previa/PortadaEnVivo';
import { Icono } from '@/componentes/base';
import { cms } from '@/contenido/provisional';
import type { Hero } from '@/lib/landing';

const DISPOSITIVOS = [
  { clave: 'escritorio', ancho: 1280, icono: 'desktop_windows' },
  { clave: 'tableta', ancho: 768, icono: 'tablet' },
  { clave: 'celular', ancho: 390, icono: 'smartphone' },
] as const;
type Dispositivo = (typeof DISPOSITIVOS)[number]['clave'];

const ALTO = 820;

/**
 * EDUN-322 · Previsualizador en vivo: la portada real (con los estilos y puntos de corte del
 * sitio) en un marco del mismo origen, a escala, en escritorio, tableta o celular. Cada cambio
 * del formulario se envía al marco al instante, sin guardar.
 */
export function VistaPrevia({ hero, aviso }: { hero: Hero; aviso: string }) {
  const t = cms.vistaPrevia;
  const [dispositivo, setDispositivo] = useState<Dispositivo>('escritorio');
  const [ancho, setAncho] = useState(0);
  const caja = useRef<HTMLDivElement>(null);
  const marco = useRef<HTMLIFrameElement>(null);
  const ultimo = useRef({ hero, aviso });
  ultimo.current = { hero, aviso };

  const enviar = useCallback(() => {
    marco.current?.contentWindow?.postMessage({ tipo: MENSAJE_VISTA_PREVIA, ...ultimo.current }, location.origin);
  }, []);

  // El marco avisa cuando está listo (también tras recargarse): se le manda lo último.
  useEffect(() => {
    function alRecibir(e: MessageEvent) {
      if (e.origin === location.origin && e.source === marco.current?.contentWindow && e.data?.tipo === `${MENSAJE_VISTA_PREVIA}:lista`) enviar();
    }
    window.addEventListener('message', alRecibir);
    return () => window.removeEventListener('message', alRecibir);
  }, [enviar]);

  // Cada cambio del formulario llega al instante (si el marco aún no escucha, lo pedirá al estar listo).
  useEffect(() => {
    enviar();
  }, [hero, aviso, enviar]);

  useEffect(() => {
    const el = caja.current;
    if (!el) return;
    const obs = new ResizeObserver(([e]) => setAncho(e.contentRect.width));
    obs.observe(el);
    return () => obs.disconnect();
  }, []);

  const elegido = DISPOSITIVOS.find((d) => d.clave === dispositivo)!;
  const escala = ancho > 0 ? Math.min(1, ancho / elegido.ancho) : 1;
  const nombre = t[dispositivo];

  return (
    <section aria-labelledby="titulo-vista-previa" className="flex flex-col gap-space-sm p-space-md rounded-xl bg-surface-container-lowest border border-surface-container">
      <div className="flex flex-wrap items-center justify-between gap-space-sm">
        <div>
          <h2 id="titulo-vista-previa" className="font-title-lg text-title-lg text-primary">
            {t.titulo}
          </h2>
          <p className="font-body-sm text-body-sm text-on-surface-variant">{t.descripcion}</p>
        </div>
        <div role="group" aria-label={t.dispositivos} className="inline-flex rounded-lg border border-outline-variant overflow-hidden">
          {DISPOSITIVOS.map((d) => (
            <button
              key={d.clave}
              type="button"
              aria-pressed={dispositivo === d.clave}
              onClick={() => setDispositivo(d.clave)}
              className={`inline-flex items-center gap-1 h-9 px-space-sm font-label-md text-label-md ${dispositivo === d.clave ? 'bg-primary text-on-primary' : 'bg-surface-container-lowest text-primary hover:bg-surface-container'}`}
            >
              <Icono nombre={d.icono} tamano="sm" />
              {t[d.clave]}
            </button>
          ))}
        </div>
      </div>
      <div ref={caja} className="w-full overflow-hidden rounded-lg border border-surface-container bg-surface-container-low" style={{ height: ALTO * escala }}>
        <iframe
          ref={marco}
          src="/cms/vista-previa"
          title={t.marco(nombre)}
          onLoad={enviar}
          className="bg-background origin-top-left mx-auto block"
          style={{ width: elegido.ancho, height: ALTO, transform: `scale(${escala})`, marginLeft: escala < 1 ? 0 : undefined }}
        />
      </div>
    </section>
  );
}
