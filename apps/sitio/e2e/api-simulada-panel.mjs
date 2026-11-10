// Sprints 8 a 10 en la API simulada: popup de salida, CRM, exportar CSV, reclamaciones en el
// CMS, pantallas con borrador (hero, secciones, logos, popup, configuración, planes, módulos,
// testimonios), dashboard, auditoría, usuarios, SEO y políticas. Todo en memoria.
// Las reglas imitan a la API real (apps/api); allí las prueban Pest y pruebas_cms.sql.

const hoy = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Lima' }).format(new Date());

export const leadsPopup = [];
export const leadsChatbot = [];
/** Estado que cambian las pruebas (POST /__chatbot). */
export const estado = { chatbotActivo: false };

// Sprint 11 · Chatbot de prueba: siempre «en horario» (todos los días, todo el día).
export const CHATBOT = {
  activo: true,
  modo: 'flujo',
  titulo: 'Asistente de Edunova',
  saludo: '¡Hola! ¿En qué te ayudamos?',
  fuera_horario: 'Ahora estamos fuera de horario.',
  whatsapp_mensaje: 'Hola, quiero información',
  horario: { dias: [1, 2, 3, 4, 5, 6, 7], desde: '00:00', hasta: '23:59' },
  whatsapp: '51987654321',
  nodos: [
    { tipo: 'opciones', texto: '¿Qué necesitas?', url: null, siguiente: null, opciones: [{ texto: 'Ver precios', destino: 1 }, { texto: 'Hablar con un asesor', destino: 2 }] },
    { tipo: 'url', texto: 'Aquí están los planes.', url: '/planes', siguiente: null, opciones: [] },
    { tipo: 'captura', texto: 'Déjanos tu contacto y te escribimos.', url: null, siguiente: null, opciones: [] },
  ],
};

// Blog de prueba (lo que la API ya trajo y limpió de WordPress).
const ARTICULOS = [
  { slug: 'como-elegir-un-sistema-escolar', titulo: 'Cómo elegir un sistema escolar', resumen: 'Cinco preguntas antes de decidir.', imagen_url: null, publicado_en: '2026-11-01T15:00:00Z', autor_wp: 'Equipo Edunova', categoria: 'gestion', categoria_nombre: 'Gestión', contenido: '<h2>Primero</h2><p>Define qué necesitas.</p>', actualizado_wp: null },
  { slug: 'pensiones-sin-morosidad', titulo: 'Pensiones sin morosidad', resumen: 'Recordatorios que funcionan.', imagen_url: null, publicado_en: '2026-10-01T15:00:00Z', autor_wp: null, categoria: 'pagos', categoria_nombre: 'Pagos', contenido: '<p>Recordatorios.</p>', actualizado_wp: null },
];

/** El listado no lleva el contenido completo. */
const resumenArticulo = (a) => Object.fromEntries(Object.entries(a).filter(([k]) => k !== 'contenido' && k !== 'actualizado_wp'));

const integraciones = {
  tracking: Object.fromEntries(['gtm', 'ga4', 'meta_pixel', 'linkedin', 'clarity', 'search_console'].map((k) => [k, { valor: null, activo: false }])),
  crm: { url: null, token: { configurado: false } },
  smtp: { host: null, puerto: 587, usuario: null, cifrado: 'tls', remitente: null, clave: { configurado: false } },
  ia: { proveedor: null, clave: { configurado: false } },
  wordpress: { url: 'https://blog.ejemplo.test' },
};

export const popupLanding = {
  titulo: '¿Te vas sin ver Edunova en acción?',
  texto: 'Déjanos tu correo o WhatsApp y te enviamos el recorrido del sistema.',
  cta: 'Enviarme la información',
  campo: 'ambos',
  espera_seg: 0,
  // 0 = no aparece por inactividad: solo al intentar salir (las demás pruebas no lo ven).
  inactividad_seg: 0,
  frecuencia: 'sesion',
  imagen: null,
};

// --- Pantallas con borrador (EditorCms) ------------------------------------------------------

