-- =====================================================================
-- Edunova · Permisos que no vienen en el catálogo del kit (idempotente).
-- Se carga en la migración 0010 (bases existentes) y en DatabaseSeeder después de
-- 02_semillas.sql (bases nuevas, donde los roles aún no existen al migrar).
-- =====================================================================

-- EDUN-42 · Descargar archivos confidenciales.
INSERT INTO permisos (codigo, modulo, descripcion)
VALUES ('seg.archivos.confidenciales', 'SEG', 'Archivos confidenciales: descargar')
ON CONFLICT (codigo) DO NOTHING;

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r, permisos p
WHERE r.codigo IN ('superadmin', 'promotor', 'director', 'psicologia') AND p.codigo = 'seg.archivos.confidenciales'
ON CONFLICT DO NOTHING;

-- Sprint 15 · El director y el promotor piden soporte desde el colegio (PLA-08 / EDUN-33).
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r, permisos p
WHERE r.codigo IN ('promotor', 'director') AND p.codigo IN ('pla.tickets.ver', 'pla.tickets.crear')
ON CONFLICT DO NOTHING;
