import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { conSesionCms } from './sesion';

// Sprint 11, contra la API simulada:
//   EDUN-347 /funcionalidades · EDUN-351 /planes · EDUN-355 /para-colegios y /clientes
//   EDUN-359 /demo y /gracias · EDUN-363 blog · EDUN-371 preguntas frecuentes · EDUN-375 chatbot
//   EDUN-403 tracking e integraciones (Panel CMS)
// La limpieza del HTML del blog, el cifrado de las claves y las reglas del flujo los prueba Pest.

const API = `http://127.0.0.1:${process.env.PUERTO_API_SIMULADA ?? 8199}`;
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

test('las páginas públicas nuevas tienen un H1, migas y cumplen WCAG 2.1 AA', async ({ page }) => {
  for (const [ruta, titulo] of [
    ['/funcionalidades', 'Funcionalidades'],
    ['/planes', 'Planes y precios'],
    ['/para-colegios', 'Modelos por tamaño de colegio'],
    ['/clientes', 'Clientes y testimonios'],
    ['/demo', 'Solicita tu demo'],
    ['/blog', 'Blog'],
    ['/preguntas-frecuentes', 'Preguntas frecuentes'],
  ]) {
    await page.goto(ruta);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(titulo);
    await expect(page.getByRole('navigation', { name: 'Ubicación' }).getByRole('link', { name: 'Inicio' })).toBeVisible();
    const { violations } = await new AxeBuilder({ page }).include('main').withTags(WCAG).analyze();
    expect(violations.map((v) => `${ruta}: ${v.id}`)).toEqual([]);
  }
});

test('/funcionalidades: el ancla del módulo muestra su detalle, beneficios y video (EDUN-347)', async ({ page }) => {
  await page.goto('/funcionalidades#modulo-m1');
  const modulo = page.locator('#modulo-m1');
  await expect(modulo.getByRole('heading', { level: 2 })).toHaveText('Matrículas');
  await expect(modulo).toContainText('Matrícula en línea con documentos, pagos y vacantes en un solo flujo.');
  await expect(modulo.getByRole('listitem').filter({ hasText: 'Vacantes al día' })).toBeVisible();
  await expect(modulo.getByRole('link', { name: 'Ver el video «Matrícula en 2 minutos»' })).toHaveAttribute('href', /youtube-nocookie/);
  await expect(page.getByRole('navigation', { name: 'Módulos' }).getByRole('link')).toHaveCount(18);
});

test('/planes: comparador con precios, características incluidas y la misma nota legal (EDUN-351)', async ({ page }) => {
  await page.goto('/planes');
  const tabla = page.getByRole('table', { name: 'Comparador de planes' });
  await expect(tabla.getByRole('columnheader')).toHaveCount(5);
  await expect(tabla.getByRole('row', { name: /Matrícula/ }).getByText('Incluida', { exact: true })).toHaveCount(1);
  await expect(tabla.getByRole('row', { name: /Facturación electrónica/ }).getByText('No incluida', { exact: true })).toHaveCount(4);
  await expect(tabla).toContainText('S/ 500');
  await expect(page.getByText('Precios referenciales sin IGV · sujeto a validación comercial')).toBeVisible();
  await tabla.getByRole('link', { name: 'Elegir Crecimiento' }).click();
  await expect(page.getByRole('dialog', { name: 'Solicitar demo gratuita' })).toBeVisible();
});

test('/para-colegios usa pestañas accesibles y /clientes muestra los testimonios con su leyenda (EDUN-355)', async ({ page }) => {
  await page.goto('/para-colegios');
  await expect(page.getByRole('tab', { name: /En crecimiento/ })).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByRole('tabpanel')).toContainText('Menos trabajo en secretaría.');
  await page.getByRole('link', { name: 'Ver testimonios de colegios' }).click();
  await expect(page).toHaveURL(/\/clientes$/);
  await expect(page.getByText(/Testimonios de ejemplo/).first()).toBeVisible();
});

