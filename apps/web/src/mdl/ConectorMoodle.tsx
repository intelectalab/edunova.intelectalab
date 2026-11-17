import { useState, type FormEvent } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Titulo } from '../panel/ui';
import { fechaHora, useAccion, useCarga } from '../panel/utilidades';

type Conexion = {
  configurado: boolean;
  url: string | null;
  tiene_token: boolean;
  sso_activo: boolean;
  frecuencia_minutos: number;
  estado: string | null;
  ultima_sincronizacion_at: string | null;
  version?: string | null;
  alojado_por_edunova?: boolean;
  tiene_secreto_sso?: boolean;
};

/** EDUN-26 · Configuración del conector de Moodle (solo si el colegio eligió Moodle o ambas). */
export function ConectorMoodle() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, error } = useCarga(() => pedir<Conexion>('/moodle/conexion'));

  return (
    <>
      <Titulo
        migas={`${colegio.nombre} · Aula virtual`}
        titulo="Moodle"
        descripcion="Conecta el Moodle del colegio: Edunova creará los cursos y matriculará a docentes y alumnos, y traerá las notas."
      />
      <Aviso tipo="error">{error}</Aviso>
      {datos && <FormularioMoodle datos={datos} editable={puede(colegio, 'mdl.conector.configurar')} />}
      {datos?.configurado && <OperacionMoodle editable={puede(colegio, 'mdl.conector.configurar')} sincroniza={puede(colegio, 'mdl.conector.sincronizar')} />}
    </>
  );
}

function FormularioMoodle({ datos, editable }: { datos: Conexion; editable: boolean }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const [f, setF] = useState({ url: datos.url ?? '', token: '', sso_activo: datos.sso_activo, frecuencia_minutos: datos.frecuencia_minutos });
  const [guardado, setGuardado] = useState(datos);

  async function guardar(e: FormEvent) {
    e.preventDefault();
    await accion.ejecutar(async () => {
      setGuardado(await pedir<Conexion>('/moodle/conexion', { metodo: 'PUT', cuerpo: { ...f, token: f.token || null } }));
      setF({ ...f, token: '' });
    }, 'Conector guardado.');
  }

  return (
    <>
      <div className="tarjeta" style={{ maxWidth: 720 }}>
        <h2>Conector</h2>
        <p className="ayuda">
          {guardado.configurado ? (
            <>
              Configurado · token {guardado.tiene_token ? 'guardado' : 'pendiente'} · última sincronización {fechaHora(guardado.ultima_sincronizacion_at)}
            </>
          ) : (
            'Aún no está configurado.'
          )}
        </p>
        <form onSubmit={guardar} noValidate>
          <div className="campos">
            <Campo etiqueta="Dirección de Moodle" ancho ayuda="Por ejemplo https://aula.tucolegio.edu.pe">
              <input type="url" value={f.url} disabled={!editable} onChange={(e) => setF({ ...f, url: e.target.value })} />
            </Campo>
            <Campo etiqueta="Token del servicio web" ancho ayuda={guardado.tiene_token ? 'Ya hay un token guardado. Escribe uno solo para cambiarlo.' : 'Se guarda cifrado y no se vuelve a mostrar.'}>
              <input type="password" autoComplete="off" value={f.token} disabled={!editable} onChange={(e) => setF({ ...f, token: e.target.value })} />
            </Campo>
            <Campo etiqueta="Sincronizar cada">
              <select value={f.frecuencia_minutos} disabled={!editable} onChange={(e) => setF({ ...f, frecuencia_minutos: Number(e.target.value) })}>
                {[15, 30, 60, 180, 720, 1440].map((m) => (
                  <option key={m} value={m}>
                    {m < 60 ? `${m} minutos` : m === 60 ? '1 hora' : m === 1440 ? '1 día' : `${m / 60} horas`}
                  </option>
                ))}
              </select>
            </Campo>
            <label className="casilla" style={{ alignSelf: 'end' }}>
              <input type="checkbox" checked={f.sso_activo} disabled={!editable} onChange={(e) => setF({ ...f, sso_activo: e.target.checked })} />
              Entrar a Moodle sin otra contraseña
            </label>
          </div>
          <Aviso tipo="error">{accion.error}</Aviso>
          <Aviso tipo="ok">{accion.ok}</Aviso>
          {editable && (
            <div className="acciones">
              <button type="submit" className="boton" disabled={accion.enviando}>
                {accion.enviando ? 'Guardando…' : 'Guardar'}
              </button>
            </div>
          )}
        </form>
      </div>
    </>
  );
}

