import { useState, type FormEvent } from 'react';
import { useSesion } from '../sesion/contexto';
import { useRuta } from '../panel/ruta';
import { Aviso, Campo, Titulo } from '../panel/ui';
import { fecha, useAccion, useCarga } from '../panel/utilidades';
import '../panel/panel.css';
import { CumplimientoSla, MesaAyuda } from './MesaAyuda';
import { Exportaciones, ListaImplementacion, Suspension } from './OperacionColegio';
import { ParametrosLegales } from './Parametros';
import { CobroMensual } from './CobroMensual';

type Fila = {
  id: number;
  nombre: string;
  slug: string;
  codigo_modular: string;
  nivel_educativo: string;
  estado: string;
  plan: string | null;
  aula_opcion: string | null;
  modulos_extra: string[];
  alumnos_activos: number;
};
type Catalogos = { planes: { codigo: string; nombre: string }[]; modulos: { codigo: string; nombre: string; tipo: string }[]; dominio: string };
type Modulo = { codigo: string; nombre: string; tipo: string; activo: boolean; fijo: boolean };
type Detalle = {
  id: number;
  suspension_programada: string | null;
  nombre: string;
  subdominio: string;
  estado: string;
  ugel: string;
  ruc: string | null;
  creado: string;
  servicios: { nivel: string; codigo_modular: string }[];
  suscripcion: { plan: string; fecha_inicio: string; meses_gratis: number; aula_opcion: string; estado: string } | null;
  modulos: Modulo[];
  promotores: { id: number; nombres: string; apellidos: string; email: string | null; activo: boolean; acepto: boolean }[];
};

const ESTADOS: Record<string, [string, string]> = {
  implementacion: ['Implementación', 'insignia-azul'],
  activo: ['Activo', 'insignia-ok'],
  suspendido: ['Suspendido', 'insignia-ambar'],
  baja: ['Baja', 'insignia-rojo'],
};
const AULA: Record<string, string> = { medida: 'Aula a medida', moodle: 'Moodle', ambas: 'Aula a medida y Moodle', ninguna: 'Sin aula virtual' };
const NIVELES = ['inicial', 'primaria', 'secundaria'];

function Estado({ estado }: { estado: string }) {
  const [n, c] = ESTADOS[estado] ?? [estado, ''];
  return <span className={`insignia ${c}`}>{n}</span>;
}

