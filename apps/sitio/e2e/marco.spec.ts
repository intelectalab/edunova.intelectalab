import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { conSesionCms } from './sesion';

// EDUN-220 · Criterios de aceptación de pantalla. Corren a 1440 px y a 390 px (playwright.config.ts).

const PRIMARY = 'rgb(10, 50, 87)'; // #0a3257
const ACCION = 'rgb(4, 120, 87)'; // --accion (D-SIT-12, ver globals.css)

// El Panel CMS exige sesión de superadmin (EDUN-226): se entra con la API simulada.
test.beforeEach(async ({ context, baseURL }) => conSesionCms(context, baseURL));

test.describe('Fuentes y colores del prototipo', () => {
  for (const ruta of ['/', '/cms']) {
    test(`${ruta} carga Lexend, Source Sans 3 y Material Symbols`, async ({ page }) => {
      await page.goto(ruta);
      await page.evaluate(() => document.fonts.ready);
      const fuentes = await page.evaluate(async () => {
        await Promise.all([
          document.fonts.load('600 16px Lexend'),
          document.fonts.load('400 16px "Source Sans 3"'),
          document.fonts.load('24px "Material Symbols Outlined"', 'home'),
        ]);
        return {
          lexend: document.fonts.check('600 16px Lexend'),
          source: document.fonts.check('400 16px "Source Sans 3"'),
          iconos: document.fonts.check('24px "Material Symbols Outlined"', 'home'),
          cuerpo: getComputedStyle(document.body).fontFamily,
          fondo: getComputedStyle(document.body).backgroundColor,
        };
      });
      expect(fuentes.lexend).toBe(true);
      expect(fuentes.source).toBe(true);
      expect(fuentes.iconos).toBe(true);
      expect(fuentes.cuerpo).toContain('Source Sans 3');
      expect(fuentes.fondo).toBe('rgb(248, 249, 255)'); // background #f8f9ff
      const h1 = page.getByRole('heading', { level: 1 });
      await expect(h1).toHaveCSS('font-family', /Lexend/);
      await expect(h1).toHaveCSS('color', PRIMARY);
    });
  }

  test('el menú del CMS usa el navy del prototipo', async ({ page }) => {
    await page.goto('/cms');
    await expect(page.locator('aside')).toHaveCSS('background-color', PRIMARY);
  });
});

test.describe('Página de muestra de componentes', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/muestra');
    await page.evaluate(() => document.fonts.ready);
  });

  test('los componentes base se ven y funcionan', async ({ page }) => {
    const publicar = page.getByRole('button', { name: 'Publicar cambios' });
    await expect(publicar).toHaveCSS('background-color', ACCION);
    await expect(publicar).toHaveCSS('height', '40px');

    const sw = page.getByRole('switch', { name: 'Visible en el sitio' });
    await expect(sw).toBeChecked();
    await page.getByText('Visible en el sitio').click();
    await expect(sw).not.toBeChecked();

    await expect(page.getByRole('row').nth(1)).toHaveCSS('height', '52px');

    await page.getByRole('button', { name: 'Abrir modal' }).click();
    await expect(page.getByRole('dialog', { name: 'Solicitar demo gratuita' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toBeHidden();

    await page.getByRole('button', { name: 'Mostrar aviso' }).click();
    await expect(page.getByRole('status')).toHaveText(/Borrador guardado/);
  });

  test('no hay desplazamiento horizontal', async ({ page }) => {
    const desborde = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(desborde).toBeLessThanOrEqual(0);
  });
});

test.describe('Accesibilidad (axe)', () => {
  for (const ruta of ['/', '/cms', '/muestra']) {
    test(`${ruta} no tiene violaciones serias ni críticas`, async ({ page }) => {
      await page.goto(ruta);
      await page.evaluate(() => document.fonts.ready);
      const { violations } = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
      const graves = violations
        .filter((v) => v.impact === 'serious' || v.impact === 'critical')
        .map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`);
      expect(graves).toEqual([]);
    });
  }

  test('el modal abierto no tiene violaciones serias ni críticas', async ({ page }) => {
    await page.goto('/muestra');
    await page.getByRole('button', { name: 'Abrir modal' }).click();
    const { violations } = await new AxeBuilder({ page }).include('[role="dialog"]').analyze();
    expect(violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id)).toEqual([]);
  });
});
