import type { Metadata } from 'next';
import { ProveedorToast } from '@/componentes/base';
import { muestra } from '@/contenido/provisional';
import { Muestra } from './Muestra';

// Página de muestra de los componentes base (EDUN-220). No se indexa.
export const metadata: Metadata = {
  title: muestra.titulo,
  robots: { index: false, follow: false },
};

export default function PaginaMuestra() {
  return (
    <ProveedorToast>
      <Muestra />
    </ProveedorToast>
  );
}
