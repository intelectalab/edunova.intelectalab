import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Icono } from '@/componentes/base';
import { MarcoPublico } from '@/componentes/legal/MarcoPublico';
import { EncabezadoPagina } from '@/componentes/paginas/EncabezadoPagina';
import { paginas } from '@/contenido/provisional';
import { obtenerLanding } from '@/lib/landing';
import { metadatosPagina } from '@/lib/seo';
import { PestanasColegios } from './PestanasColegios';

// EDUN-355 / SIT-063 · /para-colegios: casos por tipo de institución (En crecimiento,
// Consolidada, Multisede) desde la sección «casos_uso» del CMS.
export const dynamic = 'force-dynamic';

const t = paginas.paraColegios;
export const generateMetadata = () => metadatosPagina(t.titulo, t.descripcion, '/para-colegios');

export default async function ParaColegios() {
  const datos = await obtenerLanding();
  if (!datos) notFound();
  const casos = datos.secciones.casos_uso;
  const tarjetas = (casos?.tarjetas ?? []).filter((c) => c.visible);

  return (
    <MarcoPublico datos={datos} comercial>
      <EncabezadoPagina titulo={casos?.titulo ?? t.titulo} descripcion={casos?.descripcion ?? t.descripcion} ruta="/para-colegios" />
      <div className="max-w-7xl mx-auto px-margin-mobile md:px-margin flex flex-col gap-space-lg">
        <PestanasColegios etiqueta={t.tipos} tarjetas={tarjetas} modulosSugeridos={t.modulosSugeridos} pedirDemo={paginas.pedirDemo} />
        <Link href="/clientes" className="self-start inline-flex items-center gap-1 font-label-lg text-label-lg text-primary underline">
          <Icono nombre="reviews" tamano="sm" />
          {t.verClientes}
        </Link>
      </div>
    </MarcoPublico>
  );
}
