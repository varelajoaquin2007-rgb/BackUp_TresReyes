-- =============================================================
-- MIGRACIÓN: código postal de la dirección de entrega
-- Ejecutar UNA VEZ en phpMyAdmin. Si ya existía la columna, esa línea
-- da error y se ignora.
-- =============================================================

-- Código postal (CPA) extraído del campo Entrega de la factura.
-- Se suma a la búsqueda de geocodificación para mayor precisión.
ALTER TABLE boletas ADD COLUMN cp VARCHAR(12) NULL AFTER barrio;
