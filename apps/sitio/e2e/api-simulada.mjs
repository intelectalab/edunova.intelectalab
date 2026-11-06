// API simulada para las pruebas de pantalla del sitio (Playwright). Responde lo mínimo de
// /api/v1/auth y /api/v1/cms con el mismo formato que la API real (apps/api).
// Las pruebas de la API real están en apps/api/tests (Pest).
import { createServer } from 'node:http';

const PUERTO = Number(process.env.PUERTO_API_SIMULADA ?? 8199);
const CLAVE = 'clave-de-prueba';

const USUARIOS = {
  'superadmin@demo.edunova.pe': { token: 'tk-superadmin', nombre: 'Ana Administradora', roles_plataforma: ['superadmin'] },
  'director@demo.edunova.pe': { token: 'tk-director', nombre: 'María Cárdenas', roles_plataforma: [] },
};
const POR_TOKEN = Object.fromEntries(Object.values(USUARIOS).map((u) => [u.token, u]));

function json(res, estado, cuerpo) {
  res.writeHead(estado, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify(cuerpo));
}

createServer((req, res) => {
  let datos = '';
  req.on('data', (c) => (datos += c));
  req.on('end', () => {
    const token = (req.headers.authorization ?? '').replace('Bearer ', '');
    if (req.url === '/api/v1/salud') return json(res, 200, { estado: 'ok' });
    if (req.method === 'POST' && req.url === '/api/v1/auth/ingresar') {
      const { usuario, password } = JSON.parse(datos || '{}');
      const u = USUARIOS[usuario];
      if (!u || password !== CLAVE) {
        return json(res, 422, { message: 'x', errors: { usuario: ['El documento, correo o contraseña no son correctos.'] } });
      }
      return json(res, 200, { token: u.token, usuario: { nombres: u.nombre }, colegios: [], roles_plataforma: u.roles_plataforma });
    }
    if (req.method === 'POST' && req.url === '/api/v1/auth/salir') return json(res, 200, { message: 'Sesión cerrada.' });
    if (req.method === 'GET' && req.url === '/api/v1/cms/sesion') {
      const u = POR_TOKEN[token];
      if (!u) return json(res, 401, { message: 'Inicia sesión para continuar.' });
      if (!u.roles_plataforma.includes('superadmin')) return json(res, 403, { message: 'No tienes acceso al Panel CMS.' });
      return json(res, 200, { usuario: { id: 1, nombre: u.nombre, rol: 'superadmin' }, rol_cms: 'superadmin' });
    }
    json(res, 404, { message: 'No encontramos lo que buscas.' });
  });
}).listen(PUERTO, '127.0.0.1');
