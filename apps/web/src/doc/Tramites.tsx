import { useState } from 'react';
import { apiArchivo } from '../api/cliente';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Dialogo, Pestanas, Titulo } from '../panel/ui';
import { fecha, fechaHora, useAccion, useCarga } from '../panel/utilidades';

type EstadoTramite = 'solicitado' | 'observado' | 'en_revision' | 'por_firmar' | 'entregado' | 'rechazado';
type Tramite = {
  id: number; numero: string; estado: EstadoTramite; vence: string | null; created_at: string; comentario: string | null; tipo: string; alumno: string;
  solicitante: string; cargo_monto: number | null; pagado: boolean; con_plantilla: boolean; codigo: string | null; anulado: boolean; documento_emitido_id: number | null;
  semaforo: 'verde' | 'ambar' | 'rojo' | null;
};
type Detalle = Tramite & {
  historial: { estado: EstadoTramite; comentario: string | null; created_at: string; usuario: string }[];
  adjuntos: { id: number; nombre_original: string; requisito: string; created_at: string }[];
};
type Requisito = { id?: number; descripcion: string; obligatorio: boolean };
type Tipo = {
  id: number; nombre: string; descripcion: string | null; concepto_cobro_id: number | null; plazo_dias: number; requiere_sin_deuda: boolean;
  plantilla_documento_id: number | null; firma_cargo: string; activo: boolean; concepto: string | null; costo: number; plantilla: string | null; requisitos: Requisito[];
};
type Plantilla = { id: number; nombre: string; tipo: string; contenido_html: string; con_qr: boolean; version: number; en_uso: number; versiones: { id: number; version: number; updated_at: string }[] };

const ESTADO_TRAMITE: Record<EstadoTramite, { texto: string; clase: string }> = {
  solicitado: { texto: 'Solicitado', clase: 'insignia-ambar' },
  observado: { texto: 'Observado', clase: 'insignia-rojo' },
  en_revision: { texto: 'En revisión', clase: 'insignia-ambar' },
  por_firmar: { texto: 'Por firmar', clase: 'insignia-ambar' },
  entregado: { texto: 'Entregado', clase: 'insignia-ok' },
  rechazado: { texto: 'Rechazado', clase: 'insignia-rojo' },
};
const SEMAFORO = { verde: { texto: 'En plazo', clase: 'insignia-ok' }, ambar: { texto: 'Vence pronto', clase: 'insignia-ambar' }, rojo: { texto: 'Vencido', clase: 'insignia-rojo' } };
const soles = (n: number) => `S/ ${n.toFixed(2)}`;

/** Abre en otra pestaña la URL firmada que devuelve la API (documento o adjunto). */
function useAbrir() {
  const { pedir } = useSesion();
  const accion = useAccion();
  return { accion, abrir: (ruta: string) => accion.ejecutar(async () => window.open((await pedir<{ url: string }>(ruta)).url, '_blank', 'noopener')) };
}

