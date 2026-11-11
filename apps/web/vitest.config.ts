import react from '@vitejs/plugin-react';
import { configDefaults, defineConfig } from 'vitest/config';

export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/pruebas/configuracion.ts'],
    css: true,
    // Las pruebas de pantalla (e2e/, Playwright contra la API real) corren aparte con «pnpm e2e».
    exclude: [...configDefaults.exclude, 'e2e/**'],
  },
});
