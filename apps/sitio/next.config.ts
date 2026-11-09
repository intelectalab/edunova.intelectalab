import type { NextConfig } from 'next';
import { cabecerasSeguridad } from './seguridad.mjs';

const nextConfig: NextConfig = {
  // Carpeta de compilación configurable: las pruebas de pantalla locales compilan en
  // .next-e2e para no pisar el servidor de desarrollo (que usa .next).
  distDir: process.env.NEXT_DIST_DIR || '.next',
  // forbidden() responde 403 en el Panel CMS para quien no es superadmin (EDUN-226).
  // Subidas: Biblioteca de medios hasta 5 MB (EDUN-237) y Libro de Reclamaciones, 2 adjuntos de 5 MB (EDUN-294).
  experimental: { authInterrupts: true, serverActions: { bodySizeLimit: '11mb' } },
  // EDUN-209 · CSP, HSTS (en producción) y cabeceras contra clickjacking en todas las rutas.
  poweredByHeader: false,
  async headers() {
    return [{ source: '/:ruta*', headers: cabecerasSeguridad() }];
  },
};

export default nextConfig;
