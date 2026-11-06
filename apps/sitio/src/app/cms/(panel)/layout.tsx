import { cookies } from 'next/headers';
import { forbidden, redirect } from 'next/navigation';
import type { ReactNode } from 'react';
import { ProveedorToast } from '@/componentes/base';
import { MarcoCms } from '@/componentes/cms/MarcoCms';
import { cmsIngreso } from '@/contenido/provisional';
import { COOKIE_CMS, leerSesionCms } from '@/lib/sesionCms';

// EDUN-226 · Solo el superadmin del SaaS ve el panel. La API lo decide en cada carga.
// EDUN-230 · Marco del panel: menú, barra superior y navegación rápida.
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

  // «En línea» solo cuando hay un dominio público configurado (TRASPASO §7: nada sin respaldo).
  const sitioPublico = process.env.EDUNOVA_SITIO_PUBLICO || null;

  return (
    <ProveedorToast>
      <MarcoCms usuario={sesion.usuario} sitioPublico={sitioPublico}>
        {children}
      </MarcoCms>
    </ProveedorToast>
  );
}
