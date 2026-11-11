import { NextResponse, type NextRequest } from 'next/server';
import { registrarSesionChatbot } from '@/app/acciones-sitio';

// EDUN-380 · Recibe con navigator.sendBeacon el cierre de una conversación del chatbot: así se
// registra aunque el visitante salga de la página al tocar un enlace o WhatsApp.
const RESULTADOS = new Set(['whatsapp', 'url', 'fin', 'abandono']);

export async function POST(request: NextRequest) {
  const d = (await request.json().catch(() => null)) as { sesion_uuid?: unknown; resultado?: unknown; recorrido?: unknown; page_url?: unknown } | null;
  if (!d || typeof d.sesion_uuid !== 'string' || typeof d.resultado !== 'string' || !RESULTADOS.has(d.resultado) || !Array.isArray(d.recorrido)) {
    return NextResponse.json({ message: 'Datos incompletos.' }, { status: 422 });
  }
  await registrarSesionChatbot({
    sesion_uuid: d.sesion_uuid,
    resultado: d.resultado as 'whatsapp' | 'url' | 'fin' | 'abandono',
    recorrido: d.recorrido.filter((x): x is string => typeof x === 'string'),
    page_url: typeof d.page_url === 'string' ? d.page_url : null,
  });
  return new NextResponse(null, { status: 204 });
}
