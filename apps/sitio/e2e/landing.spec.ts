import { expect, test } from '@playwright/test';

// EDUN-242 / SIT-001 · Barra superior, navegación y hero de la landing.
// EDUN-233 / SIT-044 · Un cambio publicado llega a la landing al revalidar.

const API = `http://127.0.0.1:${process.env.PUERTO_API_SIMULADA ?? 8199}`;

test('muestra el hero publicado en el CMS con un único H1', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
  // El texto exacto lo cambia la prueba de revalidación; aquí basta que venga del CMS.
  await expect(page.getByRole('heading', { level: 1 })).not.toBeEmpty();
  await expect(page.getByText('Gestión escolar K-12')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Solicitar demo gratuita' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Ver video demostrativo' })).toBeVisible();
});

test('la barra superior lleva al Panel CMS y, sin teléfono, muestra [A CONFIRMAR]', async ({ page, viewport }) => {
  test.skip((viewport?.width ?? 0) < 768, 'En celular el teléfono y el enlace se ocultan');
  await page.goto('/');
  await expect(page.getByRole('link', { name: 'Panel CMS' })).toHaveAttribute('href', '/cms');
  await expect(page.getByText('Atención Lima: [A CONFIRMAR]')).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Menú principal' }).getByRole('link', { name: 'Funcionalidades' })).toBeVisible();
});

test('a 390 px no hay scroll horizontal y «Solicitar demo» sigue visible', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/');
  const desborde = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(desborde).toBeLessThanOrEqual(0);
  await expect(page.getByRole('banner').getByRole('link', { name: 'Solicitar demo' })).toBeVisible();
  await expect(page.getByText('Atención Lima: [A CONFIRMAR]')).toBeHidden();
});

test('un H1 publicado aparece en la landing al revalidar', async ({ page, request }, info) => {
  // Cambia el contenido compartido de la API simulada: corre en un solo tamaño.
  test.skip(info.project.name !== 'escritorio', 'Se prueba una vez');
  await page.goto('/');
  const nuevo = `Titular publicado ${Date.now()}`;
  await request.post(`${API}/__publicar`, { data: { titulo: nuevo } });

  // Sin revalidar, la landing sigue con el contenido en caché (ISR de 5 minutos).
  await page.reload();
  await expect(page.getByRole('heading', { level: 1 })).not.toHaveText(nuevo);

  // Una revalidación con secreto falso no hace nada.
  expect((await request.post('/api/revalidar', { headers: { 'x-edunova-revalidar': 'falso' }, data: { etiquetas: ['landing'] } })).status()).toBe(401);

  // La API revalida al publicar: la landing muestra el nuevo H1.
  const r = await request.post('/api/revalidar', { headers: { 'x-edunova-revalidar': 'secreto-e2e' }, data: { etiquetas: ['landing'] } });
  expect(await r.json()).toEqual({ revalidadas: ['landing'] });
  await page.reload();
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(nuevo);

  await request.post(`${API}/__publicar`, { data: { titulo: 'Administra tu colegio desde un solo lugar' } });
  await request.post('/api/revalidar', { headers: { 'x-edunova-revalidar': 'secreto-e2e' }, data: { etiquetas: ['landing'] } });
});
