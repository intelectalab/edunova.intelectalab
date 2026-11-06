'use server';

import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { cmsIngreso } from '@/contenido/provisional';
import { COOKIE_CMS, llamarApi } from '@/lib/sesionCms';

export type EstadoIngreso = { error: string | null };

/** Solo rutas internas del panel, para que «volver» no lleve a otro sitio. */
function destinoSeguro(volver: FormDataEntryValue | null): string {
  const v = typeof volver === 'string' ? volver : '';
  return v.startsWith('/cms') && !v.startsWith('//') ? v : '/cms';
}

/** EDUN-226 · Ingreso al Panel CMS con el usuario del SaaS. Solo entra el superadmin. */
export async function ingresarCms(_previo: EstadoIngreso, datos: FormData): Promise<EstadoIngreso> {
  const usuario = String(datos.get('usuario') ?? '').trim();
  const password = String(datos.get('password') ?? '');
  if (!usuario || !password) return { error: cmsIngreso.errorFaltanDatos };

  let r: Response;
  try {
    r = await llamarApi('/auth/ingresar', { metodo: 'POST', cuerpo: { usuario, password, dispositivo: 'cms' } });
  } catch {
    return { error: cmsIngreso.errorConexion };
  }
  const cuerpo = await r.json().catch(() => ({}));
  if (!r.ok) {
    const primero = cuerpo?.errors ? (Object.values(cuerpo.errors)[0] as string[] | undefined)?.[0] : undefined;
    return { error: primero ?? cuerpo?.message ?? cmsIngreso.errorConexion };
  }

  if (!Array.isArray(cuerpo.roles_plataforma) || !cuerpo.roles_plataforma.includes('superadmin')) {
    // Usuario válido pero de colegio: los colegios no tienen CMS. Se cierra el token creado.
    await llamarApi('/auth/salir', { metodo: 'POST', token: cuerpo.token }).catch(() => undefined);
    return { error: cmsIngreso.errorSinAcceso };
  }

  (await cookies()).set(COOKIE_CMS, cuerpo.token, {
    httpOnly: true,
    sameSite: 'lax',
    secure: process.env.NODE_ENV === 'production',
    path: '/cms',
    maxAge: 60 * 60 * 12,
  });
  redirect(destinoSeguro(datos.get('volver')));
}

/** Cerrar sesión desde el pie del menú: invalida el token en la API y borra la cookie. */
export async function salirCms(): Promise<void> {
  const almacen = await cookies();
  const token = almacen.get(COOKIE_CMS)?.value;
  if (token) await llamarApi('/auth/salir', { metodo: 'POST', token }).catch(() => undefined);
  almacen.delete({ name: COOKIE_CMS, path: '/cms' });
  redirect('/cms/ingresar');
}
