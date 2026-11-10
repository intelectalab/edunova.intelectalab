import type { Metadata } from 'next';
import type { Landing } from './landing';

/*
 * EDUN-311 · SEO técnico. URL pública del sitio para canonical, Open Graph y sitemap.
 * Mientras se trabaja en staging el sitio NO se indexa: solo con EDUNOVA_INDEXAR=true
 * (producción) se permite a los buscadores.
 */
export const URL_SITIO = (process.env.EDUNOVA_SITIO_URL ?? 'http://localhost:3000').replace(/\/$/, '');

export function indexable(): boolean {
  return process.env.EDUNOVA_INDEXAR === 'true';
}

export function metadatosLanding(datos: Landing | null): Metadata {
  const seo = datos?.seo;
  const titulo = seo?.titulo ?? 'Edunova';
  const descripcion = seo?.descripcion ?? undefined;
  const imagen = seo?.og_imagen ? [{ url: seo.og_imagen.url, alt: seo.og_imagen.alt ?? '', width: seo.og_imagen.ancho ?? 1200, height: seo.og_imagen.alto ?? 630 }] : undefined;
  const permitir = indexable() && (seo?.robots ?? 'index,follow').startsWith('index');

  return {
    title: titulo,
    description: descripcion,
    keywords: seo?.keywords?.length ? seo.keywords : undefined,
    alternates: { canonical: seo?.canonical ?? '/' },
    // EDUN-403 · Verificación de Search Console desde Tracking e integraciones.
    verification: datos?.tracking?.search_console ? { google: datos.tracking.search_console } : undefined,
    robots: permitir ? { index: true, follow: true } : { index: false, follow: (seo?.robots ?? 'index,follow').endsWith(',follow') },
    openGraph: {
      type: 'website',
      locale: 'es_PE',
      url: '/',
      siteName: 'Edunova',
      title: seo?.og_titulo ?? titulo,
      description: seo?.og_descripcion ?? descripcion,
      images: imagen,
    },
    twitter: {
      card: imagen ? 'summary_large_image' : 'summary',
      title: seo?.og_titulo ?? titulo,
      description: seo?.og_descripcion ?? descripcion,
      images: imagen?.map((i) => i.url),
    },
  };
}

/**
 * JSON-LD de la landing: Organization + SoftwareApplication con las ofertas en PEN.
 * FAQPage solo si hay preguntas; Review solo para testimonios reales (nunca de ejemplo).
 */
export function jsonLdLanding(datos: Landing): object[] {
  const c = datos.config;
  const organizacion = {
    '@context': 'https://schema.org',
    '@type': 'Organization',
    name: (c.razon_social as string | null) || 'Edunova',
    url: URL_SITIO,
    ...(c.email_contacto ? { email: c.email_contacto } : {}),
    ...(c.telefono_central ? { telephone: c.telefono_central } : {}),
    areaServed: 'PE',
  };

  const ofertas = (datos.planes ?? [])
    .filter((p) => p.precio_mensual !== null)
    .map((p) => ({
      '@type': 'Offer',
      name: p.nombre,
      price: Number(p.precio_mensual).toFixed(2),
      priceCurrency: 'PEN',
      url: `${URL_SITIO}/#planes`,
    }));

  const reales = (datos.testimonios ?? []).filter((t) => !t.es_ejemplo && t.nombre);
  const aplicacion = {
    '@context': 'https://schema.org',
    '@type': 'SoftwareApplication',
    name: 'Edunova',
    applicationCategory: 'BusinessApplication',
    operatingSystem: 'Web, Android, iOS',
    description: datos.seo?.descripcion ?? undefined,
    ...(ofertas.length ? { offers: ofertas } : {}),
    ...(reales.length
      ? {
          review: reales.map((t) => ({
            '@type': 'Review',
            author: { '@type': 'Person', name: t.nombre },
            reviewBody: t.texto,
            reviewRating: { '@type': 'Rating', ratingValue: t.calificacion, bestRating: 5 },
          })),
        }
      : {}),
  };

  const bloques: object[] = [organizacion, aplicacion];
  if (datos.faq?.length) {
    bloques.push({
      '@context': 'https://schema.org',
      '@type': 'FAQPage',
      mainEntity: datos.faq.map((f) => ({ '@type': 'Question', name: f.pregunta, acceptedAnswer: { '@type': 'Answer', text: f.respuesta } })),
    });
  }
  return bloques;
}

/** Serializa JSON-LD sin permitir cerrar la etiqueta <script> desde el contenido. */
export function scriptJsonLd(bloque: object): string {
  return JSON.stringify(bloque).replace(/</g, '\\u003c');
}

/** Sprint 11 · Metadatos de una página pública secundaria (funcionalidades, planes, blog…). */
export function metadatosPagina(titulo: string, descripcion: string, ruta: string, indexar = true): Metadata {
  const completo = `${titulo} · Edunova`;
  return {
    title: completo,
    description: descripcion,
    alternates: { canonical: ruta },
    robots: indexable() && indexar ? { index: true, follow: true } : { index: false, follow: true },
    openGraph: { type: 'website', locale: 'es_PE', url: ruta, siteName: 'Edunova', title: completo, description: descripcion },
  };
}