const secciones = {
  hero: { nombre: 'Hero y Portada', publicado: { insignia: 'Gestión escolar K-12', titulo: 'Administra tu colegio desde un solo lugar', subtitulo: 'Matrículas, notas, pagos y comunicados en un solo sistema.', cta_principal: 'Solicitar demo gratuita', cta_secundario: 'Ver video demostrativo', sellos: null } },
  barra_superior: { nombre: 'Barra superior', publicado: { aviso: 'Plataforma para colegios privados del Perú' } },
  _estructura: {
    nombre: 'Orden de la landing',
    publicado: {
      lista: [
        ['barra_superior', 'Barra superior'], ['hero', 'Hero y Portada'], ['retos', 'Retos'], ['modulos', 'Módulos'], ['videos', 'Videos'], ['pasos', 'Implementación'],
        ['testimonios', 'Testimonios'], ['planes', 'Planes'], ['logos', 'Logos de clientes'], ['cta_final', 'CTA final'], ['footer', 'Footer'],
      ].map(([clave, nombre]) => ({ clave, nombre, visible: clave !== 'logos' })),
    },
  },
  logos: { nombre: 'Logos de clientes', publicado: { titulo: 'Confían en Edunova', velocidad: 30, columnas: 5, mostrar: false, lista: [] } },
  _popup: { nombre: 'Popup de salida', publicado: { activo: true, titulo: popupLanding.titulo, texto: popupLanding.texto, imagen_id: null, cta: popupLanding.cta, campo: 'ambos', espera_seg: 30, inactividad_seg: 45, frecuencia: 'sesion' } },
  _faq: { nombre: 'Preguntas frecuentes', publicado: { lista: [{ id: 1, pregunta: '¿Funciona con SIAGIE?', respuesta: 'Exporta las actas.', categoria: 'SIAGIE', visible: true }] } },
  _chatbot: { nombre: 'Chatbot', publicado: { activo: false, modo: 'whatsapp', titulo: 'Asistente de Edunova', saludo: '¡Hola!', fuera_horario: 'Fuera de horario.', whatsapp_mensaje: null, horario: { dias: [1, 2, 3, 4, 5], desde: '08:00', hasta: '18:00' }, nodos: [] } },
  _config: {
    nombre: 'Configuración general',
    publicado: { razon_social: null, ruc: null, direccion: null, telefono_central: null, whatsapp: null, email_contacto: null, email_reclamaciones: null, horario_soporte: null, mantenimiento: { activo: false, mensaje: null, vuelta: null } },
  },
};
for (const s of Object.values(secciones)) s.borrador = null;

export const publicaciones = [];

function validar(clave, c) {
  const e = {};
  if (clave === '_config' && c.ruc && !/^(10|15|17|20)\d{9}$/.test(c.ruc)) e.ruc = ['El RUC tiene 11 dígitos y empieza con 10, 15, 17 o 20.'];
  if (clave === '_config' && c.mantenimiento?.activo && !c.mantenimiento?.mensaje) e['mantenimiento.mensaje'] = ['Escribe el mensaje que verán los visitantes durante el mantenimiento.'];
  if (clave === 'logos' && c.mostrar && !(c.lista ?? []).some((l) => l.visible && l.autorizado)) e.mostrar = ['La sección necesita al menos un logo autorizado y visible.'];
  if (clave === 'hero' && !c.titulo) e.titulo = ['Este campo es obligatorio.'];
  if (clave === '_estructura') {
    const oculta = (c.lista ?? []).find((s) => ['hero', 'footer'].includes(s.clave) && !s.visible);
    if (oculta) e.lista = ['La portada y el footer no se pueden ocultar.'];
  }
  return e;
}

const vista = (clave) => {
  const s = secciones[clave];
  return { clave, nombre: s.nombre, publicado: s.publicado, borrador: s.borrador ? { ...s.publicado, ...s.borrador } : null, updated_at: '2026-11-10T12:00:00Z', medios: {} };
};

// --- Reclamaciones (EDUN-302) ----------------------------------------------------------------

