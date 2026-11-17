import { useState } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Pestanas, Titulo } from '../panel/ui';
import { fecha, useAccion, useCarga } from '../panel/utilidades';

type Menu = { id: number; fecha: string; entrada: string | null; fondo: string; bebida: string | null; postre: string | null; kcal: number | null; proteinas_g: number | null; carbohidratos_g: number | null; grasas_g: number | null; alergenos: string | null; validado_por: string | null; estado: 'borrador' | 'propuesto' | 'aprobado' | 'publicado'; observacion_nutricion: string | null; porciones: Record<string, { porcion?: string; kcal?: number }> | null };
type Plan = { id: number; nombre: string; modalidad: 'mensual' | 'diario'; concepto: string | null; monto: number | null; activo: boolean };
type Inscripcion = { id: number; alumno_id: number; alumno: string; plan: string; modalidad: string; desde: string; hasta: string | null; dieta_especial: boolean };
type FilaLista = { alumno_id: number; alumno: string; servido: boolean; hora: string | null; dieta_especial: boolean };
const ESTADO = { borrador: 'insignia-gris', propuesto: 'insignia-ambar', aprobado: 'insignia-azul', publicado: 'insignia-ok' };
const DIAS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'];
const lunesDe = (d: Date) => { const x = new Date(d); x.setDate(x.getDate() - ((x.getDay() + 6) % 7)); return x.toLocaleDateString('sv-SE'); };
const sumar = (iso: string, dias: number) => { const x = new Date(`${iso}T12:00:00`); x.setDate(x.getDate() + dias); return x.toLocaleDateString('sv-SE'); };

