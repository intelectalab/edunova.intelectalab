import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { conSesionCms } from './sesion';

// Sprints 9 y 10 · Pantallas del Panel CMS contra la API simulada. Las reglas de negocio
// (precios, grid, consentimientos, restauración, versiones…) las prueban Pest y pruebas_cms.sql.

const API = `http://127.0.0.1:${process.env.PUERTO_API_SIMULADA ?? 8199}`;
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

test.beforeEach(async ({ context, baseURL }) => conSesionCms(context, baseURL));

test('las pantallas nuevas del panel cumplen WCAG 2.1 AA', async ({ page }) => {
  for (const ruta of ['/cms', '/cms/sitio/estructura', '/cms/conversion/popup', '/cms/configuracion', '/cms/contenido/logos', '/cms/usuarios', '/cms/auditoria', '/cms/seo/paginas', '/cms/legal/politicas']) {
    await page.goto(ruta);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.locator('main [role="alert"]').filter({ hasText: /No pudimos cargar/ })).toHaveCount(0);
    const { violations } = await new AxeBuilder({ page }).include('main').withTags(WCAG).analyze();
    expect(violations.map((v) => `${ruta}: ${v.id}`)).toEqual([]);
  }
});

test('dashboard: indicadores, tendencia y pendientes con accesos directos (EDUN-383)', async ({ page }) => {
  await page.goto('/cms');
  await expect(page.getByText('Solicitudes de hoy')).toBeVisible();
  await expect(page.getByText('+50 % vs. 7 días previos')).toBeVisible();
  await expect(page.getByRole('table', { name: 'Solicitudes por día (últimos 30 días)' }).locator('tr')).toHaveCount(30);
  await page.getByRole('link', { name: '1 reclamación vencida sin respuesta' }).click();
  await expect(page).toHaveURL(/\/cms\/reclamaciones\?plazo=vencidos/);
});

test('hero: el previsualizador cambia al escribir, sin guardar (EDUN-322)', async ({ page }) => {
  await page.goto('/cms/sitio/secciones');
  await page.getByLabel('Titular (H1)').fill('Titular escrito en vivo');
  const marco = page.frameLocator('iframe[title^="Vista previa de la portada"]');
  await expect(marco.getByRole('heading', { level: 1 })).toHaveText('Titular escrito en vivo');
  await page.getByRole('button', { name: 'Celular' }).click();
  await expect(page.locator('iframe[title="Vista previa de la portada en celular"]')).toHaveCount(1);
});

test('SEO: con 75 caracteres el contador queda en rojo y no se guarda (EDUN-314)', async ({ page }) => {
  await page.goto('/cms/seo/paginas');
  await page.getByLabel('Title tag').fill('x'.repeat(75));
  await expect(page.locator('[data-contador]').first()).toHaveAttribute('data-contador', 'excedido');
  await expect(page.locator('[data-contador]').first()).toHaveClass(/text-error/);
  await expect(page.getByRole('button', { name: 'Guardar y actualizar metadatos' })).toBeDisabled();
  await page.getByRole('button', { name: 'Descartar cambios' }).click();
  await expect(page.getByLabel('Title tag')).toHaveValue('Edunova · Sistema de gestión escolar');
});

test('logos: sin logos se ve el aviso y el interruptor de la sección está deshabilitado (EDUN-338)', async ({ page }) => {
  await page.goto('/cms/contenido/logos');
  await expect(page.getByText('Aún no hay logos publicados.')).toBeVisible();
  await expect(page.getByRole('switch', { name: 'Mostrar la sección en la landing' })).toBeDisabled();
});

