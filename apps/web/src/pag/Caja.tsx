import { useState } from 'react';
import { apiArchivo, guardarArchivo } from '../api/cliente';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Titulo } from '../panel/ui';
import { fechaHora, useAccion, useCarga } from '../panel/utilidades';
import { soles } from './tipos';

type CargoPend = { id: number; descripcion: string; vencimiento: string; saldo: number; vencido: boolean; estado: string };
type Apoderado = { id: number; nombres: string; apellidos: string; numero_documento: string; email: string | null; es_responsable_pago: boolean };
type AlumnoCaja = { id: number; nombre: string; numero_documento: string | null; cargos: CargoPend[]; apoderados: Apoderado[] };
type Resumen = {
  sesion: { id: number; caja: string; cajero: string; abierta_at: string; monto_inicial: number; cerrada_at: string | null };
  por_medio: Record<string, number>;
  cobrado: number;
  pagos: number;
  anulados: number;
  efectivo_esperado: number;
};
type EstadoCaja = { cajas: { id: number; nombre: string; sesion_id: number | null; abierta_por_nombre: string | null }[]; sesion: Resumen | null; medios: Record<string, string>; tipos: Record<string, string> };
type Medio = { medio: string; monto: number; referencia: string };

function Abrir({ datos, alAbrir }: { datos: EstadoCaja; alAbrir: () => void }) {
  const { pedir, estado } = useSesion();
  const accion = useAccion();
  const libres = datos.cajas.filter((c) => !c.sesion_id);
  const [caja, setCaja] = useState<number | ''>(libres[0]?.id ?? '');
  const [monto, setMonto] = useState(0);
  const [nueva, setNueva] = useState('');
  const crear = puede(estado!.colegio_activo!, 'pag.conceptos.editar');
  return (
    <div className="tarjeta" style={{ maxWidth: 560 }}>
      <h2>Abrir caja</h2>
      {datos.cajas.filter((c) => c.sesion_id).map((c) => <p key={c.id} className="ayuda">{c.nombre}: abierta por {c.abierta_por_nombre}</p>)}
      {libres.length > 0 ? (
        <div className="campos">
          <Campo etiqueta="Caja">
            <select value={caja} onChange={(e) => setCaja(Number(e.target.value))}>{libres.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}</select>
          </Campo>
          <Campo etiqueta="Efectivo inicial (S/)"><input type="number" min={0} step="0.10" value={monto} onChange={(e) => setMonto(Number(e.target.value))} /></Campo>
        </div>
      ) : <p>No hay cajas libres.</p>}
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="acciones">
        {libres.length > 0 && <button type="button" className="boton" disabled={!caja || accion.enviando} onClick={() => accion.ejecutar(async () => { await pedir('/pagos/caja/abrir', { metodo: 'POST', cuerpo: { caja_id: caja, monto_inicial: monto } }); alAbrir(); })}>Abrir caja</button>}
      </div>
      {crear && (
        <div className="filtros" style={{ alignItems: 'end', marginTop: 12 }}>
          <Campo etiqueta="Nueva caja"><input value={nueva} placeholder="Ventanilla 2" onChange={(e) => setNueva(e.target.value)} /></Campo>
          <button type="button" className="boton boton-borde" disabled={!nueva.trim()} onClick={() => accion.ejecutar(async () => { await pedir('/pagos/cajas', { metodo: 'POST', cuerpo: { nombre: nueva } }); setNueva(''); alAbrir(); })}>Crear</button>
        </div>
      )}
    </div>
  );
}

