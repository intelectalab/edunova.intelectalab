import { useState } from 'react';
import { apiArchivo, guardarArchivo } from '../api/cliente';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Pestanas } from '../panel/ui';
import { fecha, mensajeError, useAccion, useCarga } from '../panel/utilidades';
import { FormAlumno, type Ficha, type Seccion } from './Alumnos';

type Estado = { estado: string; version: string | null; fecha: string | null; desactualizado: boolean };
type Movimiento = { tipo: string; fecha: string; motivo: string | null; ie_destino: string | null; seccion_destino: string | null };
type Historial = { id: number; anio: number; seccion: string; estado: string; tipo: string; fecha_matricula: string; movimientos: Movimiento[] };
type Cargo = { id: number; descripcion: string; vencimiento: string; saldo: number; estado: string; vencido: boolean };
type ExpedienteDatos = Omit<Ficha, 'apoderados'> & {
  apoderados: (Ficha['apoderados'][number] & { id: number })[];
  historial: Historial[];
  consentimientos: Record<string, Estado>;
  secciones: string[];
  deuda?: { vencida: number; por_vencer: number; cargos: Cargo[] };
  conducta?: { fecha: string; tipo: string; gravedad: string | null; detalle: string }[];
  documentos?: { expediente: { id: number; categoria: string; nombre: string; vence: string | null }[]; emitidos: { id: number; nombre: string; codigo_verificacion: string; anulado_at: string | null }[] };
  salud?: Record<string, string | null> | null;
};
type Carne = { codigo: string; qr: string; alumno: string; seccion: string | null; anio: number | null; foto: string | null };

const ESTADO_CONS: Record<string, [string, string]> = {
  otorgado: ['Otorgado', 'insignia-ok'],
  negado: ['No otorgado', 'insignia-rojo'],
  revocado: ['Revocado', 'insignia-rojo'],
  pendiente: ['Pendiente', 'insignia-ambar'],
};
const MOVIMIENTO: Record<string, string> = { retiro: 'Retiro', traslado_salida: 'Traslado', traslado_ingreso: 'Traslado de ingreso', cambio_seccion: 'Cambio de sección' };
const soles = (n: number) => `S/ ${n.toFixed(2)}`;

