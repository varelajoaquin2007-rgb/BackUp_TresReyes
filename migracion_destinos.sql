-- =============================================================
-- MIGRACIÓN: puntos de finalización configurables por viaje
-- Ejecutar UNA VEZ en phpMyAdmin.
-- =============================================================

-- Tabla de puntos de finalización guardados (dónde puede terminar un
-- viaje además de la cochera de siempre).
CREATE TABLE IF NOT EXISTS puntos_finalizacion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    calle VARCHAR(150) NOT NULL,
    altura VARCHAR(20) NOT NULL,
    localidad VARCHAR(100) NOT NULL,
    provincia VARCHAR(100) NOT NULL,
    cp VARCHAR(15) NULL,
    lat DECIMAL(10,7) NULL,
    lon DECIMAL(10,7) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Qué punto de finalización eligió el admin para cada viaje (si es NULL,
-- el viaje termina en la cochera de siempre, como hasta ahora).
ALTER TABLE viajes ADD COLUMN destino_id INT NULL;
