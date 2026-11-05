import type { Metadata } from 'next';
import type { ReactNode } from 'react';
import { MenuCms } from '@/componentes/cms/MenuCms';
import { ProveedorToast } from '@/componentes/base';

// Rutas protegidas del Panel CMS. El control de acceso (solo superadmin del SaaS) llega con EDUN-226.
export const metadata: Metadata = {
  robots: { index: false, follow: false },
};

export default function LayoutCms({ children }: { children: ReactNode }) {
  return (
    <ProveedorToast>
      <MenuCms />
      <main className="min-h-screen bg-background xl:pl-menu-cms">{children}</main>
    </ProveedorToast>
  );
}
