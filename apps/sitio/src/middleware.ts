import { NextResponse, type NextRequest } from 'next/server';

// EDUN-226 · Guarda de /cms/*: sin sesión, al inicio de sesión del panel.
// Que la sesión sea del superadmin lo verifica la API en el layout del panel.
const COOKIE_CMS = 'edunova_cms';

export function middleware(request: NextRequest) {
  const { pathname, search } = request.nextUrl;
  if (pathname.startsWith('/cms/ingresar')) return NextResponse.next();
  if (request.cookies.get(COOKIE_CMS)?.value) return NextResponse.next();

  const destino = new URL('/cms/ingresar', request.url);
  destino.searchParams.set('volver', pathname + search);
  return NextResponse.redirect(destino);
}

export const config = {
  matcher: ['/cms', '/cms/:ruta*'],
};
