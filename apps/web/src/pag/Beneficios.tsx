import { useState, type FormEvent } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Titulo } from '../panel/ui';
import { fechaHora, useAccion, useCarga } from '../panel/utilidades';
import { useAnioEscolar } from '../adm/anios';
import { SelectorAnio } from '../adm/SelectorAnio';
import { soles, type Asignacion, type TipoBeneficio } from './tipos';

type Datos = { tipos: TipoBeneficio[]; asignaciones: Asignacion[]; motivos: Record<string, string>; aplica: Record<string, string>; puede_aprobar: boolean };
type AlumnoFila = { id: number; apellido_paterno: string; apellido_materno: string | null; nombres: string; numero_documento: string | null };

const ESTADO: Record<string, [string, string]> = { pendiente: ['Por aprobar', 'insignia-ambar'], aprobado: ['Aprobado', 'insignia-ok'], rechazado: ['Rechazado', 'insignia-rojo'] };

const valorBeneficio = (b: { tipo: string; valor: number }) => (b.tipo === 'porcentaje' ? `${b.valor} %` : soles(b.valor));

function FormTipo({ inicial, d, alGuardar }: { inicial: Partial<TipoBeneficio>; d: Datos; alGuardar: (t: TipoBeneficio[]) => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const [f, setF] = useState({ nombre: '', tipo: 'porcentaje', valor: 10, motivo: 'hermanos', aplica_a: 'pension', activo: true, ...inicial });
  async function enviar(e: FormEvent) {
    e.preventDefault();
    await accion.ejecutar(async () => {
      const r = await pedir<{ tipos: TipoBeneficio[] }>(inicial.id ? `/pagos/beneficios/${inicial.id}` : '/pagos/beneficios', { metodo: inicial.id ? 'PUT' : 'POST', cuerpo: f });
      alGuardar(r.tipos);
    });
  }
  return (
    <form onSubmit={enviar} noValidate>
      <div className="campos">
        <Campo etiqueta="Nombre"><input value={f.nombre} onChange={(e) => setF({ ...f, nombre: e.target.value })} /></Campo>
        <Campo etiqueta="Tipo">
          <select value={f.tipo} onChange={(e) => setF({ ...f, tipo: e.target.value as TipoBeneficio['tipo'] })}>
            <option value="porcentaje">Porcentaje</option>
            <option value="monto">Monto fijo</option>
          </select>
        </Campo>
        <Campo etiqueta={f.tipo === 'porcentaje' ? 'Porcentaje' : 'Monto (S/)'}>
          <input type="number" min={0} step="0.01" value={f.valor} onChange={(e) => setF({ ...f, valor: Number(e.target.value) })} />
        </Campo>
        <Campo etiqueta="Motivo">
          <select value={f.motivo} onChange={(e) => setF({ ...f, motivo: e.target.value })}>
            {Object.entries(d.motivos).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </Campo>
        <Campo etiqueta="Aplica a">
          <select value={f.aplica_a} onChange={(e) => setF({ ...f, aplica_a: e.target.value })}>
            {Object.entries(d.aplica).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </Campo>
      </div>
      <label className="casilla"><input type="checkbox" checked={f.activo} onChange={(e) => setF({ ...f, activo: e.target.checked })} /> Activo</label>
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="acciones"><button type="submit" className="boton" disabled={accion.enviando}>Guardar</button></div>
    </form>
  );
}

function Asignar({ anio, d, alAsignar }: { anio: number; d: Datos; alAsignar: (mensaje: string) => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const [buscar, setBuscar] = useState('');
  const alumnos = useCarga(() => (buscar.trim().length >= 3 ? pedir<{ data: AlumnoFila[] }>(`/alumnos?buscar=${encodeURIComponent(buscar)}&anio_escolar_id=${anio}`) : Promise.resolve({ data: [] })), [buscar, anio]);
  const [alumno, setAlumno] = useState<AlumnoFila | null>(null);
  const [beneficio, setBeneficio] = useState<number | ''>('');
  const [sustento, setSustento] = useState<File | null>(null);
  const [observacion, setObservacion] = useState('');

  async function enviar(e: FormEvent) {
    e.preventDefault();
    await accion.ejecutar(async () => {
      let archivo: number | null = null;
      if (sustento) {
        const cuerpo = new FormData();
        cuerpo.append('archivo', sustento);
        cuerpo.append('confidencial', '1');
        archivo = (await pedir<{ id: number }>('/archivos', { metodo: 'POST', cuerpo })).id;
      }
      const r = await pedir<{ estado: string; recalculados: number }>('/pagos/beneficios/asignaciones', {
        metodo: 'POST', cuerpo: { alumno_id: alumno?.id, beneficio_id: beneficio || null, anio_escolar_id: anio, sustento_archivo_id: archivo, observacion: observacion || null },
      });
      alAsignar(r.estado === 'aprobado' ? `Beneficio aprobado; se recalcularon ${r.recalculados} cargos.` : 'Solicitud enviada a dirección para su aprobación.');
    });
  }

  return (
    <form onSubmit={enviar} noValidate>
      {alumno ? (
        <p>
          Alumno: <b>{alumno.apellido_paterno} {alumno.apellido_materno}, {alumno.nombres}</b>{' '}
          <button type="button" className="boton boton-chico boton-borde" onClick={() => setAlumno(null)}>Cambiar</button>
        </p>
      ) : (
        <>
          <Campo etiqueta="Buscar alumno" ayuda="Apellidos, nombres o DNI (mínimo 3 letras)" ancho>
            <input value={buscar} onChange={(e) => setBuscar(e.target.value)} />
          </Campo>
          <div style={{ display: 'grid', gap: 4, margin: '6px 0 12px' }}>
            {alumnos.datos?.data.slice(0, 8).map((a) => (
              <button key={a.id} type="button" className="boton boton-chico boton-borde" style={{ justifyContent: 'flex-start' }} onClick={() => setAlumno(a)}>
                {a.apellido_paterno} {a.apellido_materno}, {a.nombres} · {a.numero_documento}
              </button>
            ))}
          </div>
        </>
      )}
      <div className="campos">
        <Campo etiqueta="Beneficio">
          <select value={beneficio} onChange={(e) => setBeneficio(Number(e.target.value) || '')}>
            <option value="">Elige</option>
            {d.tipos.filter((t) => t.activo).map((t) => <option key={t.id} value={t.id}>{t.nombre} · {valorBeneficio(t)}</option>)}
          </select>
        </Campo>
        <Campo etiqueta="Observación"><input value={observacion} maxLength={300} onChange={(e) => setObservacion(e.target.value)} /></Campo>
      </div>
      <label className="boton boton-borde" style={{ marginTop: 10 }}>
        {sustento ? `Sustento: ${sustento.name}` : 'Adjuntar sustento (PDF o imagen)'}
        <input type="file" hidden accept="image/*,.pdf" onChange={(e) => setSustento(e.target.files?.[0] ?? null)} />
      </label>
      <p className="ayuda">{d.puede_aprobar ? 'Como tienes permiso de aprobar, el beneficio queda aprobado al guardarlo.' : 'Dirección debe aprobarlo; recién entonces se aplica a los cargos.'}</p>
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="acciones">
        <button type="submit" className="boton" disabled={accion.enviando || !alumno}>{accion.enviando ? 'Guardando…' : d.puede_aprobar ? 'Asignar' : 'Enviar a aprobación'}</button>
      </div>
    </form>
  );
}

/** EDUN-72 · PAG-03 Descuentos y becas con sustento y aprobación. */
export function Beneficios() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const editable = puede(colegio, 'pag.conceptos.editar');
  const { anios, anio, setAnio } = useAnioEscolar();
  const { datos, setDatos, error, recargar } = useCarga(() => (anio ? pedir<Datos>(`/pagos/beneficios?anio_escolar_id=${anio}`) : Promise.resolve(null)), [anio]);
  const accion = useAccion();
  const [tipo, setTipo] = useState<Partial<TipoBeneficio> | null>(null);
  const [asignando, setAsignando] = useState(false);
  const [rechazo, setRechazo] = useState<{ id: number; motivo: string } | null>(null);
  const resolver = (id: number, aprobar: boolean, observacion?: string) =>
    accion.ejecutar(async () => {
      const r = await pedir<{ recalculados: number }>(`/pagos/beneficios/asignaciones/${id}`, { metodo: 'PATCH', cuerpo: { aprobar, observacion } });
      setRechazo(null);
      recargar();
      return r;
    }, aprobar ? 'Beneficio aprobado y aplicado a los cargos pendientes.' : 'Solicitud rechazada.');
  const pendientes = datos?.asignaciones.filter((a) => a.estado === 'pendiente').length ?? 0;

  return (
    <>
      <Titulo migas={`${colegio.nombre} · Pagos`} titulo="Descuentos y becas" descripcion="Tesorería los propone con sustento; dirección los aprueba. Al aprobar se recalculan los cargos pendientes.">
        {anios.length > 0 && <SelectorAnio anios={anios} anio={anio} alCambiar={setAnio} />}
      </Titulo>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {datos?.puede_aprobar && pendientes > 0 && <Aviso tipo="ambar">Hay {pendientes} solicitudes por aprobar.</Aviso>}
      {datos && (
        <div style={{ display: 'grid', gap: 18, gridTemplateColumns: 'minmax(0, 1fr)' }}>
          <div className="tarjeta">
            <div className="titulo-fila">
              <h2>Asignados a alumnos</h2>
              {(editable || datos.puede_aprobar) && <button type="button" className="boton boton-chico" onClick={() => setAsignando(true)}>+ Asignar</button>}
            </div>
            <div className="tabla-caja">
              <table className="tabla">
                <thead><tr><th>Alumno</th><th>Beneficio</th><th>Estado</th><th>Sustento</th><th>Solicitó / aprobó</th><th /></tr></thead>
                <tbody>
                  {datos.asignaciones.map((a) => (
                    <tr key={a.id}>
                      <td>{a.alumno}</td>
                      <td>{a.beneficio} · {valorBeneficio(a)}</td>
                      <td><span className={`insignia ${ESTADO[a.estado][1]}`}>{ESTADO[a.estado][0]}</span>{a.observacion && <><br /><small className="ayuda">{a.observacion}</small></>}</td>
                      <td>{a.sustento ?? '—'}</td>
                      <td><small>{a.solicitado_por ?? '—'}{a.aprobado_por && <><br />{a.aprobado_por} · {fechaHora(a.aprobado_at)}</>}</small></td>
                      <td>
                        {datos.puede_aprobar && a.estado === 'pendiente' && (
                          <div style={{ display: 'flex', gap: 6 }}>
                            <button type="button" className="boton boton-chico" disabled={accion.enviando} onClick={() => resolver(a.id, true)}>Aprobar</button>
                            <button type="button" className="boton boton-chico boton-borde" onClick={() => setRechazo({ id: a.id, motivo: '' })}>Rechazar</button>
                          </div>
                        )}
                        {datos.puede_aprobar && a.estado === 'aprobado' && (
                          <button type="button" className="boton boton-chico boton-borde"
                            onClick={() => window.confirm('¿Quitar el beneficio? Los cargos pendientes vuelven a su monto completo.') && accion.ejecutar(async () => {
                              await pedir(`/pagos/beneficios/asignaciones/${a.id}`, { metodo: 'DELETE' });
                              recargar();
                            }, 'Beneficio retirado.')}>
                            Quitar
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {datos.asignaciones.length === 0 && <p className="vacio">Ningún alumno tiene beneficios este año.</p>}
            </div>
          </div>
          <div className="tarjeta">
            <div className="titulo-fila">
              <h2>Tipos de beneficio</h2>
              {editable && <button type="button" className="boton boton-chico boton-borde" onClick={() => setTipo({})}>+ Tipo</button>}
            </div>
            <div className="tabla-caja">
              <table className="tabla">
                <thead><tr><th>Beneficio</th><th>Valor</th><th>Motivo</th><th>Aplica a</th><th /></tr></thead>
                <tbody>
                  {datos.tipos.map((t) => (
                    <tr key={t.id} style={{ opacity: t.activo ? 1 : 0.55 }}>
                      <td>{t.nombre}</td><td>{valorBeneficio(t)}</td><td>{datos.motivos[t.motivo]}</td><td>{datos.aplica[t.aplica_a] ?? t.aplica_a}</td>
                      <td>{editable && <button type="button" className="boton boton-chico boton-borde" onClick={() => setTipo(t)}>Editar</button>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}
      {tipo && datos && (
        <Dialogo titulo={tipo.id ? `Editar ${tipo.nombre}` : 'Nuevo tipo de beneficio'} alCerrar={() => setTipo(null)}>
          <FormTipo inicial={tipo} d={datos} alGuardar={(tipos) => { setDatos({ ...datos, tipos }); setTipo(null); }} />
        </Dialogo>
      )}
      {asignando && datos && anio && (
        <Dialogo titulo="Asignar descuento o beca" alCerrar={() => setAsignando(false)}>
          <Asignar anio={anio} d={datos} alAsignar={(m) => { setAsignando(false); accion.setOk(m); recargar(); }} />
        </Dialogo>
      )}
      {rechazo && (
        <Dialogo titulo="Rechazar solicitud" alCerrar={() => setRechazo(null)}>
          <Campo etiqueta="Motivo del rechazo" ancho>
            <input value={rechazo.motivo} maxLength={300} onChange={(e) => setRechazo({ ...rechazo, motivo: e.target.value })} />
          </Campo>
          <div className="acciones">
            <button type="button" className="boton" disabled={!rechazo.motivo.trim() || accion.enviando} onClick={() => resolver(rechazo.id, false, rechazo.motivo)}>Rechazar</button>
          </div>
        </Dialogo>
      )}
    </>
  );
}