const reclamosCms = [
  { id: 1, numero_expediente: 'REC-2026-0001', tipo: 'reclamo', nombre_reclamante: 'Rosa Prueba', email: 'rosa@ejemplo.pe', producto_descripcion: 'Plan Crecimiento · pagos', estado: 'recibido', vence_en: '2026-11-30', created_at: '2026-11-09T15:00:00Z', respondido_en: null, plazo: 'en_plazo' },
  { id: 2, numero_expediente: 'REC-2026-0002', tipo: 'queja', nombre_reclamante: 'Luis Prueba', email: 'luis@ejemplo.pe', producto_descripcion: 'Soporte', estado: 'en_revision', vence_en: '2026-11-02', created_at: '2026-10-10T15:00:00Z', respondido_en: null, plazo: 'vencido' },
];
const SIGUIENTES = { recibido: ['en_revision', 'respondido'], en_revision: ['respondido'], respondido: ['cerrado'], cerrado: [] };
const detalleReclamo = (r) => ({
  ...r,
  tipo_doc: 'DNI', numero_doc: '45678912', telefono: '987654321', direccion: 'Av. Ejemplo 123, Lima', menor_edad: false, apoderado_nombre: null, apoderado_doc: null,
  monto_reclamado: null, descripcion: 'La plataforma no generó las boletas del mes y soporte no respondió a tiempo.', pedido: 'Que se regularice.',
  notas_internas: r.notas_internas ?? null, respuesta_cliente: r.respuesta_cliente ?? null, updated_at: r.updated_at ?? '2026-11-09T15:00:00Z',
  siguientes: SIGUIENTES[r.estado], adjuntos: [], historial: r.historial ?? [],
});

// --- Auditoría (EDUN-342) --------------------------------------------------------------------

const registrosAuditoria = [
  { id: 3, created_at: '2026-11-10T14:00:00Z', usuario: 'Ana Administradora', usuario_rol: 'superadmin', seccion: 'planes', accion: 'publicado', entidad: 'secciones', entidad_id: 'planes', campo: null, detalle: 'Planes · publicado', valor_antes: { lista: [{ clave: 'inicial', precio_mensual: '100.00' }] }, valor_despues: { lista: [{ clave: 'inicial', precio_mensual: '111.00' }] }, restaurable: true },
  { id: 2, created_at: '2026-11-10T13:00:00Z', usuario: 'Ana Administradora', usuario_rol: 'superadmin', seccion: 'hero', accion: 'borrador', entidad: 'secciones', entidad_id: 'hero', campo: null, detalle: 'Hero y Portada · borrador guardado', valor_antes: { titulo: 'A' }, valor_despues: { titulo: 'B' }, restaurable: true },
  { id: 1, created_at: '2026-11-09T10:00:00Z', usuario: 'Ana Administradora', usuario_rol: 'superadmin', seccion: 'leads', accion: 'editado', entidad: 'leads', entidad_id: '7', campo: 'estado', detalle: 'Bandeja de solicitudes · Colegio 7: Nuevo → Contactado', valor_antes: 'nuevo', valor_despues: 'contactado', restaurable: false },
];
const NOMBRES_SECCION = { planes: 'Planes', hero: 'Hero y Portada', leads: 'Bandeja de solicitudes', seo: 'Ajustes SEO', politicas: 'Políticas legales' };
const ACCIONES = { editado: 'Editado', borrador: 'Borrador', publicado: 'Publicado', ocultado: 'Ocultado', restaurado: 'Restaurado', eliminado: 'Eliminado' };

// --- SEO y políticas -------------------------------------------------------------------------

