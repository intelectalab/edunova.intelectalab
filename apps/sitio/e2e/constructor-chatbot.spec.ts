import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { conSesionCms } from './sesion';

// Sprint 24 · Constructor visual de flujos del chatbot e historial de sesiones (EDUN-381/382 ·
// SIT-028), contra la API simulada a 1440 px y 390 px. Publicar, auditoría y permisos: Pest.

const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

test.beforeEach(async ({ context, baseURL }) => conSesionCms(context, baseURL));

test('constructor e historial cumplen WCAG 2.1 AA y no desbordan la pantalla', async ({ page }) => {
  for (const ruta of ['/cms/conversion/chatbot', '/cms/conversion/chatbot/sesiones', '/cms/conversion/chatbot/sesiones?sesion=1']) {
    await page.goto(ruta);
    if (ruta === '/cms/conversion/chatbot') {
      await page.getByLabel('Modo').selectOption('flujo');
      await page.getByRole('button', { name: 'Agregar paso: Pregunta con opciones' }).click();
    }
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    const { violations } = await new AxeBuilder({ page }).include('main').withTags(WCAG).analyze();
    expect(violations.map((v) => `${ruta}: ${v.id}`)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
  }
});

test('constructor: agrega los seis tipos de paso, los enlaza con flechas y los mueve con el teclado (EDUN-381)', async ({ page }) => {
  await page.goto('/cms/conversion/chatbot');
  await page.getByLabel('Modo').selectOption('flujo');
  const lienzo = page.getByRole('group', { name: 'Lienzo del flujo' });
  for (const tipo of ['Mensaje', 'Pregunta con opciones', 'Pedir contacto', 'Ir a una página', 'Pasar a WhatsApp', 'Fin']) {
    await page.getByRole('button', { name: `Agregar paso: ${tipo}` }).click();
  }
  await expect(lienzo.locator('[data-nodo]')).toHaveCount(6);
  // Los pasos nuevos todavía no están enlazados: la revisión lo avisa.
  await expect(page.getByRole('list', { name: 'Revisión del flujo' })).toContainText('No se llega desde el inicio a: paso 2, 3, 4, 5, 6.');

  // Paso 1 (mensaje) → paso 2 (pregunta), y la pregunta lleva al contacto.
  await lienzo.locator('[data-nodo="1"]').click();
  const edicion1 = page.getByRole('region', { name: 'Edición del paso 1' });
  await edicion1.getByLabel('Texto del bot').fill('Hola, soy el asistente.');
  await edicion1.getByLabel('Después, ir a').selectOption({ label: 'Paso 2' });
  await lienzo.locator('[data-nodo="2"]').click();
  const edicion2 = page.getByRole('region', { name: 'Edición del paso 2' });
  await edicion2.getByLabel('Opción 1', { exact: true }).fill('Quiero una demo');
  await edicion2.getByLabel('Lleva a').selectOption({ label: 'Paso 3' });
  await expect(lienzo.locator('svg path[marker-end]')).toHaveCount(2);
  await expect(lienzo.locator('svg text')).toHaveText('Quiero una demo');
  await expect(page.getByRole('list', { name: 'Revisión del flujo' })).toContainText('No se llega desde el inicio a: paso 4, 5, 6.');
  await expect(lienzo.locator('[data-nodo="1"]')).toContainText('Hola, soy el asistente.');

  // Mover con el teclado: cada flecha corre el paso 20 px.
  const nodo = lienzo.locator('[data-nodo="3"]');
  const antes = await nodo.evaluate((e) => parseInt((e as HTMLElement).style.left));
  await nodo.focus();
  await page.keyboard.press('ArrowRight');
  await page.keyboard.press('ArrowRight');
  await expect.poll(() => nodo.evaluate((e) => parseInt((e as HTMLElement).style.left))).toBe(antes + 40);

  // La vista de lista muestra los mismos pasos.
  await page.getByRole('button', { name: 'Lista', exact: true }).click();
  await expect(page.locator('li[data-paso]')).toHaveCount(6);
  await expect(page.locator('li[data-paso="2"]').getByLabel('Opción 1', { exact: true })).toHaveValue('Quiero una demo');
});

test('constructor: arrastrar un paso cambia su posición y «Ordenar» lo vuelve a la grilla (EDUN-381)', async ({ page }) => {
  await page.goto('/cms/conversion/chatbot');
  await page.getByLabel('Modo').selectOption('flujo');
  await page.getByRole('button', { name: 'Agregar paso: Mensaje' }).click();
  await page.getByRole('button', { name: 'Agregar paso: Fin' }).click();
  const nodo = page.locator('[data-nodo="2"]');
  await nodo.scrollIntoViewIfNeeded();
  const caja = (await nodo.boundingBox())!;
  await page.mouse.move(caja.x + 20, caja.y + 20);
  await page.mouse.down();
  await page.mouse.move(caja.x + 140, caja.y + 80, { steps: 6 });
  await page.mouse.up();
  const movida = (await nodo.boundingBox())!;
  expect(Math.round(movida.x - caja.x)).toBe(120);
  await page.getByRole('button', { name: 'Ordenar automáticamente' }).click();
  await expect.poll(async () => Math.round((await nodo.boundingBox())!.x)).toBe(Math.round(caja.x));
});

test('historial: filtra por resultado, contacto y texto, y abre la conversación (EDUN-381)', async ({ page }) => {
  await page.goto('/cms/conversion/chatbot');
  await page.getByRole('link', { name: 'Historial de sesiones' }).click();
  await expect(page).toHaveURL(/\/cms\/conversion\/chatbot\/sesiones$/);
  await expect(page.getByRole('heading', { name: '3 sesiones' })).toBeVisible();

  await page.getByLabel('Resultado').selectOption('url');
  await page.getByRole('button', { name: 'Filtrar' }).click();
  await expect(page).toHaveURL(/resultado=url/);
  await expect(page.getByRole('heading', { name: '1 sesión' })).toBeVisible();
  await page.getByRole('link', { name: /^Ver conversación/ }).click();
  const conversacion = page.getByRole('region', { name: 'Conversación' });
  await expect(conversacion.getByRole('list', { name: 'Conversación' }).getByRole('listitem')).toHaveCount(3);
  await expect(conversacion).toContainText('Tú: Ver precios');

  await page.getByRole('link', { name: 'Limpiar' }).click();
  await expect(page).toHaveURL(/\/sesiones$/);
  await expect(page.getByRole('heading', { name: '3 sesiones' })).toBeVisible();
  await page.locator('select[name="lead"]').selectOption('si');
  await page.getByLabel('Buscar en la conversación').fill('asesor');
  await page.getByRole('button', { name: 'Filtrar' }).click();
  await expect(page.getByRole('heading', { name: '1 sesión' })).toBeVisible();
  await page.getByRole('link', { name: /^Ver conversación/ }).click();
  await expect(page.getByRole('region', { name: 'Conversación' })).toContainText('rosa@colegio.pe');
});
