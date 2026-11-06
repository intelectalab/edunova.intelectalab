import type { MetadataRoute } from 'next';
import { indexable, URL_SITIO } from '@/lib/seo';

// EDUN-311 · robots.txt: en staging bloquea todo; en producción (EDUNOVA_INDEXAR=true)
// permite el sitio público y bloquea el Panel CMS y las rutas internas.
export default function robots(): MetadataRoute.Robots {
  if (!indexable()) {
    return { rules: { userAgent: '*', disallow: '/' } };
  }
  return {
    rules: { userAgent: '*', allow: '/', disallow: ['/cms', '/api', '/muestra'] },
    sitemap: `${URL_SITIO}/sitemap.xml`,
  };
}
