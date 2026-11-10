// API simulada para las pruebas de pantalla del sitio (Playwright). Responde lo mínimo de
// /api/v1/auth y /api/v1/cms con el mismo formato que la API real (apps/api).
// Las pruebas de la API real están en apps/api/tests (Pest).
import { createServer } from 'node:http';

import { atenderPanel, popupLanding } from './api-simulada-panel.mjs';

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
    { clave: 'integral', nombre: 'Plan Integral', rango_texto: 'Hasta 500 alumnos', precio_mensual: '800.00', descripcion: null, caracteristicas: [{ texto: 'Todo lo del Crecimiento', incluida: true }], recomendado: false, cta_texto: 'Seleccionar Plan' },
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
  seo: { titulo: 'Edunova · Gestión escolar', descripcion: 'Software de gestión escolar para colegios privados del Perú.', canonical: null, robots: 'index,follow', keywords: ['gestión escolar'], og_titulo: 'Edunova', og_descripcion: 'Gestión escolar integral.', og_imagen: null },
  faq: [{ pregunta: '¿Funciona con SIAGIE?', respuesta: 'Exporta las actas en el formato de MINEDU.' }],
  testimonios: [{ id: 1, nombre: null, cargo: 'Directora', institucion: 'Colegio particular · Arequipa', texto: 'Cerramos el bimestre sin pasar notas a mano.', calificacion: 5, es_ejemplo: true, avatar: null }],
  menu: {
    principal: [
      { label: 'Funcionalidades', url: '/funcionalidades', target: '_self', columna: null },
      { label: 'Planes', url: '/#planes', target: '_self', columna: null },
    ],
    footer: [
      { label: 'Módulos', url: '/#funcionalidades', target: '_self', columna: 'Plataforma' },
      { label: 'Política de privacidad', url: '/#privacidad', target: '_self', columna: 'Legal' },
      { label: 'Términos y condiciones', url: '/#terminos', target: '_self', columna: 'Legal' },
      { label: 'Libro de Reclamaciones', url: '/libro-de-reclamaciones', target: '_self', columna: 'Legal' },
    ],
  },
  // EDUN-306 · Contenedor de prueba: las pruebas interceptan googletagmanager.com.
  tracking: { gtm: 'GTM-PRUEBA1' },
  // Sprint 8 · Popup de salida (solo al intentar salir) y logos (Sprint 10, sin logos).
  popup: popupLanding,
  logos: [],
});

// Sprint 6 · Políticas, leads y consentimientos en memoria (EDUN-267, 287, 291 y 306).
const TITULOS = { privacidad: 'Política de Privacidad', terminos: 'Términos y Condiciones del servicio', seguridad: 'Seguridad de Datos (LPDP)', cookies: 'Política de Cookies' };
const politica = (tipo) => ({
  tipo,
  titulo: TITULOS[tipo],
  version: '1.0',
  vigente_desde: '2026-11-07',
  borrador: true,
  contenido: `<p>Texto de prueba de ${TITULOS[tipo]}.</p><h3>1. Datos que recopilamos</h3><p>Nombre y correo.</p>`,
});
const leads = [];
const consentimientos = [];
const HORAS = ['09:00', '10:30', '12:00', '15:00', '16:30', '18:00'];

function validarDemo(d) {
  const errores = {};
  for (const k of ['nombre', 'cargo', 'telefono', 'email', 'colegio', 'alumnos', 'demo_fecha']) if (!d[k]) errores[k] = ['Completa este campo.'];
  if (!HORAS.includes(d.demo_hora)) errores.demo_hora = ['Elige una de las horas disponibles.'];
  if (d.consentimiento !== true) errores.consentimiento = ['Debes aceptar la Política de Privacidad para enviar la solicitud.'];
  const dia = d.demo_fecha ? new Date(`${d.demo_fecha}T12:00:00Z`).getUTCDay() : 1;
  if (dia === 0 || dia === 6) errores.demo_fecha = ['La demo se agenda de lunes a viernes.'];
  return errores;
}

// Sprint 7 · Libro de Reclamaciones (EDUN-294), seguimiento (EDUN-298) y bandeja (EDUN-278).
const reclamosGuardados = [];
const auditoria = [];
const fallosSeguimiento = new Map();

