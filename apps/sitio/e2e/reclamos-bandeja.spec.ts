import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { abrirMenuCms, conSesionCms } from './sesion';

// Sprint 7, contra la API simulada:
//   EDUN-294 / SIT-010  Libro de Reclamaciones virtual
//   EDUN-298 / SIT-011  Seguimiento del reclamo
//   EDUN-278 / SIT-026  Bandeja de solicitudes de demo (Panel CMS)
// El número, el vencimiento en días hábiles, el correo, la base y el bloqueo por IP los prueba
// apps/api (Pest y pruebas_cms.sql).

const API = `http://127.0.0.1:${process.env.PUERTO_API_SIMULADA ?? 8199}`;
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

async function llenarReclamo(page: Page, documento: string, descripcion: string) {
  await page.getByLabel('Nombres y apellidos *').fill('Lucía Ramos');
  await page.getByLabel('Número de documento *').fill(documento);
  await page.getByLabel('Correo electrónico *').fill('lucia@ejemplo.pe');
  await page.getByLabel('Dirección *').fill('Av. Ejemplo 123, Lima');
  await page.getByLabel('Servicio o módulo objeto del reclamo *').fill('Plan Crecimiento');
  await page.getByLabel('Detalle de los hechos *').fill(descripcion);
  await page.getByLabel('Pedido o solución que esperas *').fill('Que se regularice el servicio.');
  await page.getByLabel('Declaro que la información que brindo es verdadera. *').check();
  await page.getByRole('checkbox', { name: /Autorizo el tratamiento de mis datos/ }).check();
}

test.describe('Libro de Reclamaciones (EDUN-294)', () => {
  test('desde el footer, con los datos de Edunova de cms.config y sin datos de ejemplo', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('contentinfo').getByRole('link', { name: /Libro de Reclamaciones/ }).click();
    await expect(page).toHaveURL(/\/libro-de-reclamaciones$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Libro de Reclamaciones virtual');
    // La API simulada no tiene RUC ni razón social: se ve «[A CONFIRMAR]», nunca un RUC inventado.
    await expect(page.getByText('(RUC [A CONFIRMAR])')).toBeVisible();
    await expect(page.getByText(/\b20\d{9}\b/)).toHaveCount(0);
    const { violations } = await new AxeBuilder({ page }).withTags(WCAG).analyze();
    expect(violations.map((v) => v.id)).toEqual([]);
  });

  test('con 30 caracteres pide un mínimo de 50 y no envía', async ({ page }) => {
    await page.goto('/libro-de-reclamaciones');
    await llenarReclamo(page, '45678912', 'Treinta caracteres de prueba.');
    await expect(page.getByText('29 / 2000 caracteres · mínimo 50')).toBeVisible();
    await page.getByRole('button', { name: 'Enviar hoja de reclamación' }).click();
    await expect(page.getByText('Describe los hechos con al menos 50 caracteres.').first()).toBeVisible();
    await expect(page.getByLabel('Detalle de los hechos *')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('[data-expediente]')).toHaveCount(0);
  });

  test('completo: muestra el número REC-AAAA-NNNN y el plazo, y se puede consultar el estado', async ({ page }, info) => {
    const documento = info.project.name === 'escritorio' ? '45678912' : '45678913';
    await page.goto('/libro-de-reclamaciones');
    await page.getByRole('radio', { name: /Queja/ }).check();
    await llenarReclamo(page, documento, 'La plataforma no generó las boletas del mes y soporte no respondió a tiempo.');
    await page.getByLabel('Documentos de sustento (opcional)').setInputFiles({ name: 'boleta.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 prueba') });
    await page.getByRole('button', { name: 'Enviar hoja de reclamación' }).click();

    const resultado = page.getByRole('status').filter({ hasText: 'Número de expediente' });
    await expect(resultado.getByRole('heading', { name: 'Queja registrada' })).toBeVisible();
    const expediente = (await resultado.locator('[data-expediente]').textContent()) ?? '';
    expect(expediente).toMatch(/^REC-\d{4}-\d{4,}$/);
    await expect(resultado).toContainText('15 días hábiles, a más tardar el lunes 30 de noviembre de 2026');

    // EDUN-298 · Desde la confirmación se consulta el estado con el documento.
    await resultado.getByRole('link', { name: 'Consultar el estado' }).click();
    await expect(page.getByLabel('Número de expediente *')).toHaveValue(expediente);
    await page.getByLabel('Número de documento *').fill(documento);
    await page.getByRole('button', { name: 'Consultar' }).click();
    const estado = page.getByRole('region', { name: 'Estado de tu expediente' });
    await expect(estado).toContainText(expediente);
    await expect(estado).toContainText('Recibido');
    await expect(estado).not.toContainText('Lucía');
  });
});

