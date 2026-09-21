-- =============================================================
-- MIGRACIÓN: reparto por zona + CRUD de camiones/repartidores
-- Ejecutar UNA VEZ en la base de datos (phpMyAdmin de InfinityFree
-- o el cliente MySQL que uses). Es seguro correrlo aunque alguna
-- columna ya exista: en ese caso esa línea puntual va a dar error
-- y se puede ignorar/borrar esa línea.
-- =============================================================

-- 1) La boleta ahora trae su ZONA (1 a 6) escaneada del PDF
ALTER TABLE boletas ADD COLUMN zona VARCHAR(20) NULL AFTER barrio;

-- 2) El viaje se arma por zona: permitir camión nulo mientras está
--    "armado" (pendiente de que el admin elija el camión y confirme)
ALTER TABLE viajes MODIFY COLUMN camion_id INT NULL;
ALTER TABLE viajes MODIFY COLUMN zona VARCHAR(50) NULL;

-- 3) Datos del camión: marca (separada de modelo) para el panel de
--    Configuración → Camiones
ALTER TABLE camiones ADD COLUMN marca VARCHAR(50) NULL AFTER patente;

-- (Opcional pero recomendado) índice para buscar rápido el viaje
-- "armado" de una zona en una fecha determinada
ALTER TABLE viajes ADD INDEX idx_fecha_zona_estado (fecha, zona, estado);
