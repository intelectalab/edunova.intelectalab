import { useState, type FormEvent } from 'react';
import { puede, useSesion } from '../sesion/contexto';
import { Aviso, Campo, Pestanas, Titulo } from '../panel/ui';
import { useAccion, useCarga, useImagenApi } from '../panel/utilidades';
import { AnioEscolar } from './AnioEscolar';

type Servicio = { nivel: string; codigo_modular: string; anexo?: string };
type Datos = Record<string, string | null> & {
  tiene_logo: boolean;
  servicios: Servicio[];
};

const CAMPOS_LEGALES: [string, string, string?][] = [
  ['nombre', 'Nombre del colegio'],
  ['razon_social', 'Razón social'],
  ['ruc', 'RUC', '11 dígitos'],
  ['ugel', 'UGEL'],
  ['dre', 'DRE / GRE'],
  ['codigo_local', 'Código de local', '6 dígitos'],
  ['ubigeo', 'Ubigeo', '6 dígitos'],
  ['resolucion_creacion', 'Resolución de creación'],
  ['director_nombre', 'Director(a) que firma'],
  ['direccion', 'Dirección'],
];
const CAMPOS_CONTACTO: [string, string][] = [
  ['telefono', 'Teléfono'],
  ['correo', 'Correo'],
  ['horario_atencion', 'Horario de atención'],
  ['pagina_web', 'Página web'],
];
const NIVELES = ['inicial', 'primaria', 'secundaria'];
const NOMBRE_NIVEL: Record<string, string> = { inicial: 'Inicial', primaria: 'Primaria', secundaria: 'Secundaria' };

