-- Roles de PostgreSQL para desarrollo local (se ejecuta solo al crear el volumen por primera vez).
-- El esquema (01_esquema.sql) los crea sin clave si no existen; aquí se crean antes, con clave de desarrollo.
-- En otros entornos las claves vienen del gestor de secretos, nunca de este archivo.
CREATE ROLE edunova_app LOGIN NOBYPASSRLS PASSWORD 'edunova_app';
CREATE ROLE edunova_plataforma LOGIN BYPASSRLS PASSWORD 'edunova_plataforma';
