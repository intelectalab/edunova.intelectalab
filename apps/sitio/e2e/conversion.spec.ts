import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

// Sprint 6 · Conversión y legal del sitio público, contra la API simulada:
//   EDUN-267 / SIT-002  Solicitar demo con calendario
//   EDUN-287 / SIT-053  Políticas de privacidad, términos y seguridad
//   EDUN-291 / SIT-054  Consentimiento en el formulario
//   EDUN-306 / SIT-055  Banner de cookies con Consent Mode v2
// Que la API guarde el lead y su consentimiento, y rechace sin casilla, lo prueban apps/api (Pest).

const API = `http://127.0.0.1:${process.env.PUERTO_API_SIMULADA ?? 8199}`;
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

const ventanaDemo = (page: Page) => page.getByRole('dialog', { name: 'Solicitar demo gratuita' });

async function abrirDemo(page: Page) {
  await page.goto('/');
  await page.locator('section[aria-labelledby="titulo-hero"]').getByRole('link', { name: 'Solicitar demo gratuita' }).click();
  await expect(ventanaDemo(page)).toBeVisible();
  return ventanaDemo(page);
}

async function llenarContacto(page: Page, email: string) {
  const v = ventanaDemo(page);
  await v.getByLabel('Nombre completo *').fill('Rosa Quispe');
  await v.getByLabel('Cargo *').selectOption('Director(a)');
  await v.getByLabel('Teléfono / WhatsApp *').fill('987 654 321');
  await v.getByLabel('Correo electrónico *').fill(email);
  await v.getByLabel('Nombre del colegio *').fill('Colegio de prueba');
  await v.getByLabel('N.° de alumnos *').selectOption('300');
}

async function leadsRecibidos(page: Page, email: string) {
  const r = await page.request.get(`${API}/__leads`);
  const { leads, consentimientos } = (await r.json()) as { leads: Record<string, unknown>[]; consentimientos: Record<string, unknown>[] };
  return { leads: leads.filter((l) => l.email === email), consentimientos: consentimientos.filter((c) => c.email === email) };
}

