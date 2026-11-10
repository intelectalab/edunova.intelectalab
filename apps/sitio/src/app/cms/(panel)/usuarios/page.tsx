import { Insignia } from '@/componentes/base';
import { cms } from '@/contenido/provisional';
import { leerUsuarios } from '../../panel-acciones';

// EDUN-407 / SIT-065 · Usuarios con acceso al CMS: solo lectura. Entran únicamente los
// superadmin de la plataforma; el alta y la baja se hacen en la consola de plataforma (PLA).
export const dynamic = 'force-dynamic';

const t = cms.usuarios;
const fecha = (iso: string | null) =>
  iso ? new Intl.DateTimeFormat('es-PE', { timeZone: 'America/Lima', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(iso)) : t.nunca;

export default async function UsuariosCms() {
  const usuarios = await leerUsuarios();

  return (
    <div className="px-margin-mobile md:px-margin py-space-lg flex flex-col gap-space-md">
      <header className="flex flex-col gap-1">
        <h1 className="font-headline-md text-headline-md text-primary tracking-tight">{t.titulo}</h1>
        <p className="font-body-md text-body-md text-on-surface-variant">{t.descripcion}</p>
      </header>
      {!usuarios ? (
        <p role="alert" className="font-body-md text-body-md text-error">
          {t.errorCarga}
        </p>
      ) : (
        <div className="rounded-xl overflow-hidden border border-surface-container bg-surface-container-lowest">
          <div className="overflow-x-auto">
            <table className="w-full border-collapse">
              <caption className="sr-only">{t.tabla}</caption>
              <thead>
                <tr className="bg-surface-container-low text-secondary font-label-md text-label-md uppercase tracking-wider h-11 text-left">
                  {Object.values(t.columnas).map((c) => (
                    <th key={c} scope="col" className="py-3 px-space-md font-semibold whitespace-nowrap">
                      {c}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-surface-container font-body-sm text-body-sm text-on-surface">
                {usuarios.length === 0 ? (
                  <tr className="h-fila-tabla">
                    <td colSpan={7} className="px-space-md text-center text-on-surface-variant">
                      {t.vacio}
                    </td>
                  </tr>
                ) : (
                  usuarios.map((u) => (
                    <tr key={u.id} className="h-fila-tabla">
                      <td className="px-space-md font-semibold">{u.nombre}</td>
                      <td className="px-space-md break-all">{u.email}</td>
                      <td className="px-space-md">
                        <Insignia tono="info">{t.rol}</Insignia>
                      </td>
                      <td className="px-space-md whitespace-nowrap">{fecha(u.desde)}</td>
                      <td className="px-space-md whitespace-nowrap">{fecha(u.ultimo_acceso_at)}</td>
                      <td className="px-space-md">
                        <Insignia tono={u.dos_pasos ? 'exito' : 'aviso'}>{u.dos_pasos ? t.si : t.no}</Insignia>
                      </td>
                      <td className="px-space-md text-right">{u.cambios_30d}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
