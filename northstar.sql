-- phpMyAdmin SQL Dump
-- version 4.1.4
-- http://www.phpmyadmin.net
--
-- Host: 127.0.0.1
-- Generation Time: Feb 12, 2026 alle 12:38
-- Versione del server: 5.6.15-log
-- PHP Version: 5.5.8

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;

--
-- Database: `northstar`
--

-- --------------------------------------------------------

--
-- Struttura della tabella `asset`
--

CREATE TABLE IF NOT EXISTS `asset` (
  `id_asset` int(11) NOT NULL AUTO_INCREMENT,
  `codice_asset` varchar(20) NOT NULL,
  `id_tipologia` int(11) NOT NULL,
  `stato` enum('Disponibile','Occupato','Non prenotabile') DEFAULT 'Disponibile',
  `mappa` enum('Sede','Parcheggio') NOT NULL,
  PRIMARY KEY (`id_asset`),
  UNIQUE KEY `codice_asset` (`codice_asset`),
  KEY `id_tipologia` (`id_tipologia`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Struttura della tabella `permessi_prenotazione`
--

CREATE TABLE IF NOT EXISTS `permessi_prenotazione` (
  `id_ruolo` int(11) NOT NULL DEFAULT '0',
  `id_tipologia` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id_ruolo`,`id_tipologia`),
  KEY `id_tipologia` (`id_tipologia`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

--
-- Dump dei dati per la tabella `permessi_prenotazione`
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
-- Struttura della tabella `prenotazioni`
--

CREATE TABLE IF NOT EXISTS `prenotazioni` (
  `id_prenotazione` int(11) NOT NULL AUTO_INCREMENT,
  `id_utente` int(11) NOT NULL,
  `id_asset` int(11) NOT NULL,
  `data_inizio` datetime NOT NULL,
  `data_fine` datetime NOT NULL,
  `numero_modifiche` int(11) DEFAULT '0',
  `attiva` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id_prenotazione`),
  KEY `id_utente` (`id_utente`),
  KEY `id_asset` (`id_asset`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Struttura della tabella `ruoli`
--

CREATE TABLE IF NOT EXISTS `ruoli` (
  `id_ruolo` int(11) NOT NULL AUTO_INCREMENT,
  `nome_ruolo` varchar(20) NOT NULL,
  PRIMARY KEY (`id_ruolo`),
  UNIQUE KEY `nome_ruolo` (`nome_ruolo`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=4 ;

--
-- Dump dei dati per la tabella `ruoli`
--

INSERT INTO `ruoli` (`id_ruolo`, `nome_ruolo`) VALUES
(1, 'Dipendente'),
(2, 'Coordinatore'),
(3, 'Admin');

-- --------------------------------------------------------

--
-- Struttura della tabella `tipologie_asset`
--

CREATE TABLE IF NOT EXISTS `tipologie_asset` (
  `id_tipologia` int(11) NOT NULL AUTO_INCREMENT,
  `codice` varchar(5) NOT NULL,
  `descrizione` varchar(100) NOT NULL,
  PRIMARY KEY (`id_tipologia`),
  UNIQUE KEY `codice` (`codice`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=5 ;

--
-- Dump dei dati per la tabella `tipologie_asset`
--

INSERT INTO `tipologie_asset` (`id_tipologia`, `codice`, `descrizione`) VALUES
(1, 'A', 'Scrivania, cassettiera, armadietto'),
(2, 'A2', 'Scrivania con monitor esterno, cassettiera, armadietto'),
(3, 'B', 'Sala riunioni'),
(4, 'C', 'Posto auto');

-- --------------------------------------------------------

--
-- Struttura della tabella `utenti`
--

CREATE TABLE IF NOT EXISTS `utenti` (
  `id_utente` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) NOT NULL,
  `cognome` varchar(50) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `id_ruolo` int(11) NOT NULL,
  `id_coordinatore` int(11) DEFAULT NULL,
  PRIMARY KEY (`id_utente`),
  UNIQUE KEY `username` (`username`),
  KEY `id_ruolo` (`id_ruolo`),
  KEY `id_coordinatore` (`id_coordinatore`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=6 ;

--
-- Dump dei dati per la tabella `utenti`
--

INSERT INTO `utenti` (`id_utente`, `nome`, `cognome`, `username`, `password`, `id_ruolo`, `id_coordinatore`) VALUES
(1, 'Mattia', 'Carta', 'CartaMattia', 'Password@123', 3, NULL),
(2, 'Filippo', 'Gucci', 'GucciFilippo', 'Password@123', 1, NULL),
(3, 'Miguel', 'Vitali', 'VitaliMiguel', 'Password@123', 1, NULL),
(4, 'Darius', 'Pop', 'PopDarius', 'Password@123', 1, NULL),
(5, 'Ares', 'Gaba', 'GabaAres', 'Password@123', 2, NULL);

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
