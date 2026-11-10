import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from './App';

// Sprint 13 · Plan de estudios, secciones, personal, carga docente, horario y alumnos.

const COLEGIO = {
  id: 1,
  nombre: 'IEP San Martín de Porres',
  slug: 'smp',
  roles: ['director'],
  permisos: [
    'adm.plan_estudios.ver',
    'adm.plan_estudios.editar',
    'adm.personal.ver',
    'adm.personal.crear',
    'adm.horarios.ver',
    'adm.horarios.editar',
    'adm.alumnos.ver',
    'adm.alumnos.crear',
    'adm.matricula.crear',
  ],
  modulos: ['PLA', 'SEG', 'ADM'],
  aula_opcion: 'medida',
  estado: 'activo',
  marca: { color_primario: null, tiene_logo: false },
};
const SESION = {
  usuario: { id: 9, nombres: 'Marta', apellidos: 'Directora', email: 'marta@colegio.pe' },
  colegios: [COLEGIO],
  colegio_activo: COLEGIO,
  debe_elegir_colegio: false,
  roles_plataforma: [],
  dos_pasos: 'ok',
};
const ANIOS = { data: [{ id: 7, anio: 2027, tipo_periodo: 'bimestre', estado: 'abierto' }] };

function respuesta(estado: number, cuerpo: unknown) {
  return Promise.resolve(new Response(JSON.stringify(cuerpo), { status: estado, headers: { 'Content-Type': 'application/json' } }));
}

type Ruta = (init?: RequestInit) => Promise<Response>;
let llamadas: { url: string; init?: RequestInit }[] = [];
/** Simula la API por método y ruta (sin la consulta). */
function simularApi(rutas: Record<string, Ruta>) {
  llamadas = [];
  vi.stubGlobal('fetch', (url: string, init?: RequestInit) => {
    llamadas.push({ url, init });
    const ruta = new URL(url).pathname.replace(/^\/api\/v1/, '');
    const clave = `${init?.method ?? 'GET'} ${ruta}`;
    return rutas[clave] ? rutas[clave](init) : respuesta(404, { message: `No existe ${clave}` });
  });
}

async function entrar(hash: string) {
  window.location.hash = hash;
  render(<App />);
  const u = userEvent.setup();
  await u.type(screen.getByLabelText('DNI o correo'), 'marta@colegio.pe');
  await u.type(screen.getByLabelText('Contraseña'), 'secreta');
  await u.click(screen.getByRole('button', { name: 'Ingresar' }));
  return u;
}

beforeEach(() => {
  localStorage.clear();
  window.history.replaceState(null, '', '/');
});
afterEach(() => vi.unstubAllGlobals());

describe('Menú académico', () => {
  it('muestra plan de estudios, carga y horarios, personal y alumnos según los permisos', async () => {
    simularApi({ 'POST /auth/ingresar': () => respuesta(200, { ...SESION, token: 't1' }) });
    await entrar('#/inicio');
    const menu = await screen.findByRole('navigation', { name: 'Menú principal' });
    for (const opcion of ['Plan de estudios y secciones', 'Carga docente y horarios', 'Personal', 'Alumnos']) {
      expect(menu).toHaveTextContent(opcion);
    }
  });
});

describe('Horario', () => {
  it('muestra el cruce de docente que devuelve la API', async () => {
    simularApi({
      'POST /auth/ingresar': () => respuesta(200, { ...SESION, token: 't1' }),
      'GET /anios-escolares': () => respuesta(200, ANIOS),
      'GET /cargas': () => respuesta(200, { secciones: [{ seccion_id: 3, seccion: '3.° de Secundaria A', areas: [] }], docentes: [] }),
      'GET /horarios': () =>
        respuesta(200, {
          titulo: 'Horario de 3.° de Secundaria A',
          bloques: [{ id: 11, turno: 'manana', orden: 1, hora_inicio: '08:00', hora_fin: '08:45', es_recreo: false }],
          dias: { 1: 'Lunes', 2: 'Martes' },
          celdas: [],
          cargas: [{ id: 21, area: 'Matemática', docente: 'Ana Torres' }],
          ambientes: [],
        }),
      'PUT /horarios/celda': () => respuesta(422, { message: 'x', errors: { carga_docente_id: ['Ana Torres ya tiene clase el lunes a las 08:00 en 3.° de Secundaria B.'] } }),
    });
    const u = await entrar('#/horarios');
    await u.click(await screen.findByRole('tab', { name: 'Horario' }));
    const celda = await screen.findByLabelText('Lunes 08:00');
    await u.selectOptions(celda, '21');

    expect(await screen.findByRole('alert')).toHaveTextContent('Ana Torres ya tiene clase el lunes a las 08:00 en 3.° de Secundaria B.');
    const put = llamadas.find((l) => l.init?.method === 'PUT');
    expect(JSON.parse(String(put?.init?.body))).toMatchObject({ seccion_id: 3, dia_semana: 1, bloque_horario_id: 11, carga_docente_id: 21 });
  });
});

describe('Alumnos', () => {
  it('registra al alumno con su apoderado responsable de pago y lo matricula', async () => {
    let lista = { data: [] as unknown[] };
    simularApi({
      'POST /auth/ingresar': () => respuesta(200, { ...SESION, token: 't1' }),
      'GET /anios-escolares': () => respuesta(200, ANIOS),
      'GET /secciones': () => respuesta(200, { data: [{ id: 3, grado: '3.° de Secundaria', nombre: 'A' }] }),
      'GET /alumnos': () => respuesta(200, lista),
      'POST /alumnos': () => {
        lista = { data: [{ id: 50, apellido_paterno: 'Mendoza', apellido_materno: null, nombres: 'Tomás', numero_documento: '78111222', codigo_estudiante_siagie: null, seccion: '3.° de Secundaria A' }] };
        return respuesta(201, { id: 50 });
      },
    });
    const u = await entrar('#/alumnos');
    await u.click(await screen.findByRole('button', { name: '+ Registrar alumno' }));
    const dialogo = await screen.findByRole('dialog', { name: 'Registrar alumno' });
    const campos = within(dialogo);

    await u.type(campos.getAllByLabelText('DNI')[0], '78111222');
    await u.type(campos.getByLabelText('Apellido paterno'), 'Mendoza');
    await u.type(campos.getAllByLabelText('Nombres')[0], 'Tomás');
    await u.selectOptions(campos.getByLabelText('Sección del año'), '3');
    await u.type(campos.getAllByLabelText('DNI')[1], '40111333');
    await u.type(campos.getByLabelText('Apellidos'), 'Ríos León');
    await u.type(campos.getAllByLabelText('Nombres')[1], 'Inés');
    await u.click(campos.getByRole('button', { name: 'Registrar alumno' }));

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(await screen.findByText('Mendoza, Tomás')).toBeInTheDocument();
    const post = llamadas.find((l) => l.init?.method === 'POST' && l.url.endsWith('/alumnos'));
    const cuerpo = JSON.parse(String(post?.init?.body));
    expect(cuerpo).toMatchObject({ numero_documento: '78111222', apellido_paterno: 'Mendoza', nombres: 'Tomás', seccion_id: 3 });
    expect(cuerpo.apoderados[0]).toMatchObject({ numero_documento: '40111333', apellidos: 'Ríos León', nombres: 'Inés', parentesco: 'madre', es_responsable_pago: true });
  });
});
