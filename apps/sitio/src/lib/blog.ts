import 'server-only';
import { REVALIDAR_SEGUNDOS } from './landing';
import { API_URL } from './sesionCms';

// EDUN-363 / SIT-005 · Blog: el sitio lee los artículos ya limpios desde la API (que los trae
// de WordPress). ISR de 5 minutos y etiqueta «blog».

export type ResumenArticulo = { slug: string; titulo: string; resumen: string | null; imagen_url: string | null; publicado_en: string | null; autor_wp?: string | null; categoria?: string | null; categoria_nombre?: string | null };
export type ListadoBlog = { configurado: boolean; categorias: { slug: string; nombre: string }[]; data: ResumenArticulo[]; pagina: number; paginas: number };
export type Articulo = ResumenArticulo & { contenido: string | null; actualizado_wp: string | null; relacionados: ResumenArticulo[] };

async function leer<T>(ruta: string): Promise<T | null> {
  try {
    const r = await fetch(`${API_URL}${ruta}`, { headers: { Accept: 'application/json' }, next: { revalidate: REVALIDAR_SEGUNDOS, tags: ['blog'] } });
    return r.ok ? ((await r.json()) as T) : null;
  } catch {
    return null;
  }
}

export function leerBlog(categoria?: string, pagina?: string) {
  const p = new URLSearchParams();
  if (categoria && /^[a-z0-9-]+$/.test(categoria)) p.set('categoria', categoria);
  if (pagina && /^\d+$/.test(pagina)) p.set('pagina', pagina);
  return leer<ListadoBlog>(`/sitio/blog?${p}`);
}

export function leerArticulo(slug: string) {
  return /^[a-z0-9][a-z0-9-]{0,199}$/.test(slug) ? leer<Articulo>(`/sitio/blog/${slug}`) : Promise.resolve(null);
}

export const fechaArticulo = (iso: string | null) => (iso ? new Intl.DateTimeFormat('es-PE', { timeZone: 'America/Lima', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(iso)) : '');