function Lista() {
  const { pedir } = useSesion();
  const [buscar, setBuscar] = useState('');
  const { datos, error, cargando } = useCarga(() => pedir<{ data: Fila[] }>(`/plataforma/instituciones${buscar ? `?buscar=${encodeURIComponent(buscar)}` : ''}`), [buscar]);
  const parametros = useCarga(() => pedir<{ sin_confirmar: number }>('/plataforma/parametros-legales'));
  return (
    <>
      <Titulo migas="Plataforma" titulo="Colegios" descripcion="Colegios clientes con su plan, aula elegida y módulos extra.">
        <a className="boton" href="#/colegios/nuevo">
          + Dar de alta un colegio
        </a>
      </Titulo>
      {(parametros.datos?.sin_confirmar ?? 0) > 0 && (
        <Aviso tipo="ambar">
          Hay {parametros.datos!.sin_confirmar} parámetros legales vigentes sin confirmar. <a href="#/parametros">Revisarlos</a>
        </Aviso>
      )}
      <div className="tarjeta" style={{ marginTop: 12 }}>
        <div className="filtros">
          <Campo etiqueta="Buscar">
            <input placeholder="Nombre, subdominio, código modular o RUC" value={buscar} onChange={(e) => setBuscar(e.target.value)} />
          </Campo>
        </div>
        <Aviso tipo="error">{error}</Aviso>
        <div className="tabla-caja">
          <table className="tabla">
            <thead>
              <tr>
                <th>Colegio</th>
                <th>Estado</th>
                <th>Plan</th>
                <th>Alumnos activos</th>
                <th>Aula</th>
                <th>Extras</th>
              </tr>
            </thead>
            <tbody>
              {datos?.data.map((c) => (
                <tr key={c.id}>
                  <td>
                    <a href={`#/colegios/${c.id}`}>
                      <b>{c.nombre}</b>
                    </a>
                    <br />
                    <small className="ayuda">
                      {c.slug} · {c.codigo_modular}
                    </small>
                  </td>
                  <td>
                    <Estado estado={c.estado} />
                  </td>
                  <td>{c.plan ?? '—'}</td>
                  <td>{c.alumnos_activos}</td>
                  <td>{c.aula_opcion ? AULA[c.aula_opcion] : '—'}</td>
                  <td>{c.modulos_extra.join(', ') || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {cargando && <p className="vacio">Cargando…</p>}
          {!cargando && datos?.data.length === 0 && <p className="vacio">Sin colegios.</p>}
        </div>
      </div>
    </>
  );
}

/** EDUN-24 · PLA-02 Alta de institución. */
function Alta() {
  const { pedir } = useSesion();
  const catalogos = useCarga(() => pedir<Catalogos>('/plataforma/catalogos'));
  const accion = useAccion();
  const [f, setF] = useState({ nombre: '', razon_social: '', ruc: '', ugel: '', dre: '', direccion: '', slug: '', plan: 'P150', aula_opcion: 'medida', meses_gratis: '0' });
  const [servicios, setServicios] = useState([{ nivel: 'primaria', codigo_modular: '' }]);
  const [extras, setExtras] = useState<string[]>([]);
  const [p, setP] = useState({ nombres: '', apellidos: '', numero_documento: '', email: '', celular: '' });
  const cambiar = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value });
  const cambiarP = (k: keyof typeof p) => (e: { target: { value: string } }) => setP({ ...p, [k]: e.target.value });
  const vacio = (v: string) => (v.trim() === '' ? null : v.trim());

  async function crear(e: FormEvent) {
    e.preventDefault();
    await accion.ejecutar(async () => {
      const r = await pedir<{ id: number }>('/plataforma/instituciones', {
        metodo: 'POST',
        cuerpo: {
          ...Object.fromEntries(Object.entries(f).map(([k, v]) => [k, vacio(v)])),
          meses_gratis: Number(f.meses_gratis) || 0,
          servicios,
          modulos_extra: extras,
          promotor: Object.fromEntries(Object.entries(p).map(([k, v]) => [k, vacio(v)])),
        },
      });
      window.location.hash = `#/colegios/${r.id}`;
    });
  }

  return (
    <>
      <Titulo migas="Plataforma · Colegios" titulo="Dar de alta un colegio" descripcion="El colegio queda en implementación y el promotor recibe una invitación por correo." />
      <form onSubmit={crear} noValidate className="rejilla">
        <div className="tarjeta">
          <h2>Datos MINEDU</h2>
          <div className="campos" style={{ marginTop: 12 }}>
            <Campo etiqueta="Nombre del colegio" ancho>
              <input value={f.nombre} onChange={cambiar('nombre')} />
            </Campo>
            <Campo etiqueta="Razón social">
              <input value={f.razon_social} onChange={cambiar('razon_social')} />
            </Campo>
            <Campo etiqueta="RUC">
              <input inputMode="numeric" maxLength={11} value={f.ruc} onChange={cambiar('ruc')} />
            </Campo>
            <Campo etiqueta="UGEL">
              <input value={f.ugel} onChange={cambiar('ugel')} />
            </Campo>
            <Campo etiqueta="DRE / GRE">
              <input value={f.dre} onChange={cambiar('dre')} />
            </Campo>
            <Campo etiqueta="Dirección" ancho>
              <input value={f.direccion} onChange={cambiar('direccion')} />
            </Campo>
          </div>
          <h2 style={{ marginTop: 20 }}>Niveles y códigos modulares</h2>
          <p className="ayuda">Un código modular por nivel.</p>
          {servicios.map((s, i) => (
            <div key={i} className="filtros" style={{ marginBottom: 6 }}>
              <Campo etiqueta="Nivel">
                <select value={s.nivel} onChange={(e) => setServicios(servicios.map((x, j) => (j === i ? { ...x, nivel: e.target.value } : x)))}>
                  {NIVELES.map((n) => (
                    <option key={n} value={n}>
                      {n[0].toUpperCase() + n.slice(1)}
                    </option>
                  ))}
                </select>
              </Campo>
              <Campo etiqueta="Código modular">
                <input
                  inputMode="numeric"
                  maxLength={7}
                  value={s.codigo_modular}
                  onChange={(e) => setServicios(servicios.map((x, j) => (j === i ? { ...x, codigo_modular: e.target.value.replace(/\D/g, '') } : x)))}
                />
              </Campo>
              {servicios.length > 1 && (
                <button type="button" className="boton boton-chico boton-borde" onClick={() => setServicios(servicios.filter((_, j) => j !== i))}>
                  Quitar
                </button>
              )}
            </div>
          ))}
          {servicios.length < 3 && (
            <button
              type="button"
              className="boton boton-chico boton-claro"
              onClick={() => setServicios([...servicios, { nivel: NIVELES.find((n) => !servicios.some((s) => s.nivel === n)) ?? 'inicial', codigo_modular: '' }])}
            >
              + Agregar nivel
            </button>
          )}
        </div>
        <div style={{ display: 'grid', gap: 18 }}>
          <div className="tarjeta">
            <h2>Plan y módulos</h2>
            <div className="campos" style={{ marginTop: 12 }}>
              <Campo etiqueta="Subdominio" ayuda={`${f.slug || 'colegio'}.${catalogos.datos?.dominio ?? 'edunova.pe'}`}>
                <input value={f.slug} onChange={(e) => setF({ ...f, slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '') })} />
              </Campo>
              <Campo etiqueta="Plan">
                <select value={f.plan} onChange={cambiar('plan')}>
                  {catalogos.datos?.planes.map((pl) => (
                    <option key={pl.codigo} value={pl.codigo}>
                      {pl.nombre}
                    </option>
                  ))}
                </select>
              </Campo>
              <Campo etiqueta="Aula virtual">
                <select value={f.aula_opcion} onChange={cambiar('aula_opcion')}>
                  {Object.entries(AULA).map(([k, v]) => (
                    <option key={k} value={k}>
                      {v}
                    </option>
                  ))}
                </select>
              </Campo>
              <Campo etiqueta="Meses gratis">
                <input inputMode="numeric" maxLength={2} value={f.meses_gratis} onChange={cambiar('meses_gratis')} />
              </Campo>
            </div>
            <p className="campo" style={{ margin: '14px 0 6px' }}>
              Módulos extra
            </p>
            <div className="casillas">
              {catalogos.datos?.modulos
                .filter((m) => m.tipo === 'extra')
                .map((m) => (
                  <label key={m.codigo} className="casilla">
                    <input type="checkbox" checked={extras.includes(m.codigo)} onChange={(e) => setExtras(e.target.checked ? [...extras, m.codigo] : extras.filter((x) => x !== m.codigo))} />
                    {m.nombre}
                  </label>
                ))}
            </div>
          </div>
          <div className="tarjeta">
            <h2>Promotor</h2>
            <p className="ayuda">Recibe la invitación por correo para crear su contraseña.</p>
            <div className="campos">
              <Campo etiqueta="Nombres">
                <input value={p.nombres} onChange={cambiarP('nombres')} />
              </Campo>
              <Campo etiqueta="Apellidos">
                <input value={p.apellidos} onChange={cambiarP('apellidos')} />
              </Campo>
              <Campo etiqueta="DNI">
                <input inputMode="numeric" maxLength={8} value={p.numero_documento} onChange={cambiarP('numero_documento')} />
              </Campo>
              <Campo etiqueta="Correo">
                <input type="email" value={p.email} onChange={cambiarP('email')} />
              </Campo>
              <Campo etiqueta="Celular">
                <input inputMode="tel" value={p.celular} onChange={cambiarP('celular')} />
              </Campo>
            </div>
            <Aviso tipo="error">{accion.error ?? catalogos.error}</Aviso>
            <div className="acciones">
              <button type="submit" className="boton" disabled={accion.enviando}>
                {accion.enviando ? 'Creando…' : 'Dar de alta'}
              </button>
              <a className="boton boton-borde" href="#/colegios">
                Cancelar
              </a>
            </div>
          </div>
        </div>
      </form>
    </>
  );
}

/** EDUN-25 / EDUN-26 · PLA-03 Módulos y aula del colegio. */
function Ficha({ id }: { id: number }) {
  const { pedir, estado } = useSesion();
  const esSuperadmin = estado!.roles_plataforma.includes('superadmin');
  const { datos, setDatos, error, recargar } = useCarga(() => pedir<Detalle>(`/plataforma/instituciones/${id}`), [id]);
  const accion = useAccion();
  if (!datos) return <Aviso tipo="error">{error}</Aviso>;

  return (
    <>
      <Titulo migas="Plataforma · Colegios" titulo={datos.nombre} descripcion={`${datos.subdominio} · alta ${fecha(datos.creado)}`}>
        <Estado estado={datos.estado} />
      </Titulo>
      <Aviso tipo="error">{accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      <div className="rejilla" style={{ marginTop: 12 }}>
        <div className="tarjeta">
          <h2>Módulos</h2>
          <p className="ayuda">El cambio aplica de inmediato en los menús y en la API del colegio. Los datos se conservan.</p>
          {datos.modulos
            .filter((m) => m.tipo === 'base' || m.tipo === 'extra')
            .map((m) => (
              <label key={m.codigo} className="interruptor">
                <span>
                  {m.nombre} <small className="ayuda">{m.tipo === 'extra' ? '· extra' : ''}</small>
                </span>
                <input
                  type="checkbox"
                  role="switch"
                  aria-label={m.nombre}
                  checked={m.activo}
                  disabled={!esSuperadmin || m.fijo || accion.enviando}
                  onChange={(e) =>
                    accion.ejecutar(async () =>
                      setDatos(await pedir<Detalle>(`/plataforma/instituciones/${id}/modulos/${m.codigo}`, { metodo: 'PUT', cuerpo: { activo: e.target.checked } })),
                    )
                  }
                />
              </label>
            ))}
        </div>
        <div style={{ display: 'grid', gap: 18 }}>
          <div className="tarjeta">
            <h2>Aula virtual</h2>
            <p className="ayuda">Decide qué menú ve el colegio. Con Moodle se habilita la configuración del conector.</p>
            <Campo etiqueta="Opción de aula">
              <select
                value={datos.suscripcion?.aula_opcion ?? 'ninguna'}
                disabled={!esSuperadmin || accion.enviando}
                onChange={(e) =>
                  accion.ejecutar(async () => setDatos(await pedir<Detalle>(`/plataforma/instituciones/${id}/aula`, { metodo: 'PUT', cuerpo: { aula_opcion: e.target.value } })))
                }
              >
                {Object.entries(AULA).map(([k, v]) => (
                  <option key={k} value={k}>
                    {v}
                  </option>
                ))}
              </select>
            </Campo>
          </div>
          <div className="tarjeta">
            <h2>Datos</h2>
            <p className="ayuda">
              UGEL {datos.ugel}
              {datos.ruc ? ` · RUC ${datos.ruc}` : ''}
              <br />
              {datos.servicios.map((s) => `${s.nivel} ${s.codigo_modular}`).join(' · ')}
              <br />
              Plan {datos.suscripcion?.plan ?? '—'} desde {fecha(datos.suscripcion?.fecha_inicio)}
              {datos.suscripcion?.meses_gratis ? ` · ${datos.suscripcion.meses_gratis} meses gratis` : ''}
            </p>
          </div>
          <div className="tarjeta">
            <h2>Promotor</h2>
            {datos.promotores.map((pr) => (
              <div key={pr.id} className="interruptor">
                <span>
                  {pr.nombres} {pr.apellidos}
                  <br />
                  <small className="ayuda">{pr.email}</small>
                </span>
                {pr.acepto ? (
                  <span className="insignia insignia-ok">Activo</span>
                ) : esSuperadmin ? (
                  <button
                    type="button"
                    className="boton boton-chico boton-claro"
                    disabled={accion.enviando}
                    onClick={() => accion.ejecutar(() => pedir(`/plataforma/instituciones/${id}/promotores/${pr.id}/invitacion`, { metodo: 'POST' }), 'Invitación reenviada.')}
                  >
                    Reenviar invitación
                  </button>
                ) : (
                  <span className="insignia insignia-ambar">Pendiente</span>
                )}
              </div>
            ))}
          </div>
        </div>
        <ListaImplementacion id={id} estado={datos.estado} alCambiar={recargar} />
        {esSuperadmin && <Suspension id={id} estado={datos.estado} programada={datos.suspension_programada} alCambiar={recargar} />}
        {esSuperadmin && <Exportaciones id={id} />}
      </div>
    </>
  );
}

/** Consola de la plataforma (staff de Edunova con segundo factor). */
export function Consola() {
  const { estado, salir } = useSesion();
  useRuta();
  const partes = window.location.hash.replace(/^#\/?/, '').split('/');
  const seccion = partes[0] || 'colegios';
  const pantalla =
    seccion === 'soporte' ? <MesaAyuda /> :
    seccion === 'sla' ? <CumplimientoSla /> :
    seccion === 'parametros' ? <ParametrosLegales /> :
    seccion === 'cobro' ? <CobroMensual /> :
    partes[1] === 'nuevo' ? <Alta /> : partes[1] && /^\d+$/.test(partes[1]) ? <Ficha id={Number(partes[1])} /> : <Lista />;

  return (
    <div className="marco">
      <aside className="lateral">
        <div className="lateral-marca">
          <span className="lateral-logo">E</span>
          <div>
            <strong>Edunova</strong>
            <small>Plataforma</small>
          </div>
        </div>
        <nav aria-label="Menú de plataforma">
          <div className="lateral-grupo">Plataforma</div>
          <a href="#/colegios" aria-current={seccion === 'colegios' && !partes[1] ? 'page' : undefined}>
            Colegios
          </a>
          {estado!.roles_plataforma.includes('superadmin') && (
            <a href="#/colegios/nuevo" aria-current={partes[1] === 'nuevo' ? 'page' : undefined}>
              Alta de colegio
            </a>
          )}
          {estado!.roles_plataforma.includes('superadmin') && (
            <a href="#/cobro" aria-current={seccion === 'cobro' ? 'page' : undefined}>
              Cobro mensual
            </a>
          )}
          <div className="lateral-grupo">Soporte</div>
          <a href="#/soporte" aria-current={seccion === 'soporte' ? 'page' : undefined}>
            Mesa de ayuda
          </a>
          <a href="#/sla" aria-current={seccion === 'sla' ? 'page' : undefined}>
            Cumplimiento SLA
          </a>
          <div className="lateral-grupo">Normativa</div>
          <a href="#/parametros" aria-current={seccion === 'parametros' ? 'page' : undefined}>
            Parámetros legales
          </a>
        </nav>
        <div className="lateral-pie">
          <span>
            {estado!.usuario.nombres} {estado!.usuario.apellidos}
          </span>
          <button type="button" onClick={salir}>
            Cerrar sesión
          </button>
        </div>
      </aside>
      <main className="contenido">{pantalla}</main>
    </div>
  );
}
