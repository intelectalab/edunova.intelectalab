import { cookies } from 'next/headers';
import { COOKIE_CMS, llamarApi } from '@/lib/sesionCms';

// EDUN-302 · Descarga de un adjunto del reclamo (almacenamiento privado) a través del servidor.
export const dynamic = 'force-dynamic';

export async function GET(request: Request) {
  const p = new URL(request.url).searchParams;
  const id = Number(p.get('id'));
  const indice = Number(p.get('n'));
  if (!Number.isInteger(id) || id < 1 || !Number.isInteger(indice) || indice < 0) return new Response('Adjunto no válido.', { status: 400 });

  const token = (await cookies()).get(COOKIE_CMS)?.value;
  let r: Response;
  try {
    r = await llamarApi(`/cms/reclamaciones/${id}/adjuntos/${indice}`, { token });
  } catch {
    return new Response('No pudimos descargar el adjunto.', { status: 502 });
  }
  if (!r.ok) return new Response('No encontramos ese adjunto.', { status: r.status === 401 || r.status === 403 ? r.status : 404 });
  return new Response(r.body, {
    status: 200,
    headers: {
      'Content-Type': r.headers.get('content-type') ?? 'application/octet-stream',
      'Content-Disposition': r.headers.get('content-disposition') ?? 'attachment',
      'Cache-Control': 'no-store',
      'X-Content-Type-Options': 'nosniff',
    },
  });
}