function DatosColegio({ datos, editable, alGuardar }: { datos: Datos; editable: boolean; alGuardar: (d: Datos) => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const [f, setF] = useState<Record<string, string>>(() => Object.fromEntries([...CAMPOS_LEGALES, ...CAMPOS_CONTACTO].map(([k]) => [k, datos[k] ?? ''])));

  async function guardar(e: FormEvent) {
    e.preventDefault();
    await accion.ejecutar(async () => {
      const cuerpo = Object.fromEntries(Object.entries(f).map(([k, v]) => [k, v.trim() === '' ? null : v.trim()]));
      alGuardar(await pedir<Datos>('/institucion', { metodo: 'PUT', cuerpo: { ...cuerpo, color_primario: datos.color_primario } }));
    }, 'Datos guardados.');
  }

  const campo = ([k, nombre, ayuda]: [string, string, string?]) => (
    <Campo key={k} etiqueta={nombre} ayuda={ayuda} ancho={k === 'direccion'}>
      <input value={f[k] ?? ''} disabled={!editable} onChange={(e) => setF({ ...f, [k]: e.target.value })} />
    </Campo>
  );

  return (
    <form onSubmit={guardar} noValidate className="rejilla">
      <div className="tarjeta">
        <h2>Datos legales</h2>
        <p className="ayuda">Aparecen en libretas, constancias y comprobantes.</p>
        <div className="campos">{CAMPOS_LEGALES.map(campo)}</div>
      </div>
      <div className="tarjeta">
        <h2>Contacto</h2>
        <p className="ayuda">Lo ven las familias en la app y en los documentos.</p>
        <div className="campos">{CAMPOS_CONTACTO.map(campo)}</div>
        <Aviso tipo="error">{accion.error}</Aviso>
        <Aviso tipo="ok">{accion.ok}</Aviso>
        {editable && (
          <div className="acciones">
            <button type="submit" className="boton" disabled={accion.enviando}>
              {accion.enviando ? 'Guardando…' : 'Guardar'}
            </button>
          </div>
        )}
      </div>
    </form>
  );
}

function Niveles({ datos, editable, alGuardar }: { datos: Datos; editable: boolean; alGuardar: (d: Datos) => void }) {
  const { pedir } = useSesion();
  const accion = useAccion();
  const [filas, setFilas] = useState<Servicio[]>(datos.servicios);
  const libres = NIVELES.filter((n) => !filas.some((f) => f.nivel === n));

  return (
    <div className="tarjeta" style={{ maxWidth: 760 }}>
      <h2>Niveles y códigos modulares</h2>
      <p className="ayuda">Cada nivel tiene su código modular del MINEDU (7 dígitos). Se usa en el SIAGIE y en los documentos.</p>
      <div className="tabla-caja">
        <table className="tabla">
          <thead>
            <tr>
              <th>Nivel</th>
              <th>Código modular</th>
              <th>Anexo</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {filas.map((s, i) => (
              <tr key={s.nivel}>
                <td>
                  <b>{NOMBRE_NIVEL[s.nivel]}</b>
                </td>
                <td>
                  <input
                    className="control"
                    aria-label={`Código modular de ${NOMBRE_NIVEL[s.nivel]}`}
                    inputMode="numeric"
                    maxLength={7}
                    value={s.codigo_modular}
                    disabled={!editable}
                    onChange={(e) => setFilas(filas.map((x, j) => (j === i ? { ...x, codigo_modular: e.target.value.replace(/\D/g, '') } : x)))}
                  />
                </td>
                <td style={{ width: 90 }}>
                  <input
                    className="control"
                    aria-label={`Anexo de ${NOMBRE_NIVEL[s.nivel]}`}
                    maxLength={1}
                    value={s.anexo ?? '0'}
                    disabled={!editable}
                    onChange={(e) => setFilas(filas.map((x, j) => (j === i ? { ...x, anexo: e.target.value.replace(/\D/g, '') } : x)))}
                  />
                </td>
                <td style={{ textAlign: 'right' }}>
                  {editable && filas.length > 1 && (
                    <button type="button" className="boton boton-chico boton-borde" onClick={() => setFilas(filas.filter((_, j) => j !== i))}>
                      Quitar
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <Aviso tipo="error">{accion.error}</Aviso>
      <Aviso tipo="ok">{accion.ok}</Aviso>
      {editable && (
        <div className="acciones">
          {libres.map((n) => (
            <button key={n} type="button" className="boton boton-claro" onClick={() => setFilas([...filas, { nivel: n, codigo_modular: '', anexo: '0' }])}>
              + {NOMBRE_NIVEL[n]}
            </button>
          ))}
          <button
            type="button"
            className="boton"
            disabled={accion.enviando}
            onClick={() =>
              accion.ejecutar(async () => alGuardar(await pedir<Datos>('/institucion/servicios', { metodo: 'PUT', cuerpo: { servicios: filas } })), 'Niveles guardados.')
            }
          >
            Guardar niveles
          </button>
        </div>
      )}
    </div>
  );
}

function Imagen({ datos, editable, alGuardar }: { datos: Datos; editable: boolean; alGuardar: (d: Datos) => void }) {
  const { pedir, recargar } = useSesion();
  const accion = useAccion();
  const [color, setColor] = useState(datos.color_primario ?? '#3a6f9f');
  const [version, setVersion] = useState(0);
  const vista = useImagenApi('/institucion/logo', datos.tiene_logo, String(version));

  async function subir(archivo: File | undefined) {
    if (!archivo) return;
    const cuerpo = new FormData();
    cuerpo.append('logo', archivo);
    if (await accion.ejecutar(async () => alGuardar(await pedir<Datos>('/institucion/logo', { metodo: 'POST', cuerpo })), 'Logo actualizado.')) {
      setVersion(version + 1);
      await recargar();
    }
  }

  async function guardarColor() {
    const cuerpo = Object.fromEntries([...CAMPOS_LEGALES, ...CAMPOS_CONTACTO].map(([k]) => [k, datos[k] ?? null]));
    if (await accion.ejecutar(async () => alGuardar(await pedir<Datos>('/institucion', { metodo: 'PUT', cuerpo: { ...cuerpo, color_primario: color } })), 'Color guardado.')) await recargar();
  }

  return (
    <div className="rejilla">
      <div className="tarjeta">
        <h2>Logo</h2>
        <p className="ayuda">Aparece en la web, la app, las libretas y los correos. PNG, JPG o WEBP, hasta 2 MB.</p>
        <div className="color-fila" style={{ alignItems: 'flex-start', gap: 16 }}>
          <div className="logo-vista">{vista ? <img src={vista} alt="Logo del colegio" /> : 'Sin logo'}</div>
          {editable && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              <label className="boton boton-claro">
                Subir logo
                <input type="file" accept="image/png,image/jpeg,image/webp" hidden onChange={(e) => void subir(e.target.files?.[0])} />
              </label>
              {datos.tiene_logo && (
                <button
                  type="button"
                  className="boton boton-borde"
                  onClick={async () => {
                    if (await accion.ejecutar(async () => alGuardar(await pedir<Datos>('/institucion/logo', { metodo: 'DELETE' })), 'Logo quitado.')) await recargar();
                  }}
                >
                  Quitar logo
                </button>
              )}
            </div>
          )}
        </div>
      </div>
      <div className="tarjeta">
        <h2>Color del colegio</h2>
        <p className="ayuda">Se usa en botones y acentos de la web y la app.</p>
        <div className="color-fila">
          <input type="color" aria-label="Color del colegio" value={color} disabled={!editable} onChange={(e) => setColor(e.target.value)} />
          <code>{color}</code>
          <span className="boton boton-chico" style={{ background: color, borderColor: color }}>
            Vista previa
          </span>
        </div>
        {editable && (
          <div className="acciones">
            <button type="button" className="boton" disabled={accion.enviando} onClick={guardarColor}>
              Guardar color
            </button>
          </div>
        )}
      </div>
      <div style={{ gridColumn: '1 / -1' }}>
        <Aviso tipo="error">{accion.error}</Aviso>
        <Aviso tipo="ok">{accion.ok}</Aviso>
      </div>
    </div>
  );
}

type Seccion = 'datos' | 'niveles' | 'anio' | 'imagen';

/** EDUN-43 / EDUN-44 · ADM-01 y ADM-02: configuración del colegio. */
export function Configuracion() {
  const { pedir, estado } = useSesion();
  const colegio = estado!.colegio_activo!;
  const editable = puede(colegio, 'adm.configuracion.editar');
  const [seccion, setSeccion] = useState<Seccion>('datos');
  const { datos, setDatos, error } = useCarga(() => pedir<Datos>('/institucion'));

  return (
    <>
      <Titulo
        migas={`${colegio.nombre} · Institución · Configuración`}
        titulo="Configuración del colegio"
        descripcion="Datos legales, niveles con su código modular, año escolar e imagen del colegio."
      />
      <Pestanas<Seccion>
        opciones={[
          ['datos', 'Datos del colegio'],
          ['niveles', 'Niveles y códigos modulares'],
          ['anio', 'Año escolar y periodos'],
          ['imagen', 'Imagen en la app'],
        ]}
        actual={seccion}
        alCambiar={setSeccion}
      />
      <Aviso tipo="error">{error}</Aviso>
      {datos && seccion === 'datos' && <DatosColegio key={JSON.stringify(datos)} datos={datos} editable={editable} alGuardar={setDatos} />}
      {datos && seccion === 'niveles' && <Niveles key={JSON.stringify(datos.servicios)} datos={datos} editable={editable} alGuardar={setDatos} />}
      {seccion === 'anio' && <AnioEscolar editable={editable} />}
      {datos && seccion === 'imagen' && <Imagen datos={datos} editable={editable} alGuardar={setDatos} />}
    </>
  );
}
