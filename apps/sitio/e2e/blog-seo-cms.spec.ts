import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { conSesionCms } from './sesion';

// Sprint 23 · Previsualización del blog en el CMS (EDUN-369/370 · SIT-023) y asistente SEO con IA
// (EDUN-413/414 · SIT-030), contra la API simulada a 1440 px y 390 px. Las reglas (auditoría,
// versiones, permisos, proveedor) las prueban Pest y pruebas_cms.sql.

const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

test.beforeEach(async ({ context, baseURL }) => conSesionCms(context, baseURL));

// La API real pide la revalidación de la etiqueta «blog» al publicar; la simulada no, así que se pide aquí.
const revalidarBlog = (page: import('@playwright/test').Page) => page.request.post('/api/revalidar', { headers: { 'x-edunova-revalidar': 'secreto-e2e' }, data: { etiquetas: ['blog'] } });

test('blog y asistente SEO cumplen WCAG 2.1 AA y no desbordan la pantalla', async ({ page }) => {
  for (const ruta of ['/cms/blog', '/cms/blog?post=como-elegir-un-sistema-escolar', '/cms/seo/paginas']) {
    await page.goto(ruta);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.locator('main [role="alert"]').filter({ hasText: /No pudimos cargar/ })).toHaveCount(0);
    const { violations } = await new AxeBuilder({ page }).include('main').withTags(WCAG).analyze();
    expect(violations.map((v) => `${ruta}: ${v.id}`)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
  }
});

test('blog: lista con estado y fecha, enlace a wp-admin y vista previa con el estilo del sitio (EDUN-369)', async ({ page }) => {
  await page.goto('/cms/blog');
  await expect(page.getByRole('link', { name: 'Abrir WordPress (wp-admin)' })).toHaveAttribute('href', 'https://blog.ejemplo.pe/wp-admin/');
  const item = page.getByRole('listitem').filter({ hasText: 'Cómo elegir un sistema escolar' });
  await expect(item.getByText('Visible')).toBeVisible();
  await expect(item.getByText('1 de noviembre de 2026')).toBeVisible();
  await expect(item.getByRole('link', { name: 'Editar en WordPress' })).toHaveAttribute('href', /wp-admin\/post\.php\?post=\d+&action=edit/);
  await item.getByRole('link', { name: 'Vista previa' }).click();
  await expect(page).toHaveURL(/post=como-elegir-un-sistema-escolar/);
  const vista = page.getByRole('region', { name: 'Vista previa' });
  await expect(vista.getByRole('heading', { name: 'Cómo elegir un sistema escolar' })).toBeVisible();
  await expect(vista.getByRole('heading', { name: 'Primero' })).toBeVisible();
  await vista.getByRole('link', { name: 'Cerrar vista previa' }).click();
  await expect(page.getByRole('region', { name: 'Vista previa' })).toHaveCount(0);
});

test('blog: mostrar un post lo publica en el sitio y ocultarlo lo retira (EDUN-369)', async ({ page }, info) => {
  const slug = `revision-${info.project.name}`;
  await page.goto(`/cms/blog?post=${slug}`);
  await expect(page.getByText('Este artículo está oculto')).toBeVisible();
  await revalidarBlog(page); // la caché de datos del build puede venir de una corrida anterior
  expect((await page.request.get(`/blog/${slug}`)).status()).toBe(404);

  await page.getByRole('button', { name: new RegExp(`^Mostrar en el sitio: Post en revisión \\(${info.project.name}\\)`) }).click();
  await expect(page.getByText('El artículo se muestra en el sitio.')).toBeVisible();
  await expect(page.getByText('Este artículo está oculto')).toHaveCount(0);
  await revalidarBlog(page);
  expect((await page.request.get(`/blog/${slug}`)).status()).toBe(200);

  await page.getByRole('button', { name: new RegExp(`^Ocultar del sitio: Post en revisión \\(${info.project.name}\\)`) }).click();
  await expect(page.getByText('El artículo ya no se muestra en el sitio.')).toBeVisible();
  await revalidarBlog(page);
  expect((await page.request.get(`/blog/${slug}`)).status()).toBe(404);
});

test('asistente SEO: 3 opciones con largo y legibilidad; elegir una llena el formulario sin publicar (EDUN-413)', async ({ page }) => {
  await page.goto('/cms/seo/paginas');
  await page.getByLabel('Contexto para la IA (opcional)').fill('Colegios privados de Lima');
  await page.getByRole('button', { name: 'Generar sugerencias' }).click();
  const opciones = page.getByRole('list').filter({ hasText: 'Pide tu demo gratuita hoy' }).getByRole('listitem');
  await expect(opciones).toHaveCount(3);
  await expect(opciones.first().getByText(/caracteres \(ideal 120–155\)/)).toBeVisible();
  await expect(opciones.first().getByText(/Legibilidad 64\/100/)).toBeVisible();

  await opciones.nth(1).getByRole('button', { name: 'Usar esta' }).click();
  await expect(page.getByLabel('Meta descripción', { exact: true })).toHaveValue(/^Gestiona tu colegio en una sola plataforma/);
  await expect(opciones.nth(1).getByRole('button', { name: 'En uso' })).toBeDisabled();
  await page.getByRole('button', { name: 'Agregar «software escolar»' }).click();
  await expect(page.getByLabel('Palabras clave (separadas por comas)')).toHaveValue(/software escolar/);
  await expect(page.getByRole('button', { name: 'Guardar y actualizar metadatos' })).toBeEnabled();
  await page.getByRole('button', { name: 'Descartar cambios' }).click();
  await expect(page.getByLabel('Meta descripción', { exact: true })).not.toHaveValue(/^Gestiona tu colegio/);
});

test('asistente SEO: avisa del título repetido en otra página y del largo para Google (EDUN-413)', async ({ page }) => {
  await page.goto('/cms/seo/paginas');
  await page.getByLabel('Title tag').fill('Planes | Edunova');
  await expect(page.getByText('Igual o muy parecido en: planes')).toBeVisible();
  await expect(page.getByText('Muy corto').first()).toBeVisible();
  await page.getByRole('button', { name: 'Descartar cambios' }).click();
});

test('asistente SEO: el proveedor se elige en el CMS y avisa si falta la clave en el servidor (EDUN-413)', async ({ page }) => {
  await page.goto('/cms/seo/paginas');
  await page.getByText('Proveedor de IA', { exact: true }).first().click();
  await page.getByLabel('Proveedor de IA').selectOption('claude');
  await expect(page.getByText('El servidor todavía no tiene la clave de la IA')).toBeVisible();
  await expect(page.getByLabel('Modelo')).toHaveValue('claude-opus-5-5');
});
