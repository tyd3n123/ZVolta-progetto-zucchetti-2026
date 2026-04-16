<?php
session_start();
require_once __DIR__ . "/../../../config/config.php";

// ── Signout ───────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'signout') {
    session_unset();
    session_destroy();
    header("Location: ../login.php");
    exit();
}

// ── Auth: login + admin check ─────────────────────────
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['id_utente'])) {
    header("Location: ../login.php");
    exit();
}

$id_utente = (int)$_SESSION['id_utente'];

$stmt = $conn->prepare("SELECT r.nome_ruolo FROM utenti u JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE u.id_utente = ?");
$stmt->bind_param("i", $id_utente);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || strtolower($user['nome_ruolo']) !== 'dipendente') {
    header("Location: ../login.php");
    exit();
}

// ── Info utente ───────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT u.nome, u.cognome, r.nome_ruolo AS ruolo
     FROM utenti u
     LEFT JOIN ruoli r ON u.id_ruolo = r.id_ruolo
     WHERE u.id_utente = ? LIMIT 1"
);
$stmt->bind_param("i", $id_utente);
$stmt->execute();
$userInfo = $stmt->get_result()->fetch_assoc();
$stmt->close();

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Northstar</title>
    <link rel="stylesheet" href="./dashboard.css"> 
</head>
<body>

<!-- ── Header ─────────────────────────────────────────── -->
<header class="header">
    <div class="header-left"><h1>Northstar</h1></div>
    <a href="?action=signout" class="signout-btn">Esci</a>
</header>

<!-- ── Welcome ───────────────────────────────────────── -->
<div class="welcome-section">
    <h2>Benvenuto, <?= htmlspecialchars($userInfo['nome']) ?></h2>
    <span class="role-badge"><?= htmlspecialchars($userInfo['ruolo']) ?></span>
</div>

<!-- ── Main Layout ────────────────────────────────────── -->
<div class="dashboard-container">

    <!-- Colonna sinistra: prenota -->
        <div class="booking-card" onclick="location.href='./asset-sede.php'">
            <div class="card-content">
                <h3>Prenotazioni</h3>
                <p>Prenota sale riunioni, uffici o parcheggi</p>
                <button class="book-btn">Prenota ora</button>
            </div>
        </div>

    <!-- Colonna destra: prenotazioni + utenti -->
    <div class="bookings-section">


        <div class="bookings-row">

            <!-- Le tue prenotazioni -->
            <div class="bookings-column">
                <h2>Le tue prenotazioni</h2>
                <div class="booking-list">
                    <?php if (empty($userBookings)): ?>
                        <p>Nessuna prenotazione attiva</p>
                    <?php else: ?>
                        <?php foreach (array_slice($userBookings, 0, 3) as $b): ?>
                            <div class="booking-item">
                                <h4><?= htmlspecialchars($b['codice_asset']) ?></h4>
                                <p><strong>Inizio:</strong> <?= date('d/m/Y H:i', strtotime($b['data_inizio'])) ?></p>
                                <p><strong>Fine:</strong>   <?= date('d/m/Y H:i', strtotime($b['data_fine'])) ?></p>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($userBookings) > 3): ?>
                            <p class="more-bookings">+<?= count($userBookings) - 3 ?> altre prenotazioni</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <button class="show-more-btn" onclick="openUserBookingsModal()">Mostra tutte →</button>
            </div>

<!-- ── Modals ─────────────────────────────────────────── -->
<div id="user-bookings-modal" class="modal-overlay" style="display:none;">
    <div class="modal-container"></div>
</div>

<div id="gestione-prenotazioni-modal" class="modal-overlay" style="display:none;">
    <div class="modal-container"></div>
</div>

<script>
// ── Modale: le tue prenotazioni ───────────────────────
function openUserBookingsModal() {
    const modal     = document.getElementById('user-bookings-modal');
    const container = modal.querySelector('.modal-container');
    modal.style.display = 'flex';
    fetch('modali/user-bookings-modal.php')
        .then(r => r.text())
        .then(html => { container.innerHTML = html; })
        .catch(() => { container.innerHTML = '<div class="error-message">Errore nel caricamento del contenuto.</div>'; });
}

function closeUserBookingsModal() {
    document.getElementById('user-bookings-modal').style.display = 'none';
}

function reloadUserBookingsModal() {
    const modal     = document.getElementById('user-bookings-modal');
    const container = modal.querySelector('.modal-container');
    fetch('modali/user-bookings-modal.php')
        .then(r => r.text())
        .then(html => { container.innerHTML = html; })
        .catch(() => { container.innerHTML = '<div class="error-message">Errore nel ricaricamento del contenuto.</div>'; });
}

// ── Chiudi tutti i modali ─────────────────────────────
function closeModal() {
    document.querySelectorAll('.modal-overlay').forEach(m => m.style.display = 'none');
}

document.addEventListener('click',   e => { if (e.target.classList.contains('modal-overlay')) closeModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

// ── Funzioni modale utente ────────────────────────────
function cancelBooking(id) {
    if (!confirm('Sei sicuro di voler annullare questa prenotazione?')) return;
    fetch('modali/user-bookings-modal.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=cancel&id_prenotazione=${id}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) reloadUserBookingsModal();
        else alert('Errore: ' + (data.error || 'Errore sconosciuto.'));
    })
    .catch(() => alert('Errore durante la comunicazione con il server.'));
}

</body>
</html>