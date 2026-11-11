import { expect, test } from '@playwright/test';
import { APODERADO, CUOTA, ingresar } from './datos.ts';

// La familia entra con su propia sesión (no la del director).
test.use({ storageState: { cookies: [], origins: [] } });

// Flujo 2 · Pago en línea: la familia elige una cuota pendiente, paga en la pasarela simulada,
// que avisa a la API con el webhook firmado (HMAC, cabecera x-pasarela-firma), y la cuota queda
// pagada con su boleta.
test('la familia paga una cuota en línea y el webhook la marca como pagada', async ({ page, baseURL }) => {
  await ingresar(page, APODERADO.email);
  await expect(page.getByRole('navigation', { name: 'Menú principal' })).toBeVisible();

  await page.getByRole('navigation', { name: 'Menú principal' }).getByRole('link', { name: 'Mis pagos' }).click();
  await expect(page.getByRole('heading', { name: 'Mis pagos', level: 1 })).toBeVisible();
  const pendientes = page.getByRole('row').filter({ hasText: CUOTA });
  await expect(pendientes.first()).toBeVisible();
  const antes = await pendientes.count();
  expect(antes).toBeGreaterThan(0);

  await page.getByRole('checkbox', { name: `Pagar ${CUOTA}` }).first().check();
  await page.getByRole('button', { name: /^Pagar S\/ 100\.00 en línea$/ }).click();

  // La API devuelve la URL de la pasarela; la simulada es una página de esta misma web.
  await page.waitForURL(/#\/pago-simulado\/EDU-/);
  expect(new URL(page.url()).origin).toBe(new URL(baseURL!).origin);
  await expect(page.getByRole('heading', { name: 'Confirmar pago' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'S/ 100.00' })).toBeVisible();
  await page.getByRole('radio', { name: 'Yape' }).check();
  await page.getByRole('button', { name: 'Pagar', exact: true }).click();

  const confirmado = page.getByRole('status').filter({ hasText: 'Pago confirmado por la pasarela' });
  await expect(confirmado).toBeVisible();
  await expect(confirmado).toContainText(/Boleta [A-Z]\d{3}-\d+/);
  const boleta = (await confirmado.textContent())?.match(/Boleta ([A-Z]\d{3}-\d+)/)?.[1] ?? '';

  // De vuelta en «Mis pagos»: la cuota ya no está por pagar y la boleta figura en los comprobantes.
  await page.getByRole('link', { name: 'Volver a mis pagos' }).click();
  await expect(page.getByRole('heading', { name: 'Mis pagos', level: 1 })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Mis comprobantes' })).toBeVisible();
  await expect(pendientes).toHaveCount(antes - 1);
  await expect(page.getByRole('row').filter({ hasText: boleta })).toContainText('S/ 100.00');
});
