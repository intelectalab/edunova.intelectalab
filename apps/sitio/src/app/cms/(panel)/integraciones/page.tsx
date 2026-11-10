import { cms } from '@/contenido/provisional';
import { leerIntegraciones } from '../../panel-acciones';
import { EditorIntegraciones } from './EditorIntegraciones';

// EDUN-403 / SIT-033 · Tracking e integraciones (se guarda y se aplica; las claves no se leen).
export const dynamic = 'force-dynamic';

export default async function Integraciones() {
  const t = cms.integraciones;
  const datos = await leerIntegraciones();
  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <header className="flex flex-col gap-1">
        <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{t.titulo}</h1>
        <p className="font-body-md text-body-md text-on-surface-variant">{t.descripcion}</p>
      </header>
      {datos ? (
        <EditorIntegraciones inicial={datos} />
      ) : (
        <p role="alert" className="px-space-md py-space-sm rounded-lg bg-error-container text-on-error-container font-body-md text-body-md">
          {t.errorCarga}
        </p>
      )}
    </div>
  );
}
