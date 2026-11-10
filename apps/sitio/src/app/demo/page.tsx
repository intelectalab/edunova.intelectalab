import { MarcoPublico } from '@/componentes/legal/MarcoPublico';
import { EncabezadoPagina } from '@/componentes/paginas/EncabezadoPagina';
import { demo, paginas } from '@/contenido/provisional';
import { obtenerLanding } from '@/lib/landing';
import { metadatosPagina } from '@/lib/seo';
import { FormularioDemoLargo } from './FormularioDemoLargo';

// EDUN-359 / SIT-064 · Formulario largo /demo (más datos para el asesor). Al registrarse lleva
// a /gracias, la página de conversión.
export const dynamic = 'force-dynamic';

const t = paginas.demo;
export const generateMetadata = () => metadatosPagina(t.titulo, t.descripcion, '/demo');

const PLANES = new Set(demo.planes.map((p) => p.valor));

export default async function DemoPagina({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const [datos, { plan }] = await Promise.all([obtenerLanding(), searchParams]);
  return (
    <MarcoPublico datos={datos} comercial>
      <EncabezadoPagina titulo={t.titulo} descripcion={t.descripcion} ruta="/demo" />
      <div className="max-w-7xl mx-auto px-margin-mobile md:px-margin">
        <FormularioDemoLargo planInicial={plan && PLANES.has(plan) ? plan : ''} />
      </div>
    </MarcoPublico>
  );
}
