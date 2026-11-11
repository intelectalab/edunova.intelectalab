import { expect, test } from '@playwright/test';
import { SECCION_MATRICULA } from './datos.ts';

// Flujo 1 · Matrícula: el director registra un alumno nuevo con su apoderado, lo matricula en
// ventanilla (con las condiciones económicas) y ve los cargos del cronograma generados.
test('el director registra y matricula a un alumno y ve sus cargos', async ({ page }) => {
  // Documentos ficticios distintos en cada corrida (la API no acepta DNI repetidos).
  const sufijo = String(Date.now()).slice(-6);
  const dniAlumno = `71${sufijo}`;
  const dniApoderado = `42${sufijo}`;

  await page.goto('/#/alumnos');
  await expect(page.getByRole('heading', { name: 'Alumnos', level: 1 })).toBeVisible();
  await page.getByRole('button', { name: '+ Registrar alumno' }).click();

  const registro = page.getByRole('dialog', { name: 'Registrar alumno' });
  await registro.getByLabel('DNI', { exact: true }).first().fill(dniAlumno);
  await registro.getByLabel('Apellido paterno').fill('Prueba');
  await registro.getByLabel('Apellido materno').fill('Matrícula');
  await registro.getByLabel('Nombres', { exact: true }).first().fill(`Lucía ${sufijo}`);
  await registro.getByLabel('Sexo').selectOption('M');
  await registro.getByLabel('Fecha de nacimiento').fill('2013-04-10');
  // Primer apoderado (ya viene marcado como responsable de pago).
  await registro.getByLabel('DNI', { exact: true }).nth(1).fill(dniApoderado);
  await registro.getByLabel('Apellidos', { exact: true }).fill('Prueba');
  await registro.getByLabel('Nombres', { exact: true }).nth(1).fill('Carmen');
  await registro.getByRole('button', { name: 'Registrar alumno' }).click();
  await expect(registro).toBeHidden();

  await page.getByLabel('Buscar').fill(dniAlumno);
  const fila = page.getByRole('row').filter({ hasText: dniAlumno });
  await expect(fila).toContainText('Sin matrícula');
  await fila.getByRole('button', { name: 'Ficha' }).click();

  const ficha = page.getByRole('dialog', { name: 'Ficha del alumno' });
  await ficha.getByRole('tab', { name: 'Matrícula e historial' }).click();
  await expect(ficha.getByRole('heading', { name: 'Matricular en ventanilla' })).toBeVisible();
  await ficha.getByLabel('Sección').selectOption({ label: SECCION_MATRICULA });
  await expect(ficha.getByLabel('Apoderado que firma')).toContainText('Carmen Prueba');
  await ficha.getByLabel('El apoderado leyó y acepta las condiciones económicas').check();

  const constancia = page.waitForEvent('download');
  await ficha.getByRole('button', { name: 'Matricular e imprimir constancia' }).click();
  expect((await constancia).suggestedFilename()).toBe('constancia-matricula.pdf');
  await expect(ficha.getByRole('status').filter({ hasText: /Matrícula registrada con \d+ cargos del cronograma/ })).toBeVisible();
  await expect(ficha.getByRole('heading', { name: `Matrícula 2027: ${SECCION_MATRICULA}` })).toBeVisible();
  const cargos = Number((await ficha.getByRole('status').filter({ hasText: 'Matrícula registrada' }).textContent())?.match(/con (\d+) cargos/)?.[1]);
  expect(cargos).toBeGreaterThan(0);

  // Los cargos generados aparecen en la deuda del alumno.
  await ficha.getByRole('tab', { name: 'Deuda' }).click();
  await expect(ficha.getByRole('row').filter({ hasText: 'Pensión' })).toHaveCount(cargos);

  // Y en la lista ya figura con su sección.
  await page.keyboard.press('Escape');
  await expect(page.getByRole('row').filter({ hasText: dniAlumno })).toContainText(SECCION_MATRICULA);
});
