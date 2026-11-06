import { Cabecera } from '@/componentes/landing/Cabecera';
import { Cifras } from '@/componentes/landing/Cifras';
import { CtaFinal, Pie } from '@/componentes/landing/Cierre';
import { Hero } from '@/componentes/landing/Hero';
import { Modulos } from '@/componentes/landing/Modulos';
import { Planes } from '@/componentes/landing/Planes';
import { CasosUso, Pasos, Retos } from '@/componentes/landing/Propuesta';
import { Testimonios } from '@/componentes/landing/Testimonios';
import { Videos } from '@/componentes/landing/Videos';
import { landing, sitio } from '@/contenido/provisional';
import { obtenerLanding } from '@/lib/landing';
import { jsonLdLanding, metadatosLanding, scriptJsonLd } from '@/lib/seo';

// Landing pública. TRASPASO §3: SSR en la landing e ISR de 5 minutos para el contenido del
// CMS (solo lo publicado). Orden de secciones de TRASPASO §5.
export const dynamic = 'force-dynamic';

// EDUN-311 · Metadatos de cms.seo_paginas (la petición se comparte con la página).
export async function generateMetadata() {
  return metadatosLanding(await obtenerLanding());
}

const URL_INGRESO = process.env.EDUNOVA_WEB_URL ?? 'http://localhost:5174';

export default async function Inicio() {
  const datos = await obtenerLanding();
  const hero = datos?.secciones.hero;

  if (!datos || !hero) {
    return (
      <main className="max-w-5xl mx-auto px-margin-mobile md:px-margin py-space-2xl flex flex-col gap-space-md">
        <h1 className="font-headline-lg-mobile text-headline-lg-mobile md:font-display-lg md:text-display-lg text-primary">{sitio.titulo}</h1>
        <p className="font-body-lg text-body-lg text-on-surface-variant">{sitio.enConstruccion}</p>
      </main>
    );
  }

  const s = datos.secciones;
  const c = datos.config;
  return (
    <>
      {jsonLdLanding(datos).map((bloque, i) => (
        <script key={i} type="application/ld+json" dangerouslySetInnerHTML={{ __html: scriptJsonLd(bloque) }} />
      ))}
      <Cabecera aviso={s.barra_superior?.aviso ?? ''} telefono={(c.telefono_central as string | null) ?? null} menu={datos.menu.principal} urlIngreso={URL_INGRESO} />
      <main className="w-full pt-28 bg-surface">
        <Hero datos={hero} />
        <Cifras contadores={datos.contadores ?? []} />
        {s.retos && <Retos datos={s.retos} />}
        {s.modulos && datos.modulos?.length > 0 && <Modulos seccion={s.modulos} modulos={datos.modulos} />}
        {s.videos && <Videos datos={s.videos} videos={datos.videos ?? []} etiquetaCerrar={landing.cerrarVideo} />}
        {s.pasos && <Pasos datos={s.pasos} />}
        {s.casos_uso && <CasosUso datos={s.casos_uso} />}
        {s.testimonios && <Testimonios datos={s.testimonios} testimonios={datos.testimonios ?? []} />}
        {s.planes && (
          <Planes
            datos={s.planes}
            planes={datos.planes ?? []}
            complementos={datos.complementos ?? []}
            oferta={datos.oferta}
            notaLegal={(c.nota_legal_precios as string | null) || 'Precios referenciales sin IGV · sujeto a validación comercial'}
            selectorAnual={c.selector_anual_visible === true}
            descuentoAnual={Number(c.descuento_anual_pct ?? 0)}
            textoAnio={landing.porAnio}
          />
        )}
        {s.cta_final && <CtaFinal datos={s.cta_final} telefono={c.telefono_central} />}
      </main>
      {s.footer && <Pie datos={s.footer} config={c} enlaces={datos.menu.footer} />}
    </>
  );
}
