import { expect, test } from '@playwright/test';
import { SECCION_NOTAS } from './datos.ts';

const CURSO = `Matemática · ${SECCION_NOTAS}`;
const PERIODO = 'I Bimestre';

// Flujo 3 · Notas y cierre: el director registra las notas de un curso en el periodo, cierra la
// sección en «Revisión y cierre» y las notas quedan bloqueadas.
test('el director registra las notas del periodo, cierra la sección y las notas quedan bloqueadas', async ({ page }) => {
  await page.goto('/#/notas');
  await expect(page.getByRole('heading', { name: 'Notas', level: 1 })).toBeVisible();
  await page.getByLabel('Curso').selectOption({ label: CURSO });
  await page.getByLabel('Periodo').selectOption({ label: PERIODO });
  // Mientras llega la grilla del curso elegido se sigue viendo la anterior: se espera la nueva.
  await expect(page.getByText(`Matemática · ${SECCION_NOTAS} · ${PERIODO} ·`)).toBeVisible();

  const resumen = page.getByRole('status').filter({ hasText: /\d+ de \d+ notas/ });
  await expect(resumen).toContainText('vacías');
  const celdas = page.getByRole('combobox', { name: 'Nota' });
  const total = await celdas.count();
  expect(total).toBeGreaterThan(0);
  for (let i = 0; i < total; i++) {
    // Cada nota se guarda al cambiarla; se espera la respuesta antes de la siguiente.
    const guardado = page.waitForResponse((r) => r.url().includes('/celda') && r.request().method() === 'PUT' && r.ok());
    await celdas.nth(i).selectOption(i % 3 === 0 ? 'AD' : 'A');
    await guardado;
  }
  await expect(resumen).toHaveText(`${total} de ${total} notas`);

  await page.goto('/#/cierre-notas');
  await expect(page.getByRole('heading', { name: 'Revisión y cierre de notas' })).toBeVisible();
  await page.getByLabel('Periodo').selectOption({ label: PERIODO });
  const seccion = page.locator('.tarjeta').filter({ has: page.getByRole('heading', { name: SECCION_NOTAS }) });
  await expect(seccion).toContainText('100 %');
  page.once('dialog', (d) => void d.accept());
  await seccion.getByRole('button', { name: 'Cerrar sección' }).click();
  await expect(page.getByRole('status').filter({ hasText: `${SECCION_NOTAS} cerrada.` })).toBeVisible();
  await expect(seccion.getByRole('heading', { name: SECCION_NOTAS })).toContainText('Cerrado');
  await expect(seccion.getByRole('button', { name: 'Reabrir' })).toBeVisible();

  // Con la sección cerrada la grilla queda de solo lectura.
  await page.goto('/#/notas');
  await page.getByLabel('Curso').selectOption({ label: CURSO });
  await page.getByLabel('Periodo').selectOption({ label: PERIODO });
  await expect(page.getByText(`Matemática · ${SECCION_NOTAS} · ${PERIODO} ·`)).toBeVisible();
  await expect(page.getByText('El periodo está cerrado para esta sección: las notas no se pueden modificar.')).toBeVisible();
  await expect(celdas.first()).toBeDisabled();
  await expect(celdas.last()).toBeDisabled();
});
