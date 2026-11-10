import { useState, type FormEvent } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Titulo } from '../panel/ui';
import { fechaHora, useAccion, useCarga } from '../panel/utilidades';
import { useAnioEscolar } from './anios';
import { SelectorAnio } from './SelectorAnio';

type Postulante = {
  id: number;
  admision_proceso_id: number;
  grado_id: number;
  grado: string;
  apellidos: string;
  nombres: string;
  contacto_nombre: string;
  contacto_celular: string;
  contacto_email: string | null;
  etapa: string;
  alumno_id: number | null;
  proxima_entrevista: string | null;
};
type Tablero = {
  etapas: Record<string, string>;
  procesos: { id: number; nombre: string; fecha_inicio: string; fecha_fin: string }[];
  postulantes: Postulante[];
  vacantes: { grado_id: number; grado: string; cantidad: number | null; otorgadas: number }[];
  agenda: { id: number; fecha: string; postulante: string; postulante_id: number }[];
};

function NuevoPostulante({ t, alCrear }: { t: Tablero; alCrear: () => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const [f, setF] = useState({ admision_proceso_id: String(t.procesos[0]?.id ?? ''), grado_id: String(t.vacantes[0]?.grado_id ?? ''), apellidos: '', nombres: '', numero_documento: '', contacto_nombre: '', contacto_celular: '', contacto_email: '' });
  const c = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value });
  async function enviar(e: FormEvent) {
    e.preventDefault();
    const cuerpo = { ...f, admision_proceso_id: Number(f.admision_proceso_id), grado_id: Number(f.grado_id), numero_documento: f.numero_documento || null, contacto_email: f.contacto_email || null };
    if (await accion.ejecutar(() => pedir('/admision/postulantes', { metodo: 'POST', cuerpo }))) alCrear();
  }
  return (
    <form onSubmit={enviar} noValidate>
      <div className="campos">
        <Campo etiqueta="Proceso">
          <select value={f.admision_proceso_id} onChange={c('admision_proceso_id')}>{t.procesos.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}</select>
        </Campo>
        <Campo etiqueta="Grado al que postula">
          <select value={f.grado_id} onChange={c('grado_id')}>{t.vacantes.map((v) => <option key={v.grado_id} value={v.grado_id}>{v.grado}</option>)}</select>
        </Campo>
        <Campo etiqueta="Apellidos del postulante"><input value={f.apellidos} onChange={c('apellidos')} /></Campo>
        <Campo etiqueta="Nombres del postulante"><input value={f.nombres} onChange={c('nombres')} /></Campo>
        <Campo etiqueta="DNI del postulante"><input inputMode="numeric" maxLength={8} value={f.numero_documento} onChange={c('numero_documento')} /></Campo>
        <Campo etiqueta="Contacto (madre, padre…)"><input value={f.contacto_nombre} onChange={c('contacto_nombre')} /></Campo>
        <Campo etiqueta="Celular de contacto"><input inputMode="tel" value={f.contacto_celular} onChange={c('contacto_celular')} /></Campo>
        <Campo etiqueta="Correo de contacto"><input type="email" value={f.contacto_email} onChange={c('contacto_email')} /></Campo>
      </div>
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="acciones"><button type="submit" className="boton" disabled={accion.enviando}>Registrar postulante</button></div>
    </form>
  );
}

