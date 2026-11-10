'use client';

import { useRouter } from 'next/navigation';
import { useId, useState, useTransition } from 'react';
import { Interruptor, TarjetaSeccion, useToast } from '@/componentes/base';
import { Campo, ErrorLista } from '@/componentes/cms/Formulario';
import { cms } from '@/contenido/provisional';
import { guardarIntegraciones, type CambiosIntegraciones, type IntegracionesCms } from '../../panel-acciones';

const TRACKING = ['gtm', 'ga4', 'meta_pixel', 'linkedin', 'clarity', 'search_console'] as const;

/** Campo de una clave: nunca muestra la guardada; vacío = mantenerla; casilla para borrarla. */
function CampoClave({ etiqueta, configurada, valor, borrar, error, onValor, onBorrar }: { etiqueta: string; configurada: boolean; valor: string; borrar: boolean; error?: string; onValor: (v: string) => void; onBorrar: (b: boolean) => void }) {
  const t = cms.integraciones;
  const id = useId();
  return (
    <div className="flex flex-col gap-space-xs">
      <label htmlFor={id} className="font-label-lg text-label-lg text-on-surface">
        {etiqueta}
      </label>
      <input
        id={id}
        type="password"
        autoComplete="new-password"
        value={valor}
        disabled={borrar}
        placeholder={configurada ? '••••••••' : ''}
        aria-describedby={`${id}-ayuda`}
        aria-invalid={error ? true : undefined}
        onChange={(e) => onValor(e.target.value)}
        className={`w-full px-space-md py-space-sm rounded-lg border bg-surface-container-lowest font-body-md text-body-md text-on-surface disabled:opacity-50 ${error ? 'border-error' : 'border-outline-variant'}`}
      />
      <span id={`${id}-ayuda`} className={`font-label-sm text-label-sm ${error ? 'text-error' : 'text-secondary'}`}>
        {error ?? (configurada ? t.configurada : t.sinConfigurar)}
      </span>
      {configurada && (
        <label className="inline-flex items-center gap-1 font-label-sm text-label-sm text-on-surface">
          <input type="checkbox" checked={borrar} onChange={(e) => onBorrar(e.target.checked)} className="w-4 h-4 accent-error" />
          {t.borrarClave}
        </label>
      )}
    </div>
  );
}

function Selector({ etiqueta, valor, opciones, onCambio }: { etiqueta: string; valor: string; opciones: Record<string, string>; onCambio: (v: string) => void }) {
  const id = useId();
  return (
    <div className="flex flex-col gap-space-xs">
      <label htmlFor={id} className="font-label-lg text-label-lg text-on-surface">
        {etiqueta}
      </label>
      <select id={id} value={valor} onChange={(e) => onCambio(e.target.value)} className="h-11 rounded-lg border border-outline-variant bg-surface-container-lowest px-2 font-body-md text-body-md text-on-surface">
        {Object.entries(opciones).map(([k, v]) => (
          <option key={k} value={k}>
            {v}
          </option>
        ))}
      </select>
    </div>
  );
}

/**
 * EDUN-403 / SIT-033 · Tracking e integraciones: IDs de medición (cada uno se activa aparte) y
 * conexiones del CRM, SMTP, IA y WordPress. Las claves se escriben pero no se leen.
 */
