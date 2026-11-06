import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
  // Carpeta de compilación configurable: las pruebas de pantalla locales compilan en
  // .next-e2e para no pisar el servidor de desarrollo (que usa .next).
  distDir: process.env.NEXT_DIST_DIR || '.next',
  // forbidden() responde 403 en el Panel CMS para quien no es superadmin (EDUN-226).
  // Subidas de la Biblioteca de medios: hasta 5 MB por archivo (EDUN-237).
  experimental: { authInterrupts: true, serverActions: { bodySizeLimit: '6mb' } },
};

export default nextConfig;
