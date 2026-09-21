-- =============================================================
-- MIGRACIÓN: día de salida por zona + hora de fin de cada parada
-- Ejecutar UNA VEZ en phpMyAdmin. Si una línea ya existía, esa da
-- error y se puede ignorar.
-- =============================================================

-- 1) Día en que el administrador planea que salga cada zona (viaje).
--    Es solo para planificación del admin; el repartidor no lo ve.
ALTER TABLE viajes ADD COLUMN dia_salida DATE NULL AFTER fecha;

-- 2) Momento exacto en que se marcó entregada cada parada (boleta),
--    para mostrar a qué hora terminó cada entrega.
ALTER TABLE boletas ADD COLUMN entregada_at DATETIME NULL;