test.describe('Solicitar demo con calendario (EDUN-267)', () => {
  test('incompleto: avisa qué falta y no envía nada', async ({ page }, info) => {
    const email = `incompleto-${info.project.name}@ejemplo.pe`;
    const v = await abrirDemo(page);
    await llenarContacto(page, email);
    await v.getByRole('button', { name: 'Agendar mi demo' }).click();
    // Falta la casilla de consentimiento: el navegador no deja enviar (EDUN-291).
    await expect(v.getByRole('alert')).toHaveText('Completa los campos marcados con * y acepta la Política de Privacidad.');
    await expect(v.getByLabel('Nombre completo *')).not.toHaveAttribute('aria-invalid', 'true');
    expect((await leadsRecibidos(page, email)).leads).toHaveLength(0);
  });

  test('los sábados, domingos y días pasados están deshabilitados', async ({ page }) => {
    const v = await abrirDemo(page);
    const dias = await v.locator('[data-fecha]').evaluateAll((botones) =>
      botones.map((b) => ({ fecha: b.getAttribute('data-fecha') ?? '', deshabilitado: (b as HTMLButtonElement).disabled })),
    );
    const hoy = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Lima' }).format(new Date());
    for (const d of dias) {
      const finDeSemana = [0, 6].includes(new Date(`${d.fecha}T12:00:00Z`).getUTCDay());
      expect(d.deshabilitado, d.fecha).toBe(finDeSemana || d.fecha <= hoy);
    }
    expect(dias.some((d) => !d.deshabilitado)).toBe(true);
    // Un sábado en el mes siguiente también está deshabilitado.
    await v.getByRole('button', { name: 'Mes siguiente' }).click();
    await expect(v.locator('[data-fecha]').filter({ hasText: /\d/ }).first()).toBeVisible();
    const sabado = await v.locator('[data-fecha]').evaluateAll((b) => b.find((x) => new Date(`${x.getAttribute('data-fecha')}T12:00:00Z`).getUTCDay() === 6)?.getAttribute('data-fecha'));
    await expect(v.locator(`[data-fecha="${sabado}"]`)).toBeDisabled();
  });

  test('completo: queda la solicitud con fecha, hora y consentimiento, y se confirma en pantalla', async ({ page }, info) => {
    const email = `completo-${info.project.name}@ejemplo.pe`;
    const v = await abrirDemo(page);
    await llenarContacto(page, email);
    const dia = v.locator('[data-fecha]:not([disabled])').first();
    const fecha = (await dia.getAttribute('data-fecha')) ?? '';
    await dia.click();
    await v.getByRole('button', { name: '10:30 a. m.' }).click();
    await expect(v.getByText(/^Seleccionado: .* · 10:30 a\. m\.$/)).toBeVisible();
    await v.getByRole('checkbox').check();
    await v.getByRole('button', { name: 'Agendar mi demo' }).click();

    await expect(v.getByRole('status')).toContainText(`Gracias, Rosa. Un asesor confirmará tu demo del `);
    await expect(v.getByRole('status')).toContainText(` a las 10:30 a. m. en ${email}.`);

    const { leads, consentimientos } = await leadsRecibidos(page, email);
    expect(leads).toHaveLength(1);
    expect(leads[0]).toMatchObject({ source: 'demo', demo_fecha: fecha, demo_hora: '10:30', consentimiento: true, alumnos: 300 });
    expect(consentimientos[0]).toMatchObject({ tipo: 'formulario', version_politica: 'privacidad-v1.0' });

    // Eventos de analítica (TRASPASO §10).
    const eventos = await page.evaluate(() => (window.dataLayer ?? []).map((e) => (e as { event?: string }).event).filter(Boolean));
    expect(eventos).toEqual(expect.arrayContaining(['demo_modal_open', 'form_submit', 'demo_request']));
  });

  test('«Seleccionar Plan» de Integral abre la ventana con ese plan de interés', async ({ page }) => {
    await page.goto('/');
    await page.locator('[data-plan="integral"]').getByRole('link', { name: 'Seleccionar Plan' }).click();
    await expect(ventanaDemo(page)).toBeVisible();
    await expect(ventanaDemo(page).getByLabel('Plan de interés')).toHaveValue('integral');
    expect(new URL(page.url()).search).toBe('');
  });

  test('/#demo abre la ventana sola y «Quiero migrar» también la abre', async ({ page }) => {
    await page.goto('/#demo');
    await expect(ventanaDemo(page)).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(ventanaDemo(page)).toBeHidden();
    await page.getByRole('link', { name: 'Quiero migrar' }).click();
    await expect(ventanaDemo(page)).toBeVisible();
  });

  test('la ventana de demo cumple WCAG 2.1 AA', async ({ page }) => {
    await abrirDemo(page);
    const { violations } = await new AxeBuilder({ page }).include('[role="dialog"]').withTags(WCAG).analyze();
    expect(violations.map((v) => v.id)).toEqual([]);
  });
});

test.describe('Políticas (EDUN-287)', () => {
  test('desde el footer, «Política de privacidad» abre la ventana con la versión vigente', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('contentinfo').getByRole('link', { name: 'Política de privacidad' }).click();
    const v = page.getByRole('dialog', { name: 'Política de Privacidad' });
    await expect(v).toBeVisible();
    await expect(v.getByText('Versión 1.0 · vigente desde 07/11/2026')).toBeVisible();
    await expect(v.getByText('Borrador pendiente de revisión legal.')).toBeVisible();
    await expect(v.getByRole('heading', { name: '1. Datos que recopilamos' })).toBeVisible();
  });

  test('/#privacidad abre la ventana sola', async ({ page }) => {
    await page.goto('/#privacidad');
    await expect(page.getByRole('dialog', { name: 'Política de Privacidad' })).toBeVisible();
  });

  test('desde la casilla de la demo se abre encima y Escape vuelve a la demo', async ({ page }) => {
    const v = await abrirDemo(page);
    await v.getByRole('link', { name: 'Política de Privacidad' }).click();
    await expect(page.getByRole('dialog', { name: 'Política de Privacidad' })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog', { name: 'Política de Privacidad' })).toBeHidden();
    await expect(v).toBeVisible();
  });

  test('las páginas de privacidad y términos muestran el mismo texto', async ({ page }) => {
    for (const [ruta, titulo] of [
      ['/politica-de-privacidad', 'Política de Privacidad'],
      ['/terminos-de-servicio', 'Términos y Condiciones del servicio'],
    ]) {
      await page.goto(ruta);
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(titulo);
      await expect(page.getByText(`Texto de prueba de ${titulo}.`)).toBeVisible();
      await expect(page).toHaveTitle(`${titulo} · Edunova`);
      const { violations } = await new AxeBuilder({ page }).withTags(WCAG).analyze();
      expect(violations.map((v) => v.id)).toEqual([]);
    }
  });
});

