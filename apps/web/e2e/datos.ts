import { createHmac } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { expect, type Page } from '@playwright/test';

// Datos de prueba de apps/api/database/seeders/E2eSeeder.php (ficticios; deben coincidir).
export const CLAVE = 'E2e.Prueba.2026';
export const DIRECTOR = { email: 'director@e2e.edunova.test', totp: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP' };
export const APODERADO = { email: 'apoderado@e2e.edunova.test' };
export const CUOTA = 'Pensión de prueba E2E';
export const SECCION_MATRICULA = '3.° de Secundaria E2E-M';
export const SECCION_NOTAS = '3.° de Secundaria E2E-N';
export const PLANTILLA_SIAGIE =
  process.env.E2E_PLANTILLA_SIAGIE ?? fileURLToPath(new URL('../../api/storage/app/e2e/plantilla-siagie-e2e.xlsx', import.meta.url));

function desdeBase32(texto: string): Buffer {
  const alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const bits = [...texto.replace(/=+$/, '').toUpperCase()].map((c) => alfabeto.indexOf(c).toString(2).padStart(5, '0')).join('');
  const bytes = bits.match(/.{8}/g) ?? [];
  return Buffer.from(bytes.map((b) => parseInt(b, 2)));
}

/** Código TOTP (RFC 6238: 6 dígitos, 30 s, HMAC-SHA1), igual que una app autenticadora. */
export function codigoTotp(secreto: string, momento = Date.now()): string {
  const contador = Buffer.alloc(8);
  contador.writeBigUInt64BE(BigInt(Math.floor(momento / 1000 / 30)));
  const hash = createHmac('sha1', desdeBase32(secreto)).update(contador).digest();
  const desfase = hash[19] & 0x0f;
  const numero = (hash.readUInt32BE(desfase) & 0x7fffffff) % 1_000_000;
  return String(numero).padStart(6, '0');
}

/** Ingreso con correo y contraseña desde la pantalla de ingreso. */
export async function ingresar(page: Page, usuario: string) {
  await page.goto('/');
  await page.getByLabel('DNI o correo').fill(usuario);
  await page.getByLabel('Contraseña').fill(CLAVE);
  await page.getByRole('button', { name: 'Ingresar' }).click();
}

/**
 * Segundo factor del director. La API no acepta dos veces el mismo código: si una corrida
 * anterior ya lo usó, se prueba el del paso siguiente (la API admite ±30 s).
 */
export async function confirmarSegundoFactor(page: Page) {
  await expect(page.getByRole('heading', { name: 'Confirma que eres tú' })).toBeVisible();
  for (let intento = 0; intento < 3; intento++) {
    if (intento === 2) {
      // Ambos códigos ya se usaron: se espera al siguiente paso de 30 s.
      await page.waitForTimeout(30_000 - (Date.now() % 30_000) + 500);
    }
    await page.getByLabel('Código de la app').fill(codigoTotp(DIRECTOR.totp, Date.now() + (intento === 0 ? 0 : 30_000)));
    await page.getByRole('button', { name: 'Confirmar' }).click();
    const listo = await page
      .getByRole('navigation', { name: 'Menú principal' })
      .waitFor({ timeout: 5_000 })
      .then(() => true)
      .catch(() => false);
    if (listo) return;
  }
  throw new Error('La API no aceptó el código del segundo factor.');
}
