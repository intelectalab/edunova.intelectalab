import { useState } from 'react';
import { apiArchivo, guardarArchivo } from '../api/cliente';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Pestanas, Titulo } from '../panel/ui';
import { fecha, fechaHora, useAccion, useCarga } from '../panel/utilidades';

type Libro = { id: number; titulo: string; autor: string | null; isbn: string | null; editorial: string | null; categoria: string | null; tipo: string; nivel: string; grados: string | null; area: string | null; ejemplares: number; disponibles: number; licencias: number | null };
type Ficha = Libro & { descripcion: string | null; reservas: number; ejemplares_detalle: { id: number; codigo: string; estado: string; conservacion: string; estante: string | null; lector: string | null; vence: string | null }[] };
type Prestamo = { id: number; lector: string; codigo: string; titulo: string; prestado_at: string; vence: string; vencido: boolean; renovaciones: number };
const TIPOS: Record<string, string> = { libro: 'Libro', texto: 'Texto escolar', consulta: 'Consulta', revista: 'Revista', ebook: 'Digital', audiolibro: 'Audiolibro' };

/** EDUN-151 a 160 · Biblioteca: catálogo, préstamos, plan lector, inventario, reglas y estadísticas. */
export function Biblioteca() {
  const { estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const gestiona = puede(colegio, 'bib.biblioteca.gestionar');
  const opciones: ['catalogo' | 'prestamos' | 'plan' | 'inventario' | 'reglas' | 'estadisticas', string][] = [['catalogo', 'Catálogo']];
  if (puede(colegio, 'bib.biblioteca.ver')) opciones.push(['prestamos', 'Préstamos']);
  if (puede(colegio, 'bib.plan_lector.ver')) opciones.push(['plan', 'Plan lector']);
  if (gestiona) opciones.push(['inventario', 'Inventario'], ['reglas', 'Reglas']);
  if (puede(colegio, 'bib.biblioteca.ver')) opciones.push(['estadisticas', 'Estadísticas']);
  const [pestana, setPestana] = useState(opciones[0][0]);
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Servicios`} titulo="Biblioteca" descripcion="Catálogo por área, nivel y grado; préstamos con el código del ejemplar; reservas desde la app; plan lector y libros digitales con licencias." />
      <Pestanas opciones={opciones} actual={pestana} alCambiar={setPestana} />
      {pestana === 'catalogo' && <Catalogo gestiona={gestiona} />}
      {pestana === 'prestamos' && <Prestamos gestiona={gestiona} />}
      {pestana === 'plan' && <PlanLector />}
      {pestana === 'inventario' && <Inventario />}
      {pestana === 'reglas' && <Reglas />}
      {pestana === 'estadisticas' && <Estadisticas />}
    </>
  );
}

function Catalogo({ gestiona }: { gestiona: boolean }) {
  const { pedir } = useSesion();
  const [f, setF] = useState({ buscar: '', tipo: '', nivel: '', categoria: '' });
  const [vista, setVista] = useState<'tarjetas' | 'lista'>('lista');
  const q = new URLSearchParams(Object.entries(f).filter(([, v]) => v) as [string, string][]).toString();
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Libro[]; categorias: string[] }>(`/biblioteca/catalogo?${q}`), [q]);
  const [ficha, setFicha] = useState<Ficha | null>(null);
  const [nuevo, setNuevo] = useState(false);
  const accion = useAccion();
  const abrir = (id: number) => accion.ejecutar(async () => setFicha(await pedir<Ficha>(`/biblioteca/libros/${id}`)));
  return (
    <>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="filtros">
        <Campo etiqueta="Buscar"><input value={f.buscar} onChange={(e) => setF({ ...f, buscar: e.target.value })} placeholder="Título, autor o ISBN" /></Campo>
        <Campo etiqueta="Tipo"><select value={f.tipo} onChange={(e) => setF({ ...f, tipo: e.target.value })}><option value="">Todos</option>{Object.entries(TIPOS).map(([v, n]) => <option key={v} value={v}>{n}</option>)}</select></Campo>
        <Campo etiqueta="Nivel"><select value={f.nivel} onChange={(e) => setF({ ...f, nivel: e.target.value })}><option value="">Todos</option><option value="inicial">Inicial</option><option value="primaria">Primaria</option><option value="secundaria">Secundaria</option></select></Campo>
        <Campo etiqueta="Categoría"><select value={f.categoria} onChange={(e) => setF({ ...f, categoria: e.target.value })}><option value="">Todas</option>{(datos?.categorias ?? []).map((c) => <option key={c}>{c}</option>)}</select></Campo>
        <button type="button" className="boton boton-borde boton-chico" onClick={() => setVista(vista === 'lista' ? 'tarjetas' : 'lista')}>{vista === 'lista' ? 'Ver en tarjetas' : 'Ver en lista'}</button>
        {gestiona && <button type="button" className="boton" onClick={() => setNuevo(true)}>Nuevo libro</button>}
        {gestiona && <label className="boton boton-borde">Importar Excel<input type="file" hidden accept=".xlsx,.xls,.csv" onChange={(e) => {
          const archivo = e.target.files?.[0];
          if (!archivo) return;
          const cuerpo = new FormData();
          cuerpo.append('archivo', archivo);
          void accion.ejecutar(async () => { const r = await pedir<{ creados: number; errores: string[] }>('/biblioteca/importacion', { metodo: 'POST', cuerpo }); recargar(); accion.setOk(`${r.creados} libro(s) importado(s).${r.errores.length ? ` Con observaciones: ${r.errores.slice(0, 3).join(' · ')}` : ''}`); });
        }} /></label>}
      </div>
      {gestiona && <p className="ayuda">Excel: columnas titulo, autor, isbn, editorial, anio, categoria, tipo, nivel, ejemplares.</p>}
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : datos.data.length === 0 ? <p className="vacio">No hay libros con esos filtros.</p> : vista === 'tarjetas' ? (
        <div className="rejilla">{datos.data.map((l) => (
          <button key={l.id} type="button" className="tarjeta" style={{ textAlign: 'left', cursor: 'pointer' }} onClick={() => abrir(l.id)}>
            <b>{l.titulo}</b><br /><small className="ayuda">{l.autor ?? 'Sin autor'} · {TIPOS[l.tipo]}</small><br />
            <span className={`insignia ${l.disponibles ? 'insignia-ok' : 'insignia-ambar'}`}>{l.tipo === 'ebook' ? `${l.licencias} licencia(s)` : `${l.disponibles} de ${l.ejemplares} disponibles`}</span>
          </button>
        ))}</div>
      ) : (
        <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Título</th><th>Autor</th><th>Tipo</th><th>Nivel</th><th>Disponibles</th><th /></tr></thead><tbody>
          {datos.data.map((l) => <tr key={l.id}><td>{l.titulo}{l.isbn && <><br /><small className="ayuda">ISBN {l.isbn}</small></>}</td><td>{l.autor ?? '—'}</td><td>{TIPOS[l.tipo]}</td><td>{l.nivel}</td><td>{l.tipo === 'ebook' ? `${l.licencias} lic.` : `${l.disponibles}/${l.ejemplares}`}</td><td><button type="button" className="boton boton-borde boton-chico" onClick={() => abrir(l.id)}>Ficha</button></td></tr>)}
        </tbody></table></div></div>
      )}
      {ficha && (
        <Dialogo titulo={ficha.titulo} alCerrar={() => setFicha(null)}>
          <p>{ficha.autor ?? ''}{ficha.editorial ? ` · ${ficha.editorial}` : ''}{ficha.isbn ? ` · ISBN ${ficha.isbn}` : ''}</p>
          {ficha.descripcion && <p className="ayuda">{ficha.descripcion}</p>}
          {ficha.reservas > 0 && <p><span className="insignia insignia-ambar">{ficha.reservas} reserva(s)</span></p>}
          <table className="tabla"><thead><tr><th>Ejemplar</th><th>Estado</th><th>Conservación</th><th>Estante</th>{gestiona && <th>Quién lo tiene</th>}</tr></thead><tbody>
            {ficha.ejemplares_detalle.map((e) => <tr key={e.id}><td>{e.codigo}</td><td>{e.estado}{e.vence ? ` hasta ${fecha(e.vence)}` : ''}</td><td>{e.conservacion}</td><td>{e.estante ?? '—'}</td>{gestiona && <td>{e.lector ?? '—'}</td>}</tr>)}
          </tbody></table>
          {gestiona && <button type="button" className="boton boton-borde boton-chico" onClick={() => accion.ejecutar(async () => { await pedir(`/biblioteca/libros/${ficha.id}/ejemplares`, { metodo: 'POST', cuerpo: { cantidad: 1 } }); setFicha(await pedir<Ficha>(`/biblioteca/libros/${ficha.id}`)); recargar(); })}>Agregar un ejemplar</button>}
        </Dialogo>
      )}
      {nuevo && <NuevoLibro alTerminar={() => { setNuevo(false); recargar(); }} />}
    </>
  );
}

function NuevoLibro({ alTerminar }: { alTerminar: () => void }) {
  const { pedir } = useSesion();
  const [f, setF] = useState({ isbn: '', titulo: '', autor: '', editorial: '', anio_publicacion: '', categoria: '', tipo: 'libro', nivel: 'todos', grados: '', ejemplares: '1', estante: '', licencias: '' });
  const [archivo, setArchivo] = useState<File | null>(null);
  const accion = useAccion();
  const campo = (k: keyof typeof f, etiqueta: string) => <Campo etiqueta={etiqueta}><input value={f[k]} onChange={(e) => setF({ ...f, [k]: e.target.value })} /></Campo>;
  return (
    <Dialogo titulo="Nuevo libro" alCerrar={alTerminar}>
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="filtros">
        {campo('isbn', 'ISBN')}
        <button type="button" className="boton boton-borde boton-chico" disabled={f.isbn.replace(/\D/g, '').length < 10 || accion.enviando} onClick={() => accion.ejecutar(async () => {
          const r = await pedir<{ titulo: string | null; autor: string | null; editorial: string | null; anio_publicacion: number | null }>(`/biblioteca/isbn/${f.isbn.replace(/[^0-9Xx]/g, '')}`);
          setF({ ...f, titulo: r.titulo ?? f.titulo, autor: r.autor ?? f.autor, editorial: r.editorial ?? f.editorial, anio_publicacion: r.anio_publicacion ? String(r.anio_publicacion) : f.anio_publicacion });
        })}>Completar con el ISBN</button>
      </div>
      <div className="campos">
        {campo('titulo', 'Título')}{campo('autor', 'Autor')}{campo('editorial', 'Editorial')}{campo('anio_publicacion', 'Año')}{campo('categoria', 'Categoría')}
        <Campo etiqueta="Tipo"><select value={f.tipo} onChange={(e) => setF({ ...f, tipo: e.target.value })}>{Object.entries(TIPOS).map(([v, n]) => <option key={v} value={v}>{n}</option>)}</select></Campo>
        <Campo etiqueta="Nivel"><select value={f.nivel} onChange={(e) => setF({ ...f, nivel: e.target.value })}><option value="todos">Todos</option><option value="inicial">Inicial</option><option value="primaria">Primaria</option><option value="secundaria">Secundaria</option></select></Campo>
        {campo('grados', 'Grados (por ejemplo 3,4)')}
        {f.tipo === 'ebook' ? <>{campo('licencias', 'Licencias')}<Campo etiqueta="Archivo (PDF o EPUB)"><input type="file" accept=".pdf,.epub" onChange={(e) => setArchivo(e.target.files?.[0] ?? null)} /></Campo></> : <>{campo('ejemplares', 'Ejemplares')}{campo('estante', 'Estante')}</>}
      </div>
      <button type="button" className="boton" disabled={accion.enviando || f.titulo.trim().length < 2} onClick={() => accion.ejecutar(async () => {
        const cuerpo = new FormData();
        Object.entries(f).forEach(([k, v]) => { if (v) cuerpo.append(k, k === 'isbn' ? v.replace(/[^0-9Xx]/g, '') : v); });
        if (archivo) cuerpo.append('archivo', archivo);
        await pedir('/biblioteca/libros', { metodo: 'POST', cuerpo });
        alTerminar();
      })}>Guardar</button>
    </Dialogo>
  );
}

function Prestamos({ gestiona }: { gestiona: boolean }) {
  const { pedir } = useSesion();
  const [soloVencidos, setSoloVencidos] = useState(false);
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Prestamo[] }>(`/biblioteca/prestamos${soloVencidos ? '?vencidos=1' : ''}`), [soloVencidos]);
  const [f, setF] = useState({ ejemplar: '', lector: '' });
  const [dev, setDev] = useState('');
  const accion = useAccion();
  return (
    <>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {gestiona && <div className="tarjeta"><div className="filtros">
        <Campo etiqueta="Ejemplar (código o lector)"><input value={f.ejemplar} onChange={(e) => setF({ ...f, ejemplar: e.target.value })} /></Campo>
        <Campo etiqueta="Carné o DNI del alumno"><input value={f.lector} onChange={(e) => setF({ ...f, lector: e.target.value })} /></Campo>
        <button type="button" className="boton" disabled={!f.ejemplar || !f.lector || accion.enviando} onClick={() => accion.ejecutar(async () => { const r = await pedir<{ libro: string; lector: string; vence: string }>('/biblioteca/prestamos', { metodo: 'POST', cuerpo: f }); setF({ ejemplar: '', lector: '' }); recargar(); accion.setOk(`«${r.libro}» prestado a ${r.lector} hasta el ${fecha(r.vence)}.`); })}>Prestar</button>
        <Campo etiqueta="Devolver: código del ejemplar"><input value={dev} onChange={(e) => setDev(e.target.value)} /></Campo>
        <button type="button" className="boton boton-borde" disabled={!dev || accion.enviando} onClick={() => accion.ejecutar(async () => { const r = await pedir<{ atraso_dias: number; suspendido_hasta: string | null; reservado: boolean }>('/biblioteca/devoluciones', { metodo: 'POST', cuerpo: { ejemplar: dev } }); setDev(''); recargar(); accion.setOk(`Devuelto.${r.atraso_dias ? ` ${r.atraso_dias} día(s) de atraso: préstamos suspendidos hasta el ${fecha(r.suspendido_hasta)}.` : ''}${r.reservado ? ' Queda separado para una reserva.' : ''}`); })}>Devolver</button>
      </div></div>}
      <label className="casilla"><input type="checkbox" checked={soloVencidos} onChange={(e) => setSoloVencidos(e.target.checked)} /> Solo vencidos</label>
      {datos && (datos.data.length === 0 ? <p className="vacio">No hay préstamos.</p> : (
        <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Libro</th><th>Lector</th><th>Vence</th><th /></tr></thead><tbody>
          {datos.data.map((p) => <tr key={p.id}><td>{p.titulo}<br /><small className="ayuda">{p.codigo}</small></td><td>{p.lector}</td><td>{fecha(p.vence)}{p.vencido && <> <span className="insignia insignia-rojo">Vencido</span></>}</td>
            <td>{gestiona && !p.vencido && <button type="button" className="boton boton-borde boton-chico" onClick={() => accion.ejecutar(async () => { await pedir(`/biblioteca/prestamos/${p.id}/renovacion`, { metodo: 'POST' }); recargar(); })}>Renovar</button>}</td></tr>)}
        </tbody></table></div></div>
      ))}
    </>
  );
}

function PlanLector() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, error, recargar } = useCarga(() => pedir<{ data: { id: number; titulo: string; grado: string; periodo: string; terminados: number }[]; grados: { id: number; nombre: string; nivel: string }[]; periodos: { id: number; nombre: string }[] }>('/biblioteca/plan-lector'));
  const libros = useCarga(() => pedir<{ data: Libro[] }>('/biblioteca/catalogo'));
  const [f, setF] = useState({ grado_id: '', periodo_id: '', bib_libro_id: '' });
  const [avance, setAvance] = useState<{ plan: number; titulo: string; filas: { alumno_id: number; alumno: string; seccion: string; estado: string }[] } | null>(null);
  const accion = useAccion();
  const abrir = (id: number, titulo: string) => accion.ejecutar(async () => setAvance({ plan: id, titulo, filas: (await pedir<{ data: { alumno_id: number; alumno: string; seccion: string; estado: string }[] }>(`/biblioteca/plan-lector/${id}/avance`)).data }));
  return (
    <>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {puede(colegio, 'bib.plan_lector.editar') && datos && <div className="filtros">
        <Campo etiqueta="Grado"><select value={f.grado_id} onChange={(e) => setF({ ...f, grado_id: e.target.value })}><option value="">Elige…</option>{datos.grados.map((g) => <option key={g.id} value={g.id}>{g.nombre} ({g.nivel})</option>)}</select></Campo>
        <Campo etiqueta="Periodo"><select value={f.periodo_id} onChange={(e) => setF({ ...f, periodo_id: e.target.value })}><option value="">Elige…</option>{datos.periodos.map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}</select></Campo>
        <Campo etiqueta="Libro"><select value={f.bib_libro_id} onChange={(e) => setF({ ...f, bib_libro_id: e.target.value })}><option value="">Elige…</option>{(libros.datos?.data ?? []).map((l) => <option key={l.id} value={l.id}>{l.titulo}</option>)}</select></Campo>
        <button type="button" className="boton" disabled={!f.grado_id || !f.periodo_id || !f.bib_libro_id} onClick={() => accion.ejecutar(async () => { await pedir('/biblioteca/plan-lector', { metodo: 'POST', cuerpo: { grado_id: Number(f.grado_id), periodo_id: Number(f.periodo_id), bib_libro_id: Number(f.bib_libro_id) } }); recargar(); })}>Agregar al plan</button>
      </div>}
      {datos && <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Grado</th><th>Periodo</th><th>Libro</th><th>Terminaron</th><th /></tr></thead><tbody>
        {datos.data.map((p) => <tr key={p.id}><td>{p.grado}</td><td>{p.periodo}</td><td>{p.titulo}</td><td>{p.terminados}</td><td><button type="button" className="boton boton-borde boton-chico" onClick={() => abrir(p.id, p.titulo)}>Avance</button></td></tr>)}
      </tbody></table></div></div>}
      {avance && (
        <Dialogo titulo={`Avance: ${avance.titulo}`} alCerrar={() => { setAvance(null); recargar(); }}>
          <table className="tabla"><thead><tr><th>Alumno</th><th>Sección</th><th>Estado</th></tr></thead><tbody>
            {avance.filas.map((a) => <tr key={a.alumno_id}><td>{a.alumno}</td><td>{a.seccion}</td><td>{puede(colegio, 'bib.plan_lector.registrar') ? (
              <select aria-label={`Avance de ${a.alumno}`} value={a.estado} onChange={(e) => accion.ejecutar(async () => { await pedir(`/biblioteca/plan-lector/${avance.plan}/avance/${a.alumno_id}`, { metodo: 'PUT', cuerpo: { estado: e.target.value } }); setAvance({ ...avance, filas: avance.filas.map((x) => (x.alumno_id === a.alumno_id ? { ...x, estado: e.target.value } : x)) }); })}>
                <option value="sin_empezar">Sin empezar</option><option value="leyendo">Leyendo</option><option value="terminado">Terminado</option>
              </select>) : a.estado}</td></tr>)}
          </tbody></table>
          {puede(colegio, 'bib.plan_lector.editar') && <button type="button" className="boton" onClick={() => accion.ejecutar(async () => { const r = await pedir<{ enviados: number }>(`/biblioteca/plan-lector/${avance.plan}/recordatorio`, { metodo: 'POST' }); accion.setOk(`Recordatorio enviado a ${r.enviados} teléfono(s).`); })}>Recordar a quienes faltan</button>}
        </Dialogo>
      )}
    </>
  );
}

function Inventario() {
  const { pedir, token } = useSesion();
  const [estante, setEstante] = useState('');
  const [codigos, setCodigos] = useState('');
  const [r, setR] = useState<{ leidos: number; desconocidos: string[]; faltan: { codigo: string; titulo: string }[] } | null>(null);
  const [libro, setLibro] = useState('');
  const accion = useAccion();
  return (
    <>
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="tarjeta"><h2>Toma de inventario por estante</h2>
        <div className="campos">
          <Campo etiqueta="Estante"><input value={estante} onChange={(e) => setEstante(e.target.value)} /></Campo>
          <Campo etiqueta="Códigos leídos (uno por línea, con el lector)" ancho><textarea rows={6} value={codigos} onChange={(e) => setCodigos(e.target.value)} /></Campo>
        </div>
        <button type="button" className="boton" disabled={!estante || accion.enviando} onClick={() => accion.ejecutar(async () => setR(await pedir('/biblioteca/inventario', { metodo: 'POST', cuerpo: { estante, codigos: codigos.split(/\s+/).filter(Boolean) } })))}>Registrar inventario</button>
        {r && <Aviso tipo={r.faltan.length ? 'ambar' : 'ok'}>{r.leidos} ejemplar(es) registrados.{r.faltan.length ? ` Faltan en el estante: ${r.faltan.map((f) => `${f.codigo} (${f.titulo})`).join(', ')}.` : ' No falta ninguno.'}{r.desconocidos.length ? ` Códigos desconocidos: ${r.desconocidos.join(', ')}.` : ''}</Aviso>}
      </div>
      <div className="tarjeta"><h2>Etiquetas con QR</h2>
        <p className="ayuda">Imprime las etiquetas de todos los ejemplares de un libro (busca el ID en la ficha del catálogo).</p>
        <div className="filtros">
          <Campo etiqueta="ID del libro"><input value={libro} onChange={(e) => setLibro(e.target.value)} /></Campo>
          <button type="button" className="boton boton-borde" disabled={!libro || accion.enviando} onClick={() => accion.ejecutar(async () => {
            const f = await pedir<Ficha>(`/biblioteca/libros/${libro}`);
            guardarArchivo(await apiArchivo('/biblioteca/etiquetas', token, { metodo: 'POST', cuerpo: { ids: f.ejemplares_detalle.map((e) => e.id) } }), `etiquetas-${libro}.pdf`);
          })}>Descargar etiquetas</button>
        </div>
      </div>
    </>
  );
}

function Reglas() {
  const { pedir } = useSesion();
  const { datos, recargar } = useCarga(() => pedir<{ dias_alumno: number; dias_personal: number; max_alumno: number; renovaciones_max: number; suspension_por_atraso: boolean; reservas_app: boolean }>('/biblioteca/reglas'));
  const accion = useAccion();
  if (!datos) return <p role="status">Cargando…</p>;
  const cambiar = (k: string, v: unknown) => accion.ejecutar(async () => { await pedir('/biblioteca/reglas', { metodo: 'PUT', cuerpo: { [k]: v } }); recargar(); });
  return (
    <div className="tarjeta">
      <Aviso tipo="error">{accion.error}</Aviso>
      <div className="campos">
        <Campo etiqueta="Días de préstamo (alumnos)"><input type="number" defaultValue={datos.dias_alumno} onBlur={(e) => cambiar('dias_alumno', Number(e.target.value))} /></Campo>
        <Campo etiqueta="Días de préstamo (personal)"><input type="number" defaultValue={datos.dias_personal} onBlur={(e) => cambiar('dias_personal', Number(e.target.value))} /></Campo>
        <Campo etiqueta="Máximo de libros por alumno"><input type="number" defaultValue={datos.max_alumno} onBlur={(e) => cambiar('max_alumno', Number(e.target.value))} /></Campo>
        <Campo etiqueta="Renovaciones"><input type="number" defaultValue={datos.renovaciones_max} onBlur={(e) => cambiar('renovaciones_max', Number(e.target.value))} /></Campo>
        <label className="campo"><span><input type="checkbox" checked={datos.suspension_por_atraso} onChange={(e) => cambiar('suspension_por_atraso', e.target.checked)} /> Suspender un día por cada día de atraso (sin multas)</span></label>
        <label className="campo"><span><input type="checkbox" checked={datos.reservas_app} onChange={(e) => cambiar('reservas_app', e.target.checked)} /> Reservas desde la app</span></label>
      </div>
    </div>
  );
}

function Estadisticas() {
  const { pedir } = useSesion();
  const { datos, error } = useCarga(() => pedir<{ por_mes: { mes: string; prestamos: number }[]; mas_leidos: { titulo: string; autor: string | null; prestamos: number }[]; lectores_por_nivel: { nivel: string; lectores: number }[] }>('/biblioteca/estadisticas'));
  if (!datos) return <Aviso tipo="error">{error}</Aviso>;
  const max = Math.max(1, ...datos.por_mes.map((m) => m.prestamos));
  return (
    <>
      <div className="tarjeta"><h2>Préstamos por mes</h2>
        {datos.por_mes.length === 0 ? <p className="vacio">Aún no hay préstamos.</p> : datos.por_mes.map((m) => <div key={m.mes} className="filtros"><span style={{ width: 70 }}>{m.mes}</span><div style={{ background: 'var(--azul, #27496f)', height: 14, width: `${(m.prestamos / max) * 60}%`, borderRadius: 4 }} /><span>{m.prestamos}</span></div>)}
      </div>
      <div className="tarjeta"><h2>Más leídos</h2><ol>{datos.mas_leidos.map((l) => <li key={l.titulo}>{l.titulo}{l.autor ? ` · ${l.autor}` : ''} ({l.prestamos})</li>)}</ol></div>
      <div className="tarjeta"><h2>Lectores por nivel</h2><ul className="lista-simple">{datos.lectores_por_nivel.map((n) => <li key={n.nivel}>{n.nivel}: {n.lectores}</li>)}</ul></div>
    </>
  );
}

/** EDUN-154 / 157 · El alumno y la familia: préstamos, reservas, plan lector y libros digitales. */
export function MiBiblioteca() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const mia = useCarga(() => pedir<{ prestamos: { id: number; titulo: string; vence: string; alumno: string; vencido: boolean }[]; reservas: { id: number; estado: string; titulo: string; alumno: string }[]; plan_lector: { titulo: string; periodo: string; alumno: string; estado: string }[]; digitales: { id: number; titulo: string; vence: string }[] }>('/biblioteca/mia'));
  const familia = useCarga(() => pedir<{ hijos: { id: number; nombres: string }[] }>('/me/familia'));
  const [buscar, setBuscar] = useState('');
  const libros = useCarga(() => (buscar.trim().length >= 3 ? pedir<{ data: Libro[] }>(`/biblioteca/catalogo?buscar=${encodeURIComponent(buscar.trim())}`) : Promise.resolve({ data: [] })), [buscar]);
  const accion = useAccion();
  const hijo = familia.datos?.hijos[0]?.id;
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Biblioteca`} titulo="Mi biblioteca" descripcion="Libros prestados, reservas, plan lector y libros digitales." />
      <Aviso tipo="error">{mia.error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {mia.datos && <>
        <div className="tarjeta"><h2>Prestados</h2>{mia.datos.prestamos.length === 0 ? <p className="vacio">Ninguno.</p> : <ul className="lista-simple">{mia.datos.prestamos.map((p) => <li key={p.id}>{p.titulo} · {p.alumno} · devolver el {fecha(p.vence)}{p.vencido && <> <span className="insignia insignia-rojo">Vencido</span></>}</li>)}</ul>}</div>
        <div className="tarjeta"><h2>Reservas</h2>{mia.datos.reservas.length === 0 ? <p className="vacio">Ninguna.</p> : <ul className="lista-simple">{mia.datos.reservas.map((r) => <li key={r.id}>{r.titulo} · {r.estado === 'disponible' ? <span className="insignia insignia-ok">Lista para recoger</span> : 'En espera'} <button type="button" className="enlace" onClick={() => accion.ejecutar(async () => { await pedir(`/biblioteca/reservas/${r.id}/cancelacion`, { metodo: 'POST' }); mia.recargar(); })}>Cancelar</button></li>)}</ul>}</div>
        {mia.datos.plan_lector.length > 0 && <div className="tarjeta"><h2>Plan lector</h2><ul className="lista-simple">{mia.datos.plan_lector.map((p, i) => <li key={i}>{p.periodo}: {p.titulo} · {p.alumno} · {p.estado.replace('_', ' ')}</li>)}</ul></div>}
      </>}
      <div className="tarjeta"><h2>Buscar y reservar</h2>
        <Campo etiqueta="Título o autor"><input value={buscar} onChange={(e) => setBuscar(e.target.value)} /></Campo>
        <ul className="lista-simple">{(libros.datos?.data ?? []).map((l) => <li key={l.id}>{l.titulo} · {l.autor ?? ''} · {l.tipo === 'ebook' ? 'digital' : `${l.disponibles} disponible(s)`}{' '}
          {l.tipo === 'ebook' ? <button type="button" className="boton boton-chico" onClick={() => accion.ejecutar(async () => { const r = await pedir<{ url: string; vence: string }>(`/biblioteca/digitales/${l.id}/lectura`, { metodo: 'POST' }); window.open(r.url, '_blank', 'noopener'); mia.recargar(); })}>Leer</button>
            : <button type="button" className="boton boton-borde boton-chico" onClick={() => accion.ejecutar(async () => { const r = await pedir<{ estado: string }>('/biblioteca/reservas', { metodo: 'POST', cuerpo: { bib_libro_id: l.id, alumno_id: hijo ?? null } }); mia.recargar(); accion.setOk(r.estado === 'disponible' ? 'Reservado: ya puedes recogerlo en la biblioteca.' : 'Reservado: te avisaremos cuando esté disponible.'); })}>Reservar</button>}
        </li>)}</ul>
      </div>
      {mia.datos && mia.datos.digitales.length > 0 && <div className="tarjeta"><h2>Leyendo (digitales)</h2><ul className="lista-simple">{mia.datos.digitales.map((d) => <li key={d.id}>{d.titulo} · hasta {fechaHora(d.vence)}</li>)}</ul></div>}
    </>
  );
}