/** EDUN-113 a 117 · Trámites: bandeja con semáforo y firma por lote, catálogo y plantillas. */
export function Tramites() {
  const { estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const opciones: ['bandeja' | 'catalogo' | 'plantillas', string][] = [];
  if (puede(colegio, 'doc.tramites.ver')) opciones.push(['bandeja', 'Bandeja']);
  if (puede(colegio, 'doc.plantillas.editar')) opciones.push(['catalogo', 'Catálogo de trámites']);
  if (puede(colegio, 'doc.plantillas.ver', 'doc.plantillas.editar')) opciones.push(['plantillas', 'Plantillas']);
  const [pestana, setPestana] = useState(opciones[0]?.[0] ?? 'bandeja');
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Documentos`} titulo="Trámites" descripcion="Las familias los piden desde la app con sus requisitos. Atiéndelos por etapa: observar, pasar a firma y entregar el documento con su código de verificación." />
      <Pestanas opciones={opciones} actual={pestana} alCambiar={setPestana} />
      {pestana === 'bandeja' && <Bandeja />}
      {pestana === 'catalogo' && <Catalogo />}
      {pestana === 'plantillas' && <Plantillas />}
    </>
  );
}

function Bandeja() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const firma = puede(colegio, 'doc.tramites.firmar');
  const [filtro, setFiltro] = useState<'activos' | 'todos' | EstadoTramite>('activos');
  const [buscar, setBuscar] = useState('');
  const [marcados, setMarcados] = useState<number[]>([]);
  const [abierto, setAbierto] = useState<number | null>(null);
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Tramite[] }>(`/tramites?estado=${filtro}${buscar.trim() ? `&buscar=${encodeURIComponent(buscar.trim())}` : ''}`), [filtro, buscar]);
  const accion = useAccion();
  const porFirmar = (datos?.data ?? []).filter((t) => t.estado === 'por_firmar' && t.con_plantilla);
  const firmar = () => accion.ejecutar(async () => {
    const r = await pedir<{ resultados: { id: number; ok: boolean; mensaje?: string }[] }>('/tramites/firmar', { metodo: 'POST', cuerpo: { ids: marcados } });
    const malos = r.resultados.filter((x) => !x.ok);
    setMarcados([]);
    recargar();
    if (malos.length) accion.setError(`${r.resultados.length - malos.length} firmado(s). No se firmaron: ${malos.map((m) => m.mensaje).join(' · ')}`);
    else accion.setOk(`${r.resultados.length} documento(s) firmado(s) y entregado(s) a las familias.`);
  });
  return (
    <>
      <Aviso tipo="error">{error ?? accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {firma && <MiFirma />}
      <div className="filtros">
        <Campo etiqueta="Mostrar">
          <select value={filtro} onChange={(e) => setFiltro(e.target.value as typeof filtro)}>
            <option value="activos">En curso</option>
            {Object.entries(ESTADO_TRAMITE).map(([v, e]) => <option key={v} value={v}>{e.texto}</option>)}
            <option value="todos">Todos</option>
          </select>
        </Campo>
        <Campo etiqueta="Buscar"><input value={buscar} onChange={(e) => setBuscar(e.target.value)} placeholder="N.° de trámite o alumno" /></Campo>
        {firma && porFirmar.length > 0 && (
          <div className="acciones">
            <button type="button" className="boton boton-borde" onClick={() => setMarcados(marcados.length === porFirmar.length ? [] : porFirmar.map((t) => t.id))}>{marcados.length === porFirmar.length ? 'Quitar selección' : `Seleccionar los ${porFirmar.length} por firmar`}</button>
            <button type="button" className="boton" disabled={!marcados.length || accion.enviando} onClick={firmar}>Firmar {marcados.length || ''} y entregar</button>
          </div>
        )}
      </div>
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : datos.data.length === 0 ? <p className="vacio">No hay trámites en esta vista.</p> : (
        <div className="tarjeta"><div className="tabla-caja">
          <table className="tabla"><thead><tr>{firma && <th aria-label="Seleccionar" />}<th>N.°</th><th>Trámite</th><th>Alumno</th><th>Plazo</th><th>Pago</th><th>Estado</th><th /></tr></thead><tbody>
            {datos.data.map((t) => (
              <tr key={t.id}>
                {firma && <td>{t.estado === 'por_firmar' && t.con_plantilla && <input type="checkbox" aria-label={`Firmar ${t.numero}`} checked={marcados.includes(t.id)} onChange={(e) => setMarcados(e.target.checked ? [...marcados, t.id] : marcados.filter((m) => m !== t.id))} />}</td>}
                <td>{t.numero}<br /><small className="ayuda">{fechaHora(t.created_at)}</small></td>
                <td>{t.tipo}</td>
                <td>{t.alumno}<br /><small className="ayuda">Pidió: {t.solicitante}</small></td>
                <td>{fecha(t.vence)}{t.semaforo && <><br /><span className={`insignia ${SEMAFORO[t.semaforo].clase}`}>{SEMAFORO[t.semaforo].texto}</span></>}</td>
                <td>{t.cargo_monto === null ? 'Sin costo' : <>{soles(t.cargo_monto)}<br /><span className={`insignia ${t.pagado ? 'insignia-ok' : 'insignia-ambar'}`}>{t.pagado ? 'Pagado' : 'Pendiente'}</span></>}</td>
                <td><span className={`insignia ${ESTADO_TRAMITE[t.estado].clase}`}>{ESTADO_TRAMITE[t.estado].texto}</span>{t.anulado && <><br /><span className="insignia insignia-rojo">Documento anulado</span></>}</td>
                <td><button type="button" className="boton boton-borde boton-chico" onClick={() => setAbierto(t.id)}>Abrir</button></td>
              </tr>
            ))}
          </tbody></table>
        </div></div>
      )}
      {abierto && <DetalleTramite id={abierto} alCerrar={() => setAbierto(null)} alCambiar={recargar} />}
    </>
  );
}

function MiFirma() {
  const { pedir } = useSesion();
  const { datos, recargar } = useCarga(() => pedir<{ tiene_firma: boolean }>('/documentos/mi-firma'));
  const accion = useAccion();
  if (!datos) return null;
  return (
    <div className="tarjeta">
      <p>{datos.tiene_firma ? 'Tu firma ya está cargada: se imprime en los documentos que firmas.' : 'Carga una imagen de tu firma (PNG o JPG con fondo blanco) para que aparezca en los documentos que firmas.'}</p>
      <Aviso tipo="error">{accion.error}</Aviso>
      <label className="boton boton-borde boton-chico">
        {datos.tiene_firma ? 'Cambiar mi firma' : 'Cargar mi firma'}
        <input type="file" accept="image/png,image/jpeg" hidden onChange={(e) => {
          const f = e.target.files?.[0];
          if (!f) return;
          const cuerpo = new FormData();
          cuerpo.append('firma', f);
          void accion.ejecutar(async () => { await pedir('/documentos/mi-firma', { metodo: 'POST', cuerpo }); recargar(); });
        }} />
      </label>
    </div>
  );
}

function DetalleTramite({ id, alCerrar, alCambiar }: { id: number; alCerrar: () => void; alCambiar: () => void }) {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const { datos: t, setDatos, error } = useCarga(() => pedir<Detalle>(`/tramites/${id}`), [id]);
  const [comentario, setComentario] = useState('');
  const [archivo, setArchivo] = useState<File | null>(null);
  const accion = useAccion();
  const { abrir, accion: apertura } = useAbrir();
  const atiende = puede(colegio, 'doc.tramites.atender');
  const firma = puede(colegio, 'doc.tramites.firmar');
  const mover = (a: 'atender' | 'observar' | 'a_firma' | 'rechazar') => accion.ejecutar(async () => {
    setDatos(await pedir<Detalle>(`/tramites/${id}/estado`, { metodo: 'POST', cuerpo: { accion: a, comentario: comentario || null } }));
    setComentario('');
    alCambiar();
  });
  return (
    <Dialogo titulo={t ? `${t.numero} · ${t.tipo}` : 'Trámite'} alCerrar={alCerrar}>
      <Aviso tipo="error">{error ?? accion.error ?? apertura.error}</Aviso>
      {!t ? <p role="status">Cargando…</p> : (
        <>
          <p><b>{t.alumno}</b> · pidió {t.solicitante} · <span className={`insignia ${ESTADO_TRAMITE[t.estado].clase}`}>{ESTADO_TRAMITE[t.estado].texto}</span></p>
          {t.comentario && <p className="ayuda">«{t.comentario}»</p>}
          {t.cargo_monto !== null && <p>Costo {soles(t.cargo_monto)}: {t.pagado ? 'pagado' : 'pendiente de pago (la familia lo paga en Mis pagos)'}.</p>}
          <h3>Requisitos adjuntos</h3>
          {t.adjuntos.length === 0 ? <p className="vacio">Sin archivos.</p> : (
            <ul className="lista-simple">{t.adjuntos.map((a) => (
              <li key={a.id}>{a.requisito}: <button type="button" className="enlace" onClick={() => abrir(`/tramites/${id}/adjuntos/${a.id}`)}>{a.nombre_original}</button></li>
            ))}</ul>
          )}
          <h3>Historial</h3>
          <ul className="lista-simple">{t.historial.map((h, i) => <li key={i}>{fechaHora(h.created_at)} · {ESTADO_TRAMITE[h.estado]?.texto ?? h.estado}{h.usuario && ` · ${h.usuario}`}{h.comentario && <><br /><small className="ayuda">{h.comentario}</small></>}</li>)}</ul>
          {t.documento_emitido_id && (
            <div className="acciones">
              <button type="button" className="boton boton-borde" onClick={() => abrir(`/tramites/${id}/documento`)}>Ver documento entregado</button>
              {t.codigo && <span className="ayuda">Código {t.codigo}</span>}
              {firma && !t.anulado && <button type="button" className="boton boton-borde" onClick={() => {
                const motivo = window.prompt('¿Por qué se anula el documento? La verificación pública dirá que ya no es válido.');
                if (motivo && motivo.trim().length >= 5) void accion.ejecutar(async () => { await pedir(`/documentos/emitidos/${t.documento_emitido_id}/anulacion`, { metodo: 'POST', cuerpo: { motivo } }); setDatos({ ...t, anulado: true }); alCambiar(); });
              }}>Anular documento</button>}
            </div>
          )}
          {atiende && ['solicitado', 'en_revision', 'observado', 'por_firmar'].includes(t.estado) && (
            <>
              <Campo etiqueta="Comentario para la familia" ayuda="Obligatorio para observar o rechazar." ancho><textarea rows={2} value={comentario} onChange={(e) => setComentario(e.target.value)} maxLength={500} /></Campo>
              <div className="acciones">
                {t.estado === 'solicitado' && <button type="button" className="boton boton-borde" disabled={accion.enviando} onClick={() => mover('atender')}>Tomar en revisión</button>}
                {['solicitado', 'en_revision'].includes(t.estado) && <button type="button" className="boton boton-borde" disabled={accion.enviando} onClick={() => mover('observar')}>Observar</button>}
                {['solicitado', 'en_revision'].includes(t.estado) && <button type="button" className="boton" disabled={accion.enviando} onClick={() => mover('a_firma')}>Pasar a firma</button>}
                <button type="button" className="boton boton-borde" disabled={accion.enviando} onClick={() => mover('rechazar')}>Rechazar</button>
              </div>
            </>
          )}
          {firma && t.estado === 'por_firmar' && !t.con_plantilla && (
            <div className="acciones">
              <Campo etiqueta="Documento firmado (PDF)" ayuda="Este trámite no tiene plantilla: sube el documento ya firmado."><input type="file" accept="application/pdf" onChange={(e) => setArchivo(e.target.files?.[0] ?? null)} /></Campo>
              <button type="button" className="boton" disabled={!archivo || accion.enviando} onClick={() => accion.ejecutar(async () => {
                const cuerpo = new FormData();
                cuerpo.append('documento', archivo!);
                setDatos(await pedir<Detalle>(`/tramites/${id}/entrega`, { metodo: 'POST', cuerpo }));
                alCambiar();
              })}>Entregar a la familia</button>
            </div>
          )}
        </>
      )}
      <div className="acciones"><button type="button" className="boton boton-borde" onClick={alCerrar}>Cerrar</button></div>
    </Dialogo>
  );
}

const TIPO_VACIO = { nombre: '', descripcion: '', concepto_cobro_id: '', plazo_dias: 3, requiere_sin_deuda: false, plantilla_documento_id: '', activo: true, requisitos: [] as Requisito[] };

function Catalogo() {
  const { pedir } = useSesion();
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Tipo[]; conceptos: { id: number; nombre: string; monto: number }[]; plantillas: { id: number; nombre: string; version: number }[] }>('/tramites/catalogo'));
  const [editando, setEditando] = useState<(typeof TIPO_VACIO & { id?: number }) | null>(null);
  const accion = useAccion();
  const guardar = () => accion.ejecutar(async () => {
    const e = editando!;
    const cuerpo = { ...e, concepto_cobro_id: e.concepto_cobro_id ? Number(e.concepto_cobro_id) : null, plantilla_documento_id: e.plantilla_documento_id ? Number(e.plantilla_documento_id) : null, plazo_dias: Number(e.plazo_dias), requisitos: e.requisitos.filter((r) => r.descripcion.trim()) };
    await pedir(e.id ? `/tramites/catalogo/${e.id}` : '/tramites/catalogo', { metodo: e.id ? 'PUT' : 'POST', cuerpo });
    setEditando(null);
    recargar();
    accion.setOk('Trámite guardado.');
  });
  return (
    <>
      <Aviso tipo="error">{error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="acciones"><button type="button" className="boton" onClick={() => setEditando({ ...TIPO_VACIO, requisitos: [{ descripcion: '', obligatorio: true }] })}>Nuevo trámite</button></div>
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : datos.data.length === 0 ? <p className="vacio">Aún no hay trámites en el catálogo. Crea, por ejemplo, «Constancia de estudios» o «Certificado de estudios».</p> : (
        <div className="tarjeta"><div className="tabla-caja">
          <table className="tabla"><thead><tr><th>Trámite</th><th>Costo</th><th>Plazo</th><th>Requisitos</th><th>Plantilla</th><th>Estado</th><th /></tr></thead><tbody>
            {datos.data.map((t) => (
              <tr key={t.id}>
                <td>{t.nombre}{t.requiere_sin_deuda && <><br /><small className="ayuda">Exige estar al día en pagos</small></>}</td>
                <td>{t.costo ? soles(t.costo) : 'Gratuito'}</td>
                <td>{t.plazo_dias} día(s) hábil(es)</td>
                <td>{t.requisitos.length ? t.requisitos.map((r) => r.descripcion).join(' · ') : '—'}</td>
                <td>{t.plantilla ?? 'Se sube el documento'}</td>
                <td><span className={`insignia ${t.activo ? 'insignia-ok' : 'insignia-gris'}`}>{t.activo ? 'Disponible' : 'Oculto'}</span></td>
                <td><button type="button" className="boton boton-borde boton-chico" onClick={() => setEditando({ id: t.id, nombre: t.nombre, descripcion: t.descripcion ?? '', concepto_cobro_id: t.concepto_cobro_id ? String(t.concepto_cobro_id) : '', plazo_dias: t.plazo_dias, requiere_sin_deuda: t.requiere_sin_deuda, plantilla_documento_id: t.plantilla_documento_id ? String(t.plantilla_documento_id) : '', activo: t.activo, requisitos: t.requisitos })}>Editar</button></td>
              </tr>
            ))}
          </tbody></table>
        </div></div>
      )}
      {editando && datos && (
        <Dialogo titulo={editando.id ? 'Editar trámite' : 'Nuevo trámite'} alCerrar={() => setEditando(null)}>
          <Aviso tipo="error">{accion.error}</Aviso>
          <div className="campos">
            <Campo etiqueta="Nombre"><input value={editando.nombre} onChange={(e) => setEditando({ ...editando, nombre: e.target.value })} maxLength={120} /></Campo>
            <Campo etiqueta="Plazo (días hábiles)"><input type="number" min={1} max={60} value={editando.plazo_dias} onChange={(e) => setEditando({ ...editando, plazo_dias: Number(e.target.value) })} /></Campo>
            <Campo etiqueta="Costo (concepto de cobro)" ayuda="Al pedirlo se genera el cargo; se firma solo cuando está pagado.">
              <select value={editando.concepto_cobro_id} onChange={(e) => setEditando({ ...editando, concepto_cobro_id: e.target.value })}>
                <option value="">Gratuito</option>
                {datos.conceptos.map((c) => <option key={c.id} value={c.id}>{c.nombre} · {soles(c.monto)}</option>)}
              </select>
            </Campo>
            <Campo etiqueta="Plantilla del documento" ayuda="Sin plantilla, el documento firmado se sube al entregar.">
              <select value={editando.plantilla_documento_id} onChange={(e) => setEditando({ ...editando, plantilla_documento_id: e.target.value })}>
                <option value="">Sin plantilla</option>
                {datos.plantillas.map((p) => <option key={p.id} value={p.id}>{p.nombre} (v{p.version})</option>)}
              </select>
            </Campo>
            <Campo etiqueta="Descripción para la familia" ancho><textarea rows={2} value={editando.descripcion} onChange={(e) => setEditando({ ...editando, descripcion: e.target.value })} maxLength={1000} /></Campo>
            <label className="campo"><span><input type="checkbox" checked={editando.requiere_sin_deuda} onChange={(e) => setEditando({ ...editando, requiere_sin_deuda: e.target.checked })} /> Exige estar al día (constancia de no adeudo)</span></label>
            <label className="campo"><span><input type="checkbox" checked={editando.activo} onChange={(e) => setEditando({ ...editando, activo: e.target.checked })} /> Disponible para las familias</span></label>
          </div>
          <h3>Requisitos</h3>
          {editando.requisitos.map((r, i) => (
            <div key={i} className="filtros">
              <Campo etiqueta={`Requisito ${i + 1}`}><input value={r.descripcion} onChange={(e) => setEditando({ ...editando, requisitos: editando.requisitos.map((x, j) => (j === i ? { ...x, descripcion: e.target.value } : x)) })} maxLength={200} /></Campo>
              <label className="campo"><span><input type="checkbox" checked={r.obligatorio} onChange={(e) => setEditando({ ...editando, requisitos: editando.requisitos.map((x, j) => (j === i ? { ...x, obligatorio: e.target.checked } : x)) })} /> Obligatorio</span></label>
              <button type="button" className="boton boton-borde boton-chico" onClick={() => setEditando({ ...editando, requisitos: editando.requisitos.filter((_, j) => j !== i) })}>Quitar</button>
            </div>
          ))}
          <button type="button" className="boton boton-borde boton-chico" onClick={() => setEditando({ ...editando, requisitos: [...editando.requisitos, { descripcion: '', obligatorio: true }] })}>Agregar requisito</button>
          <div className="acciones">
            <button type="button" className="boton" disabled={accion.enviando || editando.nombre.trim().length < 3} onClick={guardar}>Guardar</button>
            <button type="button" className="boton boton-borde" onClick={() => setEditando(null)}>Cancelar</button>
          </div>
        </Dialogo>
      )}
    </>
  );
}

const PLANTILLA_VACIA = { nombre: '', tipo: 'constancia', contenido_html: '<h1>Constancia de estudios</h1>\n<p>La dirección de {colegio.nombre} hace constar que <b>{alumno.nombre}</b>, con {alumno.documento}, cursa {seccion} de {nivel} en el año escolar {anio}.</p>\n<p>Se expide la presente a solicitud de {apoderado.nombre}, el {fecha_larga}.</p>', con_qr: true };

function Plantillas() {
  const { pedir, estado, token } = useSesion();
  const colegio = estado!.colegio_activo!;
  const edita = puede(colegio, 'doc.plantillas.editar');
  const { datos, error, recargar } = useCarga(() => pedir<{ data: Plantilla[]; variables: { variable: string; descripcion: string }[] }>('/documentos/plantillas'));
  const [editando, setEditando] = useState<(typeof PLANTILLA_VACIA & { id?: number }) | null>(null);
  const [alumno, setAlumno] = useState<{ id: number; nombre: string } | null>(null);
  const [buscar, setBuscar] = useState('');
  const alumnos = useCarga(() => (buscar.trim().length >= 3 ? pedir<{ data: { id: number; nombres: string; apellido_paterno: string; apellido_materno: string | null }[] }>(`/alumnos?buscar=${encodeURIComponent(buscar.trim())}`) : Promise.resolve({ data: [] })), [buscar]);
  const accion = useAccion();
  const vistaPrevia = (id: number) => accion.ejecutar(async () => {
    if (!alumno) throw new Error('Elige un alumno para la vista previa.');
    const b = await apiArchivo(`/documentos/plantillas/${id}/vista-previa?alumno_id=${alumno.id}`, token);
    window.open(URL.createObjectURL(b), '_blank', 'noopener');
  });
  const guardar = () => accion.ejecutar(async () => {
    const e = editando!;
    await pedir(e.id ? `/documentos/plantillas/${e.id}` : '/documentos/plantillas', { metodo: e.id ? 'PUT' : 'POST', cuerpo: e });
    setEditando(null);
    recargar();
    accion.setOk(e.id ? 'Se guardó una versión nueva. Los documentos ya emitidos conservan la versión con la que se firmaron.' : 'Plantilla creada.');
  });
  return (
    <>
      <Aviso tipo="error">{error ?? (editando ? null : accion.error)}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="filtros">
        <Campo etiqueta="Alumno para la vista previa" ayuda={alumno ? `Usando a ${alumno.nombre}.` : 'Escribe al menos 3 letras.'}>
          <input value={buscar} onChange={(e) => setBuscar(e.target.value)} placeholder="Apellido o DNI" list="alumnos-vista" />
        </Campo>
        {(alumnos.datos?.data ?? []).slice(0, 6).map((a) => (
          <button key={a.id} type="button" className={`boton boton-chico ${alumno?.id === a.id ? '' : 'boton-borde'}`} onClick={() => setAlumno({ id: a.id, nombre: `${a.nombres} ${a.apellido_paterno}` })}>{a.apellido_paterno}, {a.nombres}</button>
        ))}
        {edita && <div className="acciones"><button type="button" className="boton" onClick={() => setEditando({ ...PLANTILLA_VACIA })}>Nueva plantilla</button></div>}
      </div>
      {!datos ? <p className="aviso-carga" role="status">Cargando…</p> : datos.data.length === 0 ? <p className="vacio">Aún no hay plantillas.</p> : (
        <div className="tarjeta"><div className="tabla-caja">
          <table className="tabla"><thead><tr><th>Plantilla</th><th>Versión</th><th>Usada en</th><th /></tr></thead><tbody>
            {datos.data.map((p) => (
              <tr key={p.id}>
                <td>{p.nombre}<br /><small className="ayuda">{p.tipo}{p.con_qr ? ' · con QR' : ''}</small></td>
                <td>v{p.version}{p.versiones.length > 1 && edita && (
                  <select aria-label="Restaurar una versión anterior" value="" onChange={(e) => e.target.value && void accion.ejecutar(async () => { await pedir(`/documentos/plantillas/${e.target.value}/restaurar`, { metodo: 'POST' }); recargar(); accion.setOk('Versión restaurada como versión nueva.'); })}>
                    <option value="">Restaurar…</option>
                    {p.versiones.filter((v) => v.id !== p.id).map((v) => <option key={v.id} value={v.id}>v{v.version} · {fechaHora(v.updated_at)}</option>)}
                  </select>
                )}</td>
                <td>{p.en_uso} trámite(s)</td>
                <td><div className="acciones">
                  <button type="button" className="boton boton-borde boton-chico" disabled={accion.enviando} onClick={() => vistaPrevia(p.id)}>Vista previa</button>
                  {edita && <button type="button" className="boton boton-borde boton-chico" onClick={() => setEditando({ id: p.id, nombre: p.nombre, tipo: p.tipo, contenido_html: p.contenido_html, con_qr: p.con_qr })}>Editar</button>}
                </div></td>
              </tr>
            ))}
          </tbody></table>
        </div></div>
      )}
      {editando && datos && (
        <Dialogo titulo={editando.id ? 'Editar plantilla (se guarda como versión nueva)' : 'Nueva plantilla'} alCerrar={() => setEditando(null)}>
          <Aviso tipo="error">{accion.error}</Aviso>
          <div className="campos">
            <Campo etiqueta="Nombre"><input value={editando.nombre} onChange={(e) => setEditando({ ...editando, nombre: e.target.value })} maxLength={120} /></Campo>
            <Campo etiqueta="Tipo"><select value={editando.tipo} onChange={(e) => setEditando({ ...editando, tipo: e.target.value })}><option value="constancia">Constancia</option><option value="certificado">Certificado</option><option value="carta">Carta</option><option value="otro">Otro</option></select></Campo>
            <label className="campo"><span><input type="checkbox" checked={editando.con_qr} onChange={(e) => setEditando({ ...editando, con_qr: e.target.checked })} /> Con QR de verificación</span></label>
            <Campo etiqueta="Contenido (HTML con variables)" ayuda="Formato permitido: títulos, párrafos, negritas, listas y tablas." ancho>
              <textarea rows={12} value={editando.contenido_html} onChange={(e) => setEditando({ ...editando, contenido_html: e.target.value })} style={{ fontFamily: 'monospace' }} />
            </Campo>
          </div>
          <details><summary>Variables disponibles (toca para insertar)</summary>
            <ul className="lista-simple">{datos.variables.map((v) => (
              <li key={v.variable}><button type="button" className="enlace" onClick={() => setEditando({ ...editando, contenido_html: `${editando.contenido_html}${v.variable}` })}>{v.variable}</button> · {v.descripcion}</li>
            ))}</ul>
          </details>
          <div className="acciones">
            <button type="button" className="boton" disabled={accion.enviando || editando.nombre.trim().length < 3} onClick={guardar}>Guardar</button>
            <button type="button" className="boton boton-borde" onClick={() => setEditando(null)}>Cancelar</button>
          </div>
        </Dialogo>
      )}
    </>
  );
}

type TipoFamilia = { id: number; nombre: string; descripcion: string | null; costo: number; plazo_dias: number; requiere_sin_deuda: boolean; requisitos: (Requisito & { id: number })[] };

/** EDUN-114 · La familia pide trámites (también desde la web) y descarga el documento firmado. */
export function MisTramites() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const familia = useCarga(() => pedir<{ hijos: { id: number; nombres: string; apellido_paterno: string }[] }>('/me/familia'));
  const tipos = useCarga(() => pedir<{ data: TipoFamilia[] }>('/familia/tramites/tipos'));
  const { datos, recargar } = useCarga(() => pedir<{ data: Tramite[] }>('/familia/tramites'));
  const [tipoId, setTipoId] = useState('');
  const [hijo, setHijo] = useState('');
  const [comentario, setComentario] = useState('');
  const [archivos, setArchivos] = useState<Record<number, File>>({});
  const [respuesta, setRespuesta] = useState<{ id: number; texto: string; archivo: File | null } | null>(null);
  const accion = useAccion();
  const { abrir, accion: apertura } = useAbrir();
  const hijos = familia.datos?.hijos ?? [];
  const alumno = hijo || String(hijos[0]?.id ?? '');
  const tipo = tipos.datos?.data.find((t) => String(t.id) === tipoId);
  return (
    <>
      <Titulo migas={`${colegio.nombre} · Familia`} titulo="Trámites" descripcion="Pide constancias y certificados, adjunta los requisitos y descarga el documento firmado cuando esté listo." />
      <Aviso tipo="error">{familia.error ?? tipos.error ?? accion.error ?? apertura.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <form className="tarjeta" onSubmit={(e) => {
        e.preventDefault();
        void accion.ejecutar(async () => {
          const cuerpo = new FormData();
          cuerpo.append('tramite_tipo_id', tipoId);
          cuerpo.append('alumno_id', alumno);
          if (comentario.trim()) cuerpo.append('comentario', comentario.trim());
          Object.entries(archivos).forEach(([req, f]) => cuerpo.append(`adjuntos[${req}]`, f));
          const r = await pedir<Tramite>('/familia/tramites', { metodo: 'POST', cuerpo });
          setTipoId('');
          setArchivos({});
          setComentario('');
          recargar();
          accion.setOk(`Trámite ${r.numero} enviado.${r.cargo_monto ? ` Paga ${soles(r.cargo_monto)} en Mis pagos para que se atienda.` : ''}`);
        });
      }}>
        <div className="campos">
          <Campo etiqueta="Trámite">
            <select value={tipoId} onChange={(e) => { setTipoId(e.target.value); setArchivos({}); }} required>
              <option value="">Elige…</option>
              {(tipos.datos?.data ?? []).map((t) => <option key={t.id} value={t.id}>{t.nombre}{t.costo ? ` · ${soles(t.costo)}` : ' · gratuito'}</option>)}
            </select>
          </Campo>
          {hijos.length > 1 && <Campo etiqueta="Hijo"><select value={alumno} onChange={(e) => setHijo(e.target.value)}>{hijos.map((h) => <option key={h.id} value={h.id}>{h.nombres} {h.apellido_paterno}</option>)}</select></Campo>}
          {tipo && <p className="campo-ancho ayuda">{tipo.descripcion ?? ''} Plazo: {tipo.plazo_dias} día(s) hábil(es).{tipo.requiere_sin_deuda ? ' Exige estar al día en los pagos.' : ''}</p>}
          {tipo?.requisitos.map((r) => (
            <Campo key={r.id} etiqueta={`${r.descripcion}${r.obligatorio ? '' : ' (opcional)'}`} ayuda="Foto o PDF de hasta 5 MB.">
              <input type="file" accept="image/*,application/pdf" required={r.obligatorio} onChange={(e) => { const f = e.target.files?.[0]; setArchivos((a) => { const n = { ...a }; if (f) n[r.id] = f; else delete n[r.id]; return n; }); }} />
            </Campo>
          ))}
          <Campo etiqueta="Comentario (opcional)" ancho><textarea rows={2} value={comentario} onChange={(e) => setComentario(e.target.value)} maxLength={500} /></Campo>
        </div>
        <div className="acciones"><button type="submit" className="boton" disabled={accion.enviando || !tipoId || !alumno}>Enviar trámite</button></div>
      </form>
      {datos && datos.data.length > 0 && (
        <div className="tarjeta"><h2>Mis trámites</h2><div className="tabla-caja">
          <table className="tabla"><thead><tr><th>N.°</th><th>Trámite</th><th>Alumno</th><th>Estado</th><th /></tr></thead><tbody>
            {datos.data.map((t) => (
              <tr key={t.id}>
                <td>{t.numero}</td>
                <td>{t.tipo}{t.cargo_monto !== null && !t.pagado && <><br /><small className="ayuda">Pendiente de pago: {soles(t.cargo_monto)}</small></>}</td>
                <td>{t.alumno}</td>
                <td><span className={`insignia ${ESTADO_TRAMITE[t.estado].clase}`}>{ESTADO_TRAMITE[t.estado].texto}</span></td>
                <td>
                  {t.estado === 'entregado' && !t.anulado && <button type="button" className="boton boton-chico" onClick={() => abrir(`/familia/tramites/${t.id}/documento`)}>Descargar</button>}
                  {t.estado === 'observado' && <button type="button" className="boton boton-borde boton-chico" onClick={() => setRespuesta({ id: t.id, texto: '', archivo: null })}>Responder</button>}
                </td>
              </tr>
            ))}
          </tbody></table>
        </div></div>
      )}
      {respuesta && (
        <Dialogo titulo="Responder la observación" alCerrar={() => setRespuesta(null)}>
          <Campo etiqueta="Respuesta" ancho><textarea rows={3} value={respuesta.texto} onChange={(e) => setRespuesta({ ...respuesta, texto: e.target.value })} maxLength={500} /></Campo>
          <Campo etiqueta="Archivo corregido (opcional)"><input type="file" accept="image/*,application/pdf" onChange={(e) => setRespuesta({ ...respuesta, archivo: e.target.files?.[0] ?? null })} /></Campo>
          <div className="acciones">
            <button type="button" className="boton" disabled={accion.enviando || (!respuesta.texto.trim() && !respuesta.archivo)} onClick={() => accion.ejecutar(async () => {
              const cuerpo = new FormData();
              if (respuesta.texto.trim()) cuerpo.append('comentario', respuesta.texto.trim());
              if (respuesta.archivo) cuerpo.append('adjuntos[0]', respuesta.archivo);
              await pedir(`/familia/tramites/${respuesta.id}/respuesta`, { metodo: 'POST', cuerpo });
              setRespuesta(null);
              recargar();
              accion.setOk('Respuesta enviada: el colegio vuelve a revisar tu trámite.');
            })}>Enviar</button>
            <button type="button" className="boton boton-borde" onClick={() => setRespuesta(null)}>Cancelar</button>
          </div>
        </Dialogo>
      )}
    </>
  );
}