test('/demo: el formulario largo envía sistema y urgencia y lleva a /gracias con el evento demo_request (EDUN-359)', async ({ page }) => {
  await page.goto('/demo');
  await page.getByLabel('Nombre completo *').fill('Rosa Larga');
  await page.getByLabel('Cargo *').selectOption({ index: 1 });
  await page.getByLabel('Teléfono / WhatsApp *').fill('987654321');
  await page.getByLabel('Correo electrónico *').fill('rosa.larga@ejemplo.pe');
  await page.getByLabel('Nombre del colegio *').fill('Colegio Largo');
  await page.getByLabel('N.° de alumnos *').selectOption('300');
  await page.getByLabel('Sistema que usan hoy (opcional)').fill('Excel');
  await page.getByLabel('¿Para cuándo lo necesitan? (opcional)').selectOption('este_mes');
  await page.locator('[data-fecha]:not([disabled])').first().click();
  await page.getByRole('button', { name: '10:30 a. m.' }).click();
  await page.getByRole('checkbox', { name: /Política de Privacidad/ }).check();
  await page.getByRole('button', { name: 'Agendar mi demo' }).click();

  await expect(page).toHaveURL(/\/gracias\?fecha=\d{4}-\d{2}-\d{2}&hora=10%3A30/);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('¡Gracias! Recibimos tu solicitud');
  const eventos = await page.evaluate(() => ((window as unknown as { dataLayer?: { event?: string }[] }).dataLayer ?? []).map((e) => e.event));
  expect(eventos).toContain('demo_request');
  const { leads } = await (await page.request.get(`${API}/__leads`)).json();
  expect(leads).toContainEqual(expect.objectContaining({ email: 'rosa.larga@ejemplo.pe', sistema_actual: 'Excel', urgencia: 'este_mes', origen: 'demo_largo' }));
});

test('blog: listado con filtro por categoría y artículo con schema Article y relacionados (EDUN-363)', async ({ page }) => {
  await page.goto('/blog');
  await expect(page.getByRole('article')).toHaveCount(2);
  await page.getByRole('navigation', { name: 'Categorías' }).getByRole('link', { name: 'Pagos' }).click();
  await expect(page).toHaveURL(/categoria=pagos/);
  await expect(page.getByRole('article')).toHaveCount(1);
  await page.goto('/blog/como-elegir-un-sistema-escolar');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('Cómo elegir un sistema escolar');
  await expect(page.getByRole('heading', { name: 'Primero' })).toBeVisible();
  await expect(page.getByRole('region', { name: 'Artículos relacionados' }).getByRole('link', { name: 'Pensiones sin morosidad' })).toBeVisible();
  const tipos = await page.locator('script[type="application/ld+json"]').evaluateAll((s) => s.map((x) => JSON.parse(x.textContent ?? '{}')['@type']));
  expect(tipos).toEqual(expect.arrayContaining(['Article', 'BreadcrumbList']));
  expect((await page.goto('/blog/no-existe'))?.status()).toBe(404);
});

test('preguntas frecuentes: búsqueda sin recargar, categorías y schema FAQPage (EDUN-371)', async ({ page }) => {
  await page.goto('/preguntas-frecuentes');
  await expect(page.locator('details')).toHaveCount(3);
  await page.getByLabel('Buscar en las preguntas').fill('pension');
  await expect(page.locator('details')).toHaveCount(1);
  await expect(page.getByText('1 pregunta')).toBeVisible();
  await page.getByLabel('Buscar en las preguntas').fill('');
  await page.getByRole('group', { name: 'Categorías' }).getByRole('button', { name: 'SIAGIE' }).click();
  await expect(page.locator('details summary')).toHaveCount(1);
  await expect(page.locator('details summary')).toContainText('¿Funciona con SIAGIE?');
  await page.locator('details summary').first().click();
  await expect(page.getByText('Exporta las actas en el formato de MINEDU.')).toBeVisible();
  const tipos = await page.locator('script[type="application/ld+json"]').evaluateAll((s) => s.map((x) => JSON.parse(x.textContent ?? '{}')['@type']));
  expect(tipos).toContain('FAQPage');
});

