-- ==========================================
-- PURO DATABASE MIGRATION SCRIPT
-- Run this script in phpMyAdmin on your VPS
-- ==========================================

-- 1. Add slug column to profesionales table
ALTER TABLE profesionales ADD COLUMN slug VARCHAR(255) NULL AFTER apellido;

-- 2. Populate slugs for existing professionals to prevent null or duplicate constraints
-- (This runs a safe update concatenating the name and ID if the slug is null)
UPDATE profesionales 
SET slug = LOWER(CONCAT(
    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(nombre, ' ', '-'), 'ñ', 'n'), 'á', 'a'), 'é', 'e'), 'í', 'i'), 
    '-', 
    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(apellido, ' ', '-'), 'ñ', 'n'), 'á', 'a'), 'é', 'e'), 'í', 'i'), 
    '-', 
    id
))
WHERE slug IS NULL;

-- 3. Modify slug column to be NOT NULL and UNIQUE
ALTER TABLE profesionales MODIFY COLUMN slug VARCHAR(255) NOT NULL UNIQUE;

-- 4. Create conversaciones table for short-polling chats
CREATE TABLE IF NOT EXISTS conversaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    id_profesional INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_chat (id_usuario, id_profesional),
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (id_profesional) REFERENCES profesionales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Create mensajes table for chat messages
CREATE TABLE IF NOT EXISTS mensajes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_conversacion INT NOT NULL,
    remitente_tipo ENUM('usuario', 'profesional') NOT NULL,
    mensaje TEXT NOT NULL,
    leido TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_conversacion) REFERENCES conversaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Modify payment status to include the 'disputado' status
ALTER TABLE pagos MODIFY COLUMN estado ENUM('retenido', 'liberado', 'devuelto', 'disputado') NOT NULL DEFAULT 'retenido';
