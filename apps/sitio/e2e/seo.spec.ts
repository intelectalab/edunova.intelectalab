import { expect, test } from '@playwright/test';

// EDUN-311 / SIT-056 · SEO técnico del sitio.

test('la landing tiene título, descripción, canonical, Open Graph y Twitter Card del CMS', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveTitle('Edunova · Gestión escolar');
  await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', 'Software de gestión escolar para colegios privados del Perú.');
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', 'http://localhost:3000');
  await expect(page.locator('meta[property="og:title"]')).toHaveAttribute('content', 'Edunova');
  await expect(page.locator('meta[property="og:locale"]')).toHaveAttribute('content', 'es_PE');
  await expect(page.locator('meta[name="twitter:card"]')).toHaveAttribute('content', 'summary');
});

test('JSON-LD: Organization y SoftwareApplication con ofertas en PEN; FAQPage; sin Review de ejemplos', async ({ page }) => {
  await page.goto('/');
  const bloques = (await page.locator('script[type="application/ld+json"]').allTextContents()).map((t) => JSON.parse(t));
  const tipo = (t: string) => bloques.find((b) => b['@type'] === t);

  expect(tipo('Organization')).toMatchObject({ '@context': 'https://schema.org', name: 'Edunova', areaServed: 'PE' });
  const app = tipo('SoftwareApplication');
  expect(app.offers).toEqual([
    expect.objectContaining({ '@type': 'Offer', name: 'Plan Inicial', price: '300.00', priceCurrency: 'PEN' }),
    expect.objectContaining({ name: 'Plan Crecimiento', price: '500.00', priceCurrency: 'PEN' }),
  ]);
  expect(app.review).toBeUndefined();
  expect(tipo('FAQPage').mainEntity[0]).toMatchObject({ '@type': 'Question', name: '¿Funciona con SIAGIE?' });
});

test('en staging robots.txt bloquea todo y las páginas son noindex', async ({ page, request }) => {
  const robots = await (await request.get('/robots.txt')).text();
  expect(robots).toMatch(/User-Agent: \*\s+Disallow: \//i);
  await page.goto('/');
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
});

test('el sitemap lista la landing', async ({ request }) => {
  const r = await request.get('/sitemap.xml');
  expect(r.ok()).toBe(true);
  expect(await r.text()).toContain('<loc>http://localhost:3000/</loc>');
});
