import { cookies } from 'next/headers';
import { COOKIE_CMS, llamarApi } from '@/lib/sesionCms';

// EDUN-282 / SIT-027 · Descarga del CSV de la bandeja con los mismos filtros de la pantalla.
// El navegador no ve el token: este servidor llama a la API con la cookie httpOnly del panel.
export const dynamic = 'force-dynamic';

const PERMITIDOS = ['estado', 'plan', 'ciudad', 'q', 'desde', 'hasta'];

export async function GET(request: Request) {
  const entrada = new URL(request.url).searchParams;
  const p = new URLSearchParams([...entrada.entries()].filter(([k, v]) => PERMITIDOS.includes(k) && v !== ''));
  const token = (await cookies()).get(COOKIE_CMS)?.value;

  let r: Response;
  try {
    r = await llamarApi(`/cms/leads/exportar?${p}`, { token });
  } catch {
    return new Response('No pudimos generar el archivo. Inténtalo de nuevo.', { status: 502, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
  }
  if (!r.ok) {
    const cuerpo = await r.json().catch(() => ({}));
    return new Response(String(cuerpo?.message ?? 'No pudimos generar el archivo.'), { status: r.status, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
  }
  return new Response(r.body, {
    status: 200,
    headers: {
      'Content-Type': 'text/csv; charset=UTF-8',
      'Content-Disposition': r.headers.get('content-disposition') ?? 'attachment; filename="solicitudes-edunova.csv"',
      'Cache-Control': 'no-store',
      'X-Content-Type-Options': 'nosniff',
    },
  });
}
