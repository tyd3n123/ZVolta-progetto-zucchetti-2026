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

if (!$user || strtolower($user['nome_ruolo']) !== 'admin') {
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

// ── Analytics ─────────────────────────────────────────
$analytics = ['dipendenti' => 0, 'coordinatori' => 0, 'prenotazioni_totali' => 0];

$res = $conn->query("SELECT COUNT(*) AS c FROM utenti u JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE r.nome_ruolo = 'Dipendente'");
if ($res) $analytics['dipendenti'] = $res->fetch_assoc()['c'];

$res = $conn->query("SELECT COUNT(*) AS c FROM utenti u JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE r.nome_ruolo = 'Coordinatore'");
if ($res) $analytics['coordinatori'] = $res->fetch_assoc()['c'];

$res = $conn->query("SELECT COUNT(*) AS c FROM prenotazioni");
if ($res) $analytics['prenotazioni_totali'] = $res->fetch_assoc()['c'];

// ── Prenotazioni dell'admin ───────────────────────────
$userBookings = [];
$stmt = $conn->prepare(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine, a.codice_asset
     FROM prenotazioni p
     JOIN asset a ON p.id_asset = a.id_asset
     WHERE p.id_utente = ?
     ORDER BY p.data_inizio DESC"
);
$stmt->bind_param("i", $id_utente);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $userBookings[] = $row;
$stmt->close();

// ── Tutte le prenotazioni ─────────────────────────────
$allBookings = [];
$result = $conn->query(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine,
            a.codice_asset, u.nome, u.cognome, r.nome_ruolo
     FROM prenotazioni p
     JOIN asset  a ON p.id_asset  = a.id_asset
     JOIN utenti u ON p.id_utente = u.id_utente
     JOIN ruoli  r ON u.id_ruolo  = r.id_ruolo
     ORDER BY p.data_inizio DESC"
);
if ($result) while ($row = $result->fetch_assoc()) $allBookings[] = $row;

// ── Lista dipendenti ──────────────────────────────────
$employees = [];
$result = $conn->query(
    "SELECT u.id_utente, u.nome, u.cognome, r.nome_ruolo AS ruolo
     FROM utenti u
     JOIN ruoli r ON u.id_ruolo = r.id_ruolo
     WHERE r.nome_ruolo IN ('Dipendente', 'Coordinatore')
     ORDER BY u.cognome, u.nome"
);
if ($result) while ($row = $result->fetch_assoc()) $employees[] = $row;
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

<!-- ── Analytics ─────────────────────────────────────── -->
<div class="analytics-container">
    <div class="analytics-card">
        <div class="analytics-icon">👥</div>
        <div class="analytics-content">
            <h3>Dipendenti</h3>
            <p class="analytics-number"><?= $analytics['dipendenti'] ?></p>
        </div>
    </div>
    <div class="analytics-card">
        <div class="analytics-icon">👔</div>
        <div class="analytics-content">
            <h3>Coordinatori</h3>
            <p class="analytics-number"><?= $analytics['coordinatori'] ?></p>
        </div>
    </div>
    <div class="analytics-card">
        <div class="analytics-icon">📅</div>
        <div class="analytics-content">
            <h3>Prenotazioni Totali</h3>
            <p class="analytics-number"><?= $analytics['prenotazioni_totali'] ?></p>
        </div>
    </div>
</div>

<!-- ── Main Layout ────────────────────────────────────── -->
<div class="dashboard-container">

    <!-- Colonna sinistra: prenota -->
        <div class="booking-card" onclick="location.href='./asset-disponibili.php'">
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

            <!-- Tutte le prenotazioni -->
            <div class="bookings-column">
                <h2>Tutte le prenotazioni</h2>
                <div class="booking-list">
                    <?php if (empty($allBookings)): ?>
                        <p>Nessuna prenotazione presente</p>
                    <?php else: ?>
                        <?php foreach (array_slice($allBookings, 0, 4) as $b): ?>
                            <div class="booking-item">
                                <h4><?= htmlspecialchars($b['codice_asset']) ?></h4>
                                <p><strong>Utente:</strong> <?= htmlspecialchars($b['nome'] . ' ' . $b['cognome']) ?></p>
                                <p><strong>Inizio:</strong> <?= date('d/m/Y H:i', strtotime($b['data_inizio'])) ?></p>
                                <p><strong>Fine:</strong>   <?= date('d/m/Y H:i', strtotime($b['data_fine'])) ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <button class="show-more-btn" onclick="openGestionePrenotazioni()">Gestisci prenotazioni →</button>
            </div>

            <!-- Gestisci Utenti -->
            <div class="bookings-column small">
                <h2>Gestisci Utenti</h2>
                <div class="employees-list">
                    <?php if (empty($employees)): ?>
                        <p class="no-employees">Nessun dipendente presente</p>
                    <?php else: ?>
                        <?php foreach ($employees as $emp): ?>
                            <div class="employee-item">
                                <div class="employee-info">
                                    <h4><?= htmlspecialchars($emp['nome'] . ' ' . $emp['cognome']) ?></h4>
                                    <p class="employee-role"><?= htmlspecialchars($emp['ruolo']) ?></p>
                                </div>
                                <button class="employee-btn"
                                        onclick="location.href='../utenti/index.php?id=<?= $emp['id_utente'] ?>'">
                                    Gestisci
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
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

// ── Modale: gestione prenotazioni (admin) ─────────────
function openGestionePrenotazioni() {
    const modal     = document.getElementById('gestione-prenotazioni-modal');
    const container = modal.querySelector('.modal-container');
    modal.style.display = 'flex';
    fetch('modali/gestione-prenotazioni-modal.php')
        .then(r => r.text())
        .then(html => { container.innerHTML = html; })
        .catch(() => { container.innerHTML = '<div class="error-message">Errore nel caricamento del contenuto.</div>'; });
}

function reloadGestioneModal() {
    const modal     = document.getElementById('gestione-prenotazioni-modal');
    const container = modal.querySelector('.modal-container');
    fetch('modali/gestione-prenotazioni-modal.php')
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

// ── Funzioni modale admin ─────────────────────────────
function showEditForm(id) {
    document.getElementById('edit-form-' + id).style.display = 'table-row';
}

function hideEditForm(id) {
    document.getElementById('edit-form-' + id).style.display = 'none';
}

function updateBooking(id) {
    const dataInizio = document.getElementById('start-' + id).value;
    const dataFine   = document.getElementById('end-'   + id).value;
    if (!dataInizio || !dataFine) { alert('Per favore compila tutti i campi.'); return; }
    if (new Date(dataFine) <= new Date(dataInizio)) { alert('La data di fine deve essere successiva alla data di inizio.'); return; }
    fetch('modali/gestione-prenotazioni-modal.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=update&id_prenotazione=${id}&data_inizio=${encodeURIComponent(dataInizio)}&data_fine=${encodeURIComponent(dataFine)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) reloadGestioneModal();
        else alert('Errore: ' + (data.error || 'Errore sconosciuto.'));
    })
    .catch(() => alert('Errore durante la comunicazione con il server.'));
}

function deleteBooking(id) {
    if (!confirm('Sei sicuro di voler eliminare questa prenotazione?')) return;
    fetch('modali/elimina-prenotazioni.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=delete&id_prenotazione=${id}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) reloadGestioneModal();
        else alert('Errore: ' + (data.error || 'Errore sconosciuto.'));
    })
    .catch(() => alert('Errore durante la comunicazione con il server.'));
}
</script>

</body>
</html>