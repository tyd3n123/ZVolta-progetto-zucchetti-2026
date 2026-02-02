-- =========================
-- CREAZIONE DATABASE
-- =========================
CREATE DATABASE IF NOT EXISTS z_volta;
USE z_volta;

-- =========================
-- TABELLA UTENTI
-- =========================
CREATE TABLE IF NOT EXISTS utenti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(100) NOT NULL,
    nome VARCHAR(50) NOT NULL,
    cognome VARCHAR(50) NOT NULL,
    ruolo ENUM('dipendente','coordinatore','admin') NOT NULL,
    coordinatore_id INT DEFAULT NULL,
    CONSTRAINT fk_coordinatore
        FOREIGN KEY (coordinatore_id)
        REFERENCES utenti(id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

-- =========================
-- TABELLA UFFICI / POSTAZIONI (A - A2)
-- =========================
CREATE TABLE IF NOT EXISTS uffici (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codice VARCHAR(20) UNIQUE NOT NULL,
    tipo ENUM('A','A2') NOT NULL,
    stato ENUM('disponibile','occupato','non prenotabile') DEFAULT 'disponibile'
) ENGINE=InnoDB;

-- =========================
-- TABELLA SALE RIUNIONI (B)
-- =========================
CREATE TABLE IF NOT EXISTS sale_riunioni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codice VARCHAR(20) UNIQUE NOT NULL,
    capienza INT NOT NULL,
    stato ENUM('disponibile','occupato','non prenotabile') DEFAULT 'disponibile'
) ENGINE=InnoDB;

-- =========================
-- TABELLA PARCHEGGI (C)
-- =========================
CREATE TABLE IF NOT EXISTS parcheggi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codice VARCHAR(20) UNIQUE NOT NULL,
    stato ENUM('disponibile','occupato','non prenotabile') DEFAULT 'disponibile'
) ENGINE=InnoDB;

-- =========================
-- TABELLA PRENOTAZIONI
-- =========================
CREATE TABLE IF NOT EXISTS prenotazioni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    utente_id INT NOT NULL,
    tipo_asset ENUM('ufficio','sala','parcheggio') NOT NULL,
    asset_id INT NOT NULL,
    data_inizio DATETIME NOT NULL,
    data_fine DATETIME NOT NULL,
    modifiche INT DEFAULT 0,
    CONSTRAINT fk_prenotazioni_utenti
        FOREIGN KEY (utente_id)
        REFERENCES utenti(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- =========================
-- INSERT UTENTI
-- =========================
INSERT INTO utenti (username, password, nome, cognome, ruolo, coordinatore_id) VALUES
('GucciFilippo', 'Passw0rd!', 'Filippo', 'Gucci', 'dipendente', 4),
('VitaliMiguel', 'Passw0rd!', 'Miguel', 'Vitali', 'dipendente', 4),
('PopDarius', 'Passw0rd!', 'Darius', 'Pop', 'dipendente', 5),
('GabaAres', 'Passw0rd!', 'Ares', 'Gaba', 'coordinatore', NULL),
('HerreraFrancesco', 'Passw0rd!', 'Francesco', 'Herrera', 'coordinatore', NULL),
('CesariniAndrea', 'Passw0rd!', 'Andrea', 'Cesarini', 'coordinatore', NULL),
('CartaMattia', 'Passw0rd!', 'Mattia', 'Carta', 'admin', NULL);

-- =========================
-- INSERT UFFICI
-- =========================
INSERT INTO uffici (codice, tipo, stato) VALUES
('A1','A','disponibile'),
('A2','A','disponibile'),
('A3','A2','disponibile'),
('A4','A2','disponibile');

-- =========================
-- INSERT SALE RIUNIONI
-- =========================
INSERT INTO sale_riunioni (codice, capienza, stato) VALUES
('B1',10,'disponibile'),
('B2',8,'disponibile');

-- =========================
-- INSERT PARCHEGGI
-- =========================
INSERT INTO parcheggi (codice, stato) VALUES
('C1','disponibile'),
('C2','disponibile');

-- =========================
-- INSERT PRENOTAZIONI
-- =========================
INSERT INTO prenotazioni (utente_id, tipo_asset, asset_id, data_inizio, data_fine, modifiche) VALUES
(1,'ufficio',1,'2026-02-05 09:00:00','2026-02-05 17:00:00',0),
(2,'ufficio',2,'2026-02-05 09:00:00','2026-02-05 17:00:00',1),
(3,'sala',1,'2026-02-05 10:00:00','2026-02-05 12:00:00',0),
(4,'parcheggio',1,'2026-02-05 08:00:00','2026-02-05 18:00:00',0);

-- =========================
-- QUERY DI TEST
-- =========================

-- Tutti gli utenti
SELECT * FROM utenti;

-- Tutti gli uffici
SELECT * FROM uffici;

-- Tutte le sale riunioni
SELECT * FROM sale_riunioni;

-- Tutti i parcheggi
SELECT * FROM parcheggi;

-- Tutte le prenotazioni
SELECT * FROM prenotazioni;

-- Prenotazioni complete (JOIN con utenti e asset)
SELECT 
    p.id AS prenotazione_id,
    u.nome AS utente_nome,
    u.cognome AS utente_cognome,
    u.ruolo AS utente_ruolo,
    p.tipo_asset,
    p.asset_id,
    p.data_inizio,
    p.data_fine,
    p.modifiche,
    CASE
        WHEN p.tipo_asset='ufficio' THEN uf.codice
        WHEN p.tipo_asset='sala' THEN s.codice
        WHEN p.tipo_asset='parcheggio' THEN pa.codice
        ELSE NULL
    END AS codice_asset
FROM prenotazioni p
JOIN utenti u ON p.utente_id = u.id
LEFT JOIN uffici uf ON p.asset_id = uf.id AND p.tipo_asset='ufficio'
LEFT JOIN sale_riunioni s ON p.asset_id = s.id AND p.tipo_asset='sala'
LEFT JOIN parcheggi pa ON p.asset_id = pa.id AND p.tipo_asset='parcheggio';