test('editor de secciones: ordenar con flechas y publicar; el hero y el footer no se ocultan (EDUN-387)', async ({ page }, info) => {
  await page.goto('/cms/sitio/estructura');
  await expect(page.getByRole('switch', { name: /Hero y Portada/ })).toBeDisabled();
  await expect(page.getByRole('switch', { name: /Footer/ })).toBeDisabled();
  test.skip(info.project.name !== 'escritorio', 'Cambia el estado compartido de la API simulada');
  await page.getByRole('button', { name: 'Subir «Videos»' }).click();
  await expect(page.getByText('«Videos» ahora está en la posición 4.')).toBeAttached();
  await page.getByRole('button', { name: 'Publicar' }).click();
  await expect(page.getByText(/Cambios publicados/).first()).toBeVisible();
  const publicadas = await (await page.request.get(`${API}/__publicaciones`)).json();
  const orden = publicadas.findLast((p: { clave: string }) => p.clave === '_estructura').contenido.lista.map((s: { clave: string }) => s.clave);
  expect(orden.indexOf('videos')).toBeLessThan(orden.indexOf('modulos'));
});

test('auditoría: filtrar por sección y restaurar con confirmación en la pantalla (EDUN-342)', async ({ page }, info) => {
  await page.goto('/cms/auditoria');
  await page.getByRole('search').getByLabel('Sección').selectOption('planes');
  // Con la acción fija: otro proyecto puede haber restaurado antes (estado compartido de la API simulada).
  await page.getByRole('search').getByLabel('Acción').selectOption('publicado');
  await page.getByRole('button', { name: 'Aplicar' }).click();
  await expect(page).toHaveURL(/seccion=planes/);
  await expect(page.getByRole('table', { name: 'Registro de cambios' }).locator('tbody tr')).toHaveCount(1);
  await expect(page.locator('[data-total-auditoria]')).toHaveAttribute('data-total-auditoria', '1');
  await expect(page.getByRole('complementary', { name: 'Detalle del cambio' })).toContainText('111.00');

  test.skip(info.project.name !== 'escritorio', 'Cambia el estado compartido de la API simulada');
  const confirmar = page.getByRole('alertdialog');
  // Reintenta el clic hasta que la página termine de hidratarse.
  await expect(async () => {
    await page.getByRole('button', { name: 'Restaurar el valor anterior' }).click({ timeout: 1000 });
    await expect(confirmar).toBeVisible({ timeout: 1000 });
  }).toPass();
  await expect(confirmar).toContainText('No se publica hasta que lo revises');
  await confirmar.getByRole('button', { name: 'Sí, restaurar como borrador' }).click();
  await expect(page.getByText(/restaurado como borrador/).first()).toBeVisible();
  await page.goto('/cms/auditoria?seccion=planes&accion=restaurado');
  await expect(page.getByRole('table', { name: 'Registro de cambios' })).toContainText('Restaurado');
});

test('políticas: publicar una versión nueva programada que aparece en el historial (EDUN-395)', async ({ page }, info) => {
  await page.goto('/cms/legal/politicas?tipo=cookies');
  await expect(page.locator('[data-version="1.0"]')).toContainText('Vigente');
  test.skip(info.project.name !== 'escritorio', 'Cambia el estado compartido de la API simulada');
  await page.getByLabel('Número de versión').fill('1.1');
  await page.getByLabel('Vigente desde').fill('2030-01-01');
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: 'Publicar versión' }).click();
  await expect(page.locator('[data-version="1.1"]')).toContainText('Programada');
});

test('configuración: un RUC inválido se rechaza con su mensaje (EDUN-399)', async ({ page }, info) => {
  // En celular la barra con «Guardar borrador» no se muestra (EDUN-230).
  test.skip(info.project.name !== 'escritorio', 'La barra del editor solo está en escritorio');
  await page.goto('/cms/configuracion');
  await page.getByLabel('RUC').fill('123');
  await page.getByRole('button', { name: 'Guardar borrador' }).click();
  await expect(page.getByText('El RUC tiene 11 dígitos y empieza con 10, 15, 17 o 20.')).toBeVisible();
});

test('usuarios: lista de solo lectura con los superadministradores (EDUN-407)', async ({ page }) => {
  await page.goto('/cms/usuarios');
  const tabla = page.getByRole('table', { name: 'Usuarios con acceso al Panel CMS' });
  await expect(tabla).toContainText('Ana Administradora');
  await expect(tabla).toContainText('Superadministrador');
  await expect(page.getByRole('button')).not.toContainText(['Agregar', 'Eliminar']);
});
