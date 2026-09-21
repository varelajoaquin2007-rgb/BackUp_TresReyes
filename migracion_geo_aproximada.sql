-- =============================================================
-- MIGRACIÓN: ubicación aproximada (reintento en cascada de geocodificación)
-- Ejecutar UNA VEZ en phpMyAdmin. Si ya la corriste, no hace falta de nuevo.
-- =============================================================

-- Marca si la ubicación de una boleta es aproximada (no se encontró la
-- dirección exacta y se usó la calle sin altura, o la localidad sola)
-- en vez de la dirección completa.
ALTER TABLE boletas ADD COLUMN geo_aproximada TINYINT(1) NOT NULL DEFAULT 0;
