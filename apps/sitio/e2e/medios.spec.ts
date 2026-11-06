import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { conSesionCms } from './sesion';

// EDUN-237 / SIT-045 · Biblioteca de medios: subir, vista previa y límite de 5 MB.

test.beforeEach(async ({ context, baseURL }) => conSesionCms(context, baseURL));

async function abrirSelector(page: import('@playwright/test').Page) {
  await page.goto('/cms/medios');
  await page.getByRole('button', { name: 'Subir o elegir de la Biblioteca' }).click();
  return page.getByRole('dialog', { name: 'Subir o elegir de la Biblioteca' });
}

test('sube una imagen y la muestra en la vista previa con su texto alternativo', async ({ page }, info) => {
  test.skip(info.project.name !== 'escritorio', 'La API simulada guarda los medios en memoria compartida');
  const dialogo = await abrirSelector(page);
  await dialogo.getByLabel('Archivo', { exact: true }).setInputFiles({ name: 'portada.png', mimeType: 'image/png', buffer: Buffer.alloc(2048, 1) });
  await dialogo.getByLabel(/Texto alternativo/).fill('Portada del sitio');
  await dialogo.getByRole('button', { name: 'Subir' }).click();

  await expect(dialogo).toBeHidden();
  const vista = page.getByRole('region', { name: 'Vista previa' });
  await expect(vista.getByRole('img', { name: 'Portada del sitio' })).toBeVisible();
  await expect(vista).toContainText('portada.png · 1200 × 800');
});

test('un archivo de 8 MB se rechaza con «El archivo supera 5 MB» antes de subirlo', async ({ page }) => {
  const dialogo = await abrirSelector(page);
  await dialogo.getByLabel('Archivo', { exact: true }).setInputFiles({ name: 'video.png', mimeType: 'image/png', buffer: Buffer.alloc(8 * 1024 * 1024) });
  await dialogo.getByRole('button', { name: 'Subir' }).click();
  await expect(dialogo.getByRole('alert')).toHaveText('El archivo supera 5 MB.');
});

test('el selector no tiene violaciones serias ni críticas', async ({ page }) => {
  await abrirSelector(page);
  const { violations } = await new AxeBuilder({ page }).include('[role="dialog"]').analyze();
  expect(violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)).toEqual([]);
});
