import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { abrirMenuCms, conSesionCms } from './sesion';

// EDUN-226 / SIT-042 · Acceso al Panel CMS solo para el superadmin del SaaS.
const CLAVE = 'clave-de-prueba';

async function ingresar(page: import('@playwright/test').Page, usuario: string, clave = CLAVE) {
  await page.getByLabel('Correo o DNI').fill(usuario);
  await page.getByLabel('Contraseña').fill(clave);
  await page.getByRole('button', { name: 'Ingresar' }).click();
}

test('un visitante sin sesión que abre /cms va al inicio de sesión', async ({ page }) => {
  await page.goto('/cms/auditoria');
  await expect(page).toHaveURL(/\/cms\/ingresar\?volver=%2Fcms%2Fauditoria$/);
  await expect(page.getByRole('heading', { name: 'Ingresa al Panel CMS' })).toBeVisible();
});

test('con datos errados muestra el mensaje de la API', async ({ page }) => {
  await page.goto('/cms/ingresar');
  await ingresar(page, 'superadmin@demo.edunova.pe', 'mala');
  await expect(page.locator('main [role="alert"]')).toHaveText('El documento, correo o contraseña no son correctos.');
});

test('un usuario de colegio no entra al Panel CMS', async ({ page, context }) => {
  await page.goto('/cms/ingresar');
  await ingresar(page, 'director@demo.edunova.pe');
  await expect(page.locator('main [role="alert"]')).toContainText('no tiene acceso al Panel CMS');
  expect((await context.cookies()).find((c) => c.name === 'edunova_cms')).toBeUndefined();
});

test('con una sesión de colegio, /cms responde 403 y no muestra el panel', async ({ page, context, baseURL }) => {
  await conSesionCms(context, baseURL, 'tk-director');
  const respuesta = await page.goto('/cms');
  expect(respuesta?.status()).toBe(403);
  await expect(page.getByRole('heading', { name: 'No tienes acceso al Panel CMS' })).toBeVisible();
  await expect(page.locator('aside')).toHaveCount(0);
});

test('el superadmin entra, vuelve a donde iba y ve su nombre y «Superadministrador»', async ({ page }) => {
  await page.goto('/cms');
  await ingresar(page, 'superadmin@demo.edunova.pe');
  await expect(page).toHaveURL(/\/cms$/);
  await abrirMenuCms(page);
  const menu = page.locator('aside');
  await expect(menu.getByText('Ana Administradora')).toBeVisible();
  await expect(menu.getByText('Superadministrador')).toBeVisible();
});

test('cerrar sesión desde el pie del menú vuelve al inicio de sesión', async ({ page, context, baseURL }) => {
  await conSesionCms(context, baseURL);
  await page.goto('/cms');
  await abrirMenuCms(page);
  await page.getByRole('button', { name: 'Cerrar sesión' }).click();
  await expect(page).toHaveURL(/\/cms\/ingresar/);
  await page.goto('/cms');
  await expect(page).toHaveURL(/\/cms\/ingresar/);
});

test('el inicio de sesión del panel no tiene violaciones serias ni críticas', async ({ page }) => {
  await page.goto('/cms/ingresar');
  await page.evaluate(() => document.fonts.ready);
  const { violations } = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  expect(violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)).toEqual([]);
});
