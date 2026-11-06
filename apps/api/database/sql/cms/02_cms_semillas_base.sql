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
