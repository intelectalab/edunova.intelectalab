import type { MetadataRoute } from 'next';
import { URL_SITIO } from '@/lib/seo';

// EDUN-311 · Sitemap dinámico: las páginas públicas que existen. Las nuevas (funcionalidades,
// planes, blog…) se agregan aquí cuando llegan sus historias.
export const dynamic = 'force-dynamic';

export default function sitemap(): MetadataRoute.Sitemap {
  return [{ url: `${URL_SITIO}/`, changeFrequency: 'weekly', priority: 1, lastModified: new Date() }];
}
