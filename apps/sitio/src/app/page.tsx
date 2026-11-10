import { Fragment } from 'react';
import { Cabecera } from '@/componentes/landing/Cabecera';
import { Cifras } from '@/componentes/landing/Cifras';
import { Conversion } from '@/componentes/conversion/Conversion';
import { CtaFinal, Pie } from '@/componentes/landing/Cierre';
import { Hero } from '@/componentes/landing/Hero';
import { Logos } from '@/componentes/landing/Logos';
import { Mantenimiento } from '@/componentes/legal/Mantenimiento';
import { Modulos } from '@/componentes/landing/Modulos';
import { Planes } from '@/componentes/landing/Planes';
import { CasosUso, Pasos, Retos } from '@/componentes/landing/Propuesta';
import { Testimonios } from '@/componentes/landing/Testimonios';
import { Videos } from '@/componentes/landing/Videos';
import { landing, sitio } from '@/contenido/provisional';
import { mantenimiento, obtenerLanding, type Landing } from '@/lib/landing';
import { jsonLdLanding, metadatosLanding, scriptJsonLd } from '@/lib/seo';

// Landing pública. TRASPASO §3: SSR en la landing e ISR de 5 minutos para el contenido del
// CMS (solo lo publicado). EDUN-387 · Las secciones salen en el orden y con la visibilidad
// del CMS (Editor de secciones); la barra superior, el hero y el footer van siempre en su lugar.
export const dynamic = 'force-dynamic';

// EDUN-311 · Metadatos de cms.seo_paginas (la petición se comparte con la página).
export async function generateMetadata() {
  return metadatosLanding(await obtenerLanding());
}

const URL_INGRESO = process.env.EDUNOVA_WEB_URL ?? 'http://localhost:5174';

/** Cada sección movible de la landing y cómo se pinta. */
function seccion(clave: string, datos: Landing) {
  const s = datos.secciones;
  const c = datos.config;
  switch (clave) {
    case 'retos':
      return s.retos && <Retos datos={s.retos} />;
    case 'modulos':
      return s.modulos && datos.modulos?.length > 0 && <Modulos seccion={s.modulos} modulos={datos.modulos} />;
    case 'videos':
      return s.videos && <Videos datos={s.videos} videos={datos.videos ?? []} etiquetaCerrar={landing.cerrarVideo} />;
    case 'pasos':
      return s.pasos && <Pasos datos={s.pasos} />;
    case 'casos_uso':
      return s.casos_uso && <CasosUso datos={s.casos_uso} />;
    case 'testimonios':
      return s.testimonios && <Testimonios datos={s.testimonios} testimonios={datos.testimonios ?? []} />;
    case 'logos':
      return s.logos && <Logos datos={s.logos} logos={datos.logos ?? []} />;
    case 'planes':
      return (
        s.planes && (
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
        )
      );
    case 'cta_final':
      return s.cta_final && <CtaFinal datos={s.cta_final} telefono={c.telefono_central} />;
    default:
      return null;
  }
}

const FIJAS = new Set(['barra_superior', 'hero', 'footer']);

export default async function Inicio() {
  const datos = await obtenerLanding();
  const hero = datos?.secciones.hero;
  const enMantenimiento = mantenimiento(datos);
  if (enMantenimiento) return <Mantenimiento datos={enMantenimiento} />;

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
  // La API entrega las secciones visibles en el orden del CMS.
  const orden = Object.keys(s).filter((k) => !FIJAS.has(k));
  return (
    <>
      {jsonLdLanding(datos).map((bloque, i) => (
        <script key={i} type="application/ld+json" dangerouslySetInnerHTML={{ __html: scriptJsonLd(bloque) }} />
      ))}
      <Cabecera aviso={s.barra_superior?.aviso ?? ''} telefono={(c.telefono_central as string | null) ?? null} menu={datos.menu.principal} urlIngreso={URL_INGRESO} />
      <main className="w-full pt-28 bg-surface">
        <Hero datos={hero} />
        <Cifras contadores={datos.contadores ?? []} />
        {orden.map((clave) => (
          <Fragment key={clave}>{seccion(clave, datos)}</Fragment>
        ))}
      </main>
      {s.footer && <Pie datos={s.footer} config={c} enlaces={datos.menu.footer} />}
      <Conversion tracking={datos.tracking ?? null} popup={datos.popup ?? null} chatbot={datos.chatbot ?? null} />
    </>
  );
}
