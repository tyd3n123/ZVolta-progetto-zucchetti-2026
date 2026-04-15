-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 15, 2026 at 04:35 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `northstar`
--

-- --------------------------------------------------------

--
-- Table structure for table `asset`
--

CREATE TABLE `asset` (
  `id_asset` int(11) NOT NULL,
  `codice_asset` varchar(20) NOT NULL,
  `id_tipologia` int(11) NOT NULL,
  `stato` enum('Disponibile','Occupato','Non prenotabile') DEFAULT 'Disponibile',
  `mappa` enum('Sede','Parcheggio') NOT NULL,
  `piano` int(11) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `asset`
--

INSERT INTO `asset` (`id_asset`, `codice_asset`, `id_tipologia`, `stato`, `mappa`, `piano`) VALUES
(1, 'AUTO-C-001', 0, 'Disponibile', 'Parcheggio', NULL),
(2, 'AUTO-C-002', 0, 'Disponibile', 'Parcheggio', NULL),
(3, 'AUTO-C-003', 0, 'Disponibile', 'Parcheggio', NULL),
(4, 'AUTO-C-004', 0, 'Disponibile', 'Parcheggio', NULL),
(5, 'AUTO-C-005', 0, 'Disponibile', 'Parcheggio', NULL),
(6, 'AUTO-C-006', 0, 'Disponibile', 'Parcheggio', NULL),
(7, 'AUTO-C-007', 0, 'Disponibile', 'Parcheggio', NULL),
(8, 'AUTO-C-008', 0, 'Disponibile', 'Parcheggio', NULL),
(9, 'AUTO-C-009', 0, 'Disponibile', 'Parcheggio', NULL),
(10, 'AUTO-C-010', 0, 'Disponibile', 'Parcheggio', NULL),
(11, 'AUTO-C-011', 0, 'Disponibile', 'Parcheggio', NULL),
(12, 'AUTO-C-012', 0, 'Disponibile', 'Parcheggio', NULL),
(13, 'AUTO-C-013', 0, 'Disponibile', 'Parcheggio', NULL),
(14, 'AUTO-C-014', 0, 'Disponibile', 'Parcheggio', NULL),
(15, 'AUTO-C-015', 0, 'Disponibile', 'Parcheggio', NULL),
(16, 'AUTO-C-016', 0, 'Disponibile', 'Parcheggio', NULL),
(27, 'TIPO-A-011', 1, 'Disponibile', 'Sede', NULL),
(26, 'TIPO-A-010', 1, 'Disponibile', 'Sede', NULL),
(25, 'TIPO-A-009', 1, 'Disponibile', 'Sede', NULL),
(24, 'TIPO-A-008', 1, 'Disponibile', 'Sede', NULL),
(23, 'TIPO-A-007', 1, 'Disponibile', 'Sede', NULL),
(22, 'TIPO-A-006', 1, 'Disponibile', 'Sede', NULL),
(21, 'TIPO-A-005', 1, 'Disponibile', 'Sede', NULL),
(20, 'TIPO-A-004', 1, 'Disponibile', 'Sede', NULL),
(17, 'TIPO-A-001', 1, 'Disponibile', 'Sede', NULL),
(18, 'TIPO-A-002', 1, 'Disponibile', 'Sede', NULL),
(19, 'TIPO-A-003', 1, 'Disponibile', 'Sede', NULL),
(49, 'TIPO-A2-013', 2, 'Disponibile', 'Sede', NULL),
(48, 'TIPO-A2-012', 2, 'Disponibile', 'Sede', NULL),
(47, 'TIPO-A2-011', 2, 'Disponibile', 'Sede', NULL),
(46, 'TIPO-A2-010', 2, 'Disponibile', 'Sede', NULL),
(45, 'TIPO-A2-009', 2, 'Disponibile', 'Sede', NULL),
(44, 'TIPO-A2-008', 2, 'Disponibile', 'Sede', NULL),
(43, 'TIPO-A2-007', 2, 'Disponibile', 'Sede', NULL),
(42, 'TIPO-A2-006', 2, 'Disponibile', 'Sede', NULL),
(41, 'TIPO-A2-005', 2, 'Disponibile', 'Sede', NULL),
(40, 'TIPO-A2-004', 2, 'Disponibile', 'Sede', NULL),
(39, 'TIPO-A2-003', 2, 'Disponibile', 'Sede', NULL),
(38, 'TIPO-A2-002', 2, 'Disponibile', 'Sede', NULL),
(37, 'TIPO-A2-001', 2, 'Disponibile', 'Sede', NULL),
(36, 'TIPO-A-020', 1, 'Disponibile', 'Sede', NULL),
(35, 'TIPO-A-019', 1, 'Disponibile', 'Sede', NULL),
(34, 'TIPO-A-018', 1, 'Disponibile', 'Sede', NULL),
(33, 'TIPO-A-017', 1, 'Disponibile', 'Sede', NULL),
(32, 'TIPO-A-016', 1, 'Disponibile', 'Sede', NULL),
(31, 'TIPO-A-015', 1, 'Disponibile', 'Sede', NULL),
(30, 'TIPO-A-014', 1, 'Disponibile', 'Sede', NULL),
(29, 'TIPO-A-013', 1, 'Disponibile', 'Sede', NULL),
(28, 'TIPO-A-012', 1, 'Disponibile', 'Sede', NULL),
(64, 'TIPO-A2-028', 2, 'Disponibile', 'Sede', NULL),
(63, 'TIPO-A2-027', 2, 'Disponibile', 'Sede', NULL),
(62, 'TIPO-A2-026', 2, 'Disponibile', 'Sede', NULL),
(61, 'TIPO-A2-025', 2, 'Disponibile', 'Sede', NULL),
(60, 'TIPO-A2-024', 2, 'Disponibile', 'Sede', NULL),
(59, 'TIPO-A2-023', 2, 'Disponibile', 'Sede', NULL),
(58, 'TIPO-A2-022', 2, 'Disponibile', 'Sede', NULL),
(57, 'TIPO-A2-021', 2, 'Disponibile', 'Sede', NULL),
(56, 'TIPO-A2-020', 2, 'Disponibile', 'Sede', NULL),
(55, 'TIPO-A2-019', 2, 'Disponibile', 'Sede', NULL),
(54, 'TIPO-A2-018', 2, 'Disponibile', 'Sede', NULL),
(53, 'TIPO-A2-017', 2, 'Disponibile', 'Sede', NULL),
(52, 'TIPO-A2-016', 2, 'Disponibile', 'Sede', NULL),
(51, 'TIPO-A2-015', 2, 'Disponibile', 'Sede', NULL),
(50, 'TIPO-A2-014', 2, 'Disponibile', 'Sede', NULL),
(65, 'TIPO-A2-029', 2, 'Disponibile', 'Sede', NULL),
(66, 'TIPO-A2-030', 2, 'Disponibile', 'Sede', NULL),
(121, 'SALA-001', 3, 'Disponibile', 'Sede', 1),
(122, 'SALA-002', 3, 'Disponibile', 'Sede', 1),
(123, 'SALA-003', 3, 'Disponibile', 'Sede', 2),
(124, 'SALA-004', 3, 'Disponibile', 'Sede', 2),
(125, 'SALA-005', 3, 'Disponibile', 'Sede', 3);

-- --------------------------------------------------------

--
-- Table structure for table `parcheggio_dettagli`
--

CREATE TABLE `parcheggio_dettagli` (
  `id_parcheggio` int(11) NOT NULL,
  `id_asset` int(11) NOT NULL,
  `numero_posto` int(11) DEFAULT NULL,
  `coperto` tinyint(1) DEFAULT 0,
  `colonnina_elettrica` tinyint(1) DEFAULT 0,
  `posizione` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `parcheggio_dettagli`
--

INSERT INTO `parcheggio_dettagli` (`id_parcheggio`, `id_asset`, `numero_posto`, `coperto`, `colonnina_elettrica`, `posizione`) VALUES
(1, 1, 1, 1, 1, 'Piano Terra'),
(2, 2, 2, 1, 1, 'Piano Terra'),
(3, 3, 3, 1, 1, 'Piano Terra'),
(4, 4, 4, 1, 1, 'Piano Terra'),
(5, 5, 5, 1, 1, 'Piano Terra'),
(6, 6, 6, 1, 1, 'Piano Terra'),
(7, 7, 7, 1, 1, 'Piano Terra'),
(8, 8, 8, 1, 1, 'Piano Terra'),
(9, 9, 9, 0, 0, 'Piano Terra'),
(10, 10, 10, 0, 0, 'Piano Terra'),
(11, 11, 11, 0, 0, 'Piano Terra'),
(12, 12, 12, 0, 0, 'Piano Terra'),
(13, 13, 13, 0, 0, 'Piano Terra'),
(14, 14, 14, 0, 0, 'Piano Terra'),
(15, 15, 15, 0, 0, 'Piano Terra'),
(16, 16, 16, 0, 0, 'Piano Terra');

-- --------------------------------------------------------

--
-- Table structure for table `permessi_prenotazione`
--

CREATE TABLE `permessi_prenotazione` (
  `id_ruolo` int(11) NOT NULL DEFAULT 0,
  `id_tipologia` int(11) NOT NULL DEFAULT 0
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `permessi_prenotazione`
--

INSERT INTO `permessi_prenotazione` (`id_ruolo`, `id_tipologia`) VALUES
(1, 1),
(1, 2),
(1, 3),
(2, 1),
(2, 2),
(2, 3),
(2, 4);

-- --------------------------------------------------------

--
-- Table structure for table `prenotazioni`
--

CREATE TABLE `prenotazioni` (
  `id_prenotazione` int(11) NOT NULL,
  `id_utente` int(11) NOT NULL,
  `id_asset` int(11) NOT NULL,
  `data_inizio` datetime NOT NULL,
  `data_fine` datetime NOT NULL,
  `modificata` tinyint(1) DEFAULT 0,
  `attiva` tinyint(1) DEFAULT 1
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ruoli`
--

CREATE TABLE `ruoli` (
  `id_ruolo` int(11) NOT NULL,
  `nome_ruolo` varchar(20) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `ruoli`
--

INSERT INTO `ruoli` (`id_ruolo`, `nome_ruolo`) VALUES
(1, 'Dipendente'),
(2, 'Coordinatore'),
(3, 'Admin');

-- --------------------------------------------------------

--
-- Table structure for table `sala_dettagli`
--

CREATE TABLE `sala_dettagli` (
  `id_asset` int(11) NOT NULL,
  `capacita` int(11) NOT NULL,
  `attrezzatura` varchar(255) DEFAULT NULL,
  `orario_apertura` time DEFAULT NULL,
  `orario_chiusura` time DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sala_dettagli`
--

INSERT INTO `sala_dettagli` (`id_asset`, `capacita`, `attrezzatura`, `orario_apertura`, `orario_chiusura`) VALUES
(1, 10, 'Proiettore, Lavagna, Videoconferenza', '09:00:00', '18:00:00'),
(2, 6, 'Monitor, Lavagna', '09:00:00', '18:00:00'),
(3, 20, 'Proiettore, Microfoni, Videoconferenza', '08:00:00', '19:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `tipologie_asset`
--

CREATE TABLE `tipologie_asset` (
  `id_tipologia` int(11) NOT NULL,
  `codice` varchar(5) NOT NULL,
  `descrizione` varchar(100) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ufficio_dettagli`
--

CREATE TABLE `ufficio_dettagli` (
  `id_ufficio` int(11) NOT NULL,
  `id_asset` int(11) NOT NULL,
  `numero_ufficio` int(11) DEFAULT NULL,
  `piano` int(11) DEFAULT NULL,
  `capacita` int(11) DEFAULT NULL,
  `telefono_interno` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ufficio_dettagli`
--

INSERT INTO `ufficio_dettagli` (`id_ufficio`, `id_asset`, `numero_ufficio`, `piano`, `capacita`, `telefono_interno`) VALUES
(1, 8, 0, 0, 4, '101'),
(2, 9, 0, 0, 6, '102'),
(3, 10, 0, 0, 8, '103'),
(4, 11, 0, 0, 10, '104');

-- --------------------------------------------------------

--
-- Table structure for table `utenti`
--

CREATE TABLE `utenti` (
  `id_utente` int(11) NOT NULL,
  `nome` varchar(50) NOT NULL,
  `cognome` varchar(50) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `id_ruolo` int(11) NOT NULL,
  `id_coordinatore` int(11) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `utenti`
--

INSERT INTO `utenti` (`id_utente`, `nome`, `cognome`, `username`, `password`, `id_ruolo`, `id_coordinatore`) VALUES
(1, 'Mattia', 'Carta', 'CartaMattia', 'Password@123', 3, NULL),
(2, 'Filippo', 'Gucci', 'GucciFilippo', 'Password@123', 1, NULL),
(3, 'Miguel', 'Vitali', 'VitaliMiguel', 'Password@123', 1, NULL),
(4, 'Darius', 'Pop', 'PopDarius', 'Password@123', 2, NULL),
(6, 'Francesco', 'Herrera', 'HerreraFrancesco', 'Password@123', 1, NULL),
(7, 'Andrea', 'Cesarini', 'CesariniAndrea', 'Password@123', 1, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `asset`
--
ALTER TABLE `asset`
  ADD PRIMARY KEY (`id_asset`),
  ADD UNIQUE KEY `codice_asset` (`codice_asset`),
  ADD KEY `id_tipologia` (`id_tipologia`);

--
-- Indexes for table `parcheggio_dettagli`
--
ALTER TABLE `parcheggio_dettagli`
  ADD PRIMARY KEY (`id_parcheggio`);

--
-- Indexes for table `permessi_prenotazione`
--
ALTER TABLE `permessi_prenotazione`
  ADD PRIMARY KEY (`id_ruolo`,`id_tipologia`),
  ADD KEY `id_tipologia` (`id_tipologia`);

--
-- Indexes for table `prenotazioni`
--
ALTER TABLE `prenotazioni`
  ADD PRIMARY KEY (`id_prenotazione`),
  ADD KEY `id_utente` (`id_utente`),
  ADD KEY `id_asset` (`id_asset`);

--
-- Indexes for table `ruoli`
--
ALTER TABLE `ruoli`
  ADD PRIMARY KEY (`id_ruolo`),
  ADD UNIQUE KEY `nome_ruolo` (`nome_ruolo`);

--
-- Indexes for table `sala_dettagli`
--
ALTER TABLE `sala_dettagli`
  ADD PRIMARY KEY (`id_asset`);

--
-- Indexes for table `tipologie_asset`
--
ALTER TABLE `tipologie_asset`
  ADD PRIMARY KEY (`id_tipologia`),
  ADD UNIQUE KEY `codice` (`codice`);

--
-- Indexes for table `ufficio_dettagli`
--
ALTER TABLE `ufficio_dettagli`
  ADD PRIMARY KEY (`id_ufficio`);

--
-- Indexes for table `utenti`
--
ALTER TABLE `utenti`
  ADD PRIMARY KEY (`id_utente`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `id_ruolo` (`id_ruolo`),
  ADD KEY `id_coordinatore` (`id_coordinatore`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `asset`
--
ALTER TABLE `asset`
  MODIFY `id_asset` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=126;

--
-- AUTO_INCREMENT for table `parcheggio_dettagli`
--
ALTER TABLE `parcheggio_dettagli`
  MODIFY `id_parcheggio` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `prenotazioni`
--
ALTER TABLE `prenotazioni`
  MODIFY `id_prenotazione` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `ruoli`
--
ALTER TABLE `ruoli`
  MODIFY `id_ruolo` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tipologie_asset`
--
ALTER TABLE `tipologie_asset`
  MODIFY `id_tipologia` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `ufficio_dettagli`
--
ALTER TABLE `ufficio_dettagli`
  MODIFY `id_ufficio` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `utenti`
--
ALTER TABLE `utenti`
  MODIFY `id_utente` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
