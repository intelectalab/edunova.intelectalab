import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from './App';

// Sprint 12 · Segundo factor (EDUN-38), menú por permisos y módulos (EDUN-25/26),
// invitación (EDUN-36) y consola de la plataforma (EDUN-24).

const USUARIO = { id: 9, nombres: 'Marta', apellidos: 'Directora', email: 'marta@colegio.pe' };
const COLEGIO = {
  id: 1,
  nombre: 'IEP San Martín de Porres',
  slug: 'smp',
  roles: ['director'],
  permisos: ['adm.configuracion.ver', 'seg.usuarios.ver', 'seg.auditoria.ver'],
  modulos: ['PLA', 'SEG', 'ADM', 'AUL'],
  aula_opcion: 'medida',
  estado: 'activo',
  marca: { color_primario: '#1d4ed8', tiene_logo: false },
};
const SESION = { usuario: USUARIO, colegios: [COLEGIO], colegio_activo: COLEGIO, debe_elegir_colegio: false, roles_plataforma: [], dos_pasos_activo: true };

function respuesta(estado: number, cuerpo: unknown) {
  return Promise.resolve(new Response(JSON.stringify(cuerpo), { status: estado, headers: { 'Content-Type': 'application/json' } }));
}

let llamadas: { url: string; init?: RequestInit }[] = [];
function simularApi(rutas: Record<string, () => Promise<Response>>) {
  llamadas = [];
  vi.stubGlobal('fetch', (url: string, init?: RequestInit) => {
    llamadas.push({ url, init });
    const ruta = Object.keys(rutas).find((r) => url.endsWith(r));
    return ruta ? rutas[ruta]() : respuesta(404, { message: 'No existe' });
  });
}