export function EditorIntegraciones({ inicial }: { inicial: IntegracionesCms }) {
  const t = cms.integraciones;
  const [d, setD] = useState(inicial);
  const [claves, setClaves] = useState({ crm: '', smtp: '', ia: '' });
  const [borrar, setBorrar] = useState({ crm: false, smtp: false, ia: false });
  const [errores, setErrores] = useState<Record<string, string>>({});
  const [guardando, iniciar] = useTransition();
  const router = useRouter();
  const avisar = useToast();
  const clave = (k: 'crm' | 'smtp' | 'ia') => (borrar[k] ? null : claves[k] || null);

  function guardar() {
    setErrores({});
    const cambios: CambiosIntegraciones = {
      tracking: d.tracking,
      crm: { url: d.crm.url || null, token: clave('crm'), token_borrar: borrar.crm },
      smtp: { host: d.smtp.host || null, puerto: Number(d.smtp.puerto) || 587, usuario: d.smtp.usuario || null, cifrado: d.smtp.cifrado, remitente: d.smtp.remitente || null, clave: clave('smtp'), clave_borrar: borrar.smtp },
      ia: { proveedor: d.ia.proveedor || null, clave: clave('ia'), clave_borrar: borrar.ia },
      wordpress: { url: d.wordpress.url || null },
    };
    iniciar(async () => {
      const r = await guardarIntegraciones(cambios);
      if (!r.ok) return setErrores(Object.keys(r.errores).length ? r.errores : { _: r.mensaje });
      setClaves({ crm: '', smtp: '', ia: '' });
      setBorrar({ crm: false, smtp: false, ia: false });
      setD(r.datos);
      avisar(t.guardado);
      router.refresh();
    });
  }

  return (
    <div className="flex flex-col gap-space-md">
      {Object.keys(errores).length > 0 && <ErrorLista error={errores._ ?? t.error} />}
      <TarjetaSeccion icono="monitoring" titulo={t.tracking} descripcion={t.trackingNota}>
        <ul className="flex flex-col gap-space-md">
          {TRACKING.map((k) => {
            const v = d.tracking[k];
            return (
              <li key={k} data-tracking={k} className="grid gap-space-sm md:grid-cols-[1fr_auto] items-end">
                <Campo
                  etiqueta={t.etiquetas[k]}
                  ayuda={t.ejemplos[k]}
                  valor={v.valor ?? ''}
                  error={errores[`tracking.${k}.valor`]}
                  onCambio={(x) => setD({ ...d, tracking: { ...d.tracking, [k]: { valor: x.trim() || null, activo: x.trim() ? v.activo : false } } })}
                />
                <span className="pb-7">
                  <Interruptor etiqueta={t.activo(t.etiquetas[k])} ocultarEtiqueta activo={v.activo} deshabilitado={!v.valor} onCambio={(x) => setD({ ...d, tracking: { ...d.tracking, [k]: { ...v, activo: x } } })} />
                </span>
              </li>
            );
          })}
        </ul>
      </TarjetaSeccion>

      <TarjetaSeccion icono="hub" titulo={t.crm}>
        <div className="grid gap-space-md md:grid-cols-2">
          <Campo etiqueta={t.crmUrl} valor={d.crm.url ?? ''} error={errores['crm.url']} onCambio={(x) => setD({ ...d, crm: { ...d.crm, url: x.trim() || null } })} />
          <CampoClave etiqueta={t.crmToken} configurada={d.crm.token.configurado} valor={claves.crm} borrar={borrar.crm} error={errores['crm.token']} onValor={(x) => setClaves({ ...claves, crm: x })} onBorrar={(b) => setBorrar({ ...borrar, crm: b })} />
        </div>
      </TarjetaSeccion>

      <TarjetaSeccion icono="mail" titulo={t.smtp}>
        <div className="grid gap-space-md md:grid-cols-3">
          <Campo etiqueta={t.smtpHost} valor={d.smtp.host ?? ''} error={errores['smtp.host']} onCambio={(x) => setD({ ...d, smtp: { ...d.smtp, host: x.trim() || null } })} />
          <Selector etiqueta={t.smtpPuerto} valor={String(d.smtp.puerto)} opciones={{ '587': '587', '465': '465', '2525': '2525', '25': '25' }} onCambio={(v) => setD({ ...d, smtp: { ...d.smtp, puerto: Number(v) } })} />
          <Selector etiqueta={t.smtpCifrado} valor={d.smtp.cifrado} opciones={{ tls: 'TLS (STARTTLS)', ssl: 'SSL', ninguno: '—' }} onCambio={(v) => setD({ ...d, smtp: { ...d.smtp, cifrado: v } })} />
          <Campo etiqueta={t.smtpUsuario} valor={d.smtp.usuario ?? ''} error={errores['smtp.usuario']} onCambio={(x) => setD({ ...d, smtp: { ...d.smtp, usuario: x.trim() || null } })} />
          <CampoClave etiqueta={t.smtpClave} configurada={d.smtp.clave.configurado} valor={claves.smtp} borrar={borrar.smtp} error={errores['smtp.clave']} onValor={(x) => setClaves({ ...claves, smtp: x })} onBorrar={(b) => setBorrar({ ...borrar, smtp: b })} />
          <Campo etiqueta={t.smtpRemitente} valor={d.smtp.remitente ?? ''} error={errores['smtp.remitente']} onCambio={(x) => setD({ ...d, smtp: { ...d.smtp, remitente: x.trim() || null } })} />
        </div>
      </TarjetaSeccion>

      <TarjetaSeccion icono="smart_toy" titulo={t.ia}>
        <div className="grid gap-space-md md:grid-cols-2">
          <Selector etiqueta={t.iaProveedor} valor={d.ia.proveedor ?? ''} opciones={t.iaProveedores} onCambio={(v) => setD({ ...d, ia: { ...d.ia, proveedor: v || null } })} />
          <CampoClave etiqueta={t.iaClave} configurada={d.ia.clave.configurado} valor={claves.ia} borrar={borrar.ia} error={errores['ia.clave']} onValor={(x) => setClaves({ ...claves, ia: x })} onBorrar={(b) => setBorrar({ ...borrar, ia: b })} />
        </div>
      </TarjetaSeccion>

      <TarjetaSeccion icono="article" titulo={t.wordpress}>
        <Campo etiqueta={t.wordpressUrl} valor={d.wordpress.url ?? ''} error={errores['wordpress.url']} onCambio={(x) => setD({ ...d, wordpress: { url: x.trim() || null } })} />
      </TarjetaSeccion>

      <button type="button" onClick={guardar} disabled={guardando} className="self-start h-11 px-space-lg rounded-lg bg-primary hover:bg-primary-container text-on-primary font-label-lg text-label-lg disabled:opacity-60">
        {guardando ? t.guardando : t.guardar}
      </button>
    </div>
  );
}
