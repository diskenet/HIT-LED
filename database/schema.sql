-- =============================================
--  ESP32 IoT LED Controller — Database Schema
-- =============================================
--  File: database/schema.sql
--
--  Import this file using one of these methods:
--    A) phpMyAdmin → Import → Select this file
--    B) MySQL CLI: mysql -u root -p < schema.sql
-- =============================================

-- Create database (skip if already exists)
CREATE DATABASE IF NOT EXISTS esp32_iot
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE esp32_iot;

-- ─────────────────────────────────────────────
--  Table 1: led_control
--  Stores the DESIRED LED state.
--  The ESP32 reads this table to know what to do.
--  Only ever has 1 row (id = 1).
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS led_control (
  id         INT          NOT NULL DEFAULT 1,      -- Always row 1
  status     ENUM('ON','OFF') NOT NULL DEFAULT 'OFF',
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  CONSTRAINT chk_single_row CHECK (id = 1)         -- Enforce single row
);

-- Insert the initial OFF state
INSERT IGNORE INTO led_control (id, status) VALUES (1, 'OFF');

-- ─────────────────────────────────────────────
--  Table 2: led_log
--  Stores the full history of every LED action.
--  Grows over time — a new row per action.
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS led_log (
  id           INT          NOT NULL AUTO_INCREMENT,
  action       ENUM('ON','OFF') NOT NULL,           -- What happened
  triggered_by VARCHAR(64)  NOT NULL DEFAULT 'Dashboard', -- Who/what triggered it
  notes        VARCHAR(255) DEFAULT NULL,            -- Optional notes
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  INDEX idx_created_at (created_at DESC)             -- Fast sorting by time
);

-- Insert a sample entry so the history log isn't empty on first load
INSERT INTO led_log (action, triggered_by, notes)
VALUES ('OFF', 'System', 'Initial setup — LED starts in OFF state');

-- ─────────────────────────────────────────────
--  Quick sanity check: view both tables
-- ─────────────────────────────────────────────
SELECT 'led_control table:' AS '';
SELECT * FROM led_control;

SELECT 'led_log table (recent 5):' AS '';
SELECT * FROM led_log ORDER BY id DESC LIMIT 5;
