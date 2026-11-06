import { defineConfig, devices } from '@playwright/test';

// Pruebas de pantalla del sitio: fuentes y colores del prototipo, axe y acceso al Panel CMS,
// a 1440 px y 390 px. Corren contra el build de producción y una API simulada.
const PUERTO = Number(process.env.PUERTO_E2E ?? 3100);
const PUERTO_API = Number(process.env.PUERTO_API_SIMULADA ?? 8199);

export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? 'github' : 'list',
  use: { baseURL: `http://127.0.0.1:${PUERTO}`, trace: 'retain-on-failure' },
  projects: [
    { name: 'escritorio', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } },
    { name: 'celular', use: { ...devices['Desktop Chrome'], viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } },
  ],
  webServer: [
    {
      command: `node e2e/api-simulada.mjs`,
      url: `http://127.0.0.1:${PUERTO_API}/api/v1/salud`,
      env: { PUERTO_API_SIMULADA: String(PUERTO_API) },
      reuseExistingServer: !process.env.CI,
    },
    {
      command: `next start -p ${PUERTO}`,
      url: `http://127.0.0.1:${PUERTO}`,
      env: { EDUNOVA_API_URL: `http://127.0.0.1:${PUERTO_API}/api/v1` },
      reuseExistingServer: !process.env.CI,
    },
  ],
});