function FichaPostulante({ p, editable, alCambiar }: { p: Postulante; editable: boolean; alCambiar: () => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const historia = useCarga(() => pedir<{ data: { tipo: string; fecha: string; resultado: string | null; responsable: string | null }[] }>(`/admision/postulantes/${p.id}/historia`), [p.id]);
  const [cita, setCita] = useState({ fecha: '', lugar: '' });
  const [apo, setApo] = useState({ numero_documento: '', apellidos: '', nombres: p.contacto_nombre, parentesco: 'madre' });

  return (
    <>
      <p className="ayuda">{p.grado} · contacto {p.contacto_nombre} · {p.contacto_celular}{p.contacto_email ? ` · ${p.contacto_email}` : ''}</p>
      <Aviso tipo="error">{accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {editable && !p.alumno_id && (
        <div className="filtros">
          <Campo etiqueta="Entrevista"><input type="datetime-local" value={cita.fecha} onChange={(e) => setCita({ ...cita, fecha: e.target.value })} /></Campo>
          <Campo etiqueta="Lugar"><input value={cita.lugar} onChange={(e) => setCita({ ...cita, lugar: e.target.value })} /></Campo>
          <button type="button" className="boton boton-claro" disabled={!cita.fecha || accion.enviando}
            onClick={() => accion.ejecutar(async () => {
              const r = await pedir<{ correo: boolean; whatsapp_url: string | null }>(`/admision/postulantes/${p.id}/entrevistas`, { metodo: 'POST', cuerpo: { fecha: cita.fecha, lugar: cita.lugar || null } });
              accion.setOk(r.correo ? 'Entrevista agendada: le enviamos un correo a la familia.' : 'Entrevista agendada.');
              if (r.whatsapp_url) window.open(r.whatsapp_url, '_blank', 'noopener');
              alCambiar();
            })}>
            Agendar y avisar
          </button>
        </div>
      )}
      {editable && !p.alumno_id && ['vacante', 'evaluacion'].includes(p.etapa) && (
        <div className="tarjeta" style={{ margin: '12px 0' }}>
          <h3 style={{ marginTop: 0 }}>Convertir en alumno</h3>
          <div className="campos">
            <Campo etiqueta="DNI del apoderado"><input inputMode="numeric" maxLength={8} value={apo.numero_documento} onChange={(e) => setApo({ ...apo, numero_documento: e.target.value })} /></Campo>
            <Campo etiqueta="Apellidos del apoderado"><input value={apo.apellidos} onChange={(e) => setApo({ ...apo, apellidos: e.target.value })} /></Campo>
            <Campo etiqueta="Nombres del apoderado"><input value={apo.nombres} onChange={(e) => setApo({ ...apo, nombres: e.target.value })} /></Campo>
            <Campo etiqueta="Parentesco">
              <select value={apo.parentesco} onChange={(e) => setApo({ ...apo, parentesco: e.target.value })}>
                {['madre', 'padre', 'abuela', 'abuelo', 'tia', 'tio', 'tutor_legal', 'otro'].map((x) => <option key={x} value={x}>{x.replace('_', ' ')}</option>)}
              </select>
            </Campo>
          </div>
          <div className="acciones">
            <button type="button" className="boton" disabled={accion.enviando}
              onClick={() => accion.ejecutar(async () => { await pedir(`/admision/postulantes/${p.id}/convertir`, { metodo: 'POST', cuerpo: apo }); alCambiar(); }, 'Alumno creado: ahora matricúlalo desde Alumnos.')}>
              Crear alumno
            </button>
          </div>
        </div>
      )}
      {p.alumno_id && <Aviso tipo="ok">Ya es alumno: matricúlalo en ventanilla desde «Alumnos».</Aviso>}
      <h3>Historia</h3>
      {historia.datos?.data.map((h, i) => (
        <p key={i} className="ayuda">{fechaHora(h.fecha)} · {h.tipo.replace('_', ' ')}{h.resultado ? ` · ${h.resultado}` : ''}{h.responsable ? ` · ${h.responsable}` : ''}</p>
      ))}
    </>
  );
}

/** EDUN-53 · ADM-08 Admisión por etapas: tablero con arrastrar, agenda de entrevistas y conversión en alumno. */
export function Admision() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const editable = puede(colegio, 'adm.admision.editar');
  const { anios, anio, setAnio } = useAnioEscolar();
  const { datos, setDatos, error, recargar } = useCarga(() => (anio ? pedir<Tablero>(`/admision?anio_escolar_id=${anio}`) : Promise.resolve(null)), [anio]);
  const accion = useAccion();
  const [arrastrando, setArrastrando] = useState<number | null>(null);
  const [dialogo, setDialogo] = useState<{ tipo: 'nuevo' | 'proceso' | 'ficha'; p?: Postulante } | null>(null);
  const [proceso, setProceso] = useState({ nombre: '', fecha_inicio: '', fecha_fin: '' });

  async function mover(id: number, etapa: string) {
    if (!datos) return;
    const previo = datos;
    setDatos({ ...datos, postulantes: datos.postulantes.map((p) => (p.id === id ? { ...p, etapa } : p)) });
    const ok = await accion.ejecutar(() => pedir(`/admision/postulantes/${id}/etapa`, { metodo: 'PATCH', cuerpo: { etapa } }));
    if (!ok) setDatos(previo);
    else recargar();
  }

  return (
    <>
      <Titulo migas={`${colegio.nombre} · Matrícula`} titulo="Admisión" descripcion="Arrastra a cada postulante entre etapas. Las entrevistas avisan a la familia.">
        <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end', flexWrap: 'wrap' }}>
          {anios.length > 0 && <SelectorAnio anios={anios} anio={anio} alCambiar={setAnio} />}
          {editable && <button type="button" className="boton boton-claro" onClick={() => setDialogo({ tipo: 'proceso' })}>+ Proceso</button>}
          {puede(colegio, 'adm.admision.crear') && datos && datos.procesos.length > 0 && <button type="button" className="boton" onClick={() => setDialogo({ tipo: 'nuevo' })}>+ Postulante</button>}
        </div>
      </Titulo>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      {datos && datos.procesos.length === 0 && <Aviso tipo="ambar">Crea primero el proceso de admisión del año.</Aviso>}
      {datos && (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))', gap: 12, marginTop: 12 }}>
            {Object.entries(datos.etapas).map(([etapa, nombre]) => (
              <div key={etapa} className="tarjeta" style={{ padding: 12, minHeight: 160, background: arrastrando ? 'var(--en-suave2)' : undefined }}
                data-etapa={etapa}
                onDragOver={(e) => editable && e.preventDefault()}
                onDrop={(e) => { e.preventDefault(); if (arrastrando) void mover(arrastrando, etapa); setArrastrando(null); }}>
                <div className="migas">{nombre} · {datos.postulantes.filter((p) => p.etapa === etapa).length}</div>
                {datos.postulantes.filter((p) => p.etapa === etapa).map((p) => (
                  <div key={p.id} draggable={editable} onDragStart={() => setArrastrando(p.id)} onDragEnd={() => setArrastrando(null)}
                    onClick={() => setDialogo({ tipo: 'ficha', p })}
                    style={{ marginTop: 8, padding: 10, border: '1px solid var(--en-linea)', borderRadius: 8, background: '#fff', cursor: editable ? 'grab' : 'pointer' }}>
                    <b style={{ fontSize: 14 }}>{p.nombres} {p.apellidos}</b>
                    <br />
                    <small className="ayuda">{p.grado}</small>
                    {p.proxima_entrevista && <><br /><small className="insignia insignia-azul">Entrevista {fechaHora(p.proxima_entrevista)}</small></>}
                    {editable && (
                      <select className="control" aria-label={`Mover a ${p.nombres}`} style={{ height: 28, fontSize: 12, marginTop: 6, width: '100%' }} value={p.etapa}
                        onClick={(e) => e.stopPropagation()} onChange={(e) => void mover(p.id, e.target.value)}>
                        {Object.entries(datos.etapas).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                      </select>
                    )}
                  </div>
                ))}
              </div>
            ))}
          </div>
          <div className="rejilla" style={{ marginTop: 18 }}>
            <div className="tarjeta">
              <h2>Agenda de entrevistas</h2>
              {datos.agenda.length === 0 ? <p className="ayuda">Sin entrevistas próximas.</p> : datos.agenda.map((a) => <p key={a.id}>{fechaHora(a.fecha)} · {a.postulante}</p>)}
            </div>
            <div className="tarjeta">
              <h2>Vacantes por grado</h2>
              <div className="tabla-caja">
                <table className="tabla">
                  <tbody>
                    {datos.vacantes.map((v) => (
                      <tr key={v.grado_id}>
                        <td>{v.grado}</td>
                        <td style={{ width: 110 }}>
                          <input className="control" aria-label={`Vacantes de ${v.grado}`} inputMode="numeric" defaultValue={v.cantidad ?? ''} disabled={!editable}
                            onBlur={(e) => { const n = Number(e.target.value); if (e.target.value !== '' && n !== v.cantidad) void accion.ejecutar(async () => { await pedir('/admision/vacantes', { metodo: 'PUT', cuerpo: { anio_escolar_id: anio, grado_id: v.grado_id, cantidad: n } }); recargar(); }); }} />
                        </td>
                        <td><small className="ayuda">{v.otorgadas} otorgadas</small></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </>
      )}
      {dialogo?.tipo === 'nuevo' && datos && (
        <Dialogo titulo="Nuevo postulante" alCerrar={() => setDialogo(null)}>
          <NuevoPostulante t={datos} alCrear={() => { setDialogo(null); recargar(); }} />
        </Dialogo>
      )}
      {dialogo?.tipo === 'proceso' && (
        <Dialogo titulo="Proceso de admisión" alCerrar={() => setDialogo(null)}>
          <div className="campos">
            <Campo etiqueta="Nombre" ancho><input value={proceso.nombre} placeholder="Admisión 2027" onChange={(e) => setProceso({ ...proceso, nombre: e.target.value })} /></Campo>
            <Campo etiqueta="Desde"><input type="date" value={proceso.fecha_inicio} onChange={(e) => setProceso({ ...proceso, fecha_inicio: e.target.value })} /></Campo>
            <Campo etiqueta="Hasta"><input type="date" value={proceso.fecha_fin} onChange={(e) => setProceso({ ...proceso, fecha_fin: e.target.value })} /></Campo>
          </div>
          <Aviso tipo="error">{accion.error}</Aviso>
          <div className="acciones">
            <button type="button" className="boton" disabled={accion.enviando}
              onClick={async () => { if (await accion.ejecutar(() => pedir('/admision/procesos', { metodo: 'POST', cuerpo: { ...proceso, anio_escolar_id: anio } }))) { setDialogo(null); recargar(); } }}>
              Crear
            </button>
          </div>
        </Dialogo>
      )}
      {dialogo?.tipo === 'ficha' && dialogo.p && (
        <Dialogo titulo={`${dialogo.p.nombres} ${dialogo.p.apellidos}`} alCerrar={() => setDialogo(null)}>
          <FichaPostulante p={dialogo.p} editable={editable} alCambiar={() => { setDialogo(null); recargar(); }} />
        </Dialogo>
      )}
    </>
  );
}