/** Campos de texto de un multipart/form-data (los archivos solo se cuentan). */
function camposMultipart(cuerpo, tipo) {
  const limite = /boundary=(.+)$/.exec(tipo ?? '')?.[1];
  const campos = {};
  let archivos = 0;
  if (!limite) return { campos, archivos };
  for (const parte of cuerpo.split(`--${limite}`)) {
    const nombre = /name="([^"]+)"/.exec(parte)?.[1];
    if (!nombre) continue;
    if (/filename="/.test(parte)) {
      archivos++;
      continue;
    }
    const valor = parte.split(String.fromCharCode(13, 10, 13, 10)).slice(1).join('');
    campos[nombre] = valor.endsWith(String.fromCharCode(13, 10)) ? valor.slice(0, -2) : valor;
  }
  return { campos, archivos };
}

const PLANES_LEAD = ['inicial', 'crecimiento', 'integral'];
const NOMBRE_PLAN = { inicial: 'Plan Inicial', crecimiento: 'Plan Crecimiento', integral: 'Plan Integral' };
const leadsBandeja = Array.from({ length: 42 }, (_, i) => ({
  id: i + 1,
  nombre: `Contacto ${i + 1}`,
  cargo: 'Director(a)',
  email: `contacto${i + 1}@ejemplo.pe`,
  telefono: '987654321',
  colegio: `Colegio de ejemplo ${i + 1}`,
  ciudad: i % 2 ? 'Arequipa' : 'Lima',
  alumnos: 150 + i,
  plan_interes: PLANES_LEAD[i % 3],
  plan_nombre: NOMBRE_PLAN[PLANES_LEAD[i % 3]],
  precio_mensual: null,
  estado: 'nuevo',
  source: 'demo',
  demo_fecha: null,
  demo_hora: null,
  mensaje: null,
  utm_source: null,
  utm_medium: null,
  utm_campaign: null,
  page_url: null,
  created_at: new Date(Date.now() - i * 3600_000).toISOString(),
  primer_contacto_en: null,
}));

