import { Icono } from '@/componentes/base';
import type { SeccionCasos, SeccionPasos, SeccionRetos } from '@/lib/landing';
import { Encabezado } from './Encabezado';

/** EDUN-248 · Retos de la dirección escolar: «El dolor habitual» y «Solución Edunova». */
export function Retos({ datos }: { datos: SeccionRetos }) {
  const tarjetas = datos.tarjetas.filter((t) => t.visible);
  if (tarjetas.length === 0) return null;
  return (
    <section aria-labelledby="titulo-retos" className="w-full py-space-2xl bg-background">
      <div className="max-w-7xl mx-auto px-margin-mobile md:px-margin">
        <Encabezado id="titulo-retos" insignia={datos.insignia} titulo={datos.titulo} descripcion={datos.descripcion} />
        <ul className="grid grid-cols-1 md:grid-cols-3 gap-space-lg">
          {tarjetas.map((t) => (
            <li key={t.titulo} className="bg-surface-container-lowest rounded-2xl shadow-sm p-space-lg flex flex-col gap-space-md">
              <span className="w-12 h-12 rounded-xl bg-error-container text-error flex items-center justify-center">
                <Icono nombre={t.icono} className="text-[1.75rem]" />
              </span>
              <span className="font-label-sm text-label-sm uppercase tracking-wider text-error">{datos.etiqueta_dolor}</span>
              <h3 className="font-title-lg text-title-lg text-primary">{t.titulo}</h3>
              <p className="font-body-sm text-body-sm text-secondary">{t.dolor}</p>
              <div className="mt-auto pt-space-md border-t border-surface-container flex flex-col gap-space-xs">
                <span className="flex items-center gap-space-xs font-label-md text-label-md text-accion">
                  <Icono nombre="check_circle" tamano="sm" />
                  {datos.etiqueta_solucion}
                </span>
                <p className="font-body-sm text-body-sm text-on-surface">{t.solucion}</p>
              </div>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}

/** EDUN-248 · Implementación en 4 pasos. En celular se apilan sin la barra conectora. */
export function Pasos({ datos }: { datos: SeccionPasos }) {
  const pasos = datos.pasos.filter((p) => p.visible);
  if (pasos.length === 0) return null;
  return (
    <section aria-labelledby="titulo-pasos" className="w-full py-space-2xl bg-surface">
      <div className="max-w-7xl mx-auto px-margin-mobile md:px-margin">
        <Encabezado id="titulo-pasos" insignia={datos.insignia} titulo={datos.titulo} descripcion={datos.descripcion} centrado />
        <ol className="relative grid grid-cols-1 lg:grid-cols-4 gap-space-lg">
          <span data-conector aria-hidden="true" className="hidden lg:block absolute top-6 left-[12.5%] right-[12.5%] h-0.5 bg-surface-container-high" />
          {pasos.map((p, i) => (
            <li key={p.titulo} className="relative flex flex-col items-center text-center gap-space-sm">
              <span className="w-12 h-12 rounded-full bg-primary text-on-primary flex items-center justify-center font-headline-sm text-headline-sm font-bold shadow-sm">
                {i + 1}
              </span>
              <h3 className="font-title-lg text-title-lg text-primary">{p.titulo}</h3>
              <p className="font-body-sm text-body-sm text-secondary">{p.descripcion}</p>
              {p.nota && <span className="font-label-md text-label-md text-accion">{p.nota}</span>}
            </li>
          ))}
        </ol>
      </div>
    </section>
  );
}

/** EDUN-248 · Casos de uso por tamaño de colegio. */
export function CasosUso({ datos }: { datos: SeccionCasos }) {
  const tarjetas = datos.tarjetas.filter((t) => t.visible);
  if (tarjetas.length === 0) return null;
  return (
    <section id="para-colegios" aria-labelledby="titulo-casos" className="w-full py-space-2xl bg-surface-container-low scroll-mt-28">
      <div className="max-w-7xl mx-auto px-margin-mobile md:px-margin">
        <Encabezado id="titulo-casos" insignia={datos.insignia} titulo={datos.titulo} descripcion={datos.descripcion} />
        <ul className="grid grid-cols-1 md:grid-cols-2 gap-space-lg">
          {tarjetas.map((t) => (
            <li key={t.titulo} className="bg-surface-container-lowest rounded-2xl shadow-sm p-space-lg flex flex-col gap-space-md">
              <div className="flex items-center gap-space-sm">
                <span className="w-12 h-12 rounded-xl bg-primary-fixed text-primary flex items-center justify-center">
                  <Icono nombre={t.icono} className="text-[1.75rem]" />
                </span>
                <span className="px-space-sm py-0.5 rounded-full bg-secondary-container text-on-secondary-container font-label-md text-label-md">{t.rango}</span>
              </div>
              <h3 className="font-headline-sm text-headline-sm text-primary">{t.titulo}</h3>
              <p className="font-body-md text-body-md text-secondary">{t.descripcion}</p>
              <ul className="flex flex-col gap-space-xs">
                {t.beneficios.map((b) => (
                  <li key={b} className="flex items-start gap-space-xs font-body-sm text-body-sm text-on-surface">
                    <Icono nombre="check" tamano="sm" className="text-accion mt-0.5" />
                    {b}
                  </li>
                ))}
              </ul>
              {(t.modulos || t.nota) && (
                <div className="mt-auto pt-space-md border-t border-surface-container flex flex-wrap justify-between gap-space-sm font-label-md text-label-md">
                  {t.modulos && <span className="text-secondary">{t.modulos}</span>}
                  {t.nota && <span className="text-accion">{t.nota}</span>}
                </div>
              )}
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
