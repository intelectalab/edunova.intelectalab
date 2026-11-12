'use client';

import { useRouter } from 'next/navigation';
import { useId, useState, useTransition } from 'react';
import { Interruptor, TarjetaSeccion, useToast } from '@/componentes/base';
import { Campo, ErrorLista } from '@/componentes/cms/Formulario';
import { cms } from '@/contenido/provisional';
import { guardarIntegraciones, probarIntegracion, type CambiosIntegraciones, type IntegracionesCms, type ServicioPrueba } from '../../panel-acciones';

const TRACKING = ['gtm', 'ga4', 'meta_pixel', 'linkedin', 'clarity', 'search_console'] as const;
const CLAVES = ['crm', 'smtp', 'ia', 'recaptcha', 'alertas', 'correo'] as const;
type Clave = (typeof CLAVES)[number];
const vacias = () => Object.fromEntries(CLAVES.map((k) => [k, ''])) as Record<Clave, string>;
const sinBorrar = () => Object.fromEntries(CLAVES.map((k) => [k, false])) as Record<Clave, boolean>;

/** Campo de una clave: nunca muestra la guardada; vacío = mantenerla; casilla para borrarla. */
function CampoClave({ etiqueta, configurada, servidor, valor, borrar, error, onValor, onBorrar }: { etiqueta: string; configurada: boolean; servidor?: boolean; valor: string; borrar: boolean; error?: string; onValor: (v: string) => void; onBorrar: (b: boolean) => void }) {
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
        {error ?? (configurada ? t.configurada : servidor ? t.enServidor : t.sinConfigurar)}
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

/** «Probar conexión»: usa lo guardado en el servidor y muestra el resultado al lado. */
function BotonProbar({ servicio, pendiente }: { servicio: ServicioPrueba; pendiente: boolean }) {
  const t = cms.integraciones;
  const [probando, iniciar] = useTransition();
  const [resultado, setResultado] = useState<{ ok: boolean; mensaje: string } | null>(null);
  return (
    <div className="flex flex-wrap items-center gap-space-sm" data-probar={servicio}>
      <button
        type="button"
        disabled={probando}
        onClick={() =>
          iniciar(async () => {
            setResultado(null);
            setResultado(await probarIntegracion(servicio));
          })
        }
        className="h-10 px-space-md rounded-lg border border-outline text-primary hover:bg-surface-container font-label-lg text-label-lg disabled:opacity-60"
      >
        {probando ? t.probando : t.probar}
      </button>
      {resultado ? (
        <span role="status" className={`font-label-md text-label-md ${resultado.ok ? 'text-primary' : 'text-error'}`}>
          {resultado.mensaje}
        </span>
      ) : (
        pendiente && <span className="font-label-sm text-label-sm text-secondary">{t.probarNota}</span>
      )}
    </div>
  );
}

/**
 * EDUN-403 / SIT-033 · Tracking e integraciones: IDs de medición (cada uno se activa aparte),
 * conexiones (CRM, SMTP, IA, WordPress, reCAPTCHA, alertas, correo) con «Probar conexión», el
 * acceso de demostración y el estado de los conectores. Las claves se escriben pero no se leen.
 */
export function EditorIntegraciones({ inicial }: { inicial: IntegracionesCms }) {
  const t = cms.integraciones;
  const [d, setD] = useState(inicial);
  const [claves, setClaves] = useState(vacias);
  const [borrar, setBorrar] = useState(sinBorrar);
  const [cambiado, setCambiado] = useState(false);
  const [errores, setErrores] = useState<Record<string, string>>({});
  const [guardando, iniciar] = useTransition();
  const router = useRouter();
  const avisar = useToast();
  const clave = (k: Clave) => (borrar[k] ? null : claves[k] || null);
  const cambiar = (nuevo: IntegracionesCms) => {
    setD(nuevo);
    setCambiado(true);
  };
  const ponerClave = (k: Clave, v: string) => {
    setClaves({ ...claves, [k]: v });
    setCambiado(true);
  };
  const ponerBorrar = (k: Clave, b: boolean) => {
    setBorrar({ ...borrar, [k]: b });
    setCambiado(true);
  };

  function guardar() {
    setErrores({});
    const cambios: CambiosIntegraciones = {
      tracking: d.tracking,
      crm: { url: d.crm.url || null, token: clave('crm'), token_borrar: borrar.crm },
      smtp: { host: d.smtp.host || null, puerto: Number(d.smtp.puerto) || 587, usuario: d.smtp.usuario || null, cifrado: d.smtp.cifrado, remitente: d.smtp.remitente || null, clave: clave('smtp'), clave_borrar: borrar.smtp },
      ia: { proveedor: d.ia.proveedor || null, clave: clave('ia'), clave_borrar: borrar.ia },
      wordpress: { url: d.wordpress.url || null },
      recaptcha: { sitio: d.recaptcha.sitio || null, umbral: Number(d.recaptcha.umbral) || 0.5, secreto: clave('recaptcha'), secreto_borrar: borrar.recaptcha },
      alertas: { webhook: clave('alertas'), webhook_borrar: borrar.alertas },
      correo: { webhook_secreto: clave('correo'), webhook_secreto_borrar: borrar.correo },
      demo: { activo: d.demo.activo, colegio: d.demo.colegio || null },
    };
    iniciar(async () => {
      const r = await guardarIntegraciones(cambios);
      if (!r.ok) return setErrores(Object.keys(r.errores).length ? r.errores : { _: r.mensaje });
      setClaves(vacias());
      setBorrar(sinBorrar());
      setD(r.datos);
      setCambiado(false);
      avisar(t.guardado);
      router.refresh();
    });
  }

  const campoClave = (k: Clave, etiqueta: string, guardada: { configurado: boolean; servidor?: boolean }, campoError: string) => (
    <CampoClave etiqueta={etiqueta} configurada={guardada.configurado} servidor={guardada.servidor} valor={claves[k]} borrar={borrar[k]} error={errores[campoError]} onValor={(x) => ponerClave(k, x)} onBorrar={(b) => ponerBorrar(k, b)} />
  );

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
                  onCambio={(x) => cambiar({ ...d, tracking: { ...d.tracking, [k]: { valor: x.trim() || null, activo: x.trim() ? v.activo : false } } })}
                />
                <span className="pb-7">
                  <Interruptor etiqueta={t.activo(t.etiquetas[k])} ocultarEtiqueta activo={v.activo} deshabilitado={!v.valor} onCambio={(x) => cambiar({ ...d, tracking: { ...d.tracking, [k]: { ...v, activo: x } } })} />
                </span>
              </li>
            );
          })}
        </ul>
      </TarjetaSeccion>

      <TarjetaSeccion icono="article" titulo={t.wordpress}>
        <div className="flex flex-col gap-space-md">
          <Campo etiqueta={t.wordpressUrl} valor={d.wordpress.url ?? ''} error={errores['wordpress.url']} onCambio={(x) => cambiar({ ...d, wordpress: { url: x.trim() || null } })} />
          <BotonProbar servicio="wordpress" pendiente={cambiado} />
        </div>
      </TarjetaSeccion>

      <TarjetaSeccion icono="mail" titulo={t.smtp} descripcion={t.smtpNota}>
        <div className="flex flex-col gap-space-md">
          <div className="grid gap-space-md md:grid-cols-3">
            <Campo etiqueta={t.smtpHost} valor={d.smtp.host ?? ''} error={errores['smtp.host']} onCambio={(x) => cambiar({ ...d, smtp: { ...d.smtp, host: x.trim() || null } })} />
            <Selector etiqueta={t.smtpPuerto} valor={String(d.smtp.puerto)} opciones={{ '587': '587', '465': '465', '2525': '2525', '25': '25' }} onCambio={(v) => cambiar({ ...d, smtp: { ...d.smtp, puerto: Number(v) } })} />
            <Selector etiqueta={t.smtpCifrado} valor={d.smtp.cifrado} opciones={{ tls: 'TLS (STARTTLS)', ssl: 'SSL', ninguno: '—' }} onCambio={(v) => cambiar({ ...d, smtp: { ...d.smtp, cifrado: v } })} />
            <Campo etiqueta={t.smtpUsuario} valor={d.smtp.usuario ?? ''} error={errores['smtp.usuario']} onCambio={(x) => cambiar({ ...d, smtp: { ...d.smtp, usuario: x.trim() || null } })} />
            {campoClave('smtp', t.smtpClave, d.smtp.clave, 'smtp.clave')}
            <Campo etiqueta={t.smtpRemitente} valor={d.smtp.remitente ?? ''} error={errores['smtp.remitente']} onCambio={(x) => cambiar({ ...d, smtp: { ...d.smtp, remitente: x.trim() || null } })} />
          </div>
          <BotonProbar servicio="smtp" pendiente={cambiado} />
        </div>
      </TarjetaSeccion>

      <TarjetaSeccion icono="hub" titulo={t.crm}>
        <div className="flex flex-col gap-space-md">
          <div className="grid gap-space-md md:grid-cols-2">
            <Campo etiqueta={t.crmUrl} valor={d.crm.url ?? ''} error={errores['crm.url']} onCambio={(x) => cambiar({ ...d, crm: { ...d.crm, url: x.trim() || null } })} />
            {campoClave('crm', t.crmToken, d.crm.token, 'crm.token')}
          </div>
          <BotonProbar servicio="crm" pendiente={cambiado} />
        </div>
      </TarjetaSeccion>

      <TarjetaSeccion icono="verified_user" titulo={t.recaptcha} descripcion={t.recaptchaNota}>
        <div className="grid gap-space-md md:grid-cols-3">
          <Campo etiqueta={t.recaptchaSitio} valor={d.recaptcha.sitio ?? ''} error={errores['recaptcha.sitio']} onCambio={(x) => cambiar({ ...d, recaptcha: { ...d.recaptcha, sitio: x.trim() || null } })} />
          {campoClave('recaptcha', t.recaptchaSecreto, d.recaptcha.secreto, 'recaptcha.secreto')}
          <Campo etiqueta={t.recaptchaUmbral} valor={String(d.recaptcha.umbral)} error={errores['recaptcha.umbral']} onCambio={(x) => cambiar({ ...d, recaptcha: { ...d.recaptcha, umbral: Number(x.replace(',', '.')) || 0 } })} />
        </div>
      </TarjetaSeccion>

      <TarjetaSeccion icono="smart_toy" titulo={t.ia} descripcion={t.iaNota}>
        <div className="grid gap-space-md md:grid-cols-2">
          <Selector etiqueta={t.iaProveedor} valor={d.ia.proveedor ?? ''} opciones={t.iaProveedores} onCambio={(v) => cambiar({ ...d, ia: { ...d.ia, proveedor: v || null } })} />
          {campoClave('ia', t.iaClave, d.ia.clave, 'ia.clave')}
        </div>
      </TarjetaSeccion>

      <TarjetaSeccion icono="notifications_active" titulo={t.alertas} descripcion={t.alertasNota}>
        <div className="flex flex-col gap-space-md">
          {campoClave('alertas', t.alertasWebhook, d.alertas.webhook, 'alertas.webhook')}
          <BotonProbar servicio="alertas" pendiente={cambiado} />
        </div>
      </TarjetaSeccion>

      <TarjetaSeccion icono="mark_email_unread" titulo={t.correo} descripcion={t.correoNota}>
        {campoClave('correo', t.correoSecreto, d.correo.webhook_secreto, 'correo.webhook_secreto')}
      </TarjetaSeccion>

      <TarjetaSeccion icono="co_present" titulo={t.demo} descripcion={t.demoNota}>
        <div className="grid gap-space-md md:grid-cols-2 items-end">
          <Interruptor etiqueta={t.demoActivo} activo={d.demo.activo} onCambio={(x) => cambiar({ ...d, demo: { ...d.demo, activo: x } })} />
          <div className="flex flex-col gap-space-xs">
            <Selector etiqueta={t.demoColegio} valor={d.demo.colegio} opciones={Object.fromEntries(d.demo.colegios.map((c) => [c.slug, c.nombre]))} onCambio={(v) => cambiar({ ...d, demo: { ...d.demo, colegio: v } })} />
            {errores['demo.colegio'] && <span className="font-label-sm text-label-sm text-error">{errores['demo.colegio']}</span>}
          </div>
        </div>
      </TarjetaSeccion>

      <TarjetaSeccion icono="settings_ethernet" titulo={t.conectores} descripcion={t.conectoresNota}>
        <div className="flex flex-col gap-space-sm">
          <p className="font-label-md text-label-md text-secondary">{t.entorno(d.conectores.produccion)}</p>
          <ul className="flex flex-col gap-space-xs" data-conectores>
            {(['pasarela_simulada', 'facturacion_simulada', 'whatsapp_simulado'] as const).map((k) => (
              <li key={k} className="flex items-center justify-between gap-space-md py-1 border-b border-outline-variant last:border-0">
                <span className="font-body-md text-body-md text-on-surface">{t.conectorNombres[k]}</span>
                <span className={`px-2 py-0.5 rounded-full font-label-sm text-label-sm ${d.conectores[k] ? 'bg-secondary-container text-on-secondary-container' : 'bg-primary-container text-on-primary-container'}`}>
                  {d.conectores[k] ? t.simulado : t.real}
                </span>
              </li>
            ))}
          </ul>
        </div>
      </TarjetaSeccion>

      <button type="button" onClick={guardar} disabled={guardando} className="self-start h-11 px-space-lg rounded-lg bg-primary hover:bg-primary-container text-on-primary font-label-lg text-label-lg disabled:opacity-60">
        {guardando ? t.guardando : t.guardar}
      </button>
    </div>
  );
}
