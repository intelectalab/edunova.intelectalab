import { defineConfig, devices } from '@playwright/test';

// EDUN-210 · Pruebas de pantalla de la web de gestión contra la API REAL (no simulada):
// matrícula, pago en línea con el webhook de la pasarela simulada, notas y cierre, y SIAGIE.
// Antes: la API corriendo con los datos de E2eSeeder (ver infra/e2e-web.sh) y la web compilada
// con VITE_API_URL apuntando a esa API. La API debe aceptar el origen de la web (CORS_ORIGENES)
// y devolver la familia a esa misma web tras pagar (EDUNOVA_WEB_URL).
const WEB_URL = process.env.E2E_WEB_URL ?? 'http://localhost:5174';
const PUERTO = new URL(WEB_URL).port || '80';

export default defineConfig({
  testDir: './e2e',
  // Los flujos comparten la base de datos: uno a la vez.
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['github'], ['list']] : 'list',
  timeout: 60_000,
  expect: { timeout: 10_000 },
  use: {
    baseURL: WEB_URL,
    trace: 'retain-on-failure',
    acceptDownloads: true,
  },
  projects: [
    // Ingresa una vez como director (contraseña + segundo factor) y guarda la sesión.
    { name: 'sesion', testMatch: /sesion\.setup\.ts/ },
    {
      name: 'escritorio',
      dependencies: ['sesion'],
      use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 }, storageState: 'e2e/.sesion/director.json' },
    },
  ],
  // Sirve el build (dist/) si no hay ya una web en E2E_WEB_URL (por ejemplo, `pnpm dev`).
  webServer: {
    command: `vite preview --port ${PUERTO} --strictPort`,
    url: WEB_URL,
    reuseExistingServer: true,
    timeout: 60_000,
  },
});
