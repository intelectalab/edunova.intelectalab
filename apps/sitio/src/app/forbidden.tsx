import Link from 'next/link';
import { clasesBoton } from '@/componentes/base';
import { cmsSinAcceso } from '@/contenido/provisional';
import { salirCms } from './cms/acciones';

// Respuesta 403 (forbidden()): por ahora solo la usa el Panel CMS (EDUN-226).
export default function Prohibido() {
  return (
    <main className="min-h-screen bg-surface-container-low flex items-center justify-center px-margin-mobile">
      <div className="max-w-md bg-surface-container-lowest rounded-xl shadow-sm p-space-xl flex flex-col gap-space-md">
        <h1 className="font-headline-md text-headline-md text-primary">{cmsSinAcceso.titulo}</h1>
        <p className="font-body-md text-body-md text-on-surface-variant">{cmsSinAcceso.detalle}</p>
        <div className="flex flex-wrap gap-space-sm">
          <form action={salirCms}>
            <button type="submit" className={clasesBoton('institucional')}>
              {cmsSinAcceso.otraCuenta}
            </button>
          </form>
          <Link href="/" className={clasesBoton('secundario')}>
            {cmsSinAcceso.irAlSitio}
          </Link>
        </div>
      </div>
    </main>
  );
}