/** EDUN-168 a 175 · Comedor: menú con nutrición, planes e inscripciones, servicio del día y nutrición. */
export function Comedor() {
  const { estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const opciones: ['menu' | 'inscripciones' | 'servicio' | 'nutricion', string][] = [['menu', 'Menú de la semana']];
  if (puede(colegio, 'cod.comedor.ver')) opciones.push(['inscripciones', 'Planes e inscripciones']);
  if (puede(colegio, 'cod.comedor.gestionar')) opciones.push(['servicio', 'Servicio del día']);
  if (puede(colegio, 'sal.restricciones.ver', 'cod.nutricion.ver')) opciones.push(['nutricion', 'Nutrición']);
  const [pestana, setPestana] = useState(opciones[0][0]);
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Servicios`} titulo="Comedor" descripcion="Menú saludable (Ley 30021) con nutrientes y alérgenos, inscripciones con su cobro aparte de la pensión y pase con el QR del carné." />
      <Pestanas opciones={opciones} actual={pestana} alCambiar={setPestana} />
      {pestana === 'menu' && <MenuSemana />}
      {pestana === 'inscripciones' && <Inscripciones />}
      {pestana === 'servicio' && <Servicio />}
      {pestana === 'nutricion' && <Nutricion />}
    </>
  );
}

function MenuSemana() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const gestiona = puede(colegio, 'cod.comedor.gestionar');
  const nutricion = puede(colegio, 'cod.nutricion.aprobar');
  const [lunes, setLunes] = useState(() => lunesDe(new Date()));
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Menu[]; tiene_nutricionista: boolean }>(`/comedor/menus?lunes=${lunes}`), [lunes]);
  const [edit, setEdit] = useState<Record<string, Partial<Menu>>>({});
  const accion = useAccion();
  const mover = (m: Menu, a: string) => {
    const observacion = a === 'devolver' ? window.prompt('¿Qué hay que cambiar?') : null;
    if (a === 'devolver' && !observacion?.trim()) return;
    void accion.ejecutar(async () => { await pedir(`/comedor/menus/${m.id}/estado`, { metodo: 'POST', cuerpo: { accion: a, observacion } }); recargar(); });
  };
  return (
    <>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <div className="filtros">
        <button type="button" className="boton boton-borde boton-chico" onClick={() => setLunes(sumar(lunes, -7))}>← Semana anterior</button>
        <span>Semana del {fecha(lunes)}</span>
        <button type="button" className="boton boton-borde boton-chico" onClick={() => setLunes(sumar(lunes, 7))}>Semana siguiente →</button>
      </div>
      {datos?.tiene_nutricionista && <p className="ayuda">El colegio tiene nutricionista: el menú se publica después de su aprobación.</p>}
      {DIAS.map((dia, i) => {
        const f = sumar(lunes, i);
        const m = datos?.data.find((x) => x.fecha === f);
        const e = edit[f] ?? m ?? {};
        const set = (k: keyof Menu, v: string) => setEdit({ ...edit, [f]: { ...e, [k]: v } });
        return (
          <div key={f} className="tarjeta">
            <div className="titulo-fila"><h2>{dia} {fecha(f)}</h2>{m && <span className={`insignia ${ESTADO[m.estado]}`}>{m.estado}</span>}</div>
            {m?.observacion_nutricion && <Aviso tipo="ambar">Nutrición: {m.observacion_nutricion}</Aviso>}
            <div className="campos">
              {(['entrada', 'fondo', 'bebida', 'postre'] as const).map((k) => <Campo key={k} etiqueta={k[0].toUpperCase() + k.slice(1)}><input value={String(e[k] ?? '')} disabled={!gestiona} onChange={(ev) => set(k, ev.target.value)} /></Campo>)}
              <Campo etiqueta="Kcal"><input type="number" value={String(e.kcal ?? '')} disabled={!gestiona} onChange={(ev) => set('kcal', ev.target.value)} /></Campo>
              <Campo etiqueta="Proteínas (g)"><input type="number" value={String(e.proteinas_g ?? '')} disabled={!gestiona} onChange={(ev) => set('proteinas_g', ev.target.value)} /></Campo>
              <Campo etiqueta="Carbohidratos (g)"><input type="number" value={String(e.carbohidratos_g ?? '')} disabled={!gestiona} onChange={(ev) => set('carbohidratos_g', ev.target.value)} /></Campo>
              <Campo etiqueta="Grasas (g)"><input type="number" value={String(e.grasas_g ?? '')} disabled={!gestiona} onChange={(ev) => set('grasas_g', ev.target.value)} /></Campo>
              <Campo etiqueta="Alérgenos"><input value={String(e.alergenos ?? '')} disabled={!gestiona} onChange={(ev) => set('alergenos', ev.target.value)} placeholder="Gluten, lácteos, maní…" /></Campo>
            </div>
            {m?.validado_por && <p className="ayuda">Validado por {m.validado_por}.</p>}
            <div className="acciones">
              {gestiona && edit[f] && <button type="button" className="boton" disabled={accion.enviando || !e.fondo} onClick={() => accion.ejecutar(async () => {
                const num = (v: unknown) => (v === '' || v === null || v === undefined ? null : Number(v));
                await pedir('/comedor/menus', { metodo: 'PUT', cuerpo: { fecha: f, entrada: e.entrada || null, fondo: e.fondo, bebida: e.bebida || null, postre: e.postre || null, kcal: num(e.kcal), proteinas_g: num(e.proteinas_g), carbohidratos_g: num(e.carbohidratos_g), grasas_g: num(e.grasas_g), alergenos: e.alergenos || null } });
                const { [f]: _, ...resto } = edit;
                void _;
                setEdit(resto);
                recargar();
              })}>Guardar</button>}
              {m && gestiona && m.estado === 'borrador' && datos?.tiene_nutricionista && <button type="button" className="boton boton-borde" onClick={() => mover(m, 'proponer')}>Enviar a nutrición</button>}
              {m && nutricion && ['borrador', 'propuesto'].includes(m.estado) && <button type="button" className="boton boton-borde" onClick={() => mover(m, 'aprobar')}>Aprobar</button>}
              {m && nutricion && ['propuesto', 'aprobado'].includes(m.estado) && <button type="button" className="boton boton-borde" onClick={() => mover(m, 'devolver')}>Pedir cambios</button>}
              {m && gestiona && (m.estado === 'aprobado' || (!datos?.tiene_nutricionista && m.estado !== 'publicado')) && <button type="button" className="boton" onClick={() => mover(m, 'publicar')}>Publicar a las familias</button>}
            </div>
          </div>
        );
      })}
    </>
  );
}

function Inscripciones() {
  const { pedir, estado } = useSesion();
  const gestiona = puede(estado!.colegio_activo!, 'cod.comedor.gestionar');
  const planes = useCarga(() => pedir<{ data: Plan[]; conceptos: { id: number; nombre: string; monto: number }[] }>('/comedor/planes'));
  const [buscar, setBuscar] = useState('');
  const insc = useCarga(() => pedir<{ data: Inscripcion[] }>(`/comedor/inscripciones${buscar ? `?buscar=${encodeURIComponent(buscar)}` : ''}`), [buscar]);
  const [nuevoPlan, setNuevoPlan] = useState({ nombre: '', modalidad: 'mensual', concepto_cobro_id: '' });
  const [alumnoBuscar, setAlumnoBuscar] = useState('');
  const alumnos = useCarga(() => (alumnoBuscar.trim().length >= 3 ? pedir<{ data: { id: number; nombres: string; apellido_paterno: string }[] }>(`/alumnos?buscar=${encodeURIComponent(alumnoBuscar.trim())}`) : Promise.resolve({ data: [] })), [alumnoBuscar]);
  const [inscribir, setInscribir] = useState(() => ({ alumno_id: 0, com_plan_id: '', desde: new Date().toLocaleDateString('sv-SE'), hasta: '' }));
  const accion = useAccion();
  return (
    <>
      <Aviso tipo="error">{planes.error ?? insc.error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="tarjeta"><h2>Planes</h2>
        <ul className="lista-simple">{(planes.datos?.data ?? []).map((p) => <li key={p.id}>{p.nombre} · {p.modalidad === 'mensual' ? 'mensual' : 'por día'}{p.monto !== null ? ` · S/ ${p.monto.toFixed(2)}` : ' · sin cobro'}</li>)}</ul>
        {gestiona && planes.datos && <div className="filtros">
          <Campo etiqueta="Nombre"><input value={nuevoPlan.nombre} onChange={(e) => setNuevoPlan({ ...nuevoPlan, nombre: e.target.value })} /></Campo>
          <Campo etiqueta="Modalidad"><select value={nuevoPlan.modalidad} onChange={(e) => setNuevoPlan({ ...nuevoPlan, modalidad: e.target.value })}><option value="mensual">Mensual (un cargo por mes)</option><option value="diario">Por día (un cargo por día servido)</option></select></Campo>
          <Campo etiqueta="Concepto de cobro"><select value={nuevoPlan.concepto_cobro_id} onChange={(e) => setNuevoPlan({ ...nuevoPlan, concepto_cobro_id: e.target.value })}><option value="">Sin cobro</option>{planes.datos.conceptos.map((c) => <option key={c.id} value={c.id}>{c.nombre} · S/ {Number(c.monto).toFixed(2)}</option>)}</select></Campo>
          <button type="button" className="boton boton-chico" disabled={nuevoPlan.nombre.trim().length < 3} onClick={() => accion.ejecutar(async () => { await pedir('/comedor/planes', { metodo: 'POST', cuerpo: { ...nuevoPlan, concepto_cobro_id: nuevoPlan.concepto_cobro_id ? Number(nuevoPlan.concepto_cobro_id) : null } }); setNuevoPlan({ nombre: '', modalidad: 'mensual', concepto_cobro_id: '' }); planes.recargar(); })}>Agregar plan</button>
        </div>}
      </div>
      {gestiona && (
        <div className="tarjeta"><h2>Inscribir</h2>
          <div className="filtros">
            <Campo etiqueta="Alumno" ayuda={inscribir.alumno_id ? 'Elegido.' : 'Escribe al menos 3 letras.'}><input value={alumnoBuscar} onChange={(e) => setAlumnoBuscar(e.target.value)} /></Campo>
            {(alumnos.datos?.data ?? []).slice(0, 6).map((a) => <button key={a.id} type="button" className={`boton boton-chico ${inscribir.alumno_id === a.id ? '' : 'boton-borde'}`} onClick={() => setInscribir({ ...inscribir, alumno_id: a.id })}>{a.apellido_paterno}, {a.nombres}</button>)}
          </div>
          <div className="filtros">
            <Campo etiqueta="Plan"><select value={inscribir.com_plan_id} onChange={(e) => setInscribir({ ...inscribir, com_plan_id: e.target.value })}><option value="">Elige…</option>{(planes.datos?.data ?? []).filter((p) => p.activo).map((p) => <option key={p.id} value={p.id}>{p.nombre}</option>)}</select></Campo>
            <Campo etiqueta="Desde"><input type="date" value={inscribir.desde} onChange={(e) => setInscribir({ ...inscribir, desde: e.target.value })} /></Campo>
            <Campo etiqueta="Hasta (opcional)"><input type="date" value={inscribir.hasta} onChange={(e) => setInscribir({ ...inscribir, hasta: e.target.value })} /></Campo>
            <button type="button" className="boton" disabled={!inscribir.alumno_id || !inscribir.com_plan_id || accion.enviando} onClick={() => accion.ejecutar(async () => {
              const r = await pedir<{ cargos: number }>('/comedor/inscripciones', { metodo: 'POST', cuerpo: { ...inscribir, com_plan_id: Number(inscribir.com_plan_id), hasta: inscribir.hasta || null } });
              insc.recargar();
              accion.setOk(`Inscrito.${r.cargos ? ` Se generaron ${r.cargos} cargo(s) mensuales.` : ''}`);
            })}>Inscribir</button>
          </div>
        </div>
      )}
      <div className="filtros"><Campo etiqueta="Buscar inscrito"><input value={buscar} onChange={(e) => setBuscar(e.target.value)} /></Campo></div>
      {insc.datos && (
        <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Alumno</th><th>Plan</th><th>Vigencia</th><th /></tr></thead><tbody>
          {insc.datos.data.map((i) => <tr key={i.id}><td>{i.alumno}{i.dieta_especial && <> <span className="insignia insignia-ambar">Dieta especial</span></>}</td><td>{i.plan}</td><td>{fecha(i.desde)} – {i.hasta ? fecha(i.hasta) : 'sin fin'}</td>
            <td>{gestiona && <button type="button" className="boton boton-borde boton-chico" onClick={() => accion.ejecutar(async () => { await pedir(`/comedor/inscripciones/${i.id}/fin`, { metodo: 'POST', cuerpo: { hasta: new Date().toLocaleDateString('sv-SE') } }); insc.recargar(); })}>Terminar hoy</button>}</td></tr>)}
        </tbody></table></div></div>
      )}
    </>
  );
}

function Servicio() {
  const { pedir } = useSesion();
  const { datos, error, recargar } = useCarga(() => pedir<{ data: FilaLista[] }>('/comedor/lista'));
  const [codigo, setCodigo] = useState('');
  const [ultimo, setUltimo] = useState<{ ok: boolean; alumno: string | null; alerta: string | null } | null>(null);
  const accion = useAccion();
  const servir = (cuerpo: Record<string, unknown>) => accion.ejecutar(async () => { setUltimo(await pedir('/comedor/servidos', { metodo: 'POST', cuerpo })); setCodigo(''); recargar(); });
  const servidos = datos?.data.filter((d) => d.servido).length ?? 0;
  return (
    <>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <form className="tarjeta" onSubmit={(e) => { e.preventDefault(); if (codigo.trim()) void servir({ codigo: codigo.trim(), origen: 'qr' }); }}>
        <Campo etiqueta="Carné (lector de QR) o DNI" ayuda="Con un lector conectado, el código se escribe solo."><input autoFocus value={codigo} onChange={(e) => setCodigo(e.target.value)} /></Campo>
        {ultimo && <Aviso tipo={ultimo.ok ? (ultimo.alerta ? 'ambar' : 'ok') : 'error'}>{ultimo.alumno ?? ''} {ultimo.ok ? '· servido.' : ''} {ultimo.alerta ?? ''}</Aviso>}
      </form>
      <p>Servidos: {servidos} de {datos?.data.length ?? 0} inscritos.</p>
      {datos && (
        <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th>Alumno</th><th>Estado</th><th /></tr></thead><tbody>
          {datos.data.map((a) => <tr key={a.alumno_id}><td>{a.alumno}{a.dieta_especial && <> <span className="insignia insignia-ambar">Dieta especial</span></>}</td><td>{a.servido ? `Servido ${a.hora}` : '—'}</td><td>{!a.servido && <button type="button" className="boton boton-chico" disabled={accion.enviando} onClick={() => servir({ alumno_id: a.alumno_id, origen: 'lista' })}>Servir</button>}</td></tr>)}
        </tbody></table></div></div>
      )}
    </>
  );
}

function Nutricion() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const restr = useCarga(() => (puede(colegio, 'sal.restricciones.ver') ? pedir<{ data: { id: number; alumno_id: number; alumno: string; tipo: string; detalle: string; reemplazo: string | null }[] }>('/comedor/restricciones') : Promise.resolve({ data: [] })));
  const ind = useCarga(() => (puede(colegio, 'cod.nutricion.ver') ? pedir<{ data: { nivel: string; mediciones: number; imc_promedio: number | null; bajo: number; adecuado: number; alto: number }[] }>('/comedor/indicadores') : Promise.resolve({ data: [] })));
  const [alumnoBuscar, setAlumnoBuscar] = useState('');
  const alumnos = useCarga(() => (alumnoBuscar.trim().length >= 3 ? pedir<{ data: { id: number; nombres: string; apellido_paterno: string }[] }>(`/alumnos?buscar=${encodeURIComponent(alumnoBuscar.trim())}`) : Promise.resolve({ data: [] })), [alumnoBuscar]);
  const [f, setF] = useState({ alumno_id: 0, tipo: 'alergia', detalle: '', reemplazo: '' });
  const accion = useAccion();
  return (
    <>
      <Aviso tipo="error">{restr.error ?? ind.error ?? accion.error}</Aviso>
      <div className="tarjeta"><h2>Restricciones alimentarias</h2>
        <p className="ayuda">Solo nutrición y enfermería ven el detalle; el pase del comedor muestra «Dieta especial».</p>
        <ul className="lista-simple">{(restr.datos?.data ?? []).map((r) => <li key={r.id}>{r.alumno}: {r.tipo} · {r.detalle}{r.reemplazo ? ` → ${r.reemplazo}` : ''} <button type="button" className="enlace" onClick={() => accion.ejecutar(async () => { await pedir(`/comedor/restricciones/${r.id}`, { metodo: 'DELETE' }); restr.recargar(); })}>Retirar</button></li>)}</ul>
        {puede(colegio, 'sal.restricciones.registrar') && <>
          <div className="filtros">
            <Campo etiqueta="Alumno"><input value={alumnoBuscar} onChange={(e) => setAlumnoBuscar(e.target.value)} /></Campo>
            {(alumnos.datos?.data ?? []).slice(0, 6).map((a) => <button key={a.id} type="button" className={`boton boton-chico ${f.alumno_id === a.id ? '' : 'boton-borde'}`} onClick={() => setF({ ...f, alumno_id: a.id })}>{a.apellido_paterno}, {a.nombres}</button>)}
          </div>
          <div className="filtros">
            <Campo etiqueta="Tipo"><select value={f.tipo} onChange={(e) => setF({ ...f, tipo: e.target.value })}><option value="alergia">Alergia</option><option value="intolerancia">Intolerancia</option><option value="dieta">Dieta</option><option value="otra">Otra</option></select></Campo>
            <Campo etiqueta="Detalle"><input value={f.detalle} onChange={(e) => setF({ ...f, detalle: e.target.value })} /></Campo>
            <Campo etiqueta="Reemplazo sugerido"><input value={f.reemplazo} onChange={(e) => setF({ ...f, reemplazo: e.target.value })} /></Campo>
            <button type="button" className="boton boton-chico" disabled={!f.alumno_id || f.detalle.trim().length < 3} onClick={() => accion.ejecutar(async () => { await pedir('/comedor/restricciones', { metodo: 'POST', cuerpo: { ...f, reemplazo: f.reemplazo || null } }); setF({ ...f, detalle: '', reemplazo: '' }); restr.recargar(); })}>Registrar</button>
          </div>
        </>}
      </div>
      {(ind.datos?.data ?? []).length > 0 && (
        <div className="tarjeta"><h2>Seguimiento nutricional</h2>
          <p className="ayuda">Peso y talla de campañas autorizadas por las familias (Enfermería → Campañas). IMC como referencia general; la interpretación es de nutrición.</p>
          <table className="tabla"><thead><tr><th>Nivel</th><th>Mediciones</th><th>IMC promedio</th><th>Bajo</th><th>Adecuado</th><th>Alto</th></tr></thead><tbody>
            {ind.datos!.data.map((n) => <tr key={n.nivel}><td>{n.nivel}</td><td>{n.mediciones}</td><td>{n.imc_promedio ?? '—'}</td><td>{n.bajo}</td><td>{n.adecuado}</td><td>{n.alto}</td></tr>)}
          </tbody></table>
        </div>
      )}
    </>
  );
}

/** EDUN-172 · La familia ve el menú publicado con su información nutricional. */
export function ComedorFamilia() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, error } = useCarga(() => pedir<{ menu: Menu[]; inscritos: { alumno: string; plan: string }[]; consumos: { fecha: string; hora: string; alumno: string }[] }>('/familia/comedor'));
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Familia`} titulo="Comedor" descripcion="Menú de la semana con calorías, nutrientes y alérgenos." />
      <Aviso tipo="error">{error}</Aviso>
      {datos && datos.inscritos.length > 0 && <p>Inscritos: {datos.inscritos.map((i) => `${i.alumno} (${i.plan})`).join(', ')}.</p>}
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : datos.menu.length === 0 ? <p className="vacio">El colegio aún no publicó el menú de esta semana.</p> : datos.menu.map((m) => (
        <div key={m.id} className="tarjeta">
          <h2>{fecha(m.fecha)}</h2>
          <p>{[m.entrada, m.fondo, m.bebida, m.postre].filter(Boolean).join(' · ')}</p>
          <p className="ayuda">{m.kcal ? `${m.kcal} kcal` : ''}{m.proteinas_g ? ` · proteínas ${m.proteinas_g} g` : ''}{m.carbohidratos_g ? ` · carbohidratos ${m.carbohidratos_g} g` : ''}{m.grasas_g ? ` · grasas ${m.grasas_g} g` : ''}{m.alergenos ? ` · alérgenos: ${m.alergenos}` : ''}{m.validado_por ? ` · validado por ${m.validado_por}` : ''}</p>
        </div>
      ))}
      {datos && datos.consumos.length > 0 && <div className="tarjeta"><h2>Últimos servidos</h2><ul className="lista-simple">{datos.consumos.slice(0, 10).map((c, i) => <li key={i}>{fecha(c.fecha)} {c.hora?.slice(0, 5)} · {c.alumno}</li>)}</ul></div>}
    </>
  );
}
