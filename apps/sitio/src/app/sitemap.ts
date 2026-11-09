import type { MetadataRoute } from 'next';
import { RUTAS_POLITICAS } from '@/lib/rutasPoliticas';
import { URL_SITIO } from '@/lib/seo';

// EDUN-311 · Sitemap dinámico: las páginas públicas que existen. Las nuevas (funcionalidades,
// planes, blog…) se agregan aquí cuando llegan sus historias. EDUN-287: políticas.
export const dynamic = 'force-dynamic';

export default function sitemap(): MetadataRoute.Sitemap {
  return [
    { url: `${URL_SITIO}/`, changeFrequency: 'weekly', priority: 1, lastModified: new Date() },
    { url: `${URL_SITIO}/libro-de-reclamaciones`, changeFrequency: 'yearly' as const, priority: 0.3 },
    ...Object.values(RUTAS_POLITICAS).map((ruta) => ({ url: `${URL_SITIO}${ruta}`, changeFrequency: 'monthly' as const, priority: 0.3 })),
  ];
}