function bandeja(url) {
  const p = url.searchParams;
  const base = leadsBandeja.filter((l) => (!p.get('plan') || l.plan_interes === p.get('plan')) && (!p.get('ciudad') || l.ciudad === p.get('ciudad')) && (!p.get('q') || l.colegio.toLowerCase().includes(p.get('q').toLowerCase())));
  const filtrados = base.filter((l) => !p.get('estado') || l.estado === p.get('estado'));
  const pagina = Math.max(1, Number(p.get('pagina') ?? 1));
  const contadores = { todos: base.length };
  for (const e of ['nuevo', 'contactado', 'en_proceso', 'ganado', 'perdido']) contadores[e] = base.filter((l) => l.estado === e).length;
  return {
    data: filtrados.slice((pagina - 1) * 10, pagina * 10),
    pagina,
    por_pagina: 10,
    total: filtrados.length,
    paginas: Math.max(1, Math.ceil(filtrados.length / 10)),
    contadores,
    indicadores: { total_mes: leadsBandeja.length, sin_contactar: contadores.nuevo, respuesta_horas: null, demos_agendadas: 0, demos_hoy: 0, pipeline_mensual: '0.00', pipeline_leads: 0 },
    ciudades: ['Arequipa', 'Lima'],
    planes: Object.entries(NOMBRE_PLAN).map(([clave, nombre]) => ({ clave, nombre })),
    crm: { configurado: false, pendientes: 0, con_error: 0 },
  };
}

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
    const tipoPolitica = /^\/api\/v1\/sitio\/politicas\/(\w+)$/.exec(req.url)?.[1];
    if (req.method === 'GET' && tipoPolitica) return TITULOS[tipoPolitica] ? json(res, 200, politica(tipoPolitica)) : json(res, 404, { message: 'No encontramos lo que buscas.' });
    if (req.method === 'POST' && req.url === '/api/v1/sitio/leads') {
      const d = JSON.parse(datos || '{}');
      const errores = validarDemo(d);
      if (Object.keys(errores).length > 0) return json(res, 422, { message: Object.values(errores)[0][0], errors: errores });
      leads.push(d);
      consentimientos.push({ tipo: 'formulario', version_politica: 'privacidad-v1.0', email: d.email, ip: req.headers['x-visitante-ip'] ?? null });
      return json(res, 201, { message: 'Solicitud registrada.', demo_fecha: d.demo_fecha, demo_hora: d.demo_hora, email: d.email.toLowerCase(), consentimiento_version: 'privacidad-v1.0' });
    }
    if (req.method === 'POST' && req.url === '/api/v1/sitio/consentimientos/cookies') {
      const { categorias } = JSON.parse(datos || '{}');
      consentimientos.push({ tipo: 'cookies', version_politica: 'cookies-v1.0', categorias });
      return json(res, 201, { message: 'Preferencias guardadas.', version_politica: 'cookies-v1.0' });
    }
    if (req.method === 'POST' && req.url === '/api/v1/sitio/reclamaciones') {
      const { campos, archivos } = camposMultipart(datos, req.headers['content-type']);
      const errores = {};
      for (const k of ['tipo', 'nombre', 'tipo_doc', 'numero_doc', 'email', 'direccion', 'producto', 'descripcion', 'pedido']) if (!campos[k]) errores[k] = ['Completa este campo.'];
      if (campos.descripcion && campos.descripcion.length < 50) errores.descripcion = ['Describe los hechos con al menos 50 caracteres.'];
      if (!campos.declaracion) errores.declaracion = ['Debes declarar que la información es verdadera.'];
      if (!campos.consentimiento) errores.consentimiento = ['Debes autorizar el tratamiento de tus datos para tramitar el reclamo.'];
      if (Object.keys(errores).length > 0) return json(res, 422, { message: Object.values(errores)[0][0], errors: errores });
      const expediente = `REC-2026-${String(reclamosGuardados.length + 1).padStart(4, '0')}`;
      reclamosGuardados.push({ expediente, ...campos, archivos, estado: 'recibido' });
      return json(res, 201, { message: 'Reclamación registrada.', expediente, vence_en: '2026-11-30', email: campos.email, plazo_dias_habiles: 15 });
    }
    if (req.method === 'GET' && req.url.startsWith('/api/v1/sitio/reclamaciones/seguimiento')) {
      const u = new URL(req.url, 'http://x');
      const ip = req.headers['x-visitante-ip'] ?? 'local';
      if ((fallosSeguimiento.get(ip) ?? 0) >= 10) return json(res, 429, { message: 'Hiciste demasiadas consultas sin resultado. Inténtalo de nuevo en 15 minutos.' });
      const r = reclamosGuardados.find((x) => x.expediente === (u.searchParams.get('expediente') ?? '').toUpperCase() && x.numero_doc === u.searchParams.get('documento'));
      if (!r) {
        fallosSeguimiento.set(ip, (fallosSeguimiento.get(ip) ?? 0) + 1);
        return json(res, 404, { message: 'No encontramos un reclamo con esos datos.' });
      }
      fallosSeguimiento.delete(ip);
      return json(res, 200, { expediente: r.expediente, tipo: r.tipo, estado: r.estado, actualizado_en: '2026-11-09T15:20:00Z', vence_en: '2026-11-30' });
    }
    // Sprints 8 a 10 (api-simulada-panel.mjs).
    if (atenderPanel(req, res, datos, { json, esSuperadmin: () => Boolean(POR_TOKEN[token]?.roles_plataforma.includes('superadmin')) })) return;
    if (req.url.startsWith('/api/v1/cms/leads')) {
      if (!POR_TOKEN[token]?.roles_plataforma.includes('superadmin')) return json(res, 403, { message: 'No tienes acceso al Panel CMS.' });
      const u = new URL(req.url, 'http://x');
      if (req.method === 'GET') return json(res, 200, bandeja(u));
      const lead = leadsBandeja.find((l) => l.id === Number(u.pathname.split('/').pop()));
      if (!lead) return json(res, 404, { message: 'No encontramos esa solicitud.' });
      lead.estado = JSON.parse(datos || '{}').estado;
      auditoria.push({ entidad: 'leads', entidad_id: lead.id, campo: 'estado', valor_despues: lead.estado });
      return json(res, 200, { data: { id: lead.id, estado: lead.estado }, message: 'Estado actualizado.' });
    }
    if (req.method === 'GET' && req.url === '/__auditoria') return json(res, 200, auditoria);
    // Solo para las pruebas: qué recibió la API.
    if (req.method === 'GET' && req.url === '/__leads') return json(res, 200, { leads, consentimientos });
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
