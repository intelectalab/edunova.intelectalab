import { useState, type FormEvent } from 'react';
import { useSesion } from '../sesion/contexto';
import { Aviso, Campo } from '../panel/ui';
import { fecha, useAccion, useCarga } from '../panel/utilidades';

type Resumen = { id: number; anio: number; tipo_periodo: string; fecha_inicio: string; fecha_fin: string; estado: string; periodos: number };
type Periodo = { numero: number; nombre: string; fecha_inicio: string; fecha_fin: string; fecha_limite_notas: string | null; estado?: string };
type Feriado = { fecha: string; motivo: string };
type Detalle = { id: number; anio: number; tipo_periodo: string; fecha_inicio: string; fecha_fin: string; estado: string; periodos: Periodo[]; feriados: Feriado[]; aviso?: string | null };

const ESTADO: Record<string, [string, string]> = {
  planificado: ['Planificado', 'insignia-azul'],
  abierto: ['Abierto', 'insignia-ok'],
  cerrado: ['Cerrado', ''],
  programado: ['Programado', 'insignia-azul'],
};

function Insignia({ estado }: { estado: string }) {
  const [nombre, clase] = ESTADO[estado] ?? [estado, ''];
  return <span className={`insignia ${clase}`}>{nombre}</span>;
}

function NuevoAnio({ alCrear }: { alCrear: (d: Detalle) => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const [f, setF] = useState(() => {
    const siguiente = new Date().getFullYear() + 1;
    return { anio: String(siguiente), tipo_periodo: 'bimestre', fecha_inicio: `${siguiente}-03-01`, fecha_fin: `${siguiente}-12-18` };
  });

  async function crear(e: FormEvent) {
    e.preventDefault();
    await accion.ejecutar(async () => alCrear(await pedir<Detalle>('/anios-escolares', { metodo: 'POST', cuerpo: { ...f, anio: Number(f.anio) } })));
  }

  return (
    <form className="tarjeta" onSubmit={crear} noValidate>
      <h2>Nuevo año escolar</h2>
      <p className="ayuda">Edunova reparte el año en periodos y carga los feriados nacionales. Luego puedes ajustar cada fecha.</p>
      <div className="campos">
        <Campo etiqueta="Año">
          <input inputMode="numeric" maxLength={4} value={f.anio} onChange={(e) => setF({ ...f, anio: e.target.value })} />
        </Campo>
        <Campo etiqueta="Periodos">
          <select value={f.tipo_periodo} onChange={(e) => setF({ ...f, tipo_periodo: e.target.value })}>
            <option value="bimestre">Bimestres (4)</option>
            <option value="trimestre">Trimestres (3)</option>
          </select>
        </Campo>
        <Campo etiqueta="Inicio de clases">
          <input type="date" value={f.fecha_inicio} onChange={(e) => setF({ ...f, fecha_inicio: e.target.value })} />
        </Campo>
        <Campo etiqueta="Fin de clases">
          <input type="date" value={f.fecha_fin} onChange={(e) => setF({ ...f, fecha_fin: e.target.value })} />
        </Campo>
      </div>
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="acciones">
        <button type="submit" className="boton" disabled={accion.enviando}>
          {accion.enviando ? 'Creando…' : 'Crear año escolar'}
        </button>
      </div>
    </form>
  );
}

function EditarAnio({ id, editable, aviso, alCambiar }: { id: number; editable: boolean; aviso: string | null; alCambiar: () => void }) {
  const { pedir } = useSesion();
  const { datos, setDatos, error } = useCarga(() => pedir<Detalle>(`/anios-escolares/${id}`), [id]);
  if (!datos) return <Aviso tipo="error">{error}</Aviso>;
  return <FormAnio key={JSON.stringify(datos)} datos={datos} editable={editable} aviso={aviso} alGuardar={(d) => { setDatos(d); alCambiar(); }} />;
}

function FormAnio({ datos, editable, aviso, alGuardar }: { datos: Detalle; editable: boolean; aviso: string | null; alGuardar: (d: Detalle) => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const [f, setF] = useState<Detalle>(datos);
  const [nuevo, setNuevo] = useState<Feriado>({ fecha: '', motivo: '' });
  const id = datos.id;
  const bloqueado = !editable || f.estado === 'cerrado';

  const periodo = (i: number, k: keyof Periodo, v: string) => setF({ ...f, periodos: f.periodos.map((p, j) => (j === i ? { ...p, [k]: v } : p)) });

  async function guardar(cambioTipo?: string) {
    await accion.ejecutar(async () => {
      const cuerpo = cambioTipo
        ? { tipo_periodo: cambioTipo, fecha_inicio: f.fecha_inicio, fecha_fin: f.fecha_fin, feriados: f.feriados }
        : { fecha_inicio: f.fecha_inicio, fecha_fin: f.fecha_fin, periodos: f.periodos, feriados: f.feriados };
      alGuardar(await pedir<Detalle>(`/anios-escolares/${id}`, { metodo: 'PUT', cuerpo }));
    }, 'Año escolar guardado.');
  }

  return (
    <div className="rejilla">
      <div className="tarjeta" style={{ gridColumn: '1 / -1' }}>
        <div className="titulo-fila">
          <h2>
            Año escolar {f.anio} <Insignia estado={f.estado} />
          </h2>
          {!bloqueado && f.estado === 'planificado' && (
            <button
              type="button"
              className="boton boton-chico boton-borde"
              disabled={accion.enviando}
              onClick={() => guardar(f.tipo_periodo === 'bimestre' ? 'trimestre' : 'bimestre')}
            >
              Cambiar a {f.tipo_periodo === 'bimestre' ? 'trimestres' : 'bimestres'}
            </button>
          )}
        </div>
        {aviso && <Aviso tipo="ambar">{aviso}</Aviso>}
        <div className="campos" style={{ marginTop: 12 }}>
          <Campo etiqueta="Inicio de clases">
            <input type="date" value={f.fecha_inicio} disabled={bloqueado} onChange={(e) => setF({ ...f, fecha_inicio: e.target.value })} />
          </Campo>
          <Campo etiqueta="Fin de clases">
            <input type="date" value={f.fecha_fin} disabled={bloqueado} onChange={(e) => setF({ ...f, fecha_fin: e.target.value })} />
          </Campo>
        </div>
        <div className="tabla-caja" style={{ marginTop: 14 }}>
          <table className="tabla">
            <thead>
              <tr>
                <th>Periodo</th>
                <th>Inicio</th>
                <th>Fin</th>
                <th>Límite de notas</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              {f.periodos.map((p, i) => {
                const cerrado = bloqueado || p.estado === 'cerrado';
                return (
                  <tr key={p.numero}>
                    <td>
                      <input className="control" style={{ minWidth: 140 }} aria-label={`Nombre del periodo ${p.numero}`} value={p.nombre} disabled={cerrado} onChange={(e) => periodo(i, 'nombre', e.target.value)} />
                    </td>
                    <td>
                      <input className="control" style={{ minWidth: 150 }} type="date" aria-label={`Inicio del ${p.nombre}`} value={p.fecha_inicio} disabled={cerrado} onChange={(e) => periodo(i, 'fecha_inicio', e.target.value)} />
                    </td>
                    <td>
                      <input className="control" style={{ minWidth: 150 }} type="date" aria-label={`Fin del ${p.nombre}`} value={p.fecha_fin} disabled={cerrado} onChange={(e) => periodo(i, 'fecha_fin', e.target.value)} />
                    </td>
                    <td>
                      <input
                        className="control"
                        style={{ minWidth: 150 }}
                        type="date"
                        aria-label={`Límite de notas del ${p.nombre}`}
                        value={p.fecha_limite_notas ?? ''}
                        disabled={cerrado}
                        onChange={(e) => periodo(i, 'fecha_limite_notas', e.target.value)}
                      />
                    </td>
                    <td>{p.estado && <Insignia estado={p.estado} />}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>
      <div className="tarjeta" style={{ gridColumn: '1 / -1' }}>
        <h2>Feriados y días no lectivos</h2>
        <p className="ayuda">Los feriados nacionales se cargan solos. Agrega los del colegio o de tu región.</p>
        <div className="tabla-caja">
          <table className="tabla">
            <tbody>
              {f.feriados.map((d) => (
                <tr key={d.fecha}>
                  <td style={{ width: 130 }}>{fecha(d.fecha)}</td>
                  <td>{d.motivo}</td>
                  <td style={{ textAlign: 'right' }}>
                    {!bloqueado && (
                      <button type="button" className="boton boton-chico boton-borde" onClick={() => setF({ ...f, feriados: f.feriados.filter((x) => x.fecha !== d.fecha) })}>
                        Quitar
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {f.feriados.length === 0 && <p className="vacio">Sin feriados.</p>}
        </div>
        {!bloqueado && (
          <div className="filtros" style={{ marginTop: 12 }}>
            <Campo etiqueta="Fecha">
              <input type="date" value={nuevo.fecha} min={f.fecha_inicio} max={f.fecha_fin} onChange={(e) => setNuevo({ ...nuevo, fecha: e.target.value })} />
            </Campo>
            <Campo etiqueta="Motivo">
              <input value={nuevo.motivo} onChange={(e) => setNuevo({ ...nuevo, motivo: e.target.value })} />
            </Campo>
            <button
              type="button"
              className="boton boton-claro"
              disabled={!nuevo.fecha || !nuevo.motivo.trim()}
              onClick={() => {
                setF({ ...f, feriados: [...f.feriados.filter((x) => x.fecha !== nuevo.fecha), nuevo].sort((a, b) => a.fecha.localeCompare(b.fecha)) });
                setNuevo({ fecha: '', motivo: '' });
              }}
            >
              Agregar
            </button>
          </div>
        )}
        <Aviso tipo="error">{accion.error}</Aviso>
        <Aviso tipo="ok">{accion.ok}</Aviso>
        {!bloqueado && (
          <div className="acciones">
            <button type="button" className="boton" disabled={accion.enviando} onClick={() => guardar()}>
              {accion.enviando ? 'Guardando…' : 'Guardar año escolar'}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

/** EDUN-44 · ADM-02 Año escolar y periodos. */
export function AnioEscolar({ editable }: { editable: boolean }) {
  const { pedir } = useSesion();
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Resumen[] }>('/anios-escolares'));
  const [eleccion, setElegido] = useState<number | null>(null);
  const [creando, setCreando] = useState(false);
  const [aviso, setAviso] = useState<string | null>(null);
  // Sin elección, se muestra el año más reciente.
  const elegido = eleccion ?? datos?.data[0]?.id ?? null;

  return (
    <>
      <div className="pestanas">
        {datos?.data.map((a) => (
          <button key={a.id} type="button" aria-selected={!creando && elegido === a.id} onClick={() => { setCreando(false); setElegido(a.id); setAviso(null); }}>
            {a.anio} · {a.tipo_periodo === 'bimestre' ? 'Bimestres' : 'Trimestres'}
          </button>
        ))}
        {editable && (
          <button type="button" aria-selected={creando} onClick={() => setCreando(true)}>
            + Nuevo año
          </button>
        )}
      </div>
      <Aviso tipo="error">{error}</Aviso>
      {creando || (datos && datos.data.length === 0) ? (
        editable ? (
          <NuevoAnio
            alCrear={(d) => {
              setCreando(false);
              setElegido(d.id);
              setAviso(d.aviso ?? null);
              void recargar();
            }}
          />
        ) : (
          <p className="vacio">Aún no hay años escolares.</p>
        )
      ) : (
        elegido !== null && <EditarAnio key={elegido} id={elegido} editable={editable} aviso={aviso} alCambiar={() => void recargar()} />
      )}
    </>
  );
}
