-- =====================================================================
-- Semillas neutras del esquema cms (EDUN-223)
--
-- El repositorio solo lleva código: el contenido real del sitio (precios,
-- ofertas, datos de la empresa y textos comerciales) vive en
-- cms/privado/contenido.sql, que está fuera de git. El seeder carga ese
-- archivo si existe; si no, carga este, que solo crea las filas que el
-- sitio y las pruebas necesitan, sin precios ni datos de la empresa.
-- Lo que falta se edita después desde el Panel CMS.
-- =====================================================================

INSERT INTO cms.planes_sitio (clave, nombre, rango_texto, alumnos_max, precio_mensual, recomendado, cta_texto, orden) VALUES
 ('inicial',       'Plan Inicial',     'Hasta 150 alumnos', 150,  NULL, false, '[A CONFIRMAR]', 1),
 ('crecimiento',   'Plan Crecimiento', 'Hasta 300 alumnos', 300,  NULL, true,  '[A CONFIRMAR]', 2),
 ('integral',      'Plan Integral',    'Hasta 500 alumnos', 500,  NULL, false, '[A CONFIRMAR]', 3),
 ('institucional', 'Institucional',    'Más de 500 alumnos', NULL, NULL, false, '[A CONFIRMAR]', 4);

-- Los 18 módulos con su nombre del catálogo del SaaS; descripciones a completar en el CMS.
INSERT INTO cms.modulos_web (clave, codigo_saas, nombre, icono, descripcion, en_grid, orden) VALUES
 ('matriculas',     'ADM', 'Matrículas y admisión',         'how_to_reg',           '[A CONFIRMAR]', true,  1),
 ('pagos',          'PAG', 'Pagos y facturación',           'point_of_sale',        '[A CONFIRMAR]', true,  2),
 ('calificaciones', 'CAL', 'Calificaciones y libretas',     'assignment_turned_in', '[A CONFIRMAR]', true,  3),
 ('aula',           'AUL', 'Aula virtual',                  'laptop_chromebook',    '[A CONFIRMAR]', true,  4),
 ('comunicados',    'COM', 'Comunicados y agenda',          'campaign',             '[A CONFIRMAR]', true,  5),
 ('apps',           'MOB', 'Apps móviles',                  'smartphone',           '[A CONFIRMAR]', true,  6),
 ('plataforma',     'PLA', 'Plataforma multicolegio',       'cloud',                '[A CONFIRMAR]', false, 7),
 ('seguridad',      'SEG', 'Seguridad, usuarios y roles',   'admin_panel_settings', '[A CONFIRMAR]', false, 8),
 ('siagie',         'SIA', 'Integración SIAGIE',            'upload_file',          '[A CONFIRMAR]', false, 9),
 ('documentos',     'DOC', 'Documentación y trámites',      'description',          '[A CONFIRMAR]', false, 10),
 ('moodle',         'MDL', 'Moodle conectado',              'school',               '[A CONFIRMAR]', false, 11),
 ('biblioteca',     'BIB', 'Biblioteca',                    'menu_book',            '[A CONFIRMAR]', false, 12),
 ('movilidad',      'MOV', 'Movilidad escolar',             'directions_bus',       '[A CONFIRMAR]', false, 13),
 ('comedor',        'COD', 'Comedor',                       'restaurant',           '[A CONFIRMAR]', false, 14),
 ('enfermeria',     'SAL', 'Enfermería',                    'medical_services',     '[A CONFIRMAR]', false, 15),
 ('tienda',         'TIE', 'Tienda escolar',                'storefront',           '[A CONFIRMAR]', false, 16),
 ('porteria',       'POR', 'Portería y salidas',            'door_front',           '[A CONFIRMAR]', false, 17),
 ('laboratorios',   'LAB', 'Laboratorios y talleres',       'science',              '[A CONFIRMAR]', false, 18);

-- Configuración: claves vacías hasta que se carguen desde el CMS. El sitio muestra «[A CONFIRMAR]».
INSERT INTO cms.config (clave, valor) VALUES
 ('razon_social', 'null'),
 ('ruc', 'null'),
 ('direccion', 'null'),
 ('telefono_central', 'null'),
 ('email_contacto', 'null'),
 ('horario_soporte', 'null'),
 ('crm_endpoint', '"/api/crm/leads"'),
 ('blog_wp_url', 'null'),
 ('descuento_anual_pct', 'null'),
 ('selector_anual_visible', 'false'),
 ('nota_legal_precios', 'null');

-- Tracking apagado hasta cargar los IDs reales (TRASPASO §10).
INSERT INTO cms.tracking (nombre, activo) VALUES ('gtm', false), ('ga4', false), ('meta_pixel', false), ('linkedin', false), ('clarity', false), ('search_console', false);

-- Secciones editables de la landing (EDUN-233 / EDUN-242), con textos por completar en el CMS.
INSERT INTO cms.secciones (clave, nombre, orden, config) VALUES
 ('barra_superior', 'Barra superior', 1, '{"aviso":"[A CONFIRMAR]"}'),
 ('hero', 'Hero y Portada', 2, '{"insignia":"[A CONFIRMAR]","titulo":"[A CONFIRMAR]","subtitulo":"[A CONFIRMAR]","cta_principal":"Solicitar demo","cta_secundario":null,"sellos":null}');