const seoInicio = {
  slug: 'inicio', meta_titulo: 'Edunova · Sistema de gestión escolar', meta_descripcion: 'Matrículas, notas, pagos y comunicados para colegios privados del Perú.',
  canonical_url: null, robots: 'index,follow', keywords: ['gestión escolar'], og_titulo: null, og_descripcion: null, og_imagen_id: null, og_imagen_url: null,
  og_imagen_alt: null, og_imagen_ancho: null, og_imagen_alto: null, updated_at: '2026-11-10T12:00:00Z',
};
const TEXTO_POLITICA = '<h2>1. Alcance</h2><p>' + 'Texto de la política de cookies de Edunova con detalle suficiente para la prueba. '.repeat(4) + '</p>';
const politicasCms = ['privacidad', 'terminos', 'seguridad', 'cookies'].map((tipo, i) => ({
  tipo,
  nombre: ['Política de Privacidad', 'Términos de Servicio', 'Seguridad de Datos', 'Política de Cookies'][i],
  versiones: [{ id: i + 1, version: '1.0', vigente_desde: '2026-11-07', borrador: true, created_at: '2026-11-07T10:00:00Z', contenido: TEXTO_POLITICA, estado: 'vigente' }],
}));

// --- Manejador ---------------------------------------------------------------------------------

