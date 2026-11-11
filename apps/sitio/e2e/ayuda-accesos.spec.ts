import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { conSesionCms } from './sesion';

// Ayuda por pantalla del Panel CMS y página de acceso «¿Cómo quieres ingresar?».
const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'];

test('la página de acceso ofrece colegio, familias y equipo, con ayuda para entrar y sin barreras', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('link', { name: 'Ingresar', exact: true }).first().click();
  await expect(page).toHaveURL(/\/ingresar$/);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText('¿Cómo quieres ingresar?');
  await expect(page.getByRole('link', { name: /Personal del colegio/ })).toBeVisible();
  await expect(page.getByRole('link', { name: /Familias/ })).toBeVisible();
  await expect(page.getByRole('link', { name: /Equipo de Edunova/ })).toHaveAttribute('href', '/cms/ingresar');
  await expect(page.getByRole('region', { name: '¿Problemas para entrar?' })).toContainText('¿Olvidaste tu contraseña?');
  const { violations } = await new AxeBuilder({ page }).withTags(WCAG).analyze();
  expect(violations.map((v) => v.id)).toEqual([]);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
});

test.describe('Panel CMS', () => {
  test.beforeEach(async ({ context, baseURL }) => conSesionCms(context, baseURL));

  test('cada pantalla tiene su ayuda: qué se puede y qué no, y se cierra al cambiar de pantalla', async ({ page }) => {
    await page.goto('/cms/blog');
    await page.getByRole('button', { name: 'Ayuda' }).click();
    const ayuda = page.getByRole('complementary', { name: 'Blog (WordPress)' });
    await expect(ayuda).toContainText('Puedes');
    await expect(ayuda).toContainText('Escribir o editar posts aquí');
    const { violations } = await new AxeBuilder({ page }).include('#ayuda-cms').withTags(WCAG).analyze();
    expect(violations.map((v) => v.id)).toEqual([]);
    await page.getByRole('button', { name: 'Cerrar la ayuda' }).click();
    await expect(ayuda).toHaveCount(0);
  });
});
