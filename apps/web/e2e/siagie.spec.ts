import { expect, test } from '@playwright/test';
import { PLANTILLA_SIAGIE, SECCION_NOTAS } from './datos.ts';

// Flujo 4 · Exportación al SIAGIE: con las notas completas (II Bimestre de la sección E2E-N,
// preparadas por E2eSeeder) se valida, se llena la plantilla vacía del SIAGIE y se descarga
// el Excel resultante.
test('el director valida las notas, llena la plantilla del SIAGIE y la descarga', async ({ page }) => {
  await page.goto('/#/siagie-archivos');
  await expect(page.getByRole('heading', { name: 'SIAGIE', level: 1 })).toBeVisible();
  await page.getByRole('tab', { name: 'Notas al SIAGIE' }).click();

  const tarjeta = page.locator('.tarjeta').filter({ has: page.getByRole('heading', { name: 'Validar y llenar la plantilla de una sección' }) });
  await tarjeta.getByLabel('Sección').selectOption({ label: SECCION_NOTAS });
  await tarjeta.getByLabel('Periodo').selectOption({ label: 'II Bimestre' });
  await tarjeta.getByRole('button', { name: 'Validar notas' }).click();
  await expect(tarjeta.getByText('Las notas están completas: puedes generar la plantilla.')).toBeVisible();

  await tarjeta.locator('input[type="file"]').first().setInputFiles(PLANTILLA_SIAGIE);
  await expect(tarjeta.getByText('plantilla-siagie-e2e.xlsx')).toBeVisible();
  await tarjeta.getByRole('button', { name: 'Llenar plantilla' }).click();
  const lista = tarjeta.getByRole('status').filter({ hasText: 'Plantilla lista' });
  await expect(lista).toContainText(/Plantilla lista: \d+ notas de 2 alumnos/);

  // El resultado se descarga con una URL firmada del almacenamiento: se abre en otra pestaña y
  // Chromium atribuye la descarga a la página que la abrió.
  const espera = page.waitForEvent('download');
  await lista.getByRole('button', { name: 'Descargar' }).click();
  const descarga = await espera;
  expect(descarga.suggestedFilename()).toBe('llenada-plantilla-siagie-e2e.xlsx');
  expect(await descarga.failure()).toBeNull();
});
