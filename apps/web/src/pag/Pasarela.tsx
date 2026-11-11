import { useState } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Titulo } from '../panel/ui';
import { fechaHora, useAccion, useCarga } from '../panel/utilidades';

type Cuenta = {
  id: number; proveedor: string; nombre: string; ambiente: 'pruebas' | 'produccion'; llave_publica: string | null; medios: string[];
  predeterminada: boolean; estado: string; verificada_en: string | null; webhook_url: string | null; tiene_secreto_webhook: boolean;
};
type Datos = { data: Cuenta[]; proveedores: Record<string, string>; medios: Record<string, string>; simulada: boolean };

/** EDUN-88 · PAG-10 Cuenta propia del colegio en la pasarela de pagos. */
export function Pasarela() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const editable = puede(colegio, 'pag.pasarela.configurar');
  const { datos, setDatos, error } = useCarga(() => pedir<Datos>('/pagos/pasarela'));
  const accion = useAccion();
  const cuenta = datos?.data[0];
  const [f, setF] = useState<{ proveedor: string; ambiente: string; llave_publica: string; llave_secreta: string; secreto_webhook: string; medios: string[] } | null>(null);
  const form = f ?? { proveedor: cuenta?.proveedor ?? 'culqi', ambiente: cuenta?.ambiente ?? 'pruebas', llave_publica: cuenta?.llave_publica ?? '', llave_secreta: '', secreto_webhook: '', medios: cuenta?.medios ?? ['tarjeta', 'yape'] };
  const set = (cambios: Partial<typeof form>) => setF({ ...form, ...cambios });

  return (
    <>
      <Titulo migas={`${colegio.nombre} · Pagos`} titulo="Pagos en línea" descripcion="Conecta la cuenta del colegio en la pasarela. El dinero llega directo al colegio; la comisión la asume el colegio, no la familia." />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {datos?.simulada && <Aviso tipo="ambar">Este servidor usa la pasarela simulada: los pagos son de prueba y no se mueve dinero real.</Aviso>}
      {datos && (
        <div style={{ display: 'grid', gap: 18, gridTemplateColumns: 'minmax(0, 1fr)', maxWidth: 900 }}>
          {cuenta && (
            <div className="tarjeta">
              <h2>{cuenta.nombre} · {cuenta.ambiente === 'pruebas' ? 'modo pruebas' : 'producción'}</h2>
              <p>
                Estado: <span className={`insignia ${cuenta.estado === 'activa' ? 'insignia-ok' : 'insignia-ambar'}`}>{cuenta.estado === 'activa' ? 'Activa' : 'Pendiente de prueba'}</span>
                {cuenta.verificada_en && <small className="ayuda"> · verificada {fechaHora(cuenta.verificada_en)}</small>}
              </p>
              <Campo etiqueta="URL de webhook del colegio" ayuda="Pégala en el panel de la pasarela para que avise cada pago. Los avisos llegan firmados." ancho>
                <input readOnly value={cuenta.webhook_url ?? ''} onFocus={(e) => e.target.select()} />
              </Campo>
              {editable && (
                <div className="acciones">
                  <button type="button" className="boton" disabled={accion.enviando} onClick={() => accion.ejecutar(async () => {
                    const r = await pedir<{ ok: boolean; mensaje: string; data: Cuenta[] }>(`/pagos/pasarela/${cuenta.id}/prueba`, { metodo: 'POST' });
                    setDatos({ ...datos, data: r.data });
                    if (!r.ok) throw new Error(r.mensaje);
                    accion.setOk(r.mensaje);
                  })}>Probar llaves</button>
                </div>
              )}
            </div>
          )}
          {editable && (
            <div className="tarjeta">
              <h2>{cuenta ? 'Cambiar configuración' : 'Conectar la cuenta del colegio'}</h2>
              <div className="campos">
                <Campo etiqueta="Pasarela">
                  <select value={form.proveedor} disabled={!!cuenta} onChange={(e) => set({ proveedor: e.target.value })}>
                    {Object.entries(datos.proveedores).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                  </select>
                </Campo>
                <Campo etiqueta="Ambiente">
                  <select value={form.ambiente} onChange={(e) => set({ ambiente: e.target.value })}>
                    <option value="pruebas">Pruebas</option><option value="produccion">Producción</option>
                  </select>
                </Campo>
                <Campo etiqueta="Llave pública"><input value={form.llave_publica} onChange={(e) => set({ llave_publica: e.target.value })} /></Campo>
                <Campo etiqueta="Llave secreta" ayuda={cuenta ? 'Guardada y cifrada. Escribe solo para cambiarla.' : 'Se guarda cifrada.'}>
                  <input type="password" autoComplete="off" value={form.llave_secreta} onChange={(e) => set({ llave_secreta: e.target.value })} />
                </Campo>
                <Campo etiqueta="Secreto del webhook" ayuda={cuenta?.tiene_secreto_webhook ? 'Ya configurado.' : 'Opcional: si no lo das, Edunova genera uno.'}>
                  <input type="password" autoComplete="off" value={form.secreto_webhook} onChange={(e) => set({ secreto_webhook: e.target.value })} />
                </Campo>
              </div>
              <p className="etiqueta" style={{ marginTop: 10 }}>Medios activos</p>
              {Object.entries(datos.medios).map(([k, v]) => (
                <label key={k} className="casilla">
                  <input type="checkbox" checked={form.medios.includes(k)} onChange={(e) => set({ medios: e.target.checked ? [...form.medios, k] : form.medios.filter((m) => m !== k) })} /> {v}
                </label>
              ))}
              <p className="ayuda">Cambiar las llaves o el ambiente deja la cuenta pendiente hasta probarla de nuevo. Prueba primero en modo pruebas.</p>
              <div className="acciones">
                <button type="button" className="boton" disabled={accion.enviando} onClick={() => accion.ejecutar(async () => {
                  const r = await pedir<{ data: Cuenta[] }>(cuenta ? `/pagos/pasarela/${cuenta.id}` : '/pagos/pasarela', { metodo: cuenta ? 'PUT' : 'POST', cuerpo: { ...form, llave_secreta: form.llave_secreta || null, secreto_webhook: form.secreto_webhook || null } });
                  setDatos({ ...datos, data: r.data });
                  setF(null);
                }, 'Configuración guardada. Ahora prueba las llaves.')}>Guardar</button>
              </div>
            </div>
          )}
        </div>
      )}
    </>
  );
}
