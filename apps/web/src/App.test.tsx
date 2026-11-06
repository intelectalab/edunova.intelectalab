import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from './App';

// EDUN-35 · Ingreso a la web de gestión: DNI o correo, error claro y elección de colegio.

const COLEGIO_A = { id: 1, nombre: 'IEP San Martín de Porres', slug: 'smp', roles: ['secretaria'] };
const COLEGIO_B = { id: 2, nombre: 'Colegio Segundo', slug: 'seg', roles: ['director'] };
const USUARIO = { id: 9, nombres: 'Rosa', apellidos: 'Pérez', email: 'rosa@colegio.pe' };

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

async function ingresar(usuario: string, clave = 'secreta') {
  const u = userEvent.setup();
  await u.type(screen.getByLabelText('DNI o correo'), usuario);
  await u.type(screen.getByLabelText('Contraseña'), clave);
  await u.click(screen.getByRole('button', { name: 'Ingresar' }));
  return u;
}

beforeEach(() => localStorage.clear());
afterEach(() => vi.unstubAllGlobals());

describe('Ingreso', () => {
  it('con un solo colegio entra directo y muestra su nombre', async () => {
    simularApi({
      '/auth/ingresar': () =>
        respuesta(200, { token: 't1', usuario: USUARIO, colegios: [COLEGIO_A], colegio_activo: COLEGIO_A, debe_elegir_colegio: false, roles_plataforma: [] }),
    });
    render(<App />);

    await ingresar('29000123');

    expect(await screen.findByRole('heading', { name: 'Hola, Rosa' })).toBeInTheDocument();
    expect(screen.getByText('IEP San Martín de Porres')).toBeInTheDocument();
    expect(JSON.parse(String(llamadas[0].init?.body))).toMatchObject({ usuario: '29000123', password: 'secreta' });
    expect(localStorage.getItem('edunova.token')).toBe('t1');
  });

  it('muestra el mensaje de la API si los datos no son correctos', async () => {
    simularApi({
      '/auth/ingresar': () => respuesta(422, { message: 'x', errors: { usuario: ['El documento, correo o contraseña no son correctos.'] } }),
    });
    render(<App />);

    await ingresar('rosa@colegio.pe', 'mala');

    expect(await screen.findByRole('alert')).toHaveTextContent('El documento, correo o contraseña no son correctos.');
    expect(localStorage.getItem('edunova.token')).toBeNull();
  });

  it('con varios colegios pide elegir uno y entra a ese', async () => {
    simularApi({
      '/auth/ingresar': () =>
        respuesta(200, { token: 't2', usuario: USUARIO, colegios: [COLEGIO_A, COLEGIO_B], colegio_activo: null, debe_elegir_colegio: true, roles_plataforma: [] }),
      '/auth/colegio': () =>
        respuesta(200, { usuario: USUARIO, colegios: [COLEGIO_A, COLEGIO_B], colegio_activo: COLEGIO_B, debe_elegir_colegio: false, roles_plataforma: [] }),
    });
    render(<App />);

    const u = await ingresar('rosa@colegio.pe');
    expect(await screen.findByRole('heading', { name: 'Elige el colegio' })).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: /Colegio Segundo/ }));

    expect(await screen.findByText('Colegio Segundo')).toBeInTheDocument();
    const elegir = llamadas.find((l) => l.url.endsWith('/auth/colegio'));
    expect(elegir).toBeDefined();
    const init = elegir!.init!;
    expect(JSON.parse(String(init.body))).toEqual({ institucion_id: 2 });
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer t2');
  });

  it('sin conexión con la API muestra un mensaje claro', async () => {
    vi.stubGlobal('fetch', () => Promise.reject(new TypeError('Failed to fetch')));
    render(<App />);

    await ingresar('29000123');

    expect(await screen.findByRole('alert')).toHaveTextContent('No pudimos conectar con Edunova');
  });

  it('cerrar sesión vuelve al ingreso y borra el token', async () => {
    simularApi({
      '/auth/ingresar': () =>
        respuesta(200, { token: 't1', usuario: USUARIO, colegios: [COLEGIO_A], colegio_activo: COLEGIO_A, debe_elegir_colegio: false, roles_plataforma: [] }),
      '/auth/salir': () => respuesta(200, { message: 'Sesión cerrada.' }),
    });
    render(<App />);
    const u = await ingresar('29000123');

    await u.click(await screen.findByRole('button', { name: 'Cerrar sesión' }));

    expect(await screen.findByRole('heading', { name: 'Ingresa a tu cuenta' })).toBeInTheDocument();
    expect(localStorage.getItem('edunova.token')).toBeNull();
  });
});
