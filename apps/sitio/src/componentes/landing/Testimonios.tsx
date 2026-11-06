import { Icono } from '@/componentes/base';
import { landing } from '@/contenido/provisional';
import type { SeccionTestimonios, Testimonio } from '@/lib/landing';
import { Encabezado } from './Encabezado';

function iniciales(t: Testimonio): string {
  const base = t.nombre ?? t.cargo;
  return base
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p.charAt(0).toUpperCase())
    .join('');
}

/**
 * EDUN-257 · Testimonios. La API solo entrega los de ejemplo o los reales con consentimiento.
 * Mientras haya alguno de ejemplo, se muestra la leyenda.
 */
export function Testimonios({ datos, testimonios }: { datos: SeccionTestimonios; testimonios: Testimonio[] }) {
  if (testimonios.length === 0) return null;
  const hayEjemplos = testimonios.some((t) => t.es_ejemplo);
  return (
    <section aria-labelledby="titulo-testimonios" className="w-full py-space-2xl bg-background">
      <div className="max-w-7xl mx-auto px-margin-mobile md:px-margin">
        <Encabezado id="titulo-testimonios" insignia={datos.insignia} titulo={datos.titulo} />
        <ul className="grid grid-cols-1 md:grid-cols-3 gap-space-lg">
          {testimonios.slice(0, 3).map((t) => (
            <li key={t.id}>
              <figure className="h-full bg-surface-container-lowest rounded-2xl shadow-sm p-space-lg flex flex-col gap-space-md">
                <span className="flex text-aviso" role="img" aria-label={landing.estrellas(t.calificacion)}>
                  {Array.from({ length: 5 }, (_, i) => (
                    <Icono key={i} nombre="star" className={i < t.calificacion ? '' : 'text-outline-variant'} />
                  ))}
                </span>
                <blockquote className="font-body-md text-body-md text-on-surface">«{t.texto}»</blockquote>
                <figcaption className="mt-auto flex items-center gap-space-sm">
                  {t.avatar ? (
                    // eslint-disable-next-line @next/next/no-img-element -- avatar autorizado de la Biblioteca
                    <img src={t.avatar} alt="" className="w-10 h-10 rounded-full object-cover" />
                  ) : (
                    <span aria-hidden="true" className="w-10 h-10 rounded-full bg-primary-fixed text-primary flex items-center justify-center font-label-lg text-label-lg">
                      {iniciales(t)}
                    </span>
                  )}
                  <span className="flex flex-col">
                    {t.nombre && <span className="font-title-md text-title-md text-on-surface">{t.nombre}</span>}
                    <span className="font-label-md text-label-md text-on-surface">{t.cargo}</span>
                    <span className="font-label-sm text-label-sm text-secondary">{t.institucion}</span>
                  </span>
                </figcaption>
              </figure>
            </li>
          ))}
        </ul>
        {hayEjemplos && <p className="mt-space-lg font-body-sm text-body-sm text-secondary italic">{datos.leyenda_ejemplo}</p>}
      </div>
    </section>
  );
}
