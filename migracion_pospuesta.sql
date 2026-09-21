-- =============================================================
-- MIGRACIÓN: permitir el estado 'pospuesta' en boletas
-- Ejecutar UNA VEZ en phpMyAdmin.
-- =============================================================

-- Si la columna "estado" es un ENUM con una lista fija de valores
-- (pendiente, asignada, entregada, etc.), "pospuesta" no se puede
-- guardar aunque el sistema no tire error. Se cambia a VARCHAR para que
-- acepte cualquier texto sin volver a tocar la base cada vez que se
-- agregue un estado nuevo.
ALTER TABLE boletas MODIFY COLUMN estado VARCHAR(20) NOT NULL DEFAULT 'pendiente';