-- Menú principal y enlaces legales del footer (los legales no se pueden ocultar).
INSERT INTO cms.menu_items (ubicacion, label, url, orden) VALUES
 ('principal', 'Funcionalidades', '/funcionalidades', 1),
 ('principal', 'Planes', '/#planes', 2),
 ('principal', 'Para colegios', '/para-colegios', 3),
 ('principal', 'Blog', '/blog', 4);
INSERT INTO cms.menu_items (ubicacion, columna, label, url, orden, fijo) VALUES
 ('footer', 'Legal', 'Política de privacidad', '/#privacidad', 1, true),
 ('footer', 'Legal', 'Términos y condiciones', '/#terminos', 2, true),
 ('footer', 'Legal', 'Libro de Reclamaciones', '/libro-de-reclamaciones', 3, true),
 ('footer', 'Legal', 'Seguridad de datos', '/#seguridad', 4, true);

-- Sección de módulos de la landing (EDUN-251). {n} = cantidad de módulos visibles.
INSERT INTO cms.secciones (clave, nombre, orden, config) VALUES
 ('modulos', 'Módulos', 6, '{"insignia":"[A CONFIRMAR]","titulo":"[A CONFIRMAR]","descripcion":null,"boton_ver":"Ver los {n} módulos del sistema escolar","boton_ocultar":"Ocultar catálogo de módulos"}');

-- Sprint 5 (EDUN-245, 248, 254, 257, 260, 263): secciones de la landing con textos por completar,
-- cifras verificables del producto y enlaces del footer.
INSERT INTO cms.secciones (clave, nombre, orden, config) VALUES
 ('retos', 'Retos', 4, '{"insignia": "[A CONFIRMAR]", "titulo": "[A CONFIRMAR]", "descripcion": null, "etiqueta_dolor": "El dolor habitual", "etiqueta_solucion": "Solución Edunova", "tarjetas": []}'),
 ('videos', 'Videos', 7, '{"insignia": "[A CONFIRMAR]", "titulo": "[A CONFIRMAR]", "descripcion": null, "boton_demo": "Coordinar demostración en vivo"}'),
 ('pasos', 'Implementación', 8, '{"insignia": "[A CONFIRMAR]", "titulo": "[A CONFIRMAR]", "descripcion": null, "pasos": []}'),
 ('casos_uso', 'Casos de uso', 9, '{"insignia": "[A CONFIRMAR]", "titulo": "[A CONFIRMAR]", "descripcion": null, "tarjetas": []}'),
 ('testimonios', 'Testimonios', 10, '{"insignia": "Testimonios", "titulo": "[A CONFIRMAR]", "leyenda_ejemplo": "Testimonios de ejemplo: se reemplazan por testimonios reales con consentimiento firmado."}'),
 ('planes', 'Planes', 11, '{"insignia": "[A CONFIRMAR]", "titulo": "[A CONFIRMAR]", "descripcion": null, "mensual": "Mensual", "anual": "Anual", "por_mes": "/ mes", "personalizado": "Personalizado", "personalizado_nota": "[A CONFIRMAR]"}'),
 ('cta_final', 'CTA final', 12, '{"insignia": null, "titulo": "[A CONFIRMAR]", "descripcion": null, "boton": "Solicitar demo gratuita", "beneficios": []}'),
 ('footer', 'Footer', 13, '{"descripcion": "[A CONFIRMAR]", "derechos": "Todos los derechos reservados.", "moneda": "Moneda: Soles (S/)"}');
INSERT INTO cms.contadores (label, valor, sufijo, orden) VALUES
 ('módulos integrados en una sola plataforma', 18, NULL, 1),
 ('roles con permisos propios: dirección, docentes, familias', 25, NULL, 2),
 ('apps móviles para familias y docentes, Android e iPhone', 2, NULL, 3),
 ('consulta de pensiones, notas y circulares para las familias', 24, '/7', 4);
INSERT INTO cms.menu_items (ubicacion, columna, label, url, orden) VALUES
 ('footer', 'Plataforma', 'Módulos', '/#funcionalidades', 1),
 ('footer', 'Plataforma', 'Integración SIAGIE', '/funcionalidades#modulo-siagie', 2),
 ('footer', 'Plataforma', 'Planes', '/#planes', 3),
 ('footer', 'Recursos y legal', 'Blog', '/blog', 10);
INSERT INTO cms.menu_items (ubicacion, columna, label, url, orden, fijo) VALUES
 ('footer', 'Legal', 'Seguridad de datos', '/#seguridad', 4, true);
INSERT INTO cms.config (clave, valor) VALUES
 ('testimonios_solo_4_estrellas', 'false'),
 ('oferta_migracion', '{"visible":false,"titulo":null,"texto":"","cta":null,"expira":null}');

-- EDUN-311 · SEO de la landing (por completar desde el CMS).
INSERT INTO cms.seo_paginas (slug, meta_titulo, meta_descripcion) VALUES
 ('inicio', 'Edunova', 'Sistema de gestión escolar para colegios privados del Perú.');
