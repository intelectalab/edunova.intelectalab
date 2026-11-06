import type { BrowserContext, Page } from '@playwright/test';

/** Sesión del Panel CMS ya iniciada: misma cookie httpOnly (path /cms) que deja el ingreso real. */
export async function conSesionCms(contexto: BrowserContext, baseURL: string | undefined, token = 'tk-superadmin') {
  const { hostname } = new URL(baseURL ?? 'http://127.0.0.1');
  await contexto.addCookies([{ name: 'edunova_cms', value: token, domain: hostname, path: '/cms', httpOnly: true, sameSite: 'Lax' }]);
}

/** En celular el menú del panel empieza cerrado (EDUN-230): se abre si hace falta. */
export async function abrirMenuCms(page: Page) {
  const boton = page.getByRole('button', { name: 'Abrir menú' });
  if (await boton.isVisible()) await boton.click();
}
