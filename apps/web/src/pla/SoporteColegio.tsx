import { useState, type FormEvent } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Titulo } from '../panel/ui';
import { fechaHora, useAccion, useCarga } from '../panel/utilidades';
import { SemaforoSla } from './MesaAyuda';
import { ESTADOS_TICKET, PRIORIDADES, type Detalle, type Ticket } from './soporte';

type Politica = { prioridad: string; horas_primera_respuesta: number; horas_resolucion: number; descripcion: string | null };

function NuevoTicket({ categorias, politicas, alCrear }: { categorias: Record<string, string>; politicas: Politica[]; alCrear: (id: number) => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const [f, setF] = useState({ asunto: '', descripcion: '', categoria: 'consulta', prioridad: 'media' });
  const [adjuntos, setAdjuntos] = useState<File[]>([]);
  const p = politicas.find((x) => x.prioridad === f.prioridad);

  async function enviar(e: FormEvent) {
    e.preventDefault();
    const cuerpo = new FormData();
    Object.entries(f).forEach(([k, v]) => cuerpo.append(k, v));
    adjuntos.forEach((a) => cuerpo.append('adjuntos[]', a));
    await accion.ejecutar(async () => alCrear((await pedir<{ id: number }>('/soporte/tickets', { metodo: 'POST', cuerpo })).id));
  }

  return (
    <form onSubmit={enviar} noValidate>
      <div className="campos">
        <Campo etiqueta="Categoría">
          <select value={f.categoria} onChange={(e) => setF({ ...f, categoria: e.target.value })}>
            {Object.entries(categorias).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </Campo>
        <Campo etiqueta="Prioridad sugerida">
          <select value={f.prioridad} onChange={(e) => setF({ ...f, prioridad: e.target.value })}>
            {politicas.map((x) => <option key={x.prioridad} value={x.prioridad}>{PRIORIDADES[x.prioridad]} · {x.descripcion}</option>)}
          </select>
        </Campo>
        <Campo etiqueta="Asunto" ancho>
          <input value={f.asunto} onChange={(e) => setF({ ...f, asunto: e.target.value })} />
        </Campo>
      </div>
      {p && <p className="ayuda">Plazo comprometido: primera respuesta en {p.horas_primera_respuesta} h hábiles y solución en {p.horas_resolucion} h hábiles (lunes a viernes de 8:00 a 18:00).</p>}
      <Campo etiqueta="¿Qué pasa?" ancho>
        <textarea className="control" style={{ height: 140, padding: 10 }} value={f.descripcion} onChange={(e) => setF({ ...f, descripcion: e.target.value })} />
      </Campo>
      <label className="boton boton-borde" style={{ marginTop: 10 }}>
        Adjuntar capturas o archivos ({adjuntos.length})
        <input type="file" multiple hidden accept="image/*,.pdf,.xlsx,.xls,.csv,.docx,.txt" onChange={(e) => setAdjuntos(Array.from(e.target.files ?? []).slice(0, 5))} />
      </label>
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="acciones">
        <button type="submit" className="boton" disabled={accion.enviando}>
          {accion.enviando ? 'Enviando…' : 'Enviar a soporte'}
        </button>
      </div>
    </form>
  );
}

function VerTicket({ id, editable }: { id: number; editable: boolean }) {
  const { pedir } = useSesion();
  const { datos, setDatos, error } = useCarga(() => pedir<Detalle>(`/soporte/tickets/${id}`), [id]);
  const accion = useAccion();
  const [mensaje, setMensaje] = useState('');
  const [nota, setNota] = useState(0);
  if (!datos) return <Aviso tipo="error">{error}</Aviso>;
  const abierto = !['resuelto', 'cerrado'].includes(datos.estado);

  return (
    <>
      <p className="ayuda">
        {ESTADOS_TICKET[datos.estado]} · <SemaforoSla s={datos.semaforo} /> · primera respuesta comprometida: {fechaHora(datos.vence_primera_respuesta_at)} · solución: {fechaHora(datos.vence_resolucion_at)}
      </p>
      <p style={{ whiteSpace: 'pre-wrap' }}>{datos.descripcion}</p>
      {datos.mensajes.map((m) => (
        <div key={m.id} className="aviso" style={{ background: m.es_staff ? 'var(--en-azul-suave)' : 'var(--en-suave)', color: 'var(--en-texto)' }}>
          <small className="ayuda">{m.es_staff ? 'Soporte Edunova' : m.autor} · {fechaHora(m.created_at)}</small>
          <br />
          <span style={{ whiteSpace: 'pre-wrap' }}>{m.mensaje}</span>
        </div>
      ))}
      <Aviso tipo="error">{accion.error}</Aviso>
      {editable && abierto && (
        <>
          <textarea className="control" aria-label="Tu respuesta" style={{ width: '100%', height: 90, padding: 10, marginTop: 10 }} value={mensaje} onChange={(e) => setMensaje(e.target.value)} />
          <div className="acciones">
            <button type="button" className="boton" disabled={accion.enviando || !mensaje.trim()}
              onClick={() => accion.ejecutar(async () => { setDatos(await pedir<Detalle>(`/soporte/tickets/${id}/mensajes`, { metodo: 'POST', cuerpo: { mensaje } })); setMensaje(''); })}>
              Responder
            </button>
          </div>
        </>
      )}
      {editable && datos.estado === 'resuelto' && !datos.calificacion && (
        <div className="tarjeta" style={{ marginTop: 12 }}>
          <h3 style={{ marginTop: 0 }}>¿Cómo te atendimos?</h3>
          <div style={{ display: 'flex', gap: 6 }}>
            {[1, 2, 3, 4, 5].map((n) => (
              <button key={n} type="button" className={`boton boton-chico ${nota >= n ? '' : 'boton-borde'}`} aria-label={`${n} de 5`} onClick={() => setNota(n)}>
                ★
              </button>
            ))}
          </div>
          <div className="acciones">
            <button type="button" className="boton" disabled={!nota || accion.enviando}
              onClick={() => accion.ejecutar(async () => setDatos(await pedir<Detalle>(`/soporte/tickets/${id}/calificacion`, { metodo: 'POST', cuerpo: { calificacion: nota } })))}>
              Calificar y cerrar
            </button>
          </div>
        </div>
      )}
      {datos.calificacion && <p className="ayuda">Calificaste la atención con {datos.calificacion} de 5. ¡Gracias!</p>}
    </>
  );
}

/** EDUN-33 · PLA-08 Soporte desde el colegio. */
export function SoporteColegio() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const editable = puede(colegio, 'pla.tickets.crear');
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Ticket[]; categorias: Record<string, string>; politicas: Politica[] }>('/soporte/tickets'));
  const [nuevo, setNuevo] = useState(false);
  const [abierto, setAbierto] = useState<Ticket | null>(null);

  return (
    <>
      <Titulo migas={`${colegio.nombre} · Ayuda`} titulo="Soporte" descripcion="Pide ayuda a Edunova y sigue el avance con el plazo comprometido.">
        {editable && (
          <button type="button" className="boton" onClick={() => setNuevo(true)}>
            + Nueva solicitud
          </button>
        )}
      </Titulo>
      <Aviso tipo="error">{error}</Aviso>
      <div className="tarjeta">
        <div className="tabla-caja">
          <table className="tabla">
            <thead>
              <tr>
                <th>Solicitud</th>
                <th>Prioridad</th>
                <th>Estado</th>
                <th>Plazo</th>
                <th>Creada</th>
              </tr>
            </thead>
            <tbody>
              {datos?.data.map((t) => (
                <tr key={t.id} style={{ cursor: 'pointer' }} onClick={() => setAbierto(t)}>
                  <td><b>{t.numero}</b> {t.asunto}</td>
                  <td>{PRIORIDADES[t.prioridad]}</td>
                  <td>{ESTADOS_TICKET[t.estado]}</td>
                  <td><SemaforoSla s={t.semaforo} /></td>
                  <td>{fechaHora(t.creado)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {datos?.data.length === 0 && <p className="vacio">Aún no has pedido soporte.</p>}
        </div>
      </div>
      {nuevo && datos && (
        <Dialogo titulo="Nueva solicitud de soporte" alCerrar={() => setNuevo(false)}>
          <NuevoTicket categorias={datos.categorias} politicas={datos.politicas} alCrear={(id) => { setNuevo(false); recargar(); setAbierto({ id } as Ticket); }} />
        </Dialogo>
      )}
      {abierto && (
        <Dialogo titulo={abierto.numero ? `${abierto.numero} · ${abierto.asunto}` : 'Solicitud enviada'} alCerrar={() => { setAbierto(null); recargar(); }}>
          <VerTicket id={abierto.id} editable={editable} />
        </Dialogo>
      )}
    </>
  );
}