type Resumen = { cursos: { creados: number; pendientes: number; con_error: number }; usuarios: { activos: number; suspendidos: number } | null; notas_pendientes: number; tiene_secreto_sso: boolean; simulado: boolean; alojado_por_edunova: boolean };
type Sincronizacion = { id: number; tipo: string; iniciada_at: string; registros: number; errores: number; detalle: { errores?: string[] } | null };
type Imagen = { color: string; scss_previo: string; scss: string; pasos: string[] };

/** S30 · Prueba, ingreso único, sincronización, bitácora, tabla de conversión e imagen del colegio. */
function OperacionMoodle({ editable, sincroniza }: { editable: boolean; sincroniza: boolean }) {
  const { pedir } = useSesion();
  const resumen = useCarga(() => pedir<Resumen>('/moodle/resumen'));
  const bitacora = useCarga(() => pedir<{ data: Sincronizacion[] }>('/moodle/bitacora'));
  const conversion = useCarga(() => (editable ? pedir<{ rangos: { desde: number; valor: string }[] }>('/moodle/conversion') : Promise.resolve({ rangos: [] })));
  const [secreto, setSecreto] = useState<string | null>(null);
  const [imagen, setImagen] = useState<Imagen | null>(null);
  const [rangos, setRangos] = useState<{ desde: number; valor: string }[] | null>(null);
  const accion = useAccion();
  const r = resumen.datos;
  const recargar = () => {
    resumen.recargar();
    bitacora.recargar();
  };
  return (
    <>
      <Aviso tipo="error">{accion.error ?? resumen.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {r && (
        <div className="tarjeta" style={{ maxWidth: 720 }}>
          <h2>Estado</h2>
          {r.simulado && <Aviso tipo="ambar">Conector en modo simulado: no se conecta a un Moodle real (servidor de pruebas).</Aviso>}
          <p>Cursos: {r.cursos.creados} creados · {r.cursos.pendientes} por crear{r.cursos.con_error ? ` · ${r.cursos.con_error} con error` : ''}. Usuarios: {r.usuarios?.activos ?? 0} activos · {r.usuarios?.suspendidos ?? 0} suspendidos. Notas por aprobar: {r.notas_pendientes}.</p>
          <div className="acciones">
            {editable && <button type="button" className="boton boton-borde" disabled={accion.enviando} onClick={() => accion.ejecutar(async () => {
              const p = await pedir<{ ok: boolean; sitio?: string; version?: string; mensaje?: string }>('/moodle/conexion/prueba', { metodo: 'POST' });
              if (!p.ok) throw new Error(p.mensaje ?? 'No se pudo conectar.');
              accion.setOk(`Conexión correcta: ${p.sitio} · ${p.version}.`);
            })}>Probar conexión</button>}
            {sincroniza && <button type="button" className="boton" disabled={accion.enviando} onClick={() => accion.ejecutar(async () => {
              const s = await pedir<{ cursos: { registros: number }; usuarios: { registros: number }; notas: { registros: number } }>('/moodle/sincronizacion', { metodo: 'POST', cuerpo: { tipo: 'todo' } });
              recargar();
              accion.setOk(`Sincronizado: ${s.cursos.registros} curso(s), ${s.usuarios.registros} matrícula(s) o cambio(s) y ${s.notas.registros} nota(s).`);
            })}>{accion.enviando ? 'Sincronizando…' : 'Sincronizar ahora'}</button>}
            {editable && <button type="button" className="boton boton-borde" onClick={() => window.confirm(r.tiene_secreto_sso ? 'Generar otro secreto deja de servir el anterior: tendrás que pegarlo de nuevo en Moodle. ¿Continuar?' : 'Se mostrará una sola vez. ¿Generar?') && accion.ejecutar(async () => { setSecreto((await pedir<{ secreto: string }>('/moodle/sso/secreto', { metodo: 'POST' })).secreto); resumen.recargar(); })}>{r.tiene_secreto_sso ? 'Generar otro secreto de ingreso único' : 'Generar secreto de ingreso único'}</button>}
            {editable && <button type="button" className="boton boton-borde" onClick={() => accion.ejecutar(async () => setImagen(await pedir<Imagen>('/moodle/imagen')))}>Imagen del colegio en Moodle</button>}
          </div>
          {secreto && <Aviso tipo="ambar">Copia este secreto en Moodle (Extensiones → Autenticación → Edunova). No se volverá a mostrar: <code style={{ userSelect: 'all' }}>{secreto}</code></Aviso>}
        </div>
      )}
      {imagen && (
        <div className="tarjeta" style={{ maxWidth: 720 }}>
          <h2>Imagen del colegio en Moodle</h2>
          <ol>{imagen.pasos.map((p) => <li key={p}>{p}</li>)}</ol>
          <Campo etiqueta="SCSS inicial" ancho><textarea readOnly rows={2} value={imagen.scss_previo} /></Campo>
          <Campo etiqueta="SCSS sin procesar" ancho><textarea readOnly rows={4} value={imagen.scss} /></Campo>
        </div>
      )}
      {editable && conversion.datos && (
        <div className="tarjeta" style={{ maxWidth: 720 }}>
          <h2>Conversión de notas de Moodle</h2>
          <p className="ayuda">Porcentaje mínimo de la nota máxima de Moodle para cada calificación.</p>
          <div className="filtros">
            {(rangos ?? conversion.datos.rangos).map((x, i) => (
              <Campo key={x.valor} etiqueta={`${x.valor} desde (%)`}><input type="number" min={0} max={100} value={x.desde} onChange={(e) => setRangos((rangos ?? conversion.datos!.rangos).map((y, j) => (j === i ? { ...y, desde: Number(e.target.value) } : y)))} /></Campo>
            ))}
          </div>
          {rangos && <button type="button" className="boton" onClick={() => accion.ejecutar(async () => { await pedir('/moodle/conversion', { metodo: 'PUT', cuerpo: { rangos } }); setRangos(null); conversion.recargar(); accion.setOk('Tabla de conversión guardada.'); })}>Guardar tabla</button>}
        </div>
      )}
      {bitacora.datos && bitacora.datos.data.length > 0 && (
        <div className="tarjeta"><h2>Bitácora</h2><div className="tabla-caja"><table className="tabla"><thead><tr><th>Cuándo</th><th>Qué</th><th>Registros</th><th>Errores</th></tr></thead><tbody>
          {bitacora.datos.data.map((b) => <tr key={b.id}><td>{fechaHora(b.iniciada_at)}</td><td>{b.tipo}</td><td>{b.registros}</td><td>{b.errores ? <span className="insignia insignia-rojo">{b.errores}</span> : '0'}{b.detalle?.errores?.[0] && <><br /><small className="ayuda">{b.detalle.errores[0]}</small></>}</td></tr>)}
        </tbody></table></div></div>
      )}
    </>
  );
}

type NotaMoodle = { id: number; item_nombre: string; nota_numerica: number; valor_convertido: string; estado: string; area_curricular_id: number; curso: string; alumno: string };

/** EDUN-148 · El docente aprueba las notas de Moodle (con la competencia y el periodo) o las descarta. */
export function NotasMoodle() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos, error, recargar } = useCarga(() => pedir<{ data: NotaMoodle[]; periodos: { id: number; nombre: string; estado: string }[] }>('/moodle/notas'));
  const [marcadas, setMarcadas] = useState<number[]>([]);
  const [destino, setDestino] = useState({ competencia_id: '', periodo_id: '' });
  const area = datos?.data.find((n) => marcadas.includes(n.id))?.area_curricular_id;
  const competencias = useCarga(() => (area ? pedir<{ data: { id: number; nombre: string }[] }>(`/moodle/competencias?area_curricular_id=${area}`) : Promise.resolve({ data: [] })), [area]);
  const accion = useAccion();
  const resolver = (decision: 'aprobar' | 'descartar') => accion.ejecutar(async () => {
    const r = await pedir<{ procesadas: number; errores: string[] }>('/moodle/notas/resolucion', { metodo: 'POST', cuerpo: { ids: marcadas, decision, competencia_id: destino.competencia_id ? Number(destino.competencia_id) : null, periodo_id: destino.periodo_id ? Number(destino.periodo_id) : null } });
    setMarcadas([]);
    recargar();
    if (r.errores.length) accion.setError(`${r.procesadas} procesada(s). No se registraron: ${r.errores.join(' · ')}`);
    else accion.setOk(decision === 'aprobar' ? `${r.procesadas} nota(s) pasaron al registro oficial.` : `${r.procesadas} nota(s) descartada(s).`);
  });
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Aula virtual`} titulo="Notas de Moodle" descripcion="Las notas llegan de Moodle como pendientes, ya convertidas a la escala del colegio. Elige la competencia y el periodo y apruébalas para pasarlas al registro oficial." />
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : datos.data.length === 0 ? <p className="vacio">No hay notas pendientes de Moodle.</p> : (
        <>
          <div className="filtros">
            <Campo etiqueta="Competencia destino"><select value={destino.competencia_id} onChange={(e) => setDestino({ ...destino, competencia_id: e.target.value })} disabled={!area}><option value="">{area ? 'Elige…' : 'Marca notas de un curso'}</option>{(competencias.datos?.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}</select></Campo>
            <Campo etiqueta="Periodo"><select value={destino.periodo_id} onChange={(e) => setDestino({ ...destino, periodo_id: e.target.value })}><option value="">Elige…</option>{datos.periodos.map((p) => <option key={p.id} value={p.id} disabled={p.estado === 'cerrado'}>{p.nombre}{p.estado === 'cerrado' ? ' (cerrado)' : ''}</option>)}</select></Campo>
            <div className="acciones">
              <button type="button" className="boton" disabled={!marcadas.length || !destino.competencia_id || !destino.periodo_id || accion.enviando} onClick={() => resolver('aprobar')}>Aprobar {marcadas.length || ''}</button>
              <button type="button" className="boton boton-borde" disabled={!marcadas.length || accion.enviando} onClick={() => resolver('descartar')}>Descartar</button>
            </div>
          </div>
          <div className="tarjeta"><div className="tabla-caja"><table className="tabla"><thead><tr><th aria-label="Marcar" /><th>Curso</th><th>Ítem de Moodle</th><th>Alumno</th><th>Nota Moodle</th><th>Escala del colegio</th></tr></thead><tbody>
            {datos.data.map((n) => (
              <tr key={n.id}>
                <td><input type="checkbox" aria-label={`Marcar ${n.alumno}`} checked={marcadas.includes(n.id)} disabled={area !== undefined && n.area_curricular_id !== area} onChange={(e) => setMarcadas(e.target.checked ? [...marcadas, n.id] : marcadas.filter((x) => x !== n.id))} /></td>
                <td>{n.curso}</td><td>{n.item_nombre}</td><td>{n.alumno}</td><td>{n.nota_numerica}</td><td><b>{n.valor_convertido}</b></td>
              </tr>
            ))}
          </tbody></table></div></div>
        </>
      )}
    </>
  );
}

/** EDUN-144 · Botón «Entrar a Moodle» sin otra contraseña (enlace firmado de un minuto). */
export function EntrarMoodle() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const accion = useAccion();
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Aula virtual`} titulo="Entrar a Moodle" descripcion="Abre el Moodle del colegio con tu misma cuenta, sin escribir otra contraseña." />
      <Aviso tipo="error">{accion.error}</Aviso>
      <button type="button" className="boton" disabled={accion.enviando} onClick={() => accion.ejecutar(async () => { window.location.href = (await pedir<{ url: string }>('/moodle/sso')).url; })}>Entrar a Moodle</button>
    </>
  );
}