test('chatbot: flujo con opciones, captura del contacto y WhatsApp (EDUN-375)', async ({ page, request }, info) => {
  test.skip(info.project.name !== 'escritorio', 'Cambia el estado compartido de la API simulada');
  await request.post(`${API}/__chatbot`, { data: { activo: true } });
  await request.post('/api/revalidar', { headers: { 'x-edunova-revalidar': 'secreto-e2e' }, data: { etiquetas: ['landing'] } });
  try {
    await page.goto('/');
    await page.getByRole('button', { name: 'Abrir el chat' }).click();
    const chat = page.getByRole('dialog', { name: 'Asistente de Edunova' });
    await expect(chat.getByRole('list', { name: 'Conversación' })).toContainText('¿Qué necesitas?');
    await expect(chat.getByRole('link', { name: 'Escribir por WhatsApp' })).toHaveCount(0);
    await chat.getByRole('button', { name: 'Hablar con un asesor' }).click();
    await expect(chat.getByRole('list', { name: 'Conversación' })).toContainText('Déjanos tu contacto y te escribimos.');
    await chat.getByLabel('Correo electrónico').fill('chat@ejemplo.pe');
    await chat.getByRole('button', { name: 'Enviar' }).click();
    await expect(chat.getByRole('alert')).toContainText('acepta la Política de Privacidad');
    await chat.getByRole('checkbox').check();
    await chat.getByRole('button', { name: 'Enviar' }).click();
    await expect(chat).toContainText('¡Gracias! Un asesor te escribirá pronto.');
    const recibidos = await (await request.get(`${API}/__chatbot`)).json();
    expect(recibidos).toContainEqual(expect.objectContaining({ email: 'chat@ejemplo.pe', consentimiento: true, recorrido: expect.arrayContaining(['Tú: Hablar con un asesor']) }));
    const { violations } = await new AxeBuilder({ page }).include('[role="dialog"]').withTags(WCAG).analyze();
    expect(violations.map((v) => v.id)).toEqual([]);
  } finally {
    await request.post(`${API}/__chatbot`, { data: { activo: false } });
    await request.post('/api/revalidar', { headers: { 'x-edunova-revalidar': 'secreto-e2e' }, data: { etiquetas: ['landing'] } });
  }
});

test.describe('Panel CMS · Sprint 11', () => {
  test.beforeEach(async ({ context, baseURL }) => conSesionCms(context, baseURL));

  test('las pantallas de FAQ, chatbot e integraciones cumplen WCAG 2.1 AA', async ({ page }) => {
    for (const ruta of ['/cms/contenido/faq', '/cms/conversion/chatbot', '/cms/integraciones']) {
      await page.goto(ruta);
      await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
      const { violations } = await new AxeBuilder({ page }).include('main').withTags(WCAG).analyze();
      expect(violations.map((v) => `${ruta}: ${v.id}`)).toEqual([]);
    }
  });

  test('integraciones: un ID de GA4 inválido se rechaza y las claves nunca se muestran (EDUN-403)', async ({ page }) => {
    await page.goto('/cms/integraciones');
    await expect(page.getByLabel('Clave del CRM')).toHaveValue('');
    await expect(page.getByLabel('Clave del CRM')).toHaveAttribute('type', 'password');
    await page.getByLabel('Google Analytics 4', { exact: true }).fill('UA-123');
    await page.getByRole('button', { name: 'Guardar y aplicar' }).click();
    await expect(page.getByText('El ID de GA4 tiene la forma G-XXXXXXXXXX.')).toBeVisible();
  });

  test('chatbot: en modo flujo se agregan pasos con opciones (EDUN-375)', async ({ page }) => {
    await page.goto('/cms/conversion/chatbot');
    await page.getByLabel('Modo').selectOption('flujo');
    await page.getByRole('button', { name: 'Agregar paso' }).click();
    await page.getByRole('button', { name: 'Agregar paso' }).click();
    await expect(page.locator('[data-paso]')).toHaveCount(2);
    await page.locator('[data-paso="1"]').getByLabel('Tipo de paso').selectOption('opciones');
    await expect(page.locator('[data-paso="1"]').getByLabel('Opción 1', { exact: true })).toBeVisible();
    await expect(page.locator('[data-paso="1"]').getByLabel('Lleva a')).toHaveValue('0');
  });
});
