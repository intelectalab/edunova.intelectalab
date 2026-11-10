import { notFound } from 'next/navigation';
import { Logos } from '@/componentes/landing/Logos';
import { Testimonios } from '@/componentes/landing/Testimonios';
import { MarcoPublico } from '@/componentes/legal/MarcoPublico';
import { EncabezadoPagina } from '@/componentes/paginas/EncabezadoPagina';
import { paginas } from '@/contenido/provisional';
import { obtenerLanding } from '@/lib/landing';
import { metadatosPagina } from '@/lib/seo';

// EDUN-355 / SIT-063 · /clientes: testimonios publicados (solo con consentimiento o de ejemplo,
// con su leyenda) y logos autorizados (EDUN-334, EDUN-338).
export const dynamic = 'force-dynamic';

const t = paginas.clientes;
export const generateMetadata = () => metadatosPagina(t.titulo, t.descripcion, '/clientes');

export default async function Clientes() {
  const datos = await obtenerLanding();
  if (!datos) notFound();
  const testimonios = datos.testimonios ?? [];
  const seccion = datos.secciones.testimonios;
  const logos = datos.logos ?? [];

  return (
    <MarcoPublico datos={datos} comercial>
      <EncabezadoPagina titulo={t.titulo} descripcion={t.descripcion} ruta="/clientes" />
      {testimonios.length > 0 && seccion ? (
        <Testimonios datos={seccion} testimonios={testimonios} />
      ) : (
        <p className="max-w-7xl mx-auto px-margin-mobile md:px-margin font-body-lg text-body-lg text-on-surface-variant">{t.sinTestimonios}</p>
      )}
      {logos.length > 0 && datos.secciones.logos && <Logos datos={datos.secciones.logos} logos={logos} />}
    </MarcoPublico>
  );
}
