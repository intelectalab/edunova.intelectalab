import { notFound } from 'next/navigation';
import { Icono } from '@/componentes/base';
import { MarcoPublico } from '@/componentes/legal/MarcoPublico';
import { EncabezadoPagina } from '@/componentes/paginas/EncabezadoPagina';
import { paginas } from '@/contenido/provisional';
import { soles } from '@/lib/formato';
import { obtenerLanding, type Plan } from '@/lib/landing';
import { metadatosPagina } from '@/lib/seo';

// EDUN-351 / SIT-004 · /planes con comparador: los mismos planes, precios, complementos y nota
// legal que la landing (cms.planes_sitio), en una tabla por característica.
export const dynamic = 'force-dynamic';

const t = paginas.planes;
export const generateMetadata = () => metadatosPagina(t.titulo, t.descripcion, '/planes');

/** Todas las características, en el orden en que aparecen en los planes. */
function filas(planes: Plan[]): string[] {
  const vistas = new Set<string>();
  for (const p of planes) for (const c of p.caracteristicas) vistas.add(c.texto.trim());
  return [...vistas];
}

export default async function PlanesPagina() {
  const datos = await obtenerLanding();
  if (!datos) notFound();
  const planes = datos.planes ?? [];
  const seccion = datos.secciones.planes;
  const notaLegal = (datos.config.nota_legal_precios as string | null) || 'Precios referenciales sin IGV · sujeto a validación comercial';
  const caracteristicas = filas(planes);
  const incluye = (p: Plan, texto: string) => p.caracteristicas.find((c) => c.texto.trim() === texto)?.incluida === true;

  return (
    <MarcoPublico datos={datos} comercial>
      <EncabezadoPagina titulo={t.titulo} descripcion={seccion?.descripcion || t.descripcion} ruta="/planes" />
      <div className="max-w-7xl mx-auto px-margin-mobile md:px-margin flex flex-col gap-space-lg">
        <div className="rounded-2xl border border-surface-container bg-surface-container-lowest overflow-hidden">
          <div className="overflow-x-auto" tabIndex={0} role="region" aria-label={t.comparador}>
            <table className="w-full min-w-[40rem] border-collapse">
              <caption className="sr-only">{t.comparador}</caption>
              <thead>
                <tr className="bg-surface-container-low">
                  <th scope="col" className="p-space-md text-left font-label-md text-label-md text-secondary uppercase tracking-wider">
                    {t.caracteristica}
                  </th>
                  {planes.map((p) => (
                    <th key={p.clave} scope="col" data-plan={p.clave} className={`p-space-md text-center align-bottom ${p.recomendado ? 'bg-accion/10' : ''}`}>
                      {p.recomendado && <span className="block mb-1 font-label-sm text-label-sm text-accion uppercase">{t.recomendado}</span>}
                      <span className="block font-title-lg text-title-lg text-primary">{p.nombre}</span>
                      <span className="block font-label-md text-label-md text-secondary">{p.rango_texto}</span>
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-surface-container font-body-md text-body-md">
                <tr>
                  <th scope="row" className="p-space-md text-left font-semibold text-on-surface">
                    {t.precio}
                  </th>
                  {planes.map((p) => (
                    <td key={p.clave} className={`p-space-md text-center ${p.recomendado ? 'bg-accion/5' : ''}`}>
                      <span className="font-headline-md text-headline-md text-primary font-bold">{p.precio_mensual ? soles(p.precio_mensual) : (seccion?.personalizado ?? t.aMedida)}</span>
                      {p.precio_mensual && <span className="block font-label-sm text-label-sm text-secondary">{seccion?.por_mes ?? '/ mes'}</span>}
                    </td>
                  ))}
                </tr>
                {caracteristicas.map((c) => (
                  <tr key={c}>
                    <th scope="row" className="p-space-md text-left font-normal text-on-surface">
                      {c}
                    </th>
                    {planes.map((p) => (
                      <td key={p.clave} className={`p-space-md text-center ${p.recomendado ? 'bg-accion/5' : ''}`}>
                        {incluye(p, c) ? <Icono nombre="check_circle" className="text-accion" /> : <Icono nombre="remove" className="text-outline" />}
                        <span className="sr-only">{incluye(p, c) ? t.incluida : t.noIncluida}</span>
                      </td>
                    ))}
                  </tr>
                ))}
                <tr>
                  <td />
                  {planes.map((p) => (
                    <td key={p.clave} className={`p-space-md text-center ${p.recomendado ? 'bg-accion/5' : ''}`}>
                      <a href={`/planes?plan=${encodeURIComponent(p.clave)}#demo`} className={`inline-flex px-space-md py-space-sm rounded-lg font-label-lg text-label-lg ${p.recomendado ? 'bg-accion hover:bg-accion-hover text-accion-texto' : 'bg-surface-container hover:bg-surface-container-high text-primary'}`}>
                        {p.cta_texto}
                      </a>
                    </td>
                  ))}
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <p className="font-label-md text-label-md text-secondary">{notaLegal}</p>

        {(datos.complementos ?? []).length > 0 && (
          <section aria-labelledby="titulo-complementos" className="flex flex-col gap-space-sm">
            <h2 id="titulo-complementos" className="font-headline-md text-headline-md text-primary">
              {t.complementos}
            </h2>
            <ul className="grid gap-space-sm sm:grid-cols-2 lg:grid-cols-3">
              {datos.complementos.map((c) => (
                <li key={c.clave} className="p-space-md rounded-xl bg-surface-container-lowest border border-surface-container flex flex-col gap-1">
                  <span className="font-title-md text-title-md text-on-surface">{c.etiqueta}</span>
                  <span className="font-title-lg text-title-lg text-primary">{c.precio ? soles(c.precio) : t.aMedida}</span>
                  {c.nota && <span className="font-label-sm text-label-sm text-secondary">{c.nota}</span>}
                </li>
              ))}
            </ul>
          </section>
        )}
      </div>
    </MarcoPublico>
  );
}