function Matricula({ e, anio, secciones, alCambiar }: { e: ExpedienteDatos; anio: number; secciones: Seccion[]; alCambiar: () => void }) {
  const { pedir, token, estado } = useSesion();
  const colegio = estado!.colegio_activo;
  const accion = useAccion();
  const actual = e.historial.find((h) => h.estado === 'matriculado' || h.estado === 'reservado');
  const condiciones = useCarga(
    () => (puede(colegio, 'adm.matricula.ver') ? pedir<{ data: { version: number; contenido: string }[] }>(`/condiciones-economicas?anio_escolar_id=${anio}`) : Promise.resolve({ data: [] })),
    [anio],
  );
  const [f, setF] = useState({ seccion_id: '', apoderado_id: String(e.apoderados.find((p) => p.es_responsable_pago)?.id ?? e.apoderados[0]?.id ?? ''), acepta: false });
  const [mov, setMov] = useState(() => ({ tipo: '', seccion_id: '', fecha: new Date().toISOString().slice(0, 10), motivo: '', ie_destino: '' }));
  const vigente = condiciones.datos?.data[0];

  async function constancia(id: number) {
    try {
      guardarArchivo(await apiArchivo(`/matriculas/${id}/constancia`, token), 'constancia-matricula.pdf');
    } catch (x) {
      accion.setError(mensajeError(x));
    }
  }

  return (
    <>
      <Aviso tipo="error">{accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {!actual && puede(colegio, 'adm.matricula.crear') && (
        <div className="tarjeta" style={{ marginBottom: 14 }}>
          <h2>Matricular en ventanilla</h2>
          {!vigente ? (
            <Aviso tipo="ambar">Aún no se publican las condiciones económicas del año: publícalas en «Condiciones económicas».</Aviso>
          ) : (
            <>
              <div className="campos" style={{ marginTop: 10 }}>
                <Campo etiqueta="Sección">
                  <select value={f.seccion_id} onChange={(x) => setF({ ...f, seccion_id: x.target.value })}>
                    <option value="">Elige…</option>
                    {secciones.map((s) => (
                      <option key={s.id} value={s.id}>
                        {s.grado} {s.nombre}
                      </option>
                    ))}
                  </select>
                </Campo>
                <Campo etiqueta="Apoderado que firma">
                  <select value={f.apoderado_id} onChange={(x) => setF({ ...f, apoderado_id: x.target.value })}>
                    {e.apoderados.map((p) => (
                      <option key={p.id} value={p.id}>
                        {p.nombres} {p.apellidos}
                      </option>
                    ))}
                  </select>
                </Campo>
              </div>
              <details style={{ margin: '10px 0' }}>
                <summary className="ayuda" style={{ cursor: 'pointer' }}>
                  Condiciones económicas (versión {vigente.version}): léelas con el apoderado
                </summary>
                <p style={{ whiteSpace: 'pre-wrap', fontSize: 14 }}>{vigente.contenido}</p>
              </details>
              <label className="casilla">
                <input type="checkbox" checked={f.acepta} onChange={(x) => setF({ ...f, acepta: x.target.checked })} /> El apoderado leyó y acepta las condiciones económicas
              </label>
              <div className="acciones">
                <button
                  type="button"
                  className="boton"
                  disabled={accion.enviando || !f.seccion_id || !f.apoderado_id || !f.acepta}
                  onClick={() =>
                    accion.ejecutar(async () => {
                      const r = await pedir<{ matricula_id: number; cargos: number; aviso: string | null }>('/matriculas', {
                        metodo: 'POST',
                        cuerpo: { alumno_id: e.id, seccion_id: Number(f.seccion_id), apoderado_id: Number(f.apoderado_id), acepta_condiciones: true },
                      });
                      accion.setOk(`Matrícula registrada con ${r.cargos} cargos del cronograma.${r.aviso ? ` ${r.aviso}` : ''}`);
                      await constancia(r.matricula_id);
                      alCambiar();
                    })
                  }
                >
                  Matricular e imprimir constancia
                </button>
              </div>
            </>
          )}
        </div>
      )}
      {actual && (
        <div className="tarjeta" style={{ marginBottom: 14 }}>
          <h2>
            Matrícula {actual.anio}: {actual.seccion}
          </h2>
          <div className="acciones">
            <button type="button" className="boton boton-claro" onClick={() => void constancia(actual.id)}>
              Constancia (PDF)
            </button>
            {puede(colegio, 'adm.matricula.editar') && (
              <button type="button" className="boton boton-borde" onClick={() => setMov({ ...mov, tipo: 'cambio' })}>
                Cambiar de sección
              </button>
            )}
            {puede(colegio, 'adm.matricula.retirar') && (
              <button type="button" className="boton boton-peligro" onClick={() => setMov({ ...mov, tipo: 'retiro' })}>
                Retiro o traslado
              </button>
            )}
          </div>
          {mov.tipo && (
            <div style={{ marginTop: 12, borderTop: '1px solid var(--en-linea)', paddingTop: 12 }}>
              <div className="campos">
                {mov.tipo === 'cambio' ? (
                  <Campo etiqueta="Nueva sección">
                    <select value={mov.seccion_id} onChange={(x) => setMov({ ...mov, seccion_id: x.target.value })}>
                      <option value="">Elige…</option>
                      {secciones
                        .filter((s) => actual.seccion.startsWith(s.grado) && `${s.grado} ${s.nombre}` !== actual.seccion)
                        .map((s) => (
                          <option key={s.id} value={s.id}>
                            {s.grado} {s.nombre}
                          </option>
                        ))}
                    </select>
                  </Campo>
                ) : (
                  <>
                    <Campo etiqueta="Tipo">
                      <select value={mov.tipo} onChange={(x) => setMov({ ...mov, tipo: x.target.value })}>
                        <option value="retiro">Retiro</option>
                        <option value="traslado">Traslado a otra IE</option>
                      </select>
                    </Campo>
                    {mov.tipo === 'traslado' && (
                      <Campo etiqueta="IE de destino">
                        <input value={mov.ie_destino} onChange={(x) => setMov({ ...mov, ie_destino: x.target.value })} />
                      </Campo>
                    )}
                  </>
                )}
                <Campo etiqueta="Fecha">
                  <input type="date" value={mov.fecha} onChange={(x) => setMov({ ...mov, fecha: x.target.value })} />
                </Campo>
                <Campo etiqueta="Motivo" ancho>
                  <input value={mov.motivo} onChange={(x) => setMov({ ...mov, motivo: x.target.value })} />
                </Campo>
              </div>
              {mov.tipo !== 'cambio' && <p className="ayuda">Se anulan los cargos que vencen después de la fecha, se quita el acceso al aula y a Moodle, y queda el aviso para el SIAGIE.</p>}
              <div className="acciones">
                <button
                  type="button"
                  className={mov.tipo === 'cambio' ? 'boton' : 'boton boton-peligro'}
                  disabled={accion.enviando}
                  onClick={() =>
                    accion.ejecutar(async () => {
                      if (mov.tipo === 'cambio') {
                        await pedir(`/matriculas/${actual.id}/cambio-seccion`, { metodo: 'POST', cuerpo: { seccion_id: Number(mov.seccion_id), fecha: mov.fecha, motivo: mov.motivo || null } });
                        accion.setOk('Cambio de sección registrado. Las notas del periodo pasan a la nueva sección.');
                      } else {
                        const r = await pedir<{ cargos_anulados: number }>(`/matriculas/${actual.id}/retiro`, {
                          metodo: 'POST',
                          cuerpo: { tipo: mov.tipo, fecha: mov.fecha, motivo: mov.motivo, ie_destino: mov.ie_destino || null },
                        });
                        accion.setOk(`${mov.tipo === 'retiro' ? 'Retiro' : 'Traslado'} registrado. Se anularon ${r.cargos_anulados} cargos futuros.`);
                      }
                      setMov({ ...mov, tipo: '' });
                      alCambiar();
                    })
                  }
                >
                  Confirmar
                </button>
                <button type="button" className="boton boton-borde" onClick={() => setMov({ ...mov, tipo: '' })}>
                  Cancelar
                </button>
              </div>
            </div>
          )}
        </div>
      )}
      <div className="tabla-caja">
        <table className="tabla">
          <thead>
            <tr>
              <th>Año</th>
              <th>Sección</th>
              <th>Estado</th>
              <th>Movimientos</th>
            </tr>
          </thead>
          <tbody>
            {e.historial.map((h) => (
              <tr key={h.id}>
                <td>{h.anio}</td>
                <td>{h.seccion}</td>
                <td>{h.estado}</td>
                <td>
                  {h.movimientos.map((m, i) => (
                    <div key={i} className="ayuda">
                      {fecha(m.fecha)} · {MOVIMIENTO[m.tipo] ?? m.tipo}
                      {m.seccion_destino ? ` a ${m.seccion_destino}` : ''}
                      {m.ie_destino ? ` a ${m.ie_destino}` : ''}
                      {m.motivo ? ` · ${m.motivo}` : ''}
                    </div>
                  ))}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {e.historial.length === 0 && <p className="vacio">Sin matrículas.</p>}
      </div>
    </>
  );
}

function Consentimientos({ e, alCambiar }: { e: ExpedienteDatos; alCambiar: () => void }) {
  const { pedir, estado } = useSesion();
  const accion = useAccion();
  const registra = puede(estado!.colegio_activo, 'seg.consentimientos.registrar');
  return (
    <>
      <Aviso tipo="error">{accion.error}</Aviso>
      {Object.entries({ datos: 'Datos personales', imagen: 'Imagen (fotos y videos)' }).map(([f, nombre]) => {
        const c = e.consentimientos[f];
        const [txt, clase] = ESTADO_CONS[c.estado] ?? [c.estado, ''];
        return (
          <div key={f} className="interruptor">
            <span>
              <b>{nombre}</b> <span className={`insignia ${clase}`}>{txt}</span>
              {c.version && <small className="ayuda"> · versión {c.version}</small>}
              {c.desactualizado && <small className="ayuda"> · hay un texto más nuevo</small>}
            </span>
            {registra && (
              <span style={{ display: 'flex', gap: 6 }}>
                <button type="button" className="boton boton-chico boton-claro" disabled={accion.enviando}
                  onClick={() => accion.ejecutar(async () => { await pedir('/consentimientos', { metodo: 'POST', cuerpo: { alumno_id: e.id, finalidad: f, otorgado: true } }); alCambiar(); })}>
                  Firmó que sí
                </button>
                <button type="button" className="boton boton-chico boton-borde" disabled={accion.enviando}
                  onClick={() => accion.ejecutar(async () => { await pedir('/consentimientos', { metodo: 'POST', cuerpo: { alumno_id: e.id, finalidad: f, otorgado: false } }); alCambiar(); })}>
                  No autoriza
                </button>
              </span>
            )}
          </div>
        );
      })}
      <p className="ayuda">La familia también puede aceptar o revocar desde su cuenta. Sin consentimiento de imagen, la foto no aparece en el carné ni en publicaciones.</p>
    </>
  );
}

function CarneAlumno({ id }: { id: number }) {
  const { pedir, token, estado } = useSesion();
  const editable = puede(estado!.colegio_activo, 'adm.alumnos.editar');
  const { datos, setDatos, error } = useCarga(() => pedir<Carne>(`/alumnos/${id}/carne`), [id]);
  const accion = useAccion();
  return (
    <>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {datos && (
        <div style={{ display: 'flex', gap: 18, alignItems: 'center', flexWrap: 'wrap' }}>
          <div className="logo-vista">{datos.foto ? <img src={datos.foto} alt="Foto del alumno" /> : 'Sin foto publicable'}</div>
          <img src={datos.qr} alt="Código QR del carné" width={140} height={140} />
          <div>
            <b>{datos.alumno}</b>
            <br />
            <span className="ayuda">
              {datos.seccion ?? 'Sin matrícula'} · código <code>{datos.codigo}</code>
            </span>
            <div className="acciones">
              <button type="button" className="boton boton-claro"
                onClick={async () => { try { guardarArchivo(await apiArchivo(`/carnes/pdf?alumno_id=${id}`, token), 'carne.pdf'); } catch (x) { accion.setError(mensajeError(x)); } }}>
                Descargar carné (PDF)
              </button>
              {editable && (
                <>
                  <button type="button" className="boton boton-borde" disabled={accion.enviando}
                    onClick={() => accion.ejecutar(async () => setDatos(await pedir<Carne>(`/alumnos/${id}/carne`, { metodo: 'POST' })), 'Carné regenerado: el anterior ya no sirve.')}>
                    Regenerar (invalida el anterior)
                  </button>
                  <label className="boton boton-borde">
                    Subir foto
                    <input type="file" accept="image/png,image/jpeg,image/webp" hidden
                      onChange={(x) => {
                        const archivo = x.target.files?.[0];
                        if (!archivo) return;
                        const cuerpo = new FormData();
                        cuerpo.append('foto', archivo);
                        void accion.ejecutar(async () => {
                          const r = await pedir<{ imagen_autorizada: boolean }>(`/alumnos/${id}/foto`, { metodo: 'POST', cuerpo });
                          setDatos(await pedir<Carne>(`/alumnos/${id}/carne`));
                          accion.setOk(r.imagen_autorizada ? 'Foto guardada.' : 'Foto guardada. No se mostrará hasta que la familia autorice el uso de imagen.');
                        });
                      }} />
                  </label>
                </>
              )}
            </div>
          </div>
        </div>
      )}
    </>
  );
}

type Pestana = 'datos' | 'matricula' | 'deuda' | 'conducta' | 'documentos' | 'salud' | 'consentimientos' | 'carne';

/** EDUN-52 · ADM-07 Expediente del alumno por pestañas, con las acciones de matrícula. */
export function Expediente({ id, anio, secciones, alCambiar }: { id: number; anio: number; secciones: Seccion[]; alCambiar: () => void }) {
  const { pedir } = useSesion();
  const { datos, error, recargar } = useCarga(() => pedir<ExpedienteDatos>(`/alumnos/${id}/expediente`), [id]);
  const [pestana, setPestana] = useState<Pestana>('datos');
  const accion = useAccion();
  const cambio = () => {
    recargar();
    alCambiar();
  };
  if (!datos) return <Aviso tipo="error">{error}</Aviso>;
  const opciones: [Pestana, string][] = [
    ['datos', 'Datos'],
    ['matricula', 'Matrícula e historial'],
    ...(datos.deuda ? [['deuda', 'Deuda'] as [Pestana, string]] : []),
    ...(datos.conducta ? [['conducta', 'Conducta'] as [Pestana, string]] : []),
    ...(datos.documentos ? [['documentos', 'Documentos'] as [Pestana, string]] : []),
    ...('salud' in datos ? [['salud', 'Salud'] as [Pestana, string]] : []),
    ['consentimientos', 'Consentimientos'],
    ['carne', 'Carné'],
  ];

  return (
    <>
      <p className="ayuda" style={{ marginTop: 0 }}>
        {[datos.apellido_paterno, datos.apellido_materno].filter(Boolean).join(' ')}, {datos.nombres} · DNI {datos.numero_documento ?? '—'}
      </p>
      <Pestanas opciones={opciones} actual={pestana} alCambiar={setPestana} />
      {pestana === 'datos' && (
        <>
          <FormAlumno key={JSON.stringify(datos.apoderados)} ficha={datos} anio={anio} secciones={secciones} alTerminar={cambio} />
          {datos.apoderados.length > 0 && (
            <div style={{ marginTop: 16 }}>
              <h3 style={{ margin: '0 0 6px' }}>Acceso de la familia a Edunova</h3>
              <Aviso tipo="error">{accion.error}</Aviso>
              <Aviso tipo="ok">{accion.ok}</Aviso>
              {datos.apoderados.map((p) => (
                <div key={p.id} className="interruptor">
                  <span>
                    {p.nombres} {p.apellidos} <small className="ayuda">{[p.email, p.celular].filter(Boolean).join(' · ') || 'sin contacto'}</small>
                  </span>
                  <span style={{ display: 'flex', gap: 6 }}>
                    {p.email && (
                      <button type="button" className="boton boton-chico boton-claro" disabled={accion.enviando}
                        onClick={() => accion.ejecutar(() => pedir(`/alumnos/${id}/apoderados/${p.id}/invitacion`, { metodo: 'POST', cuerpo: { canal: 'correo' } }), `Invitación enviada a ${p.email}.`)}>
                        Invitar por correo
                      </button>
                    )}
                    {p.celular && (
                      <button type="button" className="boton boton-chico boton-claro" disabled={accion.enviando}
                        onClick={() => accion.ejecutar(async () => {
                          const r = await pedir<{ whatsapp_url: string | null }>(`/alumnos/${id}/apoderados/${p.id}/invitacion`, { metodo: 'POST', cuerpo: { canal: 'whatsapp' } });
                          if (r.whatsapp_url) window.open(r.whatsapp_url, '_blank', 'noopener');
                        })}>
                        Invitar por WhatsApp
                      </button>
                    )}
                  </span>
                </div>
              ))}
            </div>
          )}
        </>
      )}
      {pestana === 'matricula' && <Matricula e={datos} anio={anio} secciones={secciones} alCambiar={cambio} />}
      {pestana === 'deuda' && datos.deuda && (
        <>
          <p>
            <span className="insignia insignia-rojo">Vencida {soles(datos.deuda.vencida)}</span> <span className="insignia">Por vencer {soles(datos.deuda.por_vencer)}</span>
          </p>
          <div className="tabla-caja">
            <table className="tabla">
              <thead>
                <tr>
                  <th>Concepto</th>
                  <th>Vence</th>
                  <th>Saldo</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                {datos.deuda.cargos.map((c) => (
                  <tr key={c.id}>
                    <td>{c.descripcion}</td>
                    <td>{fecha(c.vencimiento)}</td>
                    <td>{soles(c.saldo)}</td>
                    <td>{c.vencido ? <span className="insignia insignia-rojo">Vencido</span> : c.estado}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
      {pestana === 'conducta' && datos.conducta && (
        <div className="tabla-caja">
          <table className="tabla">
            <tbody>
              {datos.conducta.map((c, i) => (
                <tr key={i}>
                  <td style={{ width: 110 }}>{fecha(c.fecha)}</td>
                  <td>{c.tipo === 'merito' ? <span className="insignia insignia-ok">Mérito</span> : <span className="insignia insignia-ambar">Incidencia</span>}</td>
                  <td>{c.detalle}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {datos.conducta.length === 0 && <p className="vacio">Sin registros de conducta.</p>}
        </div>
      )}
      {pestana === 'documentos' && datos.documentos && (
        <>
          <h3>Expediente</h3>
          {datos.documentos.expediente.length === 0 ? <p className="ayuda">Sin documentos.</p> : datos.documentos.expediente.map((d) => <p key={d.id}>{d.categoria} · {d.nombre}</p>)}
          <h3>Documentos emitidos</h3>
          {datos.documentos.emitidos.length === 0 ? <p className="ayuda">Sin documentos emitidos.</p> : datos.documentos.emitidos.map((d) => <p key={d.id}>{d.nombre} · {d.codigo_verificacion}{d.anulado_at ? ' (anulado)' : ''}</p>)}
        </>
      )}
      {pestana === 'salud' && (
        <>
          <Aviso tipo="ambar">Datos de salud: confidenciales. Esta consulta queda registrada en la auditoría.</Aviso>
          {datos.salud ? (
            <div className="campos" style={{ marginTop: 10 }}>
              {Object.entries({ grupo_sanguineo: 'Grupo sanguíneo', seguro: 'Seguro', alergias: 'Alergias', condiciones: 'Condiciones', medicacion: 'Medicación', contacto_emergencia: 'Contacto de emergencia', telefono_emergencia: 'Teléfono de emergencia' }).map(([k, v]) => (
                <div key={k}>
                  <small className="ayuda">{v}</small>
                  <br />
                  {datos.salud?.[k] ?? '—'}
                </div>
              ))}
            </div>
          ) : (
            <p className="vacio">Sin ficha de salud.</p>
          )}
        </>
      )}
      {pestana === 'consentimientos' && <Consentimientos e={datos} alCambiar={recargar} />}
      {pestana === 'carne' && <CarneAlumno id={id} />}
    </>
  );
}