function Cobrar({ datos, alCobrar }: { datos: EstadoCaja; alCobrar: (m: string) => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const [q, setQ] = useState('');
  const resultados = useCarga(() => (q.trim().length >= 3 ? pedir<{ data: AlumnoCaja[] }>(`/pagos/caja/buscar?q=${encodeURIComponent(q.trim())}`) : Promise.resolve({ data: [] })), [q]);
  const [alumno, setAlumno] = useState<AlumnoCaja | null>(null);
  const [montos, setMontos] = useState<Record<number, number>>({});
  const [medios, setMedios] = useState<Medio[]>([{ medio: 'efectivo', monto: 0, referencia: '' }]);
  const [tipo, setTipo] = useState('03');
  const [apoderado, setApoderado] = useState<number | ''>('');
  const [cliente, setCliente] = useState({ numero_doc: '', nombre: '', email: '' });
  const total = Math.round(Object.values(montos).reduce((a, b) => a + (b || 0), 0) * 100) / 100;
  const sumaMedios = Math.round(medios.reduce((a, m) => a + (m.monto || 0), 0) * 100) / 100;

  function elegir(a: AlumnoCaja) {
    setAlumno(a);
    setMontos({});
    const p = a.apoderados.find((x) => x.es_responsable_pago) ?? a.apoderados[0];
    setApoderado(p?.id ?? '');
    setCliente({ numero_doc: p?.numero_documento ?? '', nombre: p ? `${p.nombres} ${p.apellidos}` : '', email: p?.email ?? '' });
  }

  function marcar(c: CargoPend, si: boolean) {
    const n = { ...montos };
    if (si) n[c.id] = c.saldo;
    else delete n[c.id];
    setMontos(n);
    const t = Math.round(Object.values(n).reduce((a, b) => a + b, 0) * 100) / 100;
    if (medios.length === 1) setMedios([{ ...medios[0], monto: t }]);
  }

  return (
    <div className="tarjeta">
      <h2>Cobrar</h2>
      {!alumno ? (
        <>
          <Campo etiqueta="Alumno" ayuda="Apellidos, nombres o DNI" ancho><input value={q} autoFocus onChange={(e) => setQ(e.target.value)} /></Campo>
          <div style={{ display: 'grid', gap: 4 }}>
            {resultados.datos?.data.map((a) => (
              <button key={a.id} type="button" className="boton boton-borde" style={{ justifyContent: 'space-between' }} onClick={() => elegir(a)}>
                <span>{a.nombre} · {a.numero_documento}</span><span>{a.cargos.length ? `${a.cargos.length} cargos · ${soles(a.cargos.reduce((s, c) => s + c.saldo, 0))}` : 'sin deuda'}</span>
              </button>
            ))}
          </div>
        </>
      ) : (
        <>
          <p><b>{alumno.nombre}</b> <button type="button" className="boton boton-chico boton-borde" onClick={() => setAlumno(null)}>Cambiar</button></p>
          <div className="tabla-caja">
            <table className="tabla">
              <thead><tr><th /><th>Cargo</th><th>Vence</th><th>Saldo</th><th>A cobrar</th></tr></thead>
              <tbody>
                {alumno.cargos.map((c) => (
                  <tr key={c.id}>
                    <td><input type="checkbox" aria-label={`Cobrar ${c.descripcion}`} checked={c.id in montos} onChange={(e) => marcar(c, e.target.checked)} /></td>
                    <td>{c.descripcion} {c.vencido && <span className="insignia insignia-rojo">Vencido</span>}</td>
                    <td>{c.vencimiento}</td>
                    <td>{soles(c.saldo)}</td>
                    <td>{c.id in montos && <input aria-label="Monto a cobrar" type="number" step="0.01" min={0.01} max={c.saldo} style={{ width: 100 }} value={montos[c.id]} onChange={(e) => setMontos({ ...montos, [c.id]: Number(e.target.value) })} />}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {alumno.cargos.length === 0 && <p className="vacio">No tiene deuda pendiente.</p>}
          </div>
          <h3>Medios de pago · total {soles(total)}</h3>
          {medios.map((m, i) => (
            <div key={i} className="filtros" style={{ alignItems: 'end' }}>
              <Campo etiqueta="Medio">
                <select value={m.medio} onChange={(e) => setMedios(medios.map((x, j) => (j === i ? { ...x, medio: e.target.value } : x)))}>
                  {Object.entries(datos.medios).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </select>
              </Campo>
              <Campo etiqueta="Monto"><input type="number" step="0.01" min={0} value={m.monto} onChange={(e) => setMedios(medios.map((x, j) => (j === i ? { ...x, monto: Number(e.target.value) } : x)))} /></Campo>
              {m.medio !== 'efectivo' && <Campo etiqueta="N.° de operación"><input value={m.referencia} onChange={(e) => setMedios(medios.map((x, j) => (j === i ? { ...x, referencia: e.target.value } : x)))} /></Campo>}
              {medios.length > 1 && <button type="button" className="boton boton-chico boton-borde" aria-label="Quitar medio" onClick={() => setMedios(medios.filter((_, j) => j !== i))}>✕</button>}
            </div>
          ))}
          <button type="button" className="boton boton-chico boton-borde" onClick={() => setMedios([...medios, { medio: 'yape', monto: Math.max(0, Math.round((total - sumaMedios) * 100) / 100), referencia: '' }])}>+ Otro medio</button>
          {Math.abs(sumaMedios - total) > 0.001 && total > 0 && <Aviso tipo="ambar">Los medios suman {soles(sumaMedios)}; faltan {soles(total - sumaMedios)}.</Aviso>}
          <h3>Comprobante</h3>
          <div className="campos">
            <Campo etiqueta="Tipo"><select value={tipo} onChange={(e) => setTipo(e.target.value)}>{Object.entries(datos.tipos).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Campo>
            <Campo etiqueta="Apoderado que paga">
              <select value={apoderado} onChange={(e) => { const p = alumno.apoderados.find((x) => x.id === Number(e.target.value)); setApoderado(p?.id ?? ''); if (p) setCliente({ numero_doc: p.numero_documento, nombre: `${p.nombres} ${p.apellidos}`, email: p.email ?? '' }); }}>
                <option value="">Otra persona</option>
                {alumno.apoderados.map((p) => <option key={p.id} value={p.id}>{p.nombres} {p.apellidos}</option>)}
              </select>
            </Campo>
            <Campo etiqueta={tipo === '01' ? 'RUC' : 'DNI o documento'}><input value={cliente.numero_doc} onChange={(e) => setCliente({ ...cliente, numero_doc: e.target.value })} /></Campo>
            <Campo etiqueta={tipo === '01' ? 'Razón social' : 'Nombre'}><input value={cliente.nombre} onChange={(e) => setCliente({ ...cliente, nombre: e.target.value })} /></Campo>
            <Campo etiqueta="Correo (envía la boleta)"><input type="email" value={cliente.email} onChange={(e) => setCliente({ ...cliente, email: e.target.value })} /></Campo>
          </div>
          <Aviso tipo="error">{accion.error}</Aviso>
          <div className="acciones">
            <button type="button" className="boton" disabled={accion.enviando || total <= 0 || Math.abs(sumaMedios - total) > 0.001}
              onClick={() => accion.ejecutar(async () => {
                const r = await pedir<{ comprobante: { serie: string; numero: number; estado_sunat: string } }>('/pagos/caja/cobros', {
                  metodo: 'POST',
                  cuerpo: { cargos: Object.entries(montos).map(([id, monto]) => ({ id: Number(id), monto })), medios: medios.filter((m) => m.monto > 0), apoderado_id: apoderado || null, tipo_comprobante: tipo, cliente: { ...cliente, email: cliente.email || null } },
                });
                setAlumno(null);
                setQ('');
                setMedios([{ medio: 'efectivo', monto: 0, referencia: '' }]);
                alCobrar(`Cobro registrado. Comprobante ${r.comprobante.serie}-${r.comprobante.numero} (${r.comprobante.estado_sunat}).`);
              })}>
              {accion.enviando ? 'Cobrando…' : `Cobrar ${soles(total)}`}
            </button>
          </div>
        </>
      )}
    </div>
  );
}

function Cerrar({ r, alCerrar }: { r: Resumen; alCerrar: (sesion: number) => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const esperado: Record<string, number> = { ...r.por_medio, efectivo: r.efectivo_esperado };
  const [contado, setContado] = useState<Record<string, number>>(esperado);
  const [justificacion, setJustificacion] = useState('');
  const diferencia = Math.round(Object.keys(esperado).reduce((s, k) => s + ((contado[k] ?? 0) - esperado[k]), 0) * 100) / 100;
  return (
    <>
      <div className="tabla-caja">
        <table className="tabla">
          <thead><tr><th>Medio</th><th>Esperado</th><th>Contado</th><th>Diferencia</th></tr></thead>
          <tbody>
            {Object.entries(esperado).map(([k, v]) => (
              <tr key={k}>
                <td>{k}</td><td>{soles(v)}</td>
                <td><input aria-label={`Contado ${k}`} type="number" step="0.01" min={0} style={{ width: 110 }} value={contado[k] ?? 0} onChange={(e) => setContado({ ...contado, [k]: Number(e.target.value) })} /></td>
                <td>{soles((contado[k] ?? 0) - v)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p><b>Diferencia total: {soles(diferencia)}</b></p>
      {Math.abs(diferencia) >= 0.01 && <Campo etiqueta="Justificación de la diferencia" ancho><input value={justificacion} maxLength={500} onChange={(e) => setJustificacion(e.target.value)} /></Campo>}
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="acciones">
        <button type="button" className="boton" disabled={accion.enviando} onClick={() => accion.ejecutar(async () => {
          const res = await pedir<{ sesion_id: number }>('/pagos/caja/cerrar', { metodo: 'POST', cuerpo: { contado, justificacion: justificacion || null } });
          alCerrar(res.sesion_id);
        })}>Cerrar caja</button>
      </div>
    </>
  );
}

/** EDUN-75 / EDUN-76 · PAG-05 Caja: abrir, cobrar y cerrar con arqueo. */
export function Caja() {
  const { pedir, estado, token } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, error, recargar } = useCarga(() => pedir<EstadoCaja>('/pagos/caja'));
  const accion = useAccion();
  const [cerrando, setCerrando] = useState(false);
  const abrirPdf = (sesion: number) => accion.ejecutar(async () => {
    guardarArchivo(await apiArchivo(`/pagos/caja/sesiones/${sesion}/pdf`, token), `arqueo-caja-${sesion}.pdf`);
  });
  const r = datos?.sesion;
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Pagos`} titulo="Caja" descripcion={r ? `${r.sesion.caja} · abierta ${fechaHora(r.sesion.abierta_at)}` : 'Abre la caja con el efectivo inicial para empezar a cobrar.'}>
        {r && (
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="button" className="boton boton-borde" onClick={() => abrirPdf(r.sesion.id)}>Reporte</button>
            {puede(colegio, 'pag.caja.cerrar') && <button type="button" className="boton boton-borde" onClick={() => setCerrando(true)}>Cerrar caja</button>}
          </div>
        )}
      </Titulo>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {datos && !r && <Abrir datos={datos} alAbrir={recargar} />}
      {datos && r && (
        <div style={{ display: 'grid', gap: 18, gridTemplateColumns: 'minmax(0, 1fr)' }}>
          <div className="rejilla">
            <div className="tarjeta"><div className="migas">Cobrado</div><h2>{soles(r.cobrado)}</h2><p className="ayuda">{r.pagos} pagos{r.anulados ? ` · ${r.anulados} anulados` : ''}</p></div>
            <div className="tarjeta"><div className="migas">Efectivo en caja</div><h2>{soles(r.efectivo_esperado)}</h2><p className="ayuda">Inicial {soles(r.sesion.monto_inicial)}</p></div>
            <div className="tarjeta"><div className="migas">Otros medios</div><h2>{soles(r.cobrado - r.por_medio.efectivo)}</h2></div>
          </div>
          {puede(colegio, 'pag.pagos.registrar') && <Cobrar datos={datos} alCobrar={(m) => { accion.setOk(m); recargar(); }} />}
        </div>
      )}
      {cerrando && r && (
        <Dialogo titulo={`Cerrar ${r.sesion.caja}`} alCerrar={() => setCerrando(false)}>
          <Cerrar r={r} alCerrar={(s) => { setCerrando(false); accion.setOk('Caja cerrada.'); abrirPdf(s); recargar(); }} />
        </Dialogo>
      )}
    </>
  );
}
