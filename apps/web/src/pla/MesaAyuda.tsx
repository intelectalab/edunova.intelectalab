import { useState } from 'react';
import { useSesion } from '../sesion/contexto';
import { Aviso, Campo, Titulo } from '../panel/ui';
import { fecha, fechaHora, useAccion, useCarga } from '../panel/utilidades';
import { ESTADOS_TICKET, PRIORIDADES, type Cumplimiento, type Detalle, type Fila, type Semaforo, type Ticket } from './soporte';

const COLOR: Record<string, [string, string]> = {
  verde: ['A tiempo', 'insignia-ok'],
  ambar: ['Por vencer', 'insignia-ambar'],
  rojo: ['Vencido', 'insignia-rojo'],
  cumplido: ['Cumplido', 'insignia-ok'],
  incumplido: ['Fuera de plazo', 'insignia-rojo'],
  sin_sla: ['Sin SLA', ''],
};

export function SemaforoSla({ s }: { s: Semaforo }) {
  const [t, c] = COLOR[s.color] ?? [s.color, ''];
  return (
    <span className={`insignia ${c}`} title={s.vence ? `Vence ${fechaHora(s.vence)}` : undefined}>
      {t}
      {s.porcentaje !== null ? ` · ${s.porcentaje}%` : ''}
    </span>
  );
}

