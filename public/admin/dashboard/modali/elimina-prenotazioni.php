<?php
session_start();
require_once __DIR__ . "/../../../../config/config.php";

header('Content-Type: application/json');

// ── Auth: login + admin check ─────────────────────────
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['id_utente'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non autenticato.']);
    exit();
}

$stmt = $conn->prepare("SELECT r.nome_ruolo FROM utenti u JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE u.id_utente = ?");
$stmt->bind_param("i", $_SESSION['id_utente']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || strtolower($user['nome_ruolo']) !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accesso negato. Solo gli admin possono eliminare prenotazioni.']);
    exit();
}

// ── POST: delete ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'delete') {
    echo json_encode(['success' => false, 'error' => 'Richiesta non valida.']);
    exit();
}

$id_prenotazione = (int)($_POST['id_prenotazione'] ?? 0);
if (!$id_prenotazione) {
    echo json_encode(['success' => false, 'error' => 'ID prenotazione non valido.']);
    exit();
}

try {
    $conn->begin_transaction();

    // Recupera id_asset della prenotazione
    $stmt = $conn->prepare("SELECT id_asset FROM prenotazioni WHERE id_prenotazione = ?");
    $stmt->bind_param("i", $id_prenotazione);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$booking) {
        throw new Exception('Prenotazione non trovata.');
    }

    // Elimina la prenotazione dalla tabella prenotazioni
    $stmt = $conn->prepare("DELETE FROM prenotazioni WHERE id_prenotazione = ?");
    $stmt->bind_param("i", $id_prenotazione);
    if (!$stmt->execute()) throw new Exception('Errore durante l\'eliminazione della prenotazione.');
    $stmt->close();

    // Rende l'asset disponibile
    $stmt = $conn->prepare("UPDATE asset SET stato = 'Disponibile' WHERE id_asset = ?");
    $stmt->bind_param("i", $booking['id_asset']);
    if (!$stmt->execute()) throw new Exception('Errore durante l\'aggiornamento dello stato dell\'asset.');
    $stmt->close();

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Prenotazione eliminata e asset reso disponibile.']);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}