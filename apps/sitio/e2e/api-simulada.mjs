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
const NOMBRES = ['Matrículas', 'Pagos', 'Calificaciones', 'Aula virtual', 'Comunicados', 'Apps móviles', 'Plataforma', 'Seguridad', 'SIAGIE', 'Documentos', 'Moodle', 'Biblioteca', 'Movilidad', 'Comedor', 'Enfermería', 'Tienda', 'Portería', 'Laboratorios'];
const MODULOS = NOMBRES.map((nombre, i) => ({
  clave: `m${i + 1}`,
  nombre,
  icono: 'widgets',
  descripcion: `Descripción de ${nombre}.`,
  etiqueta: i < 6 ? `Etiqueta ${i + 1}` : null,
  en_grid: i < 6,
  imagen: i === 0 ? { url: '/favicon.ico', alt: 'Vista de matrículas', ancho: 1200, alto: 800 } : null,
}));

// Contenido publicado de la landing; las pruebas lo cambian con POST /__publicar.
let titulo = 'Administra tu colegio desde un solo lugar';
const landing = () => ({
  secciones: {
    barra_superior: { aviso: 'Plataforma para colegios privados del Perú' },
    modulos: { insignia: 'Ecosistema Modular', titulo: 'Módulos para colegios', descripcion: null, boton_ver: 'Ver los {n} módulos del sistema escolar', boton_ocultar: 'Ocultar catálogo de módulos' },
    hero: {
      insignia: 'Gestión escolar K-12',
      titulo,
      subtitulo: 'Matrículas, notas, pagos y comunicados en un solo sistema.',
      cta_principal: 'Solicitar demo gratuita',
      cta_secundario: 'Ver video demostrativo',
      sellos: 'Implementación asistida · Soporte local en Perú',
    },
    // Sprint 5
    retos: {
      insignia: 'Diagnóstico',
      titulo: 'Los retos de la dirección escolar',
      descripcion: null,
      etiqueta_dolor: 'El dolor habitual',
      etiqueta_solucion: 'Solución Edunova',
      tarjetas: [
        { icono: 'grid_off', titulo: 'Datos dispersos', dolor: 'Hojas de cálculo sueltas.', solucion: 'Una sola base.', visible: true },
        { icono: 'ring_volume', titulo: 'Reto oculto', dolor: 'No debe verse.', solucion: 'No debe verse.', visible: false },
        { icono: 'alarm_off', titulo: 'Notas tarde al SIAGIE', dolor: 'Fin de bimestre a mano.', solucion: 'Exportación de actas.', visible: true },
      ],
    },
    pasos: {
      insignia: 'Acompañamiento',
      titulo: 'Implementación en 4 pasos',
      descripcion: null,
      pasos: ['Solicitas la demo', 'Configuramos tu colegio', 'Capacitamos a tu equipo', 'Empiezas a operar'].map((t, i) => ({ titulo: t, descripcion: `Paso ${i + 1}.`, nota: null, visible: true })),
    },
    casos_uso: { insignia: 'Modelos', titulo: 'Modelos por tamaño de colegio', descripcion: null, tarjetas: [{ icono: 'cottage', rango: 'Hasta 150 alumnos', titulo: 'En crecimiento', descripcion: 'Colegios pequeños.', beneficios: ['Menos trabajo en secretaría.'], modulos: null, nota: null, visible: true }] },
    testimonios: { insignia: 'Testimonios', titulo: 'Lo que cambia en el colegio', leyenda_ejemplo: 'Testimonios de ejemplo: se reemplazan por testimonios reales con consentimiento firmado.' },
    videos: { insignia: 'Videos', titulo: 'Edunova en video', descripcion: null, boton_demo: 'Coordinar demostración en vivo' },
    planes: { insignia: 'Tarifas', titulo: 'Inversión transparente', descripcion: null, mensual: 'Mensual', anual: 'Anual', por_mes: '/ mes', personalizado: 'Personalizado', personalizado_nota: 'Cotización a medida' },
    cta_final: { insignia: null, titulo: '¿Listo para modernizar tu colegio?', descripcion: null, boton: 'Solicitar demo gratuita', beneficios: ['Sin compromiso'] },
    footer: { descripcion: 'Gestión escolar para colegios privados.', derechos: 'Todos los derechos reservados.', moneda: 'Moneda: Soles (S/)' },
  },
  config: { telefono_central: null, ruc: null, razon_social: null, direccion: null, email_contacto: null, horario_soporte: null, nota_legal_precios: 'Precios referenciales sin IGV · sujeto a validación comercial', selector_anual_visible: true, descuento_anual_pct: 10 },
  modulos: MODULOS,
  planes: [
    { clave: 'inicial', nombre: 'Plan Inicial', rango_texto: 'Hasta 150 alumnos', precio_mensual: '300.00', descripcion: null, caracteristicas: [{ texto: 'Matrícula', incluida: true }, { texto: 'Facturación electrónica', incluida: false }], recomendado: false, cta_texto: 'Seleccionar plan' },
    { clave: 'crecimiento', nombre: 'Plan Crecimiento', rango_texto: 'Hasta 300 alumnos', precio_mensual: '500.00', descripcion: null, caracteristicas: [{ texto: 'Todo lo del Inicial', incluida: true }], recomendado: true, cta_texto: 'Elegir Crecimiento' },
    { clave: 'institucional', nombre: 'Institucional', rango_texto: 'Más de 500 alumnos', precio_mensual: null, descripcion: null, caracteristicas: [], recomendado: false, cta_texto: 'Cotizar' },
  ],
  complementos: [{ clave: 'tramo_501_800', etiqueta: '501 a 800 alumnos', precio: '1234.00', nota: 'al mes' }],
  oferta: { titulo: '¿Vienes de otro sistema?', texto: 'Migración gratis.', cta: 'Quiero migrar' },
  contadores: [
    { label: 'módulos integrados', valor: 18, prefijo: null, sufijo: null },
    { label: 'roles con permisos propios', valor: 25, prefijo: null, sufijo: null },
    { label: 'consulta para las familias', valor: 24, prefijo: null, sufijo: '/7' },
  ],
  videos: [{ id: 1, titulo: 'Recorrido general', descripcion: 'Panel de dirección.', proveedor: 'youtube', embed_url: 'https://www.youtube-nocookie.com/embed/abc123', duracion_seg: 270, miniatura: null, miniatura_alt: null }],
  testimonios: [{ id: 1, nombre: null, cargo: 'Directora', institucion: 'Colegio particular · Arequipa', texto: 'Cerramos el bimestre sin pasar notas a mano.', calificacion: 5, es_ejemplo: true, avatar: null }],
  menu: {
    principal: [
      { label: 'Funcionalidades', url: '/funcionalidades', target: '_self', columna: null },
      { label: 'Planes', url: '/#planes', target: '_self', columna: null },
    ],
    footer: [
      { label: 'Módulos', url: '/#funcionalidades', target: '_self', columna: 'Plataforma' },
      { label: 'Política de privacidad', url: '/#privacidad', target: '_self', columna: 'Legal' },
      { label: 'Libro de Reclamaciones', url: '/libro-de-reclamaciones', target: '_self', columna: 'Legal' },
    ],
  },
});

// Biblioteca de medios en memoria (EDUN-237).
const medios = [];

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
    if (req.method === 'GET' && req.url === '/api/v1/sitio/landing') return json(res, 200, landing());
    if (req.method === 'POST' && req.url === '/__publicar') {
      titulo = JSON.parse(datos || '{}').titulo ?? titulo;
      return json(res, 200, { titulo });
    }
    if (req.url.startsWith('/api/v1/cms/medios')) {
      if (!POR_TOKEN[token]?.roles_plataforma.includes('superadmin')) return json(res, 403, { message: 'No tienes acceso al Panel CMS.' });
      if (req.method === 'GET') return json(res, 200, { data: medios });
      const nombre = /filename="([^"]+)"/.exec(datos)?.[1] ?? 'archivo';
      // Valor del campo alt del multipart: va dos líneas después de su cabecera.
      const lineas = datos.split(String.fromCharCode(10)).map((l) => l.replace(String.fromCharCode(13), ''));
      const i = lineas.findIndex((l) => l.includes('name="alt"'));
      const alt = i >= 0 ? lineas[i + 2] || null : null;
      const medio = { id: medios.length + 1, tipo: 'imagen', nombre, url: '/favicon.ico', mime: 'image/png', bytes: datos.length, ancho: 1200, alto: 800, alt };
      medios.unshift(medio);
      return json(res, 201, { data: medio, message: 'Archivo subido.' });
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
