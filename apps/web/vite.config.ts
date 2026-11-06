import react from '@vitejs/plugin-react';
import { writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { defineConfig, loadEnv, type Plugin } from 'vite';
import { archivoHeaders, cabecerasSeguridad } from './seguridad.ts';

/** EDUN-209 · Escribe dist/_headers con las cabeceras de seguridad para el hosting. */
function cabecerasHosting(apiUrl: string | undefined): Plugin {
  return {
    name: 'edunova-cabeceras',
    apply: 'build',
    closeBundle() {
      writeFileSync(resolve(__dirname, 'dist/_headers'), archivoHeaders(apiUrl));
    },
  };
}

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_');
  return {
    plugins: [react(), cabecerasHosting(env.VITE_API_URL)],
    server: { headers: cabecerasSeguridad(env.VITE_API_URL, true) },
    preview: { headers: cabecerasSeguridad(env.VITE_API_URL, false) },
  };
});
