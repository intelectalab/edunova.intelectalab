import { useState } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Titulo } from '../panel/ui';
import { fecha, fechaHora, useAccion, useCarga } from '../panel/utilidades';
import { soles } from './tipos';

type Comprobante = {
  id: number; tipo: string; nombre_tipo: string; serie: string; numero: number; fecha_emision: string; cliente_nombre: string; cliente_numero_doc: string;
  total: number; estado_sunat: string; intentos: number; ultimo_error: string | null; siguiente_intento_at: string | null; motivo: string | null;
  referencia: string | null; tiene_nota: boolean; pdf_archivo_id: number | null; xml_archivo_id: number | null; email_cliente: string | null; correo_enviado_at: string | null;
};

const ESTADO: Record<string, [string, string]> = {
  pendiente: ['Pendiente', 'insignia-ambar'], enviado: ['Enviando', 'insignia-ambar'], aceptado: ['Aceptado', 'insignia-ok'],
  observado: ['Observado', 'insignia-ambar'], rechazado: ['Rechazado', 'insignia-rojo'], anulado: ['Anulado', 'insignia-rojo'],
};

/** EDUN-77 / EDUN-78 · PAG-06 Comprobantes: estado SUNAT, descarga, reenvío y nota de crédito. */
export function Comprobantes() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const [filtro, setFiltro] = useState({ estado: '', tipo: '', buscar: '' });
  const consulta = new URLSearchParams(Object.entries(filtro).filter(([, v]) => v)).toString();
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Comprobante[] }>(`/pagos/comprobantes${consulta ? `?${consulta}` : ''}`), [consulta]);
  const accion = useAccion();
  const [accionSobre, setAccionSobre] = useState<{ c: Comprobante; tipo: 'nota' | 'anular' | 'correo' } | null>(null);
  const [texto, setTexto] = useState('');
  const abrir = (c: Comprobante, formato: 'pdf' | 'xml') => accion.ejecutar(async () => {
    window.open((await pedir<{ url: string }>(`/pagos/comprobantes/${c.id}/${formato}`)).url, '_blank', 'noopener');
  });

  return (
    <>
      <Titulo migas={`${colegio.nombre} · Pagos`} titulo="Comprobantes" descripcion="Boletas, facturas y notas de crédito electrónicas con su estado ante SUNAT." />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="tarjeta">
        <div className="filtros">
          <Campo etiqueta="Buscar"><input placeholder="Cliente, documento o B001-25" value={filtro.buscar} onChange={(e) => setFiltro({ ...filtro, buscar: e.target.value })} /></Campo>
          <Campo etiqueta="Tipo">
            <select value={filtro.tipo} onChange={(e) => setFiltro({ ...filtro, tipo: e.target.value })}>
              <option value="">Todos</option><option value="03">Boletas</option><option value="01">Facturas</option><option value="07">Notas de crédito</option>
            </select>
          </Campo>
          <Campo etiqueta="Estado">
            <select value={filtro.estado} onChange={(e) => setFiltro({ ...filtro, estado: e.target.value })}>
              <option value="">Todos</option>{Object.entries(ESTADO).map(([k, [v]]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </Campo>
        </div>
        <div className="tabla-caja">
          <table className="tabla">
            <thead><tr><th>Comprobante</th><th>Fecha</th><th>Cliente</th><th>Total</th><th>Estado</th><th /></tr></thead>
            <tbody>
              {datos?.data.map((c) => (
                <tr key={c.id}>
                  <td><b>{c.serie}-{c.numero}</b><br /><small className="ayuda">{c.nombre_tipo}{c.referencia ? ` · modifica ${c.referencia}` : ''}</small></td>
                  <td>{fecha(c.fecha_emision)}</td>
                  <td>{c.cliente_nombre}<br /><small className="ayuda">{c.cliente_numero_doc}</small></td>
                  <td>{c.tipo === '07' ? '−' : ''}{soles(c.total)}</td>
                  <td>
                    <span className={`insignia ${ESTADO[c.estado_sunat]?.[1] ?? ''}`}>{ESTADO[c.estado_sunat]?.[0] ?? c.estado_sunat}</span>
                    {c.tiene_nota && <span className="insignia insignia-rojo"> Con nota de crédito</span>}
                    {c.estado_sunat === 'pendiente' && c.ultimo_error && <><br /><small className="ayuda">{c.intentos} intentos · {c.ultimo_error}{c.siguiente_intento_at ? ` · reintenta ${fechaHora(c.siguiente_intento_at)}` : ''}</small></>}
                    {c.motivo && <><br /><small className="ayuda">Motivo: {c.motivo}</small></>}
                  </td>
                  <td>
                    <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                      {c.pdf_archivo_id && <button type="button" className="boton boton-chico boton-borde" onClick={() => abrir(c, 'pdf')}>PDF</button>}
                      {c.xml_archivo_id && <button type="button" className="boton boton-chico boton-borde" onClick={() => abrir(c, 'xml')}>XML</button>}
                      {c.estado_sunat === 'pendiente' && puede(colegio, 'pag.comprobantes.reenviar') && (
                        <button type="button" className="boton boton-chico" onClick={() => accion.ejecutar(async () => { const r = await pedir<{ estado: string }>(`/pagos/comprobantes/${c.id}/reenvio`, { metodo: 'POST' }); recargar(); return r; }, 'Reenvío solicitado.')}>Reenviar</button>
                      )}
                      {c.pdf_archivo_id && puede(colegio, 'pag.comprobantes.reenviar') && <button type="button" className="boton boton-chico boton-borde" onClick={() => { setTexto(c.email_cliente ?? ''); setAccionSobre({ c, tipo: 'correo' }); }}>Correo</button>}
                      {c.tipo !== '07' && !c.tiene_nota && puede(colegio, 'pag.comprobantes.anular') && ['aceptado', 'observado'].includes(c.estado_sunat) && (
                        <button type="button" className="boton boton-chico boton-borde" onClick={() => { setTexto(''); setAccionSobre({ c, tipo: 'nota' }); }}>Nota de crédito</button>
                      )}
                      {puede(colegio, 'pag.comprobantes.anular') && ['pendiente', 'rechazado'].includes(c.estado_sunat) && (
                        <button type="button" className="boton boton-chico boton-borde" onClick={() => { setTexto(''); setAccionSobre({ c, tipo: 'anular' }); }}>Anular</button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {datos?.data.length === 0 && <p className="vacio">No hay comprobantes.</p>}
        </div>
      </div>
      {accionSobre && (
        <Dialogo titulo={`${accionSobre.tipo === 'nota' ? 'Nota de crédito para' : accionSobre.tipo === 'anular' ? 'Anular' : 'Enviar'} ${accionSobre.c.serie}-${accionSobre.c.numero}`} alCerrar={() => setAccionSobre(null)}>
          {accionSobre.tipo === 'nota' && <p className="ayuda">Anula el comprobante por {soles(accionSobre.c.total)} y revierte el pago: los cargos vuelven a quedar pendientes.</p>}
          <Campo etiqueta={accionSobre.tipo === 'correo' ? 'Correo' : 'Motivo'} ancho>
            <input type={accionSobre.tipo === 'correo' ? 'email' : 'text'} value={texto} maxLength={300} onChange={(e) => setTexto(e.target.value)} />
          </Campo>
          <div className="acciones">
            <button type="button" className="boton" disabled={!texto.trim() || accion.enviando}
              onClick={() => accion.ejecutar(async () => {
                const c = accionSobre.c;
                if (accionSobre.tipo === 'correo') await pedir(`/pagos/comprobantes/${c.id}/correo`, { metodo: 'POST', cuerpo: { email: texto } });
                else await pedir(`/pagos/comprobantes/${c.id}/${accionSobre.tipo === 'nota' ? 'nota-credito' : 'anulacion'}`, { metodo: 'POST', cuerpo: { motivo: texto } });
                setAccionSobre(null);
                recargar();
              }, accionSobre.tipo === 'correo' ? 'Comprobante enviado.' : accionSobre.tipo === 'nota' ? 'Nota de crédito emitida y pago revertido.' : 'Comprobante y pago anulados.')}>
              Confirmar
            </button>
          </div>
        </Dialogo>
      )}
    </>
  );
}
