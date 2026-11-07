import { NextResponse } from 'next/server';
import { esTipoPolitica, obtenerPolitica } from '@/lib/politicas';

// EDUN-287 · La ventana de políticas pide el texto aquí (mismo origen: la CSP solo permite
// conectar con el propio sitio). Devuelve la versión vigente desde la caché de 5 minutos.
export async function GET(_request: Request, { params }: { params: Promise<{ tipo: string }> }) {
  const { tipo } = await params;
  if (!esTipoPolitica(tipo)) return NextResponse.json({ message: 'No encontramos lo que buscas.' }, { status: 404 });

  const politica = await obtenerPolitica(tipo);
  return politica
    ? NextResponse.json(politica, { headers: { 'Cache-Control': 'public, max-age=60' } })
    : NextResponse.json({ message: 'Esta política aún no está publicada.' }, { status: 404 });
}
