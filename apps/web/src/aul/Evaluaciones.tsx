import { useEffect, useState } from 'react';
import { useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo } from '../panel/ui';
import { fechaHora, useAccion, useCarga } from '../panel/utilidades';
import type { CuestionarioItem, Curso } from './Aula';

type Tipo = 'opcion_multiple' | 'verdadero_falso' | 'relacionar' | 'respuesta_corta';
type Pregunta = { id: number; tipo: Tipo; enunciado: string; opciones: string[] | { izquierda: string[]; derecha: string[] } | null; respuesta_correcta?: unknown; retroalimentacion?: string | null; activa?: boolean; generada_ia?: boolean; revisada?: boolean };
type Banco = { id: number; nombre: string; alcance: 'area' | 'curso'; activas: number; por_revisar: number };
const TIPO: Record<Tipo, string> = { opcion_multiple: 'Opción múltiple', verdadero_falso: 'Verdadero o falso', relacionar: 'Relacionar', respuesta_corta: 'Respuesta corta' };

/** EDUN-128 / 129 / 137 / 138 · Bancos de preguntas (con IA revisada) y cuestionarios del curso. */
export function CuestionariosDocente({ curso: c, recargar }: { curso: Curso; recargar: () => void }) {
  const { pedir } = useSesion();
  const bancos = useCarga(() => pedir<{ data: Banco[]; ia: 'claude' | 'simulado' }>(`/aula/cursos/${c.id}/bancos`), [c.id]);
  const [banco, setBanco] = useState<number | null>(null);
  const [nuevoQ, setNuevoQ] = useState(false);
  const [verQ, setVerQ] = useState<number | null>(null);
  const accion = useAccion();
  const cuestionarios = c.unidades.flatMap((u) => u.cuestionarios.map((q) => ({ ...q, unidad: u.titulo })));
  return (
    <>
      <Aviso tipo="error">{bancos.error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="tarjeta">
        <div className="titulo-fila"><h2>Cuestionarios</h2><button type="button" className="boton" disabled={!c.unidades.length} onClick={() => setNuevoQ(true)}>Nuevo cuestionario</button></div>
        {cuestionarios.length === 0 ? <p className="vacio">Sin cuestionarios.</p> : <ul className="lista-simple">{cuestionarios.map((q) => (
          <li key={q.id}>{q.titulo} · {q.unidad} {q.publicado_at ? <span className="insignia insignia-ok">Publicado</span> : <span className="insignia insignia-gris">Borrador</span>}{' '}
            <button type="button" className="boton boton-borde boton-chico" onClick={() => setVerQ(q.id)}>Resultados</button>
            {!q.publicado_at && <button type="button" className="boton boton-chico" onClick={() => accion.ejecutar(async () => { await pedir(`/aula/cursos/${c.id}/cuestionarios/${q.id}/publicacion`, { metodo: 'POST' }); recargar(); })}>Publicar</button>}
          </li>
        ))}</ul>}
      </div>
      <div className="tarjeta">
        <div className="titulo-fila"><h2>Bancos de preguntas</h2>
          <button type="button" className="boton boton-borde" onClick={() => {
            const nombre = window.prompt('Nombre del banco:');
            if (!nombre?.trim()) return;
            const general = window.confirm('¿Banco general del área (compartido entre secciones y años)? Cancela para que sea solo de este curso.');
            void accion.ejecutar(async () => { await pedir(`/aula/cursos/${c.id}/bancos`, { metodo: 'POST', cuerpo: { nombre, alcance: general ? 'area' : 'curso' } }); bancos.recargar(); });
          }}>Nuevo banco</button>
        </div>
        {(bancos.datos?.data ?? []).length === 0 ? <p className="vacio">Crea un banco para guardar preguntas y reutilizarlas.</p> : (
          <ul className="lista-simple">{bancos.datos!.data.map((b) => (
            <li key={b.id}><button type="button" className="enlace" onClick={() => setBanco(b.id)}>{b.nombre}</button> · {b.alcance === 'area' ? 'General del área' : 'De este curso'} · {b.activas} activas{b.por_revisar > 0 && <span className="insignia insignia-ambar">{b.por_revisar} de IA por revisar</span>}</li>
          ))}</ul>
        )}
      </div>
      {banco && <BancoPreguntas curso={c} bancoId={banco} ia={bancos.datos?.ia ?? 'simulado'} alCerrar={() => { setBanco(null); bancos.recargar(); }} />}
      {nuevoQ && <NuevoCuestionario curso={c} bancos={bancos.datos?.data ?? []} alTerminar={() => { setNuevoQ(false); recargar(); }} />}
      {verQ && <ResultadosCuestionario cursoId={c.id} id={verQ} alCerrar={() => setVerQ(null)} />}
    </>
  );
}

function BancoPreguntas({ curso: c, bancoId, ia, alCerrar }: { curso: Curso; bancoId: number; ia: string; alCerrar: () => void }) {
  const { pedir } = useSesion();
  const { datos, recargar } = useCarga(() => pedir<{ data: Pregunta[] }>(`/aula/cursos/${c.id}/bancos/${bancoId}/preguntas`), [bancoId]);
  const [f, setF] = useState({ tipo: 'opcion_multiple' as Tipo, enunciado: '', opciones: 'Opción A\nOpción B\nOpción C\nOpción D', correcta: '0', izquierda: '', derecha: '', retro: '' });
  const [gen, setGen] = useState({ tema: '', cantidad: 5, competencia_id: '' });
  const accion = useAccion();
  const guardar = () => accion.ejecutar(async () => {
    const lineas = (t: string) => t.split('\n').map((x) => x.trim()).filter(Boolean);
    let cuerpo: Record<string, unknown> = { tipo: f.tipo, enunciado: f.enunciado, retroalimentacion: f.retro || null };
    if (f.tipo === 'opcion_multiple') cuerpo = { ...cuerpo, opciones: lineas(f.opciones), respuesta_correcta: Number(f.correcta) };
    if (f.tipo === 'verdadero_falso') cuerpo = { ...cuerpo, respuesta_correcta: f.correcta === 'true' };
    if (f.tipo === 'respuesta_corta') cuerpo = { ...cuerpo, respuesta_correcta: lineas(f.opciones) };
    if (f.tipo === 'relacionar') {
      const izq = lineas(f.izquierda);
      const der = lineas(f.derecha);
      // Se escriben en el mismo orden (cada fila de la izquierda con su pareja); se guardan mezcladas.
      const orden = der.map((_, i) => i).sort(() => Math.random() - 0.5);
      cuerpo = { ...cuerpo, opciones: { izquierda: izq, derecha: orden.map((i) => der[i]) }, respuesta_correcta: Object.fromEntries(izq.map((_, i) => [String(i), orden.indexOf(i)])) };
    }
    await pedir(`/aula/cursos/${c.id}/bancos/${bancoId}/preguntas`, { metodo: 'POST', cuerpo });
    setF({ ...f, enunciado: '', retro: '' });
    recargar();
  });
  return (
    <Dialogo titulo="Banco de preguntas" alCerrar={alCerrar}>
      <Aviso tipo="error">{accion.error}</Aviso>
      <details><summary>Generar preguntas con IA {ia === 'simulado' && '(sin clave: preguntas de ejemplo)'}</summary>
        <div className="campos">
          <Campo etiqueta="Tema"><input value={gen.tema} onChange={(e) => setGen({ ...gen, tema: e.target.value })} placeholder="Ej.: fracciones equivalentes" /></Campo>
          <Campo etiqueta="Cantidad"><input type="number" min={1} max={10} value={gen.cantidad} onChange={(e) => setGen({ ...gen, cantidad: Number(e.target.value) })} /></Campo>
          <Campo etiqueta="Competencia"><select value={gen.competencia_id} onChange={(e) => setGen({ ...gen, competencia_id: e.target.value })}><option value="">—</option>{(c.competencias ?? []).map((x) => <option key={x.id} value={x.id}>{x.nombre}</option>)}</select></Campo>
        </div>
        <button type="button" className="boton boton-chico" disabled={accion.enviando || gen.tema.trim().length < 3} onClick={() => accion.ejecutar(async () => {
          const r = await pedir<{ data: Pregunta[] }>(`/aula/cursos/${c.id}/bancos/${bancoId}/ia`, { metodo: 'POST', cuerpo: { ...gen, competencia_id: gen.competencia_id ? Number(gen.competencia_id) : null } });
          recargar();
          accion.setOk(`${r.data.length} pregunta(s) sugerida(s). Revísalas antes de usarlas.`);
        })}>{accion.enviando ? 'Generando…' : 'Generar'}</button>
        <Aviso tipo="ok">{accion.ok}</Aviso>
      </details>
      <h3>Nueva pregunta</h3>
      <div className="campos">
        <Campo etiqueta="Tipo"><select value={f.tipo} onChange={(e) => setF({ ...f, tipo: e.target.value as Tipo, correcta: e.target.value === 'verdadero_falso' ? 'true' : '0', opciones: e.target.value === 'respuesta_corta' ? '' : f.opciones })}>{Object.entries(TIPO).map(([v, n]) => <option key={v} value={v}>{n}</option>)}</select></Campo>
        <Campo etiqueta="Enunciado" ancho><textarea rows={2} value={f.enunciado} onChange={(e) => setF({ ...f, enunciado: e.target.value })} /></Campo>
        {f.tipo === 'opcion_multiple' && <>
          <Campo etiqueta="Opciones (una por línea)" ancho><textarea rows={4} value={f.opciones} onChange={(e) => setF({ ...f, opciones: e.target.value })} /></Campo>
          <Campo etiqueta="Correcta"><select value={f.correcta} onChange={(e) => setF({ ...f, correcta: e.target.value })}>{f.opciones.split('\n').filter((x) => x.trim()).map((o, i) => <option key={i} value={i}>{o}</option>)}</select></Campo>
        </>}
        {f.tipo === 'verdadero_falso' && <Campo etiqueta="Respuesta"><select value={f.correcta} onChange={(e) => setF({ ...f, correcta: e.target.value })}><option value="true">Verdadero</option><option value="false">Falso</option></select></Campo>}
        {f.tipo === 'respuesta_corta' && <Campo etiqueta="Respuestas aceptadas (una por línea)" ayuda="No importan tildes ni mayúsculas." ancho><textarea rows={3} value={f.opciones} onChange={(e) => setF({ ...f, opciones: e.target.value })} /></Campo>}
        {f.tipo === 'relacionar' && <>
          <Campo etiqueta="Columna izquierda (una por línea)"><textarea rows={4} value={f.izquierda} onChange={(e) => setF({ ...f, izquierda: e.target.value })} /></Campo>
          <Campo etiqueta="Su pareja, en el mismo orden" ayuda="Al alumno se le muestran mezcladas."><textarea rows={4} value={f.derecha} onChange={(e) => setF({ ...f, derecha: e.target.value })} /></Campo>
        </>}
        <Campo etiqueta="Retroalimentación (opcional)" ancho><input value={f.retro} onChange={(e) => setF({ ...f, retro: e.target.value })} /></Campo>
      </div>
      <button type="button" className="boton" disabled={accion.enviando || f.enunciado.trim().length < 3} onClick={guardar}>Agregar pregunta</button>
      <h3>Preguntas</h3>
      {!datos ? <p role="status">Cargando…</p> : datos.data.length === 0 ? <p className="vacio">Sin preguntas.</p> : (
        <ul className="lista-simple">{datos.data.map((p) => (
          <li key={p.id}>{p.enunciado} <small className="ayuda">{TIPO[p.tipo]}</small>
            {p.generada_ia && <span className={`insignia ${p.revisada ? 'insignia-azul' : 'insignia-ambar'}`}>IA{p.revisada ? ' revisada' : ' · por revisar'}</span>}
            {!p.activa && <span className="insignia insignia-gris">Inactiva</span>}
            {p.generada_ia && !p.revisada && <>
              {' '}<button type="button" className="boton boton-chico" onClick={() => accion.ejecutar(async () => { await pedir(`/aula/cursos/${c.id}/bancos/${bancoId}/preguntas/${p.id}/revision`, { metodo: 'POST', cuerpo: { aprobar: true } }); recargar(); })}>Aprobar</button>
              <button type="button" className="boton boton-borde boton-chico" onClick={() => accion.ejecutar(async () => { await pedir(`/aula/cursos/${c.id}/bancos/${bancoId}/preguntas/${p.id}/revision`, { metodo: 'POST', cuerpo: { aprobar: false } }); recargar(); })}>Descartar</button>
            </>}
          </li>
        ))}</ul>
      )}
    </Dialogo>
  );
}

function NuevoCuestionario({ curso: c, bancos, alTerminar }: { curso: Curso; bancos: Banco[]; alTerminar: () => void }) {
  const { pedir } = useSesion();
  const [f, setF] = useState({ aula_unidad_id: String(c.unidades[0]?.id ?? ''), titulo: '', minutos: '', intentos_max: 1, conserva: 'mejor', mostrar_respuestas: 'al_terminar', requiere_previos: true, aula_banco_id: String(bancos[0]?.id ?? ''), preguntas_azar: 10, abre_at: '', cierra_at: '' });
  const accion = useAccion();
  return (
    <Dialogo titulo="Nuevo cuestionario" alCerrar={alTerminar}>
      <Aviso tipo="error">{accion.error}</Aviso>
      {bancos.length === 0 ? <p className="vacio">Primero crea un banco con preguntas.</p> : (
        <div className="campos">
          <Campo etiqueta="Unidad"><select value={f.aula_unidad_id} onChange={(e) => setF({ ...f, aula_unidad_id: e.target.value })}>{c.unidades.map((u) => <option key={u.id} value={u.id}>{u.titulo}</option>)}</select></Campo>
          <Campo etiqueta="Título"><input value={f.titulo} onChange={(e) => setF({ ...f, titulo: e.target.value })} maxLength={150} /></Campo>
          <Campo etiqueta="Banco"><select value={f.aula_banco_id} onChange={(e) => setF({ ...f, aula_banco_id: e.target.value })}>{bancos.map((b) => <option key={b.id} value={b.id}>{b.nombre} ({b.activas})</option>)}</select></Campo>
          <Campo etiqueta="Preguntas al azar"><input type="number" min={1} max={100} value={f.preguntas_azar} onChange={(e) => setF({ ...f, preguntas_azar: Number(e.target.value) })} /></Campo>
          <Campo etiqueta="Tiempo (minutos, opcional)"><input type="number" min={1} max={300} value={f.minutos} onChange={(e) => setF({ ...f, minutos: e.target.value })} /></Campo>
          <Campo etiqueta="Intentos"><input type="number" min={1} max={10} value={f.intentos_max} onChange={(e) => setF({ ...f, intentos_max: Number(e.target.value) })} /></Campo>
          <Campo etiqueta="Nota que se conserva"><select value={f.conserva} onChange={(e) => setF({ ...f, conserva: e.target.value })}><option value="mejor">La mejor</option><option value="ultimo">La última</option><option value="promedio">El promedio</option></select></Campo>
          <Campo etiqueta="Mostrar respuestas"><select value={f.mostrar_respuestas} onChange={(e) => setF({ ...f, mostrar_respuestas: e.target.value })}><option value="al_terminar">Al terminar</option><option value="al_cerrar">Al cerrar el cuestionario</option><option value="nunca">Nunca</option></select></Campo>
          <Campo etiqueta="Abre (opcional)"><input type="datetime-local" value={f.abre_at} onChange={(e) => setF({ ...f, abre_at: e.target.value })} /></Campo>
          <Campo etiqueta="Cierra (opcional)"><input type="datetime-local" value={f.cierra_at} onChange={(e) => setF({ ...f, cierra_at: e.target.value })} /></Campo>
          <label className="campo"><span><input type="checkbox" checked={f.requiere_previos} onChange={(e) => setF({ ...f, requiere_previos: e.target.checked })} /> Se habilita al completar los materiales de la unidad</span></label>
        </div>
      )}
      <div className="acciones">
        {[true, false].map((publicar) => (
          <button key={String(publicar)} type="button" className={publicar ? 'boton' : 'boton boton-borde'} disabled={accion.enviando || !bancos.length || f.titulo.trim().length < 3} onClick={() => accion.ejecutar(async () => {
            await pedir(`/aula/cursos/${c.id}/cuestionarios`, { metodo: 'POST', cuerpo: {
              ...f, aula_unidad_id: Number(f.aula_unidad_id), aula_banco_id: Number(f.aula_banco_id), minutos: f.minutos ? Number(f.minutos) : null,
              abre_at: f.abre_at ? new Date(f.abre_at).toISOString() : null, cierra_at: f.cierra_at ? new Date(f.cierra_at).toISOString() : null, publicar,
            } });
            alTerminar();
          })}>{publicar ? 'Guardar y publicar' : 'Guardar borrador'}</button>
        ))}
      </div>
    </Dialogo>
  );
}

function ResultadosCuestionario({ cursoId, id, alCerrar }: { cursoId: number; id: number; alCerrar: () => void }) {
  const { pedir } = useSesion();
  const { datos, recargar } = useCarga(() => pedir<{ titulo: string; resultados: { alumno_id: number; alumno: string; intentos: number; extra: number; nota_final: { porcentaje: number; nota: string } | null }[] }>(`/aula/cursos/${cursoId}/cuestionarios/${id}`), [id]);
  const accion = useAccion();
  return (
    <Dialogo titulo={datos ? `Resultados: ${datos.titulo}` : 'Resultados'} alCerrar={alCerrar}>
      <Aviso tipo="error">{accion.error}</Aviso>
      {!datos ? <p role="status">Cargando…</p> : (
        <div className="tabla-caja"><table className="tabla"><thead><tr><th>Alumno</th><th>Intentos</th><th>Nota</th><th /></tr></thead><tbody>
          {datos.resultados.map((r) => <tr key={r.alumno_id}><td>{r.alumno}</td><td>{r.intentos}{r.extra ? ` (+${r.extra} extra)` : ''}</td><td>{r.nota_final ? `${r.nota_final.nota} · ${r.nota_final.porcentaje} %` : '—'}</td>
            <td><button type="button" className="boton boton-borde boton-chico" onClick={() => accion.ejecutar(async () => { await pedir(`/aula/cursos/${cursoId}/cuestionarios/${id}/intentos-extra`, { metodo: 'POST', cuerpo: { alumno_id: r.alumno_id, cantidad: 1 } }); recargar(); })}>Dar un intento más</button></td></tr>)}
        </tbody></table></div>
      )}
    </Dialogo>
  );
}

type Intento = { intento_id: number; numero: number; termina_at: string | null; preguntas: Pregunta[] };
type Resultado = { porcentaje: number; nota: string; intentos_disponibles: number; nota_final: { nota: string; porcentaje: number } | null; revision: { id: number; correcta: boolean; retroalimentacion: string | null }[] | null };

/** EDUN-130 · El alumno rinde el cuestionario: preguntas sin respuestas, tiempo límite y corrección automática. */
export function RendirCuestionario({ cursoId, cuestionario, alCerrar }: { cursoId: number; cuestionario: CuestionarioItem; alCerrar: () => void }) {
  const { pedir } = useSesion();
  const [intento, setIntento] = useState<Intento | null>(null);
  const [respuestas, setRespuestas] = useState<Record<number, unknown>>({});
  const [resultado, setResultado] = useState<Resultado | null>(null);
  const [restante, setRestante] = useState<number | null>(null);
  const accion = useAccion();
  const enviar = () => accion.ejecutar(async () => setResultado(await pedir<Resultado>(`/aula/cursos/${cursoId}/cuestionarios/${cuestionario.id}/envio`, { metodo: 'POST', cuerpo: { respuestas } })));
  useEffect(() => {
    if (!intento?.termina_at || resultado) return;
    const t = setInterval(() => setRestante(Math.max(0, Math.floor((new Date(intento.termina_at!).getTime() - Date.now()) / 1000))), 1000);
    return () => clearInterval(t);
  }, [intento, resultado]);
  useEffect(() => {
    if (restante === 0 && !resultado && !accion.enviando) void enviar();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- se envía solo al llegar a cero
  }, [restante]);
  const revision = new Map((resultado?.revision ?? []).map((r) => [r.id, r]));
  return (
    <Dialogo titulo={cuestionario.titulo} alCerrar={alCerrar}>
      <Aviso tipo="error">{accion.error}</Aviso>
      {!intento ? (
        <>
          <p>{cuestionario.minutos ? `Tienes ${cuestionario.minutos} minutos. ` : ''}Intentos permitidos: {cuestionario.intentos_max}.{cuestionario.cierra_at ? ` Cierra el ${fechaHora(cuestionario.cierra_at)}.` : ''}</p>
          <button type="button" className="boton" disabled={accion.enviando} onClick={() => accion.ejecutar(async () => setIntento(await pedir<Intento>(`/aula/cursos/${cursoId}/cuestionarios/${cuestionario.id}/intento`, { metodo: 'POST' })))}>Empezar</button>
        </>
      ) : resultado ? (
        <>
          <Aviso tipo="ok">Nota {resultado.nota} ({resultado.porcentaje} %). {resultado.nota_final && `Nota que se conserva: ${resultado.nota_final.nota}.`} Te quedan {resultado.intentos_disponibles} intento(s).</Aviso>
          {resultado.revision && <ul className="lista-simple">{intento.preguntas.map((p) => <li key={p.id}>{revision.get(p.id)?.correcta ? '✓' : '✗'} {p.enunciado}{revision.get(p.id)?.retroalimentacion && <><br /><small className="ayuda">{revision.get(p.id)!.retroalimentacion}</small></>}</li>)}</ul>}
          <button type="button" className="boton" onClick={alCerrar}>Cerrar</button>
        </>
      ) : (
        <>
          {restante !== null && <p><b>Tiempo:</b> {Math.floor(restante / 60)}:{String(restante % 60).padStart(2, '0')}</p>}
          {intento.preguntas.map((p, i) => (
            <fieldset key={p.id} className="tarjeta">
              <legend>{i + 1}. {p.enunciado}</legend>
              {p.tipo === 'opcion_multiple' && (p.opciones as string[]).map((o, j) => <label key={j} className="casilla"><input type="radio" name={`p${p.id}`} checked={respuestas[p.id] === j} onChange={() => setRespuestas({ ...respuestas, [p.id]: j })} /> {o}</label>)}
              {p.tipo === 'verdadero_falso' && [true, false].map((v) => <label key={String(v)} className="casilla"><input type="radio" name={`p${p.id}`} checked={respuestas[p.id] === v} onChange={() => setRespuestas({ ...respuestas, [p.id]: v })} /> {v ? 'Verdadero' : 'Falso'}</label>)}
              {p.tipo === 'respuesta_corta' && <input aria-label="Tu respuesta" value={String(respuestas[p.id] ?? '')} onChange={(e) => setRespuestas({ ...respuestas, [p.id]: e.target.value })} />}
              {p.tipo === 'relacionar' && (() => {
                const o = p.opciones as { izquierda: string[]; derecha: string[] };
                const actual = (respuestas[p.id] ?? {}) as Record<string, number>;
                return o.izquierda.map((izq, k) => (
                  <label key={k} className="campo"><span>{izq}</span>
                    <select value={actual[k] ?? ''} onChange={(e) => setRespuestas({ ...respuestas, [p.id]: { ...actual, [k]: Number(e.target.value) } })}><option value="">—</option>{o.derecha.map((d, m) => <option key={m} value={m}>{d}</option>)}</select>
                  </label>
                ));
              })()}
            </fieldset>
          ))}
          <button type="button" className="boton" disabled={accion.enviando} onClick={() => window.confirm('¿Enviar tus respuestas?') && enviar()}>Enviar</button>
        </>
      )}
    </Dialogo>
  );
}
