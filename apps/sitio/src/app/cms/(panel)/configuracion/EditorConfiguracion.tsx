'use client';

import { Interruptor, TarjetaSeccion } from '@/componentes/base';
import { AvisoBorrador, Campo, ErrorLista, useBorrador } from '@/componentes/cms/Formulario';
import { cms } from '@/contenido/provisional';

export type ConfiguracionCms = {
  razon_social: string | null;
  ruc: string | null;
  direccion: string | null;
  telefono_central: string | null;
  whatsapp: string | null;
  email_contacto: string | null;
  email_reclamaciones: string | null;
  horario_soporte: string | null;
  mantenimiento: { activo: boolean; mensaje: string | null; vuelta: string | null };
};

type Clave = keyof typeof cms.configuracion.campos;
const LEGALES: Clave[] = ['razon_social', 'ruc', 'direccion'];
const CONTACTO: Clave[] = ['telefono_central', 'whatsapp', 'email_contacto', 'email_reclamaciones', 'horario_soporte'];

/**
 * EDUN-399 / SIT-062 · Configuración general: datos legales y de contacto de Edunova (llenan los
 * «[A CONFIRMAR]» del sitio, las políticas y el Libro de Reclamaciones) y modo mantenimiento.
 */
export function EditorConfiguracion({ inicial, conBorrador }: { inicial: ConfiguracionCms; conBorrador: boolean }) {
  const t = cms.configuracion;
  const { valores, cambiar, errores } = useBorrador({ _config: inicial });
  const c = valores._config as ConfiguracionCms;
  const e = errores._config ?? {};
  const set = (x: Partial<ConfiguracionCms>) => cambiar('_config', (v) => ({ ...v, ...x }));
  const setM = (x: Partial<ConfiguracionCms['mantenimiento']>) => set({ mantenimiento: { ...c.mantenimiento, ...x } });
  const campo = (k: Clave) => (
    <Campo
      key={k}
      etiqueta={t.campos[k].etiqueta}
      max={t.campos[k].max}
      valor={c[k] ?? ''}
      error={e[k]}
      ayuda={t.vacio}
      inputMode={k === 'ruc' ? 'numeric' : undefined}
      onCambio={(v) => set({ [k]: v.trim() === '' ? null : v })}
    />
  );

  return (
    <div className="flex flex-col gap-space-md">
      <AvisoBorrador visible={conBorrador}>{cms.hero.borradorPendiente}</AvisoBorrador>
      {Object.keys(e).length > 0 && <ErrorLista error={e._ ?? cms.lista.errores} />}
      <TarjetaSeccion icono="badge" titulo={t.legales} descripcion={t.descripcion}>
        <div className="grid gap-space-md md:grid-cols-2">{LEGALES.map(campo)}</div>
      </TarjetaSeccion>
      <TarjetaSeccion icono="contact_phone" titulo={t.contacto}>
        <div className="grid gap-space-md md:grid-cols-2">{CONTACTO.map(campo)}</div>
      </TarjetaSeccion>
      <TarjetaSeccion icono="construction" titulo={t.mantenimiento} descripcion={t.mantenimientoDescripcion}>
        <Interruptor etiqueta={t.activar} activo={c.mantenimiento.activo} onCambio={(x) => setM({ activo: x })} />
        {c.mantenimiento.activo && <p className="px-space-md py-space-sm rounded-lg bg-aviso-fondo border border-aviso-borde text-aviso-texto font-body-sm text-body-sm">{t.avisoActivo}</p>}
        <div className="grid gap-space-md md:grid-cols-2">
          <Campo
            etiqueta={t.mensaje.etiqueta}
            max={t.mensaje.max}
            filas={3}
            requerido={c.mantenimiento.activo}
            valor={c.mantenimiento.mensaje ?? ''}
            error={e['mantenimiento.mensaje']}
            onCambio={(v) => setM({ mensaje: v.trim() === '' ? null : v })}
          />
          <div className="flex flex-col gap-space-xs">
            <label htmlFor="vuelta-mantenimiento" className="font-label-lg text-label-lg text-on-surface">
              {t.vuelta}
            </label>
            <input
              id="vuelta-mantenimiento"
              type="datetime-local"
              value={c.mantenimiento.vuelta ?? ''}
              aria-invalid={e['mantenimiento.vuelta'] ? true : undefined}
              onChange={(ev) => setM({ vuelta: ev.target.value || null })}
              className="h-11 rounded-lg border border-outline-variant bg-surface-container-lowest px-3 font-body-md text-body-md text-on-surface"
            />
            {e['mantenimiento.vuelta'] && <span className="font-label-sm text-label-sm text-error">{e['mantenimiento.vuelta']}</span>}
          </div>
        </div>
      </TarjetaSeccion>
    </div>
  );
}
