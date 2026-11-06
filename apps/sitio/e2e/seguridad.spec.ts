import { expect, test } from '@playwright/test';

// EDUN-209 · Cabeceras de seguridad del sitio y del Panel CMS (build de producción).

for (const ruta of ['/', '/cms/ingresar']) {
  test(`${ruta} envía CSP, HSTS y cabeceras contra clickjacking`, async ({ request }) => {
    const r = await request.get(ruta);
    const h = r.headers();
    expect(h['content-security-policy']).toContain("frame-ancestors 'none'");
    expect(h['content-security-policy']).toContain("default-src 'self'");
    expect(h['content-security-policy']).not.toContain("'unsafe-eval'");
    expect(h['x-frame-options']).toBe('DENY');
    expect(h['x-content-type-options']).toBe('nosniff');
    expect(h['strict-transport-security']).toContain('max-age=31536000');
    expect(h['x-powered-by']).toBeUndefined();
  });
}

test('con la CSP activa la landing carga sin errores de política en consola', async ({ page }) => {
  const errores: string[] = [];
  page.on('console', (m) => m.type() === 'error' && /Content Security Policy/i.test(m.text()) && errores.push(m.text()));
  await page.goto('/');
  await page.getByRole('button', { name: /Recorrido general/ }).click();
  await expect(page.getByRole('dialog').locator('iframe')).toBeVisible();
  expect(errores).toEqual([]);
});