async function ingresar() {
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

describe('Segundo factor', () => {
  it('el director confirma el código de su app antes de entrar al colegio', async () => {
    simularApi({
      '/auth/ingresar': () => respuesta(200, { ...SESION, token: 't1', dos_pasos: 'verificar', colegio_activo: { ...COLEGIO, permisos: undefined } }),
      '/auth/dos-pasos/verificar': () => respuesta(200, { message: 'Identidad confirmada.', uso_respaldo: false, respaldos_restantes: 10 }),
      '/auth/yo': () => respuesta(200, { ...SESION, dos_pasos: 'ok' }),
    });
    render(<App />);
    const u = await ingresar();

    expect(await screen.findByRole('heading', { name: 'Confirma que eres tú' })).toBeInTheDocument();
    await u.type(screen.getByLabelText('Código de la app'), '123456');
    await u.click(screen.getByRole('button', { name: 'Confirmar' }));

    expect(await screen.findByRole('heading', { name: 'Hola, Marta' })).toBeInTheDocument();
    const verificar = llamadas.find((l) => l.url.endsWith('/auth/dos-pasos/verificar'));
    expect(JSON.parse(String(verificar?.init?.body))).toEqual({ codigo: '123456' });
    expect(new Headers(verificar?.init?.headers).get('Authorization')).toBe('Bearer t1');
  });

  it('un código errado muestra el mensaje de la API', async () => {
    simularApi({
      '/auth/ingresar': () => respuesta(200, { ...SESION, token: 't1', dos_pasos: 'verificar' }),
      '/auth/dos-pasos/verificar': () => respuesta(422, { message: 'x', errors: { codigo: ['El código no es correcto o ya venció.'] } }),
    });
    render(<App />);
    const u = await ingresar();
    await u.type(await screen.findByLabelText('Código de la app'), '000000');
    await u.click(screen.getByRole('button', { name: 'Confirmar' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('El código no es correcto o ya venció.');
  });

  it('la primera vez muestra el QR y luego los códigos de respaldo', async () => {
    simularApi({
      '/auth/ingresar': () => respuesta(200, { ...SESION, token: 't1', dos_pasos: 'configurar', dos_pasos_activo: false }),
      '/auth/dos-pasos/iniciar': () => respuesta(200, { secreto: 'ABCD EFGH', uri: 'otpauth://totp/x', qr: 'data:image/svg+xml;base64,PHN2Zy8+' }),
      '/auth/dos-pasos/activar': () => respuesta(200, { codigos_respaldo: ['aaaaa-bbbbb', 'ccccc-ddddd'] }),
    });
    render(<App />);
    const u = await ingresar();
    await u.click(await screen.findByRole('button', { name: 'Mostrar el código QR' }));
    expect(await screen.findByAltText('Código QR para la app autenticadora')).toBeInTheDocument();
    await u.type(screen.getByLabelText('Código de la app'), '654321');
    await u.click(screen.getByRole('button', { name: 'Activar' }));
    expect(await screen.findByText('aaaaa-bbbbb')).toBeInTheDocument();
  });
});

describe('Menú por permisos y módulos', () => {
  it('muestra solo las opciones con permiso y módulo encendido', async () => {
    simularApi({ '/auth/ingresar': () => respuesta(200, { ...SESION, token: 't1', dos_pasos: 'ok' }) });
    render(<App />);
    await ingresar();

    const menu = await screen.findByRole('navigation', { name: 'Menú principal' });
    expect(menu).toHaveTextContent('Configuración del colegio');
    expect(menu).toHaveTextContent('Usuarios');
    expect(menu).toHaveTextContent('Auditoría');
    // Sin el módulo de Moodle (aula a medida), no aparece su conector.
    expect(menu).not.toHaveTextContent('Moodle');
  });

  it('con Moodle encendido y permiso aparece el conector; sin permisos solo inicio y perfil', async () => {
    const conMoodle = { ...COLEGIO, permisos: ['mdl.conector.ver'], modulos: ['PLA', 'SEG', 'ADM', 'MDL'] };
    simularApi({ '/auth/ingresar': () => respuesta(200, { ...SESION, colegios: [conMoodle], colegio_activo: conMoodle, token: 't1', dos_pasos: 'ok' }) });
    render(<App />);
    await ingresar();
    const menu = await screen.findByRole('navigation', { name: 'Menú principal' });
    expect(menu).toHaveTextContent('Moodle');
    expect(menu).not.toHaveTextContent('Usuarios');
    expect(menu).not.toHaveTextContent('Configuración del colegio');
  });
});

describe('Invitación', () => {
  it('el invitado crea su contraseña con el enlace', async () => {
    const token = 'a'.repeat(48);
    window.history.replaceState(null, '', `/?invitacion=${token}`);
    simularApi({
      [`/invitaciones/${token}`]: () => respuesta(200, { colegio: 'IEP San Martín de Porres', nombres: 'Lucía', apellidos: 'Pérez', email: null, tiene_password: false }),
      [`/invitaciones/${token}/aceptar`]: () => respuesta(200, { message: 'Listo.' }),
    });
    render(<App />);
    const u = userEvent.setup();

    expect(await screen.findByRole('heading', { name: 'Hola, Lucía' })).toBeInTheDocument();
    await u.type(screen.getByLabelText('Contraseña nueva'), 'ClaveSegura123');
    await u.type(screen.getByLabelText('Repite la contraseña'), 'ClaveSegura123');
    await u.click(screen.getByRole('button', { name: 'Crear contraseña' }));

    expect(await screen.findByRole('heading', { name: '¡Listo!' })).toBeInTheDocument();
    const aceptar = llamadas.find((l) => l.url.endsWith('/aceptar'));
    expect(JSON.parse(String(aceptar?.init?.body))).toEqual({ password: 'ClaveSegura123', password_confirmation: 'ClaveSegura123' });
  });

  it('un enlace usado o vencido lo dice claramente', async () => {
    const token = 'b'.repeat(48);
    window.history.replaceState(null, '', `/?invitacion=${token}`);
    simularApi({ [`/invitaciones/${token}`]: () => respuesta(410, { message: 'El enlace ya se usó o venció. Pide una invitación nueva.' }) });
    render(<App />);
    expect(await screen.findByRole('alert')).toHaveTextContent('El enlace ya se usó o venció.');
  });
});

describe('Consola de la plataforma', () => {
  it('el staff sin colegio entra a la lista de colegios', async () => {
    simularApi({
      '/auth/ingresar': () =>
        respuesta(200, { usuario: USUARIO, colegios: [], colegio_activo: null, debe_elegir_colegio: false, roles_plataforma: ['superadmin'], dos_pasos: 'ok', token: 't1' }),
      '/plataforma/instituciones': () =>
        respuesta(200, {
          data: [{ id: 1, nombre: 'IEP San Martín de Porres', slug: 'smp', codigo_modular: '0784512', nivel_educativo: 'multinivel', estado: 'activo', plan: 'P500', aula_opcion: 'medida', modulos_extra: ['MOV'], alumnos_activos: 8 }],
        }),
    });
    render(<App />);
    await ingresar();
    expect(await screen.findByRole('heading', { name: 'Colegios' })).toBeInTheDocument();
    expect(await screen.findByText('IEP San Martín de Porres')).toBeInTheDocument();
    expect(screen.getByText('Activo')).toBeInTheDocument();
  });
});