/** Devuelve true si atendió la petición. */
export function atenderPanel(req, res, datos, { json, esSuperadmin }) {
  const url = new URL(req.url, 'http://x');
  const ruta = url.pathname;
  const cuerpo = () => JSON.parse(datos || '{}');

  // EDUN-271 · Lead del popup de salida.
  if (req.method === 'POST' && ruta === '/api/v1/sitio/leads/popup') {
    const d = cuerpo();
    const errores = {};
    if (!d.email && !d.telefono) errores.email = ['Escribe tu correo o tu WhatsApp.'];
    if (d.consentimiento !== true) errores.consentimiento = ['Debes aceptar la Política de Privacidad para enviar tus datos.'];
    if (Object.keys(errores).length) return json(res, 422, { message: Object.values(errores)[0][0], errors: errores }), true;
    leadsPopup.push(d);
    return json(res, 201, { message: 'Datos registrados.', consentimiento_version: 'privacidad-v1.0' }), true;
  }
  if (req.method === 'GET' && ruta === '/__popup') return json(res, 200, leadsPopup), true;
  if (req.method === 'POST' && ruta === '/__chatbot') {
    estado.chatbotActivo = cuerpo().activo === true;
    return json(res, 200, estado), true;
  }
  if (req.method === 'GET' && ruta === '/__chatbot') return json(res, 200, leadsChatbot), true;

  // EDUN-375 · Contacto del chatbot.
  if (req.method === 'POST' && ruta === '/api/v1/sitio/chatbot/lead') {
    const d = cuerpo();
    if (!d.email && !d.telefono) return json(res, 422, { message: 'Escribe tu correo o tu WhatsApp.', errors: { email: ['Escribe tu correo o tu WhatsApp.'] } }), true;
    if (d.consentimiento !== true) return json(res, 422, { message: 'x', errors: { consentimiento: ['Debes aceptar la Política de Privacidad.'] } }), true;
    leadsChatbot.push(d);
    return json(res, 201, { message: 'Datos registrados.' }), true;
  }

  // EDUN-363 · Blog.
  if (req.method === 'GET' && ruta === '/api/v1/sitio/blog') {
    const cat = url.searchParams.get('categoria');
    return json(res, 200, {
      configurado: true,
      categorias: [{ slug: 'gestion', nombre: 'Gestión' }, { slug: 'pagos', nombre: 'Pagos' }],
      data: ARTICULOS.filter((a) => !cat || a.categoria === cat).map(resumenArticulo),
      pagina: 1,
      paginas: 1,
    }), true;
  }
  const slug = /^\/api\/v1\/sitio\/blog\/([a-z0-9-]+)$/.exec(ruta)?.[1];
  if (req.method === 'GET' && slug) {
    const a = ARTICULOS.find((x) => x.slug === slug);
    if (!a) return json(res, 404, { message: 'No encontramos ese artículo.' }), true;
    return json(res, 200, { ...a, relacionados: ARTICULOS.filter((x) => x.slug !== slug).map(resumenArticulo) }), true;
  }
  if (req.method === 'GET' && ruta === '/__publicaciones') return json(res, 200, publicaciones), true;

  if (!ruta.startsWith('/api/v1/cms/')) return false;
  const protegidas = /^\/api\/v1\/cms\/(leads\/(exportar|sincronizar)|reclamaciones|secciones|publicar|estado|dashboard|auditoria|usuarios|seo|politicas|integraciones)/;
  if (!protegidas.test(ruta)) return false;
  if (!esSuperadmin()) return json(res, 403, { message: 'No tienes acceso al Panel CMS.' }), true;

  // EDUN-282 · Exportar y sincronizar.
  if (ruta === '/api/v1/cms/leads/exportar') {
    res.writeHead(200, { 'Content-Type': 'text/csv; charset=UTF-8', 'Content-Disposition': 'attachment; filename="solicitudes-edunova-2026-11-10.csv"' });
    res.end('﻿ID,Institución educativa,Plan de interés\n1,Colegio de ejemplo 1,Plan Inicial\n');
    return true;
  }
  if (ruta === '/api/v1/cms/leads/sincronizar') return json(res, 409, { message: 'Aún no hay un CRM configurado. Los leads quedan guardados y se enviarán cuando se configure.' }), true;

  // EDUN-302 · Reclamaciones.
  if (ruta === '/api/v1/cms/reclamaciones' && req.method === 'GET') {
    const p = url.searchParams;
    const base = reclamosCms.filter((r) => (!p.get('plazo') || (p.get('plazo') === 'vencidos' ? r.plazo === 'vencido' : r.plazo === 'por_vencer')) && (!p.get('tipo') || r.tipo === p.get('tipo')));
    const filas = base.filter((r) => !p.get('estado') || r.estado === p.get('estado'));
    const contadores = { todos: base.length };
    for (const e of Object.keys(SIGUIENTES)) contadores[e] = base.filter((r) => r.estado === e).length;
    return json(res, 200, {
      data: filas, pagina: 1, por_pagina: 10, total: filas.length, paginas: 1, contadores,
      indicadores: { abiertos: reclamosCms.filter((r) => ['recibido', 'en_revision'].includes(r.estado)).length, vencidos: reclamosCms.filter((r) => r.plazo === 'vencido').length, por_vencer: 0, a_tiempo_pct: null, respondidos_90: 0 },
    }), true;
  }
  const idReclamo = Number(/^\/api\/v1\/cms\/reclamaciones\/(\d+)$/.exec(ruta)?.[1]);
  if (idReclamo) {
    const r = reclamosCms.find((x) => x.id === idReclamo);
    if (!r) return json(res, 404, { message: 'No encontramos ese expediente.' }), true;
    if (req.method === 'GET') return json(res, 200, { data: detalleReclamo(r) }), true;
    const d = cuerpo();
    if (d.estado && d.estado !== r.estado && !SIGUIENTES[r.estado].includes(d.estado)) return json(res, 422, { message: 'x', errors: { estado: ['Cambio de estado no permitido.'] } }), true;
    if (d.estado === 'respondido' && (d.respuesta_cliente ?? '').trim().length < 20) {
      return json(res, 422, { message: 'x', errors: { respuesta_cliente: ['Escribe la respuesta al cliente (al menos 20 caracteres).'] } }), true;
    }
    r.historial = [{ campo: 'estado', detalle: `Libro de Reclamaciones · ${r.numero_expediente}: ${r.estado} → ${d.estado ?? r.estado}`, created_at: new Date().toISOString(), usuario: 'Ana Administradora' }, ...(r.historial ?? [])];
    if (d.estado) r.estado = d.estado;
    if (d.estado === 'respondido') {
      r.respuesta_cliente = d.respuesta_cliente;
      r.respondido_en = new Date().toISOString();
      r.plazo = 'a_tiempo';
    }
    if ('notas_internas' in d) r.notas_internas = d.notas_internas;
    r.updated_at = new Date().toISOString();
    return json(res, 200, { data: detalleReclamo(r), message: d.estado === 'respondido' ? 'Respuesta enviada al cliente.' : 'Expediente actualizado.' }), true;
  }

  // EditorCms: borrador y publicación.
  if (ruta === '/api/v1/cms/estado') return json(res, 200, { ultima_publicacion: null, pendientes: Object.keys(secciones).filter((k) => secciones[k].borrador) }), true;
  const clave = /^\/api\/v1\/cms\/secciones\/([a-z_]+)$/.exec(ruta)?.[1];
  if (clave) {
    if (!secciones[clave]) return json(res, 404, { message: 'No encontramos esa sección.' }), true;
    if (req.method === 'GET') return json(res, 200, { data: vista(clave) }), true;
    const contenido = cuerpo().contenido ?? {};
    const errores = validar(clave, contenido);
    if (Object.keys(errores).length) return json(res, 422, { message: Object.values(errores)[0][0], errors: errores }), true;
    secciones[clave].borrador = contenido;
    return json(res, 200, { data: vista(clave), message: 'Borrador guardado.' }), true;
  }
  if (ruta === '/api/v1/cms/publicar') {
    const publicadas = (cuerpo().claves ?? []).filter((k) => secciones[k]?.borrador);
    for (const k of publicadas) {
      secciones[k].publicado = { ...secciones[k].publicado, ...secciones[k].borrador };
      secciones[k].borrador = null;
      publicaciones.push({ clave: k, contenido: secciones[k].publicado });
    }
    return json(res, 200, { publicadas, message: publicadas.length ? 'Cambios publicados.' : 'No había cambios por publicar.', ultima_publicacion: { en: new Date().toISOString(), por: 'Ana Administradora' }, pendientes: [] }), true;
  }

  // EDUN-383 · Dashboard.
  if (ruta === '/api/v1/cms/dashboard') {
    const dias = Array.from({ length: 30 }, (_, i) => {
      const d = new Date(Date.now() - (29 - i) * 86400_000);
      return { dia: d.toISOString().slice(0, 10), leads: i % 4 };
    });
    return json(res, 200, {
      leads_hoy: 3, leads_7d: 12, leads_7d_previos: 8, leads_sin_contactar: 42, demos_hoy: 1, por_origen: { demo: 30, popup: 5 }, tendencia: dias,
      reclamos: { abiertos: 2, vencidos: 1, por_vencer: 0 }, borradores: [], crm_pendientes: 0,
      ultimos_leads: [{ id: 1, colegio: 'Colegio de ejemplo 1', nombre: 'Contacto 1', email: null, telefono: null, source: 'demo', estado: 'nuevo', created_at: new Date().toISOString() }],
    }), true;
  }

  // EDUN-342 · Auditoría.
  if (ruta === '/api/v1/cms/auditoria' && req.method === 'GET') {
    const p = url.searchParams;
    const filas = registrosAuditoria.filter((a) => (!p.get('seccion') || a.seccion === p.get('seccion')) && (!p.get('accion') || a.accion === p.get('accion')));
    return json(res, 200, {
      data: filas, pagina: 1, por_pagina: 15, total: filas.length, paginas: 1,
      indicadores: { cambios_hoy: 2, publicaciones_7d: 1, usuarios_con_acceso: 1, versiones: 3 },
      secciones: Object.fromEntries([...new Set(registrosAuditoria.map((a) => a.seccion))].map((s) => [s, NOMBRES_SECCION[s] ?? s])),
      usuarios: [{ id: 1, nombre: 'Ana Administradora' }], acciones: ACCIONES,
    }), true;
  }
  if (ruta === '/api/v1/cms/auditoria/exportar') {
    res.writeHead(200, { 'Content-Type': 'text/csv; charset=UTF-8', 'Content-Disposition': 'attachment; filename="auditoria-cms-2026-11-10.csv"' });
    res.end('﻿"Fecha (hora de Lima)",Usuario\n');
    return true;
  }
  const restaurar = Number(/^\/api\/v1\/cms\/auditoria\/(\d+)\/restaurar$/.exec(ruta)?.[1]);
  if (restaurar && req.method === 'POST') {
    const a = registrosAuditoria.find((x) => x.id === restaurar);
    if (!a?.restaurable) return json(res, 422, { message: 'x', errors: { auditoria: ['Este cambio no se puede restaurar desde aquí.'] } }), true;
    registrosAuditoria.unshift({ ...a, id: registrosAuditoria.length + 10, accion: 'restaurado', detalle: `${NOMBRES_SECCION[a.seccion]} · valor anterior restaurado como borrador`, created_at: new Date().toISOString(), valor_antes: a.valor_despues, valor_despues: a.valor_antes });
    return json(res, 200, { data: {}, message: 'Valor anterior restaurado como borrador. Revísalo y publícalo cuando esté listo.' }), true;
  }

  // EDUN-407 · Usuarios.
  if (ruta === '/api/v1/cms/usuarios') {
    return json(res, 200, { data: [{ id: 1, nombre: 'Ana Administradora', email: 'superadmin@demo.edunova.pe', ultimo_acceso_at: '2026-11-10T13:00:00Z', desde: '2026-11-06T10:00:00Z', dos_pasos: false, cambios_30d: 3 }] }), true;
  }

  // EDUN-314 · SEO.
  if (ruta === '/api/v1/cms/seo') return json(res, 200, { data: [{ slug: 'inicio', meta_titulo: seoInicio.meta_titulo, robots: seoInicio.robots, updated_at: seoInicio.updated_at }] }), true;
  if (ruta === '/api/v1/cms/seo/inicio') {
    if (req.method === 'GET') return json(res, 200, { data: seoInicio }), true;
    const d = cuerpo();
    if ((d.meta_titulo ?? '').length > 70) return json(res, 422, { message: 'x', errors: { meta_titulo: ['El título admite hasta 70 caracteres.'] } }), true;
    Object.assign(seoInicio, d, { updated_at: new Date().toISOString() });
    return json(res, 200, { data: seoInicio, message: 'Metadatos actualizados.' }), true;
  }

  // EDUN-403 · Tracking e integraciones (las claves nunca se devuelven).
  if (ruta === '/api/v1/cms/integraciones') {
    if (req.method === 'GET') return json(res, 200, { data: integraciones }), true;
    const d = cuerpo();
    const ga4 = d.tracking?.ga4?.valor;
    if (ga4 && !/^G-[A-Z0-9]{6,12}$/.test(ga4)) return json(res, 422, { message: 'x', errors: { 'tracking.ga4.valor': ['El ID de GA4 tiene la forma G-XXXXXXXXXX.'] } }), true;
    integraciones.tracking = d.tracking;
    integraciones.crm = { url: d.crm.url, token: { configurado: d.crm.token_borrar ? false : Boolean(d.crm.token) || integraciones.crm.token.configurado } };
    return json(res, 200, { data: integraciones, message: 'Integraciones actualizadas.' }), true;
  }

  // EDUN-395 · Políticas.
  if (ruta === '/api/v1/cms/politicas') return json(res, 200, { data: politicasCms }), true;
  const tipo = /^\/api\/v1\/cms\/politicas\/(\w+)$/.exec(ruta)?.[1];
  if (tipo && req.method === 'POST') {
    const p = politicasCms.find((x) => x.tipo === tipo);
    const d = cuerpo();
    if (p.versiones.some((v) => v.version === d.version)) return json(res, 422, { message: 'x', errors: { version: [`La versión ${d.version} ya existe. Usa un número nuevo.`] } }), true;
    if (d.vigente_desde < hoy()) return json(res, 422, { message: 'x', errors: { vigente_desde: ['La vigencia empieza hoy o en una fecha futura.'] } }), true;
    p.versiones.unshift({ id: Date.now(), version: d.version, vigente_desde: d.vigente_desde, borrador: d.revision_pendiente, created_at: new Date().toISOString(), contenido: d.contenido.replace(/<script[\s\S]*?<\/script>/gi, ''), estado: d.vigente_desde > hoy() ? 'programada' : 'vigente' });
    return json(res, 201, { data: p, message: 'Versión publicada.' }), true;
  }

  return false;
}
