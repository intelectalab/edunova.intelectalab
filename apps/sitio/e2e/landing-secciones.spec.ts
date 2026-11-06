import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// Sprint 5 · Secciones de la landing con los criterios de aceptación de cada historia.

test.describe('EDUN-260 · Planes y precios', () => {
  test('muestra S/ 500 / mes con la nota legal y el plan recomendado', async ({ page }) => {
    await page.goto('/');
    const crecimiento = page.locator('[data-plan="crecimiento"]');
    await expect(crecimiento).toContainText('S/ 500');
    await expect(crecimiento).toContainText('/ mes');
    await expect(crecimiento).toContainText('Precios referenciales sin IGV · sujeto a validación comercial');
    await expect(crecimiento).toContainText('Recomendado');
    await expect(page.locator('[data-plan="institucional"]')).toContainText('Personalizado');
    await expect(page.getByText('S/ 1,234')).toBeVisible();
    await expect(crecimiento.getByRole('link', { name: 'Elegir Crecimiento' })).toHaveAttribute('href', '/?plan=crecimiento#demo');
    await expect(page.getByText('¿Vienes de otro sistema?')).toBeVisible();
  });

  test('con el selector anual y 10 %, cada precio pasa a ×12 −10 %', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('button', { name: /Anual/ }).click();
    await expect(page.locator('[data-plan="crecimiento"]')).toContainText('S/ 5,400');
    await expect(page.locator('[data-plan="crecimiento"]')).toContainText('/ año');
    await expect(page.locator('[data-plan="inicial"]')).toContainText('S/ 3,240');
  });
});

test.describe('EDUN-245 · Cifras', () => {
  test('cada contador llega a su valor al entrar en pantalla', async ({ page }) => {
    await page.goto('/');
    const cifras = page.getByRole('region', { name: 'Cifras del producto' });
    await cifras.scrollIntoViewIfNeeded();
    await expect(cifras.getByText('18', { exact: true })).toBeVisible();
    await expect(cifras.getByText('24/7', { exact: true })).toBeVisible();
  });

  test('con prefers-reduced-motion se ven los valores finales sin animar', async ({ browser, baseURL }) => {
    const contexto = await browser.newContext({ reducedMotion: 'reduce', baseURL });
    const page = await contexto.newPage();
    await page.goto('/');
    const valores = await page.getByRole('region', { name: 'Cifras del producto' }).locator('[aria-hidden="true"].tabular-nums').allTextContents();
    expect(valores).toEqual(['18', '25', '24/7']);
    await contexto.close();
  });
});

test.describe('EDUN-248 · Retos, pasos y casos de uso', () => {
  test('muestra lo publicado y no las tarjetas ocultas', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Datos dispersos' })).toBeVisible();
    await expect(page.getByText('Reto oculto')).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Implementación en 4 pasos' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'En crecimiento' })).toBeVisible();
  });

  test('a 390 px los 4 pasos se apilan sin la barra conectora', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');
    const pasos = page.getByRole('region', { name: 'Implementación en 4 pasos' }).getByRole('listitem');
    await expect(pasos).toHaveCount(4);
    const cajas = await Promise.all([0, 1].map((i) => pasos.nth(i).boundingBox()));
    expect(cajas[1]!.y).toBeGreaterThan(cajas[0]!.y + cajas[0]!.height - 1);
    await expect(page.locator('[data-conector]')).toBeHidden();
  });
});

test.describe('EDUN-254 · Videos', () => {
  test('al pulsar la tarjeta se abre la ventana con el video; al cerrarla se detiene', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('iframe')).toHaveCount(0);
    await page.getByRole('button', { name: /Recorrido general/ }).click();
    const dialogo = page.getByRole('dialog', { name: 'Recorrido general' });
    await expect(dialogo.locator('iframe')).toHaveAttribute('src', /youtube-nocookie\.com\/embed\/abc123\?autoplay=1/);
    await page.keyboard.press('Escape');
    await expect(page.locator('iframe')).toHaveCount(0);

    await page.getByRole('button', { name: /Recorrido general/ }).click();
    await page.getByRole('button', { name: 'Cerrar video' }).click();
    await expect(page.locator('iframe')).toHaveCount(0);
  });
});

test.describe('EDUN-257 · Testimonios', () => {
  test('con testimonios de ejemplo se ve la leyenda', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByText('Cerramos el bimestre sin pasar notas a mano.')).toBeVisible();
    await expect(page.getByText('Testimonios de ejemplo: se reemplazan por testimonios reales con consentimiento firmado.')).toBeVisible();
    await expect(page.getByRole('img', { name: '5 de 5 estrellas' })).toBeVisible();
  });
});

test.describe('EDUN-263 · CTA final y footer', () => {
  test('Libro de Reclamaciones, RUC por confirmar y año actual', async ({ page }) => {
    await page.goto('/');
    const pie = page.getByRole('contentinfo');
    await expect(pie.getByRole('link', { name: /Libro de Reclamaciones/ })).toHaveAttribute('href', '/libro-de-reclamaciones');
    await expect(pie.getByText('RUC: [A CONFIRMAR]')).toBeVisible();
    await expect(pie).toContainText(`© ${new Date().getFullYear()}`);
    await expect(page.getByRole('heading', { name: '¿Listo para modernizar tu colegio?' })).toBeVisible();
    await expect(page.getByText('Central: [A CONFIRMAR]').first()).toBeVisible();
  });
});

test('la landing completa no tiene violaciones serias ni críticas', async ({ page }) => {
  await page.goto('/');
  await page.evaluate(() => document.fonts.ready);
  const { violations } = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  expect(violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
});
