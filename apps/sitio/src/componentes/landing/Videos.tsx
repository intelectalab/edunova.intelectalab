'use client';

import { useState } from 'react';
import { Icono, Modal } from '@/componentes/base';
import { registrarEvento } from '@/lib/consentimiento';
import { duracion } from '@/lib/formato';
import type { SeccionVideos, Video } from '@/lib/landing';
import { Encabezado } from './Encabezado';

/** Solo se aceptan reproductores conocidos (TRASPASO D-SIT-09: YouTube, Vimeo o S3). */
function urlReproductor(v: Video): string | null {
  try {
    const u = new URL(v.embed_url);
    const permitidos = ['www.youtube-nocookie.com', 'www.youtube.com', 'player.vimeo.com'];
    if (v.proveedor === 's3' ? u.protocol !== 'https:' : !permitidos.includes(u.hostname)) return null;
    if (v.proveedor !== 's3') u.searchParams.set('autoplay', '1');
    return u.toString();
  } catch {
    return null;
  }
}

/**
 * EDUN-254 · Hasta 3 videos que se abren en una ventana. El reproductor no se carga hasta
 * abrirla y se desmonta al cerrarla (X, Escape o clic fuera), lo que detiene el video.
 */
export function Videos({ datos, videos, etiquetaCerrar }: { datos: SeccionVideos; videos: Video[]; etiquetaCerrar: string }) {
  const [abierto, setAbierto] = useState<Video | null>(null);
  if (videos.length === 0) return null;

  function abrir(v: Video) {
    setAbierto(v);
    registrarEvento('video_play', { video_title: v.titulo });
  }

  const url = abierto ? urlReproductor(abierto) : null;

  return (
    <section id="videos" aria-labelledby="titulo-videos" className="w-full py-space-2xl bg-surface scroll-mt-28">
      <div className="max-w-7xl mx-auto px-margin-mobile md:px-margin">
        <Encabezado id="titulo-videos" insignia={datos.insignia} titulo={datos.titulo} descripcion={datos.descripcion} />
        <ul className="grid grid-cols-1 md:grid-cols-3 gap-space-lg">
          {videos.map((v) => (
            <li key={v.id}>
              <button
                type="button"
                onClick={() => abrir(v)}
                className="w-full text-left bg-surface-container-lowest rounded-2xl shadow-sm hover:shadow-md overflow-hidden flex flex-col focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
              >
                <span className="relative h-44 bg-primary-container flex items-center justify-center">
                  {v.miniatura && (
                    // eslint-disable-next-line @next/next/no-img-element -- miniaturas de la Biblioteca de medios
                    <img src={v.miniatura} alt="" className="absolute inset-0 w-full h-full object-cover" loading="lazy" />
                  )}
                  <span className="relative w-14 h-14 rounded-full bg-surface-container-lowest text-primary flex items-center justify-center shadow-md">
                    <Icono nombre="play_arrow" className="text-[2rem]" />
                  </span>
                  {v.duracion_seg && (
                    <span className="absolute bottom-space-sm right-space-sm px-space-xs rounded bg-inverse-surface text-inverse-on-surface font-label-sm text-label-sm">
                      {duracion(v.duracion_seg)}
                    </span>
                  )}
                </span>
                <span className="p-space-lg flex flex-col gap-space-xs">
                  <span className="font-title-lg text-title-lg text-primary">{v.titulo}</span>
                  {v.descripcion && <span className="font-body-sm text-body-sm text-secondary">{v.descripcion}</span>}
                </span>
              </button>
            </li>
          ))}
        </ul>
      </div>
      <Modal abierto={abierto !== null} onCerrar={() => setAbierto(null)} titulo={abierto?.titulo ?? ''} etiquetaCerrar={etiquetaCerrar}>
        <div className="flex flex-col gap-space-md">
          <div className="aspect-video w-full rounded-lg overflow-hidden bg-inverse-surface">
            {url &&
              (abierto?.proveedor === 's3' ? (
                <video src={url} controls autoPlay className="w-full h-full" />
              ) : (
                <iframe
                  src={url}
                  title={abierto?.titulo}
                  allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
                  referrerPolicy="strict-origin-when-cross-origin"
                  className="w-full h-full"
                />
              ))}
          </div>
          <a href="#demo" onClick={() => setAbierto(null)} className="self-start px-space-lg py-space-sm rounded-lg bg-accion hover:bg-accion-hover text-accion-texto font-label-lg text-label-lg">
            {datos.boton_demo}
          </a>
        </div>
      </Modal>
    </section>
  );
}
