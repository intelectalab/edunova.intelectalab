import { describe, expect, it, vi } from 'vitest';
import { crearCliente, ErrorApi, mensajeDe, siguientePaso, type EstadoSesion } from './api';
import { destinoDeAviso } from './destinos';
import { colorDeMarca, iniciales, textoSobre, VERDE_EDUNOVA } from './marca';

const estado = (cambios: Partial<EstadoSesion>): EstadoSesion => ({
  usuario: { id: 1, nombres: 'Ana', apellidos: 'Pérez', email: null },
  colegios: [{ id: 3, nombre: 'IEP Los Andes', slug: 'andes', roles: ['apoderado'] }],
  colegio_activo: null,
  debe_elegir_colegio: false,
  dos_pasos: 'no_requerido',
  ...cambios,
});

describe('cliente de la API (EDUN-195)', () => {
  it('envía el token y el colegio activo, y devuelve el JSON', async () => {
    const fetcher = vi.fn().mockResolvedValue(new Response(JSON.stringify({ ok: true }), { status: 200 }));
    const api = crearCliente('https://api.prueba/api/v1/', () => ({ token: 'T1', colegio: 3 }), fetcher);
    await expect(api.pedir('/auth/yo')).resolves.toEqual({ ok: true });
    const [url, init] = fetcher.mock.calls[0];
    expect(url).toBe('https://api.prueba/api/v1/auth/yo');
    expect(init.headers).toMatchObject({ Authorization: 'Bearer T1', 'X-Institucion': '3', Accept: 'application/json' });
  });

  it('convierte los errores de Laravel en mensajes legibles', async () => {
    const fetcher = vi.fn().mockResolvedValue(new Response(JSON.stringify({ message: 'x', errors: { usuario: ['Escribe tu DNI o correo.'] } }), { status: 422 }));
    const api = crearCliente('https://api.prueba', () => ({ token: null, colegio: null }), fetcher);
    const error = (await api.pedir('/auth/ingresar', { metodo: 'POST', cuerpo: {} }).catch((e: unknown) => e)) as ErrorApi;
    expect(error).toBeInstanceOf(ErrorApi);
    expect(error.message).toBe('Escribe tu DNI o correo.');
    expect(error.estado).toBe(422);
    expect(mensajeDe(401, null)).toMatch(/sesión/);
    expect(mensajeDe(500, null)).toMatch(/no responde/);
  });

  it('avisa cuando no hay conexión', async () => {
    const api = crearCliente('https://api.prueba', () => ({ token: null, colegio: null }), vi.fn().mockRejectedValue(new TypeError('Network request failed')));
    await expect(api.pedir('/auth/yo')).rejects.toMatchObject({ estado: 0, message: expect.stringMatching(/conexión/) });
  });

  it('decide la siguiente pantalla tras ingresar', () => {
    expect(siguientePaso(estado({ dos_pasos: 'verificar' }))).toBe('dos_pasos');
    expect(siguientePaso(estado({ dos_pasos: 'configurar' }))).toBe('configurar_dos_pasos');
    expect(siguientePaso(estado({ colegios: [] }))).toBe('sin_colegio');
    expect(siguientePaso(estado({}))).toBe('elegir_colegio');
    expect(siguientePaso(estado({ colegio_activo: { id: 3, nombre: 'IEP Los Andes', slug: 'andes', roles: [] } }))).toBe('inicio');
  });
});

describe('imagen del colegio (EDUN-200)', () => {
  it('usa el color del colegio o el de Edunova', () => {
    expect(colorDeMarca('#1D4ED8')).toBe('#1d4ed8');
    expect(colorDeMarca(null)).toBe(VERDE_EDUNOVA);
    expect(colorDeMarca('azul')).toBe(VERDE_EDUNOVA);
  });

  it('elige el texto que más contrasta', () => {
    expect(textoSobre('#047857')).toBe('#ffffff');
    expect(textoSobre('#fde047')).toBe('#111827');
  });

  it('saca las iniciales sin el prefijo del colegio', () => {
    expect(iniciales('IEP Los Andes')).toBe('LA');
    expect(iniciales('Colegio San Martín de Porres')).toBe('SM');
  });
});

describe('abrir la pantalla del aviso (EDUN-196)', () => {
  it('abre la pantalla indicada o el inicio con el aviso', () => {
    expect(destinoDeAviso({ pantalla: 'notificaciones' })).toEqual({ pantalla: 'notificaciones', id: undefined, tipo: undefined });
    expect(destinoDeAviso({ pantalla: 'pagos', id: '12', tipo: 'pagos' }, 'Vence tu pensión')).toEqual({ pantalla: 'inicio', id: 12, tipo: 'pagos', aviso: 'Vence tu pensión' });
    expect(destinoDeAviso(null)).toEqual({ pantalla: 'inicio', id: undefined, tipo: undefined, aviso: undefined });
  });
});