test.describe('Seguimiento del reclamo (EDUN-298)', () => {
  test('con otro documento aparece «No encontramos un reclamo con esos datos»', async ({ page }) => {
    await page.goto('/libro-de-reclamaciones/seguimiento?expediente=REC-2026-0001');
    await page.getByLabel('Número de documento *').fill('99999999');
    await page.getByRole('button', { name: 'Consultar' }).click();
    await expect(page.locator('main').getByRole('alert')).toContainText('No encontramos un reclamo con esos datos.');
    const { violations } = await new AxeBuilder({ page }).withTags(WCAG).analyze();
    expect(violations.map((v) => v.id)).toEqual([]);
  });
});

test.describe('Bandeja de solicitudes (EDUN-278)', () => {
  test.beforeEach(async ({ context, baseURL }) => conSesionCms(context, baseURL));

  test('con 42 solicitudes del mes, el indicador muestra 42 y la tabla 10 por página', async ({ page }) => {
    await page.goto('/cms/leads/bandeja');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Bandeja de solicitudes de demo');
    await expect(page.locator('[data-indicador="inbox"]')).toHaveText('42');
    await expect(page.getByRole('table', { name: 'Solicitudes de demo' }).locator('tbody tr')).toHaveCount(10);
    await expect(page.getByText('Mostrando 1–10 de 42 solicitudes')).toBeVisible();
    await page.getByRole('link', { name: 'Página siguiente' }).click();
    await expect(page.getByText('Mostrando 11–20 de 42 solicitudes')).toBeVisible();
    // Sin afirmaciones sin respaldo (TRASPASO §7).
    await expect(page.getByText(/Padrón|Sincronizado SIAGIE/)).toHaveCount(0);
  });

  test('el filtro «Plan Integral» deja solo solicitudes de ese plan', async ({ page }) => {
    await page.goto('/cms/leads/bandeja');
    await page.getByRole('search').getByRole('combobox', { name: /^Plan/ }).selectOption('integral');
    await page.getByRole('button', { name: 'Aplicar' }).click();
    await expect(page).toHaveURL(/plan=integral/);
    const planes = page.getByRole('table', { name: 'Solicitudes de demo' }).locator('tbody tr td:nth-child(4)');
    await expect(planes).toHaveCount(10);
    for (const t of await planes.allInnerTexts()) expect(t).toContain('Plan Integral');
    await expect(page.getByText('de 14 solicitudes')).toBeVisible();
  });

  test('al pasar una solicitud Nueva a Contactado cambia de pestaña y queda en la auditoría', async ({ page }, info) => {
    test.skip(info.project.name !== 'escritorio', 'Cambia el estado compartido de la API simulada');
    await page.goto('/cms/leads/bandeja?q=ejemplo%2042');
    const ficha = page.getByRole('complementary', { name: 'Ficha rápida' });
    await expect(ficha).toContainText('Colegio de ejemplo 42');
    await ficha.getByLabel('Estado de la solicitud').selectOption('contactado');
    await ficha.getByRole('button', { name: 'Cambiar estado' }).click();
    await expect(page.getByText('Estado actualizado.')).toBeVisible();

    const pestanas = page.getByRole('navigation', { name: 'Solicitudes por estado' });
    await expect(pestanas.getByRole('link', { name: /Contactadas\s*1/ })).toBeVisible();
    await pestanas.getByRole('link', { name: /Contactadas/ }).click();
    await expect(page.getByRole('table', { name: 'Solicitudes de demo' }).locator('tbody tr')).toHaveCount(1);
    await expect(page.getByRole('table', { name: 'Solicitudes de demo' })).toContainText('Colegio de ejemplo 42');

    const auditoria = await (await page.request.get(`${API}/__auditoria`)).json();
    expect(auditoria).toContainEqual(expect.objectContaining({ entidad: 'leads', entidad_id: 42, campo: 'estado', valor_despues: 'contactado' }));
  });

  test('cumple WCAG 2.1 AA y se abre desde el menú del panel', async ({ page }) => {
    await page.goto('/cms');
    await abrirMenuCms(page);
    await page.getByRole('link', { name: 'Bandeja de solicitudes' }).first().click();
    await expect(page).toHaveURL(/\/cms\/leads\/bandeja/);
    const { violations } = await new AxeBuilder({ page }).include('main').withTags(WCAG).analyze();
    expect(violations.map((v) => v.id)).toEqual([]);
  });
});
