import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
  // forbidden() responde 403 en el Panel CMS para quien no es superadmin (EDUN-226).
  experimental: { authInterrupts: true },
};

export default nextConfig;
