import { timingSafeEqual } from 'node:crypto';
import { revalidateTag } from 'next/cache';
import { NextResponse, type NextRequest } from 'next/server';

// EDUN-233 · La API llama aquí al publicar desde el CMS para que la landing se actualice al
// instante (si falla, el ISR de 5 minutos la renueva igual). Protegido con un secreto compartido.
const PERMITIDAS = new Set(['landing']);

function secretoValido(recibido: string | null): boolean {
  const esperado = process.env.REVALIDAR_SECRETO;
  if (!esperado || !recibido) return false;
  const a = Buffer.from(recibido);
  const b = Buffer.from(esperado);
  return a.length === b.length && timingSafeEqual(a, b);
}

export async function POST(request: NextRequest) {
  if (!secretoValido(request.headers.get('x-edunova-revalidar'))) {
    return NextResponse.json({ message: 'No autorizado.' }, { status: 401 });
  }
  const cuerpo = (await request.json().catch(() => ({}))) as { etiquetas?: unknown };
  const etiquetas = Array.isArray(cuerpo.etiquetas) ? cuerpo.etiquetas.filter((e): e is string => typeof e === 'string' && PERMITIDAS.has(e)) : [];
  etiquetas.forEach((e) => revalidateTag(e));
  return NextResponse.json({ revalidadas: etiquetas });
}
