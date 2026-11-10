import { MarcoPublico } from '@/componentes/legal/MarcoPublico';
import { EncabezadoPagina } from '@/componentes/paginas/EncabezadoPagina';
import { paginas } from '@/contenido/provisional';
import { obtenerLanding } from '@/lib/landing';
import { metadatosPagina, scriptJsonLd } from '@/lib/seo';
import { BuscadorFaq } from './BuscadorFaq';

// EDUN-371 / SIT-007 · Preguntas frecuentes con búsqueda en tiempo real, categorías y schema
// FAQPage automático (cms.faq, gestionadas en el Panel CMS).
export const dynamic = 'force-dynamic';

const t = paginas.faq;
export const generateMetadata = () => metadatosPagina(t.titulo, t.descripcion, '/preguntas-frecuentes');

export default async function PreguntasFrecuentes() {
  const datos = await obtenerLanding();
  const faq = datos?.faq ?? [];
  const jsonLd = faq.length
    ? { '@context': 'https://schema.org', '@type': 'FAQPage', mainEntity: faq.map((f) => ({ '@type': 'Question', name: f.pregunta, acceptedAnswer: { '@type': 'Answer', text: f.respuesta } })) }
    : null;

  return (
    <MarcoPublico datos={datos} comercial>
      {jsonLd && <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: scriptJsonLd(jsonLd) }} />}
      <EncabezadoPagina titulo={t.titulo} descripcion={t.descripcion} ruta="/preguntas-frecuentes" />
      <div className="max-w-4xl mx-auto px-margin-mobile md:px-margin">
        <BuscadorFaq preguntas={faq} />
      </div>
    </MarcoPublico>
  );
}
