-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Mar 26, 2026 at 12:13 PM
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
  `mappa` enum('Sede','Parcheggio') NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `asset`
--

INSERT INTO `asset` (`id_asset`, `codice_asset`, `id_tipologia`, `stato`, `mappa`) VALUES
(1, 'Sala Riunioni A', 1, 'Disponibile', 'Sede'),
(2, 'Sala Riunioni B', 1, 'Disponibile', 'Sede'),
(3, 'Sala Riunioni C', 1, 'Disponibile', 'Sede'),
(4, 'Parcheggio A', 2, 'Disponibile', 'Parcheggio'),
(5, 'Parcheggio B', 2, 'Disponibile', 'Parcheggio'),
(6, 'Parcheggio C', 2, 'Occupato', 'Parcheggio'),
(7, 'Parcheggio D', 2, 'Disponibile', 'Parcheggio'),
(8, 'Ufficio A', 3, 'Disponibile', 'Sede'),
(9, 'Ufficio B', 3, 'Disponibile', 'Sede'),
(10, 'Ufficio C', 3, 'Disponibile', 'Sede'),
(11, 'Ufficio D', 3, 'Occupato', 'Sede');

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
(5, 4, 0, 1, 0, 'Piano Terra'),
(6, 5, 0, 1, 1, 'Piano Terra'),
(7, 6, 0, 0, 0, 'Esterno'),
(8, 7, 0, 0, 1, 'Esterno');

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
  `numero_modifiche` int(11) DEFAULT 0,
  `attiva` tinyint(1) DEFAULT 1
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `prenotazioni`
--

INSERT INTO `prenotazioni` (`id_prenotazione`, `id_utente`, `id_asset`, `data_inizio`, `data_fine`, `numero_modifiche`, `attiva`) VALUES
(19, 1, 11, '2026-03-25 11:55:00', '2026-03-25 13:00:00', 0, 1),
(18, 1, 11, '2026-03-25 08:00:00', '2026-03-25 12:00:00', 0, 1);

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
  MODIFY `id_asset` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `parcheggio_dettagli`
--
ALTER TABLE `parcheggio_dettagli`
  MODIFY `id_parcheggio` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `prenotazioni`
--
ALTER TABLE `prenotazioni`
  MODIFY `id_prenotazione` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

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
