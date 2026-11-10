import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { conSesionCms } from './sesion';

// EDUN-230 / SIT-043 · Marco del Panel CMS: menú lateral, barra superior y navegación rápida.

test.beforeEach(async ({ context, baseURL }) => conSesionCms(context, baseURL));

test.describe('escritorio (1440 px)', () => {
  test.skip(({ viewport }) => (viewport?.width ?? 0) < 1280, 'Solo con el menú lateral completo');

  test('al pulsar un ítem del menú abre esa pantalla con el ítem resaltado', async ({ page }) => {
    await page.goto('/cms');
    const menu = page.getByRole('complementary', { name: 'Panel CMS' });
    await menu.getByRole('link', { name: 'Planes y Tarifas' }).click();

    await expect(page).toHaveURL(/\/cms\/conversion\/planes$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Planes y Tarifas' })).toBeVisible();
    await expect(menu.getByRole('link', { name: 'Planes y Tarifas' })).toHaveAttribute('aria-current', 'page');
    await expect(menu.getByRole('link', { name: 'Auditoría de cambios' })).not.toHaveAttribute('aria-current', 'page');
    // Migas y chip de navegación rápida también marcan la sección.
    await expect(page.getByRole('navigation', { name: 'Ubicación' })).toContainText('Planes y Tarifas');
    await expect(page.getByRole('navigation', { name: 'Navegación rápida' }).getByRole('link', { name: 'Planes y Tarifas' })).toHaveAttribute('aria-current', 'page');
  });

  test('el menú mide 288 px y muestra los 15 ítems del superadmin', async ({ page }) => {
    await page.goto('/cms');
    const menu = page.getByRole('complementary', { name: 'Panel CMS' });
    await expect(menu).toHaveCSS('width', '288px');
    await expect(menu.getByRole('navigation').getByRole('link')).toHaveCount(15);
  });

  test('«Ver sitio público» abre la landing en otra pestaña', async ({ page, context }) => {
    await page.goto('/cms');
    const [nueva] = await Promise.all([context.waitForEvent('page'), page.getByRole('link', { name: 'Ver sitio público' }).click()]);
    await nueva.waitForLoadState();
    expect(new URL(nueva.url()).pathname).toBe('/');
    await expect(nueva.getByRole('heading', { level: 1 })).toBeVisible();
    expect(page.url()).toMatch(/\/cms$/);
  });

  test('sin dominio configurado no afirma que el sitio está en línea', async ({ page }) => {
    await page.goto('/cms');
    await expect(page.getByText('Sin dominio publicado')).toBeVisible();
    await expect(page.getByText('En línea')).toHaveCount(0);
  });

  test('una ruta del panel que no existe responde 404', async ({ page }) => {
    const r = await page.goto('/cms/no-existe');
    expect(r?.status()).toBe(404);
  });
});

test('a 1024 px el menú es un riel de íconos y no tapa el contenido', async ({ page }) => {
  await page.setViewportSize({ width: 1024, height: 768 });
  await page.goto('/cms/auditoria');
  const menu = await page.getByRole('complementary', { name: 'Panel CMS' }).boundingBox();
  const titulo = await page.getByRole('heading', { level: 1, name: 'Auditoría de cambios' }).boundingBox();
  const barra = await page.getByRole('banner').boundingBox();

  expect(menu!.width).toBe(80);
  expect(titulo!.x).toBeGreaterThanOrEqual(menu!.x + menu!.width);
  expect(barra!.x).toBeGreaterThanOrEqual(menu!.x + menu!.width);
  // Los ítems siguen teniendo nombre accesible aunque solo se vea el ícono.
  await expect(page.getByRole('complementary', { name: 'Panel CMS' }).getByRole('link', { name: 'Auditoría de cambios' })).toHaveAttribute('aria-current', 'page');
});

test('en celular el menú se abre como panel superpuesto y se cierra con Escape', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/cms');
  const menu = page.getByRole('complementary', { name: 'Panel CMS' });
  await expect(menu).toBeHidden();

  await page.getByRole('button', { name: 'Abrir menú' }).click();
  await expect(menu).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(menu).toBeHidden();

  await page.getByRole('button', { name: 'Abrir menú' }).click();
  await menu.getByRole('link', { name: 'Módulos Web' }).click();
  await expect(page).toHaveURL(/\/cms\/contenido\/modulos$/);
  await expect(menu).toBeHidden();
  const desborde = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(desborde).toBeLessThanOrEqual(0);
});

test('el marco no tiene violaciones serias ni críticas (con el menú abierto en celular)', async ({ page, viewport }) => {
  await page.goto('/cms/seo/paginas');
  if ((viewport?.width ?? 0) < 1024) await page.getByRole('button', { name: 'Abrir menú' }).click();
  await page.evaluate(() => document.fonts.ready);
  const { violations } = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  expect(violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
});
