import { cookies } from 'next/headers';
import { forbidden, redirect } from 'next/navigation';
import type { ReactNode } from 'react';
import { ProveedorToast } from '@/componentes/base';
import { MenuCms } from '@/componentes/cms/MenuCms';
import { cmsIngreso } from '@/contenido/provisional';
import { COOKIE_CMS, leerSesionCms } from '@/lib/sesionCms';

// EDUN-226 · Solo el superadmin del SaaS ve el panel. La API lo decide en cada carga.
export default async function LayoutPanelCms({ children }: { children: ReactNode }) {
  const sesion = await leerSesionCms((await cookies()).get(COOKIE_CMS)?.value);

  if (sesion.estado === 'sin-sesion') redirect('/cms/ingresar');
  if (sesion.estado === 'sin-acceso') forbidden();
  if (sesion.estado === 'error') {
    return (
      <main className="min-h-screen flex items-center justify-center px-margin-mobile">
        <p role="alert" className="font-body-lg text-body-lg text-on-surface-variant">
          {cmsIngreso.errorConexion}
        </p>
      </main>
    );
  }

  return (
    <ProveedorToast>
      <MenuCms usuario={sesion.usuario} />
      <main className="min-h-screen bg-background xl:pl-menu-cms">{children}</main>
    </ProveedorToast>
  );
}