test.describe('Banner de cookies (EDUN-306)', () => {
  // Visitante nuevo: sin elección guardada.
  test.use({ storageState: { cookies: [], origins: [] } });

  async function contarGtm(page: Page) {
    const pedidos: string[] = [];
    await page.route('https://www.googletagmanager.com/**', (r) => {
      pedidos.push(r.request().url());
      return r.fulfill({ status: 200, contentType: 'application/javascript', body: '' });
    });
    return pedidos;
  }

  test('un visitante nuevo ve el banner y cumple WCAG 2.1 AA', async ({ page }) => {
    await page.goto('/');
    const banner = page.getByRole('region', { name: 'Tu privacidad en Edunova' });
    await expect(banner).toBeVisible();
    await expect(banner.getByRole('button', { name: 'Aceptar todo' })).toBeVisible();
    await expect(banner.getByRole('button', { name: 'Solo esenciales' })).toBeVisible();
    await expect(banner.getByRole('button', { name: 'Configurar' })).toBeVisible();
    const { violations } = await new AxeBuilder({ page }).include('[role="region"]').withTags(WCAG).analyze();
    expect(violations.map((v) => v.id)).toEqual([]);
  });

  test('con «Solo esenciales» no se carga GTM ni se concede analítica, y el banner no reaparece', async ({ page }) => {
    const pedidos = await contarGtm(page);
    await page.goto('/');
    await page.getByRole('button', { name: 'Solo esenciales' }).click();
    await expect(page.getByRole('region', { name: 'Tu privacidad en Edunova' })).toBeHidden();

    // Navega: abre la demo (evento demo_modal_open) y vuelve otro día (recarga).
    await page.getByRole('banner').getByRole('link', { name: 'Solicitar demo' }).click();
    await page.keyboard.press('Escape');
    await page.reload();
    await expect(page.locator('#titulo-hero')).toBeVisible();
    await expect(page.getByRole('region', { name: 'Tu privacidad en Edunova' })).toHaveCount(0);

    expect(pedidos).toEqual([]);
    const consentimiento = await page.evaluate(() =>
      (window.dataLayer ?? []).filter((e) => (e as IArguments)[0] === 'consent').map((e) => [(e as IArguments)[1], (e as IArguments)[2].analytics_storage]),
    );
    expect(consentimiento).toEqual([
      ['default', 'denied'],
      ['update', 'denied'],
    ]);
  });

  test('con «Aceptar todo» se concede el consentimiento y se carga GTM', async ({ page }) => {
    const pedidos = await contarGtm(page);
    await page.goto('/');
    await page.getByRole('button', { name: 'Aceptar todo' }).click();
    await expect.poll(() => pedidos.length).toBe(1);
    expect(pedidos[0]).toContain('id=GTM-PRUEBA1');
    const ultima = await page.evaluate(() => {
      const c = (window.dataLayer ?? []).filter((e) => (e as IArguments)[0] === 'consent').at(-1) as IArguments;
      return c[2];
    });
    expect(ultima).toMatchObject({ analytics_storage: 'granted', ad_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted' });

    // Vuelve otro día: sin banner y con GTM cargado desde el inicio.
    await page.reload();
    await expect.poll(() => pedidos.length).toBe(2);
    await expect(page.getByRole('region', { name: 'Tu privacidad en Edunova' })).toHaveCount(0);
  });

  test('«Configurar» guarda solo las categorías elegidas y el footer permite cambiarlas', async ({ page }) => {
    const pedidos = await contarGtm(page);
    await page.goto('/');
    await page.getByRole('button', { name: 'Configurar' }).click();
    const v = page.getByRole('dialog', { name: 'Preferencias de cookies' });
    await expect(v.getByText('Siempre activas')).toBeVisible();
    await v.getByRole('switch', { name: 'Personalización' }).check({ force: true });
    await v.getByRole('button', { name: 'Guardar mis preferencias' }).click();
    await expect(v).toBeHidden();
    expect(await page.evaluate(() => JSON.parse(localStorage.getItem('edunova_cookies') ?? '{}').categorias)).toEqual(['esenciales', 'personalizacion']);
    expect(pedidos).toEqual([]);

    await page.getByRole('contentinfo').getByRole('button', { name: 'Preferencias de cookies' }).click();
    await expect(v.getByRole('switch', { name: 'Personalización' })).toBeChecked();
    await v.getByRole('switch', { name: 'Analítica' }).check({ force: true });
    await v.getByRole('button', { name: 'Guardar mis preferencias' }).click();
    await expect.poll(() => pedidos.length).toBe(1);
  });
});
