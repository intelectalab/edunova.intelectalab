import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { conSesionCms } from './sesion';

// Sprint 8, contra la API simulada:
//   EDUN-271 / SIT-009  Popup de salida
//   EDUN-318 / SIT-058  Eventos de analítica en dataLayer
//   EDUN-282 / SIT-027  Exportar y sincronizar leads
//   EDUN-302 / SIT-031  Gestión de reclamaciones en el CMS
// El envío al CRM con reintentos (EDUN-275) lo prueba apps/api (Pest).

const API = `http://127.0.0.1:${process.env.PUERTO_API_SIMULADA ?? 8199}`;
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

type Evento = Record<string, unknown> & { event?: string };
const eventos = (page: import('@playwright/test').Page) => page.evaluate(() => ((window as unknown as { dataLayer?: Evento[] }).dataLayer ?? []).filter((e) => e && e.event));

test.describe('Popup de salida (EDUN-271) y analítica (EDUN-318)', () => {
  test('al intentar salir aparece una vez, pide el consentimiento y registra el lead sin datos personales en el dataLayer', async ({ page }) => {
    await page.goto('/');
    await page.evaluate(() => document.dispatchEvent(new MouseEvent('mouseout', { clientY: 0, relatedTarget: null, bubbles: true })));
    const popup = page.getByRole('dialog', { name: '¿Te vas sin ver Edunova en acción?' });
    await expect(popup).toBeVisible();
    const { violations } = await new AxeBuilder({ page }).include('[role="dialog"]').withTags(WCAG).analyze();
    expect(violations.map((v) => v.id)).toEqual([]);

    await popup.getByLabel('WhatsApp').fill('987 654 321');
    await popup.getByRole('button', { name: 'Enviarme la información' }).click();
    await expect(popup.getByRole('alert')).toContainText('acepta la Política de Privacidad');
    await popup.getByRole('checkbox').check();
    await popup.getByRole('button', { name: 'Enviarme la información' }).click();
    await expect(popup.getByRole('status')).toContainText('¡Listo!');

    const recibidos = await (await page.request.get(`${API}/__popup`)).json();
    expect(recibidos).toContainEqual(expect.objectContaining({ telefono: '987 654 321', consentimiento: true }));
    const ev = await eventos(page);
    expect(ev.map((e) => e.event)).toEqual(expect.arrayContaining(['exit_popup_view', 'form_error', 'form_submit', 'exit_popup_submit']));
    expect(JSON.stringify(ev)).not.toContain('987');

    // Ya convirtió: no vuelve a aparecer.
    await page.keyboard.press('Escape');
    await page.reload();
    await page.evaluate(() => document.dispatchEvent(new MouseEvent('mouseout', { clientY: 0, relatedTarget: null, bubbles: true })));
    await expect(page.getByRole('dialog')).toHaveCount(0);
  });

  test('el clic en «Solicitar demo» del hero llega al dataLayer como cta_click con su ubicación', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('region', { name: /Administra tu colegio|colegio/ }).first().getByRole('link', { name: /Solicitar demo/ }).first().click();
    await expect(page.getByRole('dialog', { name: 'Solicitar demo gratuita' })).toBeVisible();
    const ev = await eventos(page);
    expect(ev).toContainEqual(expect.objectContaining({ event: 'cta_click', ubicacion: 'hero' }));
    expect(ev).toContainEqual(expect.objectContaining({ event: 'demo_modal_open' }));
  });
});

test.describe('Panel CMS · Sprint 8', () => {
  test.beforeEach(async ({ context, baseURL }) => conSesionCms(context, baseURL));

  test('la bandeja exporta el CSV con los filtros y explica que no hay CRM conectado (EDUN-282)', async ({ page }) => {
    await page.goto('/cms/leads/bandeja?plan=integral');
    await expect(page.locator('[data-crm-resumen]')).toContainText('Aún no hay un CRM conectado');
    const [descarga] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Exportar CSV' }).click()]);
    expect(descarga.suggestedFilename()).toMatch(/^solicitudes-edunova-\d{4}-\d{2}-\d{2}\.csv$/);
    expect(descarga.url()).toContain('plan=integral');
  });

  test('reclamaciones: filtra vencidos, exige la respuesta y la envía (EDUN-302)', async ({ page }, info) => {
    await page.goto('/cms/reclamaciones');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Libro de Reclamaciones');
    await expect(page.locator('[data-indicador="vencidos"]')).toHaveText('1');
    await page.getByRole('link', { name: /Vencidos sin respuesta/ }).click();
    await expect(page.getByRole('table', { name: 'Expedientes del Libro de Reclamaciones' }).locator('tbody tr')).toHaveCount(1);
    await expect(page.getByRole('table')).toContainText('REC-2026-0002');

    const { violations } = await new AxeBuilder({ page }).include('main').withTags(WCAG).analyze();
    expect(violations.map((v) => v.id)).toEqual([]);

    test.skip(info.project.name !== 'escritorio', 'Cambia el estado compartido de la API simulada');
    await page.goto('/cms/reclamaciones?id=1');
    const gestion = page.getByRole('region', { name: 'Gestión del expediente' });
    await gestion.getByLabel('Nuevo estado').selectOption('respondido');
    page.once('dialog', (d) => d.accept());
    await gestion.getByRole('button', { name: 'Enviar respuesta al cliente' }).click();
    await expect(gestion.getByRole('alert')).toContainText('al menos 20 caracteres');
    await gestion.getByLabel('Respuesta al cliente').fill('Regularizamos la emisión de boletas y descontamos el mes.');
    page.once('dialog', (d) => d.accept());
    await gestion.getByRole('button', { name: 'Enviar respuesta al cliente' }).click();
    await expect(page.getByText('Respuesta enviada al cliente.').first()).toBeVisible();
    await expect(page.getByRole('complementary', { name: 'Expediente' })).toContainText('Respondido');
  });
});
