import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from './App';

// Sprint 14 · Familia (consentimientos, carné) y matrícula en ventanilla desde el expediente.

function sesion(roles: string[], permisos: string[]) {
  const colegio = { id: 1, nombre: 'IEP San Martín de Porres', slug: 'smp', roles, permisos, modulos: ['PLA', 'SEG', 'ADM'], aula_opcion: 'medida', estado: 'activo', marca: { color_primario: null, tiene_logo: false } };
  return { usuario: { id: 9, nombres: 'Elena', apellidos: 'Paredes', email: 'elena@correo.pe' }, colegios: [colegio], colegio_activo: colegio, debe_elegir_colegio: false, roles_plataforma: [], dos_pasos: 'ok', token: 't1' };
}

function respuesta(estado: number, cuerpo: unknown) {
  return Promise.resolve(new Response(JSON.stringify(cuerpo), { status: estado, headers: { 'Content-Type': 'application/json' } }));
}

let llamadas: { url: string; init?: RequestInit }[] = [];
function simularApi(rutas: Record<string, (init?: RequestInit) => Promise<Response>>) {
  llamadas = [];
  vi.stubGlobal('fetch', (url: string, init?: RequestInit) => {
    llamadas.push({ url, init });
    const clave = `${init?.method ?? 'GET'} ${new URL(url).pathname.replace(/^\/api\/v1/, '')}`;
    return rutas[clave] ? rutas[clave](init) : respuesta(404, { message: `No existe ${clave}` });
  });
}

async function entrar(hash: string) {
  window.location.hash = hash;
  render(<App />);
  const u = userEvent.setup();
  await u.type(screen.getByLabelText('DNI o correo'), 'elena@correo.pe');
  await u.type(screen.getByLabelText('Contraseña'), 'secreta');
  await u.click(screen.getByRole('button', { name: 'Ingresar' }));
  return u;
}

const PENDIENTE = { estado: 'pendiente', version: null, fecha: null, desactualizado: false };

beforeEach(() => {
  localStorage.clear();
  window.history.replaceState(null, '', '/');
});
afterEach(() => vi.unstubAllGlobals());

describe('Familia', () => {
  it('el apoderado lee el texto y autoriza el uso de imagen de su hijo', async () => {
    simularApi({
      'POST /auth/ingresar': () => respuesta(200, sesion(['apoderado'], [])),
      'GET /me/familia': () =>
        respuesta(200, {
          hijos: [{ id: 5, nombres: 'Mateo', apellido_paterno: 'Condori', apellido_materno: null, seccion: '1.° de Primaria A', consentimientos: { datos: PENDIENTE, imagen: PENDIENTE } }],
          textos: { datos: { version: '2027.1', contenido: 'Texto de datos.' }, imagen: { version: '2027.1', contenido: 'Autorizo el uso de la imagen de mi hijo.' } },
          finalidades: { datos: 'Tratamiento de datos personales', imagen: 'Uso de la imagen del alumno' },
          condiciones: null,
        }),
      'POST /me/consentimientos': () =>
        respuesta(200, { consentimientos: { datos: PENDIENTE, imagen: { estado: 'otorgado', version: '2027.1', fecha: '2027-03-01', desactualizado: false } } }),
    });
    const u = await entrar('#/familia');

    expect(await screen.findByRole('heading', { name: 'Mateo Condori' })).toBeInTheDocument();
    const botones = screen.getAllByRole('button', { name: 'Leer y decidir' });
    await u.click(botones[1]);
    const dialogo = await screen.findByRole('dialog', { name: 'Uso de la imagen del alumno' });
    expect(within(dialogo).getByText('Autorizo el uso de la imagen de mi hijo.')).toBeInTheDocument();
    await u.click(within(dialogo).getByRole('button', { name: 'Sí, autorizo' }));

    expect(await screen.findByText('Autorizado')).toBeInTheDocument();
    const post = llamadas.find((l) => l.init?.method === 'POST' && l.url.endsWith('/me/consentimientos'));
    expect(JSON.parse(String(post?.init?.body))).toEqual({ alumno_id: 5, finalidad: 'imagen', otorgado: true });
    expect(screen.getByRole('button', { name: 'Revocar' })).toBeInTheDocument();
  });
});

describe('Matrícula en ventanilla', () => {
  it('pide la aceptación de las condiciones y matricula desde el expediente', async () => {
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: () => 'blob:x', revokeObjectURL: () => undefined }));
    const permisos = ['adm.alumnos.ver', 'adm.alumnos.crear', 'adm.alumnos.editar', 'adm.matricula.ver', 'adm.matricula.crear'];
    simularApi({
      'POST /auth/ingresar': () => respuesta(200, sesion(['secretaria'], permisos)),
      'GET /anios-escolares': () => respuesta(200, { data: [{ id: 7, anio: 2027, tipo_periodo: 'bimestre', estado: 'abierto' }] }),
      'GET /secciones': () => respuesta(200, { data: [{ id: 3, grado: '1.° de Primaria', nombre: 'A' }] }),
      'GET /alumnos': () => respuesta(200, { data: [{ id: 5, apellido_paterno: 'Condori', apellido_materno: null, nombres: 'Mateo', numero_documento: '77445566', codigo_estudiante_siagie: null, seccion: null }] }),
      'GET /alumnos/5/expediente': () =>
        respuesta(200, {
          id: 5, numero_documento: '77445566', codigo_estudiante_siagie: null, apellido_paterno: 'Condori', apellido_materno: null, nombres: 'Mateo', sexo: null, fecha_nacimiento: null,
          apoderados: [{ id: 10, numero_documento: '41333444', apellidos: 'Paredes Soto', nombres: 'Elena', parentesco: 'madre', celular: null, email: null, es_responsable_pago: true, puede_recoger: true, recibe_notificaciones: true }],
          autorizados: [], matriculas: [], historial: [], consentimientos: { datos: PENDIENTE, imagen: PENDIENTE }, secciones: [],
        }),
      'GET /condiciones-economicas': () => respuesta(200, { data: [{ id: 1, version: 2, contenido: 'Pensión de marzo a diciembre.', publicado_at: '2027-01-10', aceptaciones: 0 }] }),
      'POST /matriculas': () => respuesta(201, { matricula_id: 44, cargos: 10, aviso: null }),
      'GET /matriculas/44/constancia': () => Promise.resolve(new Response('%PDF', { status: 200, headers: { 'Content-Type': 'application/pdf' } })),
    });
    const u = await entrar('#/alumnos');
    await u.click(await screen.findByRole('button', { name: 'Ficha' }));
    await u.click(await screen.findByRole('tab', { name: 'Matrícula e historial' }));

    const boton = await screen.findByRole('button', { name: 'Matricular e imprimir constancia' });
    const ficha = within(screen.getByRole('dialog', { name: 'Ficha del alumno' }));
    await u.selectOptions(ficha.getByLabelText('Sección'), '3');
    expect(boton).toBeDisabled();
    await u.click(ficha.getByLabelText(/acepta las condiciones económicas/));
    await u.click(boton);

    await waitFor(() => expect(llamadas.some((l) => l.url.endsWith('/matriculas/44/constancia'))).toBe(true));
    const post = llamadas.find((l) => l.init?.method === 'POST' && l.url.endsWith('/matriculas'));
    expect(JSON.parse(String(post?.init?.body))).toEqual({ alumno_id: 5, seccion_id: 3, apoderado_id: 10, acepta_condiciones: true });
    expect(await screen.findByText(/Matrícula registrada con 10 cargos/)).toBeInTheDocument();
  });
});