function DetalleTicket({ id, staff, alCerrar }: { id: number; staff: { id: number; nombre: string }[]; alCerrar: () => void }) {
  const { pedir } = useSesion();
  const { datos, setDatos, error } = useCarga(() => pedir<Detalle>(`/plataforma/tickets/${id}`), [id]);
  const accion = useAccion();
  const [mensaje, setMensaje] = useState('');
  const [interno, setInterno] = useState(false);
  if (!datos) return <Aviso tipo="error">{error}</Aviso>;
  const cambiar = (cuerpo: Record<string, unknown>) =>
    accion.ejecutar(async () => setDatos(await pedir<Detalle>(`/plataforma/tickets/${id}`, { metodo: 'PATCH', cuerpo: { estado: datos.estado, ...cuerpo } })));

  return (
    <div className="tarjeta">
      <div className="titulo-fila">
        <h2>
          {datos.numero} · {datos.asunto}
        </h2>
        <button type="button" className="boton boton-chico boton-borde" onClick={alCerrar}>
          Volver
        </button>
      </div>
      <p className="ayuda">
        {datos.colegio} · {datos.categoria} · creado {fechaHora(datos.creado)} · <SemaforoSla s={datos.semaforo} />
        {datos.escalado && <span className="insignia insignia-rojo"> Escalado</span>}
      </p>
      <div className="filtros">
        <Campo etiqueta="Estado">
          <select value={datos.estado} onChange={(e) => void cambiar({ estado: e.target.value })}>
            {Object.entries(ESTADOS_TICKET).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </Campo>
        <Campo etiqueta="Prioridad">
          <select value={datos.prioridad} onChange={(e) => void cambiar({ prioridad: e.target.value })}>
            {Object.entries(PRIORIDADES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </Campo>
        <Campo etiqueta="Asignado a">
          <select value={datos.asignado_a ?? ''} onChange={(e) => void cambiar({ asignado_a: Number(e.target.value) || 0 })}>
            <option value="">Sin asignar</option>
            {staff.map((s) => <option key={s.id} value={s.id}>{s.nombre}</option>)}
          </select>
        </Campo>
      </div>
      <p className="ayuda">
        Primera respuesta: vence {fechaHora(datos.vence_primera_respuesta_at)}{datos.primera_respuesta_at ? ` · respondida ${fechaHora(datos.primera_respuesta_at)}` : ''} · Resolución: vence {fechaHora(datos.vence_resolucion_at)}
      </p>
      <p style={{ whiteSpace: 'pre-wrap' }}>{datos.descripcion}</p>
      {datos.adjuntos.length > 0 && <p className="ayuda">Adjuntos: {datos.adjuntos.map((a) => a.nombre_original).join(', ')}</p>}
      <Aviso tipo="error">{accion.error}</Aviso>
      {datos.mensajes.map((m) => (
        <div key={m.id} className="aviso" style={{ background: m.interno ? 'var(--en-ambar-suave)' : m.es_staff ? 'var(--en-azul-suave)' : 'var(--en-suave)', color: 'var(--en-texto)' }}>
          <small className="ayuda">
            {m.autor} · {fechaHora(m.created_at)}
            {m.interno ? ' · nota interna (el colegio no la ve)' : ''}
          </small>
          <br />
          <span style={{ whiteSpace: 'pre-wrap' }}>{m.mensaje}</span>
        </div>
      ))}
      <textarea className="control" aria-label="Respuesta" style={{ width: '100%', height: 100, padding: 10, marginTop: 10 }} value={mensaje} onChange={(e) => setMensaje(e.target.value)} />
      <div className="acciones">
        <label className="casilla">
          <input type="checkbox" checked={interno} onChange={(e) => setInterno(e.target.checked)} /> Nota interna
        </label>
        <button type="button" className="boton" disabled={accion.enviando || !mensaje.trim()}
          onClick={() => accion.ejecutar(async () => { setDatos(await pedir<Detalle>(`/plataforma/tickets/${id}/mensajes`, { metodo: 'POST', cuerpo: { mensaje, interno } })); setMensaje(''); })}>
          {interno ? 'Guardar nota' : 'Responder al colegio'}
        </button>
      </div>
    </div>
  );
}

/** EDUN-29 / EDUN-31 · PLA-05/07 Mesa de ayuda con semáforo de SLA. */
export function MesaAyuda() {
  const { pedir } = useSesion();
  const [estado, setEstado] = useState('activos');
  const [abierto, setAbierto] = useState<number | null>(null);
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Ticket[]; staff: { id: number; nombre: string }[] }>(`/plataforma/tickets?estado=${estado}`), [estado]);

  if (abierto && datos) return <DetalleTicket id={abierto} staff={datos.staff} alCerrar={() => { setAbierto(null); recargar(); }} />;
  return (
    <>
      <Titulo migas="Plataforma" titulo="Mesa de ayuda" descripcion="Tickets de los colegios con su plazo comprometido. Alerta al 75 % y escalamiento al vencer." />
      <div className="tarjeta">
        <div className="filtros">
          <Campo etiqueta="Estado">
            <select value={estado} onChange={(e) => setEstado(e.target.value)}>
              <option value="activos">Abiertos, en curso y esperando</option>
              {Object.entries(ESTADOS_TICKET).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </Campo>
        </div>
        <Aviso tipo="error">{error}</Aviso>
        <div className="tabla-caja">
          <table className="tabla">
            <thead>
              <tr>
                <th>Ticket</th>
                <th>Colegio</th>
                <th>Prioridad</th>
                <th>Estado</th>
                <th>SLA</th>
                <th>Asignado</th>
              </tr>
            </thead>
            <tbody>
              {datos?.data.map((t) => (
                <tr key={t.id} style={{ cursor: 'pointer' }} onClick={() => setAbierto(t.id)}>
                  <td>
                    <b>{t.numero}</b> {t.asunto}
                  </td>
                  <td>{t.colegio}</td>
                  <td>{PRIORIDADES[t.prioridad]}</td>
                  <td>{ESTADOS_TICKET[t.estado]}</td>
                  <td><SemaforoSla s={t.semaforo} /></td>
                  <td>{t.asignado ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {datos?.data.length === 0 && <p className="vacio">No hay tickets.</p>}
        </div>
      </div>
    </>
  );
}

const pct = (v: number | null) => (v === null ? '—' : `${v}%`);
const num = (v: number | null, sufijo = '') => (v === null ? '—' : `${v}${sufijo}`);

function TablaCumplimiento({ filas, clave, titulo }: { filas: (Fila & Record<string, unknown>)[]; clave: string; titulo: string }) {
  return (
    <div className="tarjeta">
      <h2>{titulo}</h2>
      <div className="tabla-caja">
        <table className="tabla">
          <thead>
            <tr>
              <th />
              <th>Tickets</th>
              <th>1.ª respuesta a tiempo</th>
              <th>Resolución a tiempo</th>
              <th>Horas 1.ª resp.</th>
              <th>Horas resolución</th>
              <th>Calificación</th>
            </tr>
          </thead>
          <tbody>
            {filas.map((f) => (
              <tr key={String(f[clave])}>
                <td><b>{clave === 'prioridad' ? PRIORIDADES[String(f[clave])] : String(f[clave])}</b></td>
                <td>{f.tickets}</td>
                <td>{pct(f.primera_respuesta_a_tiempo)}</td>
                <td>{pct(f.resolucion_a_tiempo)}</td>
                <td>{num(f.horas_primera_respuesta, ' h')}</td>
                <td>{num(f.horas_resolucion, ' h')}</td>
                <td>{num(f.calificacion, ' / 5')}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

/** EDUN-32 · PLA-H10 Cumplimiento del SLA. */
export function CumplimientoSla() {
  const { pedir } = useSesion();
  const [rango, setRango] = useState({ desde: '', hasta: '' });
  const consulta = new URLSearchParams(Object.entries(rango).filter(([, v]) => v)).toString();
  const { datos, error } = useCarga(() => pedir<Cumplimiento & { desde: string; hasta: string }>(`/plataforma/sla/cumplimiento${consulta ? `?${consulta}` : ''}`), [consulta]);
  return (
    <>
      <Titulo migas="Plataforma" titulo="Cumplimiento del SLA" descripcion={datos ? `Del ${fecha(datos.desde)} al ${fecha(datos.hasta)}` : undefined}>
        <div className="filtros" style={{ marginBottom: 0 }}>
          <Campo etiqueta="Desde"><input type="date" value={rango.desde} onChange={(e) => setRango({ ...rango, desde: e.target.value })} /></Campo>
          <Campo etiqueta="Hasta"><input type="date" value={rango.hasta} onChange={(e) => setRango({ ...rango, hasta: e.target.value })} /></Campo>
        </div>
      </Titulo>
      <Aviso tipo="error">{error}</Aviso>
      {datos && (
        <div style={{ display: 'grid', gap: 18, gridTemplateColumns: 'minmax(0, 1fr)' }}>
          <TablaCumplimiento titulo="Total" clave="nombre" filas={[{ ...datos.total, nombre: 'Todos' }]} />
          <TablaCumplimiento titulo="Por prioridad" clave="prioridad" filas={datos.por_prioridad} />
          <TablaCumplimiento titulo="Por colegio" clave="colegio" filas={datos.por_colegio} />
          <TablaCumplimiento titulo="Por mes" clave="mes" filas={datos.por_mes} />
        </div>
      )}
    </>
  );
}
