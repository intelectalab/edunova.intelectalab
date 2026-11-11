import { useState, type FormEvent } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Pestanas, Titulo } from '../panel/ui';
import { useAccion, useCarga } from '../panel/utilidades';
import { useAnioEscolar } from '../adm/anios';
import { SelectorAnio } from '../adm/SelectorAnio';
import { cuotasMensuales, NIVELES, soles, type Concepto, type Cronograma, type Cuota } from './tipos';

type Catalogos = { data: Concepto[]; tipos: Record<string, string>; obligatorios: string[]; comprobantes: Record<string, string> };

const VACIO = { codigo: '', nombre: '', tipo: 'servicio', obligatorio: false, monto: 0, afecto_igv: false, tipo_comprobante: 'boleta', activo: true };

function FormConcepto({ inicial, cat, alGuardar }: { inicial: Partial<Concepto>; cat: Catalogos; alGuardar: (d: Catalogos['data']) => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const [f, setF] = useState({ ...VACIO, ...inicial });
  const puedeObligatorio = cat.obligatorios.includes(f.tipo);

  async function enviar(e: FormEvent) {
    e.preventDefault();
    await accion.ejecutar(async () => {
      const r = await pedir<{ data: Concepto[] }>(inicial.id ? `/pagos/conceptos/${inicial.id}` : '/pagos/conceptos', {
        metodo: inicial.id ? 'PUT' : 'POST',
        cuerpo: { ...f, obligatorio: puedeObligatorio && f.obligatorio, monto: Number(f.monto) },
      });
      alGuardar(r.data);
    });
  }

  return (
    <form onSubmit={enviar} noValidate>
      <div className="campos">
        <Campo etiqueta="Código" ayuda="Corto, p. ej. PEN o ROB">
          <input value={f.codigo} maxLength={20} onChange={(e) => setF({ ...f, codigo: e.target.value })} />
        </Campo>
        <Campo etiqueta="Nombre">
          <input value={f.nombre} maxLength={120} onChange={(e) => setF({ ...f, nombre: e.target.value })} />
        </Campo>
        <Campo etiqueta="Tipo">
          <select value={f.tipo} onChange={(e) => setF({ ...f, tipo: e.target.value })}>
            {Object.entries(cat.tipos).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </Campo>
        <Campo etiqueta="Monto (S/)">
          <input type="number" min={0} step="0.01" value={f.monto} onChange={(e) => setF({ ...f, monto: Number(e.target.value) })} />
        </Campo>
        <Campo etiqueta="Comprobante">
          <select value={f.tipo_comprobante} onChange={(e) => setF({ ...f, tipo_comprobante: e.target.value })}>
            {Object.entries(cat.comprobantes).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </Campo>
      </div>
      <label className="casilla">
        <input type="checkbox" checked={f.afecto_igv} onChange={(e) => setF({ ...f, afecto_igv: e.target.checked })} /> Afecto a IGV
      </label>
      <label className="casilla" title={puedeObligatorio ? undefined : 'Solo la cuota de ingreso, la matrícula y la pensión pueden ser obligatorias'}>
        <input type="checkbox" disabled={!puedeObligatorio} checked={puedeObligatorio && f.obligatorio} onChange={(e) => setF({ ...f, obligatorio: e.target.checked })} /> Obligatorio
      </label>
      <label className="casilla">
        <input type="checkbox" checked={f.activo} onChange={(e) => setF({ ...f, activo: e.target.checked })} /> Activo
      </label>
      {!puedeObligatorio && <p className="ayuda">Por norma de Indecopi, solo la cuota de ingreso, la matrícula y la pensión pueden ser obligatorias.</p>}
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="acciones">
        <button type="submit" className="boton" disabled={accion.enviando}>{accion.enviando ? 'Guardando…' : 'Guardar concepto'}</button>
      </div>
    </form>
  );
}

function ListaConceptos({ editable }: { editable: boolean }) {
  const { pedir } = useSesion();
  const { datos, setDatos, error } = useCarga(() => pedir<Catalogos>('/pagos/conceptos'));
  const [editando, setEditando] = useState<Partial<Concepto> | null>(null);
  if (!datos) return <Aviso tipo="error">{error}</Aviso>;
  return (
    <div className="tarjeta">
      <div className="titulo-fila">
        <h2>Conceptos de cobro</h2>
        {editable && <button type="button" className="boton boton-chico" onClick={() => setEditando({})}>+ Concepto</button>}
      </div>
      <div className="tabla-caja">
        <table className="tabla">
          <thead>
            <tr><th>Código</th><th>Concepto</th><th>Tipo</th><th>Monto</th><th>Comprobante</th><th>IGV</th><th>Obligatorio</th><th /></tr>
          </thead>
          <tbody>
            {datos.data.map((c) => (
              <tr key={c.id} style={{ opacity: c.activo ? 1 : 0.55 }}>
                <td><code>{c.codigo}</code></td>
                <td>{c.nombre}{!c.activo && ' (inactivo)'}</td>
                <td>{datos.tipos[c.tipo]}</td>
                <td>{soles(c.monto)}</td>
                <td>{datos.comprobantes[c.tipo_comprobante]}</td>
                <td>{c.afecto_igv ? 'Sí' : 'No'}</td>
                <td>{c.obligatorio ? <span className="insignia insignia-ok">Obligatorio</span> : 'Opcional'}</td>
                <td>{editable && <button type="button" className="boton boton-chico boton-borde" onClick={() => setEditando(c)}>Editar</button>}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {datos.data.length === 0 && <p className="vacio">Aún no hay conceptos.</p>}
      </div>
      {editando && (
        <Dialogo titulo={editando.id ? `Editar ${editando.nombre}` : 'Nuevo concepto'} alCerrar={() => setEditando(null)}>
          <FormConcepto inicial={editando} cat={datos} alGuardar={(d) => { setDatos({ ...datos, data: d }); setEditando(null); }} />
        </Dialogo>
      )}
    </div>
  );
}

function EditorCronograma({ anio, anioNumero, inicial, conceptos, alGuardar }: {
  anio: number; anioNumero: number; inicial: Cronograma | null; conceptos: Concepto[]; alGuardar: (d: Cronograma[]) => void;
}) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const [nombre, setNombre] = useState(inicial?.nombre ?? `Pensiones ${anioNumero}`);
  const [nivel, setNivel] = useState(inicial?.nivel ?? '');
  const [cuotas, setCuotas] = useState<Cuota[]>(inicial?.cuotas ?? []);
  const pension = conceptos.find((c) => c.tipo === 'pension' && c.activo);
  const [dia, setDia] = useState(30);
  const cambiar = (i: number, cambios: Partial<Cuota>) => setCuotas(cuotas.map((q, j) => (j === i ? { ...q, ...cambios } : q)));

  return (
    <>
      <div className="campos">
        <Campo etiqueta="Nombre"><input value={nombre} onChange={(e) => setNombre(e.target.value)} /></Campo>
        <Campo etiqueta="Nivel">
          <select value={nivel} onChange={(e) => setNivel(e.target.value)}>
            {Object.entries(NIVELES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        </Campo>
      </div>
      {pension && (
        <div className="filtros" style={{ alignItems: 'end' }}>
          <Campo etiqueta="Día de vencimiento"><input type="number" min={1} max={31} value={dia} onChange={(e) => setDia(Number(e.target.value))} /></Campo>
          <button type="button" className="boton boton-borde" onClick={() => setCuotas([...cuotas.filter((q) => q.con_cargos || conceptos.find((c) => c.id === q.concepto_cobro_id)?.tipo !== 'pension'), ...cuotasMensuales(anioNumero, pension.id, pension.monto, dia)])}>
            Generar pensiones de marzo a diciembre
          </button>
        </div>
      )}
      <div className="tabla-caja">
        <table className="tabla">
          <thead><tr><th>Concepto</th><th>Mes</th><th>Emisión</th><th>Vence</th><th>Monto</th><th /></tr></thead>
          <tbody>
            {cuotas.map((q, i) => (
              <tr key={i}>
                <td>
                  <select aria-label="Concepto" value={q.concepto_cobro_id} disabled={q.con_cargos} onChange={(e) => cambiar(i, { concepto_cobro_id: Number(e.target.value) })}>
                    {conceptos.filter((c) => c.activo || c.id === q.concepto_cobro_id).map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
                  </select>
                </td>
                <td><input aria-label="Mes" type="month" value={q.mes} disabled={q.con_cargos} onChange={(e) => cambiar(i, { mes: e.target.value })} /></td>
                <td><input aria-label="Emisión" type="date" value={q.emision} onChange={(e) => cambiar(i, { emision: e.target.value })} /></td>
                <td><input aria-label="Vencimiento" type="date" value={q.vencimiento} min={`${q.mes}-01`} onChange={(e) => cambiar(i, { vencimiento: e.target.value })} /></td>
                <td><input aria-label="Monto" type="number" step="0.01" min={0} style={{ width: 100 }} value={q.monto} disabled={q.con_cargos} onChange={(e) => cambiar(i, { monto: Number(e.target.value) })} /></td>
                <td>
                  {q.con_cargos ? <small className="ayuda">con cargos</small> : (
                    <button type="button" className="boton boton-chico boton-borde" aria-label="Quitar cuota" onClick={() => setCuotas(cuotas.filter((_, j) => j !== i))}>✕</button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <button type="button" className="boton boton-chico boton-borde" style={{ marginTop: 8 }} disabled={!conceptos.length}
        onClick={() => { const mes = `${anioNumero}-03`; setCuotas([...cuotas, { concepto_cobro_id: conceptos[0].id, mes, emision: `${mes}-01`, vencimiento: `${mes}-31`, monto: conceptos[0].monto }]); }}>
        + Cuota
      </button>
      <p className="ayuda">Ninguna cuota puede vencer antes de su mes (sin cobro adelantado) y la matrícula no puede superar la pensión.</p>
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="acciones">
        <button type="button" className="boton" disabled={accion.enviando || !cuotas.length}
          onClick={() => accion.ejecutar(async () => {
            const r = await pedir<{ data: Cronograma[] }>(inicial ? `/pagos/cronogramas/${inicial.id}` : '/pagos/cronogramas', {
              metodo: inicial ? 'PUT' : 'POST', cuerpo: { anio_escolar_id: anio, nivel: nivel || null, nombre, cuotas },
            });
            alGuardar(r.data);
          })}>
          {accion.enviando ? 'Guardando…' : 'Guardar cronograma'}
        </button>
      </div>
    </>
  );
}

function Cronogramas({ editable }: { editable: boolean }) {
  const { pedir } = useSesion();
  const { anios, anio, setAnio } = useAnioEscolar();
  const conceptos = useCarga(() => pedir<Catalogos>('/pagos/conceptos'));
  const { datos, setDatos, error } = useCarga(() => (anio ? pedir<{ data: Cronograma[] }>(`/pagos/cronogramas?anio_escolar_id=${anio}`) : Promise.resolve({ data: [] })), [anio]);
  const accion = useAccion();
  const [editando, setEditando] = useState<Cronograma | 'nuevo' | null>(null);
  const [origen, setOrigen] = useState<number | ''>('');
  const anioNumero = anios.find((a) => a.id === anio)?.anio ?? 0;

  return (
    <>
      <div className="filtros" style={{ alignItems: 'end' }}>
        {anios.length > 0 && <SelectorAnio anios={anios} anio={anio} alCambiar={setAnio} />}
        {editable && <button type="button" className="boton" onClick={() => setEditando('nuevo')}>+ Cronograma</button>}
      </div>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {datos?.data.length === 0 && editable && (
        <div className="tarjeta">
          <p>Este año no tiene cronograma. Créalo o cópialo de otro año (las fechas se corren al año elegido).</p>
          <div className="filtros" style={{ alignItems: 'end' }}>
            <Campo etiqueta="Copiar desde">
              <select value={origen} onChange={(e) => setOrigen(Number(e.target.value) || '')}>
                <option value="">Elige un año</option>
                {anios.filter((a) => a.id !== anio).map((a) => <option key={a.id} value={a.id}>{a.anio}</option>)}
              </select>
            </Campo>
            <button type="button" className="boton boton-borde" disabled={!origen || accion.enviando}
              onClick={() => accion.ejecutar(async () => {
                const r = await pedir<{ copiados: number; data: Cronograma[] }>('/pagos/cronogramas/copiar', { metodo: 'POST', cuerpo: { desde_anio_id: origen, hacia_anio_id: anio } });
                setDatos({ data: r.data });
              }, 'Cronogramas copiados. Revisa los montos antes de matricular.')}>
              Copiar
            </button>
          </div>
        </div>
      )}
      <div style={{ display: 'grid', gap: 18, gridTemplateColumns: 'minmax(0, 1fr)' }}>
        {datos?.data.map((c) => (
          <div key={c.id} className="tarjeta">
            <div className="titulo-fila">
              <h2>{c.nombre} <small className="ayuda">· {NIVELES[c.nivel ?? '']}</small></h2>
              {editable && (
                <div style={{ display: 'flex', gap: 6 }}>
                  <button type="button" className="boton boton-chico boton-borde" onClick={() => setEditando(c)}>Editar</button>
                  {!c.cuotas.some((q) => q.con_cargos) && (
                    <button type="button" className="boton boton-chico boton-borde"
                      onClick={() => window.confirm(`¿Eliminar «${c.nombre}»?`) && accion.ejecutar(async () => {
                        await pedir(`/pagos/cronogramas/${c.id}`, { metodo: 'DELETE' });
                        setDatos({ data: datos.data.filter((x) => x.id !== c.id) });
                      }, 'Cronograma eliminado.')}>
                      Eliminar
                    </button>
                  )}
                </div>
              )}
            </div>
            <div className="tabla-caja">
              <table className="tabla">
                <thead><tr><th>N.°</th><th>Concepto</th><th>Mes</th><th>Emisión</th><th>Vence</th><th>Monto</th></tr></thead>
                <tbody>
                  {c.cuotas.map((q) => (
                    <tr key={q.id}><td>{q.numero}</td><td>{q.concepto}</td><td>{q.mes}</td><td>{q.emision}</td><td>{q.vencimiento}</td><td>{soles(q.monto)}</td></tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        ))}
      </div>
      {editando && anio && conceptos.datos && (
        <Dialogo titulo={editando === 'nuevo' ? 'Nuevo cronograma' : `Editar ${editando.nombre}`} alCerrar={() => setEditando(null)}>
          <EditorCronograma anio={anio} anioNumero={anioNumero} inicial={editando === 'nuevo' ? null : editando} conceptos={conceptos.datos.data}
            alGuardar={(d) => { setDatos({ data: d }); setEditando(null); }} />
        </Dialogo>
      )}
    </>
  );
}

/** EDUN-69 / EDUN-70 · PAG-02 Conceptos y cronogramas. */
export function ConceptosYCronogramas() {
  const { estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const editable = puede(colegio, 'pag.conceptos.editar');
  const [vista, setVista] = useState<'conceptos' | 'cronogramas'>('conceptos');
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Pagos`} titulo="Conceptos y cronogramas" descripcion="Qué se cobra, cuánto y cuándo vence. Los cargos se crean al matricular." />
      <Pestanas opciones={[['conceptos', 'Conceptos'], ['cronogramas', 'Cronogramas']]} actual={vista} alCambiar={setVista} />
      <div style={{ marginTop: 14 }}>{vista === 'conceptos' ? <ListaConceptos editable={editable} /> : <Cronogramas editable={editable} />}</div>
    </>
  );
}
