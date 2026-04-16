<?php
session_start();
require_once __DIR__ . "/../../../config/config.php";

// ── Signout ───────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'signout') {
    session_unset();
    session_destroy();
    header("Location: ../../login.php");
    exit();
}

// ── Auth + Coordinatore check ─────────────────────────
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['id_utente'])) {
    header("Location: ../../login.php");
    exit();
}

$id_utente = (int)$_SESSION['id_utente'];

$stmt = $conn->prepare("SELECT r.nome_ruolo FROM utenti u JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE u.id_utente = ?");
$stmt->bind_param("i", $id_utente); $stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || strtolower($user['nome_ruolo']) !== 'coordinatore') {
    header("Location: ../../login.php"); exit();
}

// ── Info utente ───────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT u.nome, u.cognome, r.nome_ruolo AS ruolo
     FROM utenti u LEFT JOIN ruoli r ON u.id_ruolo = r.id_ruolo
     WHERE u.id_utente = ? LIMIT 1"
);
$stmt->bind_param("i", $id_utente); $stmt->execute();
$userInfo = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── Analytics ─────────────────────────────────────────
$analytics = ['mie_prenotazioni' => 0, 'team_prenotazioni' => 0, 'dipendenti' => 0];

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM prenotazioni WHERE id_utente = ? AND data_fine >= NOW()");
$stmt->bind_param("i", $id_utente); $stmt->execute();
$analytics['mie_prenotazioni'] = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS c FROM prenotazioni p
     JOIN utenti u ON p.id_utente = u.id_utente
     WHERE u.id_coordinatore = ? AND p.data_fine >= NOW()"
);
$stmt->bind_param("i", $id_utente); $stmt->execute();
$analytics['team_prenotazioni'] = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM utenti WHERE id_coordinatore = ?");
$stmt->bind_param("i", $id_utente); $stmt->execute();
$analytics['dipendenti'] = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// ── Anteprima: le mie prenotazioni (ultime 3) ─────────
$userBookings = [];
$stmt = $conn->prepare(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine, a.codice_asset
     FROM prenotazioni p JOIN asset a ON p.id_asset = a.id_asset
     WHERE p.id_utente = ?
     ORDER BY p.data_inizio DESC
     LIMIT 3"
);
$stmt->bind_param("i", $id_utente); $stmt->execute();
$r = $stmt->get_result(); while ($row = $r->fetch_assoc()) $userBookings[] = $row; $stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM prenotazioni WHERE id_utente = ?");
$stmt->bind_param("i", $id_utente); $stmt->execute();
$totalUserBookings = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// ── Anteprima: prenotazioni team (ultime 4) ───────────
$teamBookings = [];
$stmt = $conn->prepare(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine, a.codice_asset, u.nome, u.cognome
     FROM prenotazioni p
     JOIN asset   a ON p.id_asset  = a.id_asset
     JOIN utenti  u ON p.id_utente = u.id_utente
     WHERE u.id_coordinatore = ?
     ORDER BY p.data_inizio DESC
     LIMIT 4"
);
$stmt->bind_param("i", $id_utente); $stmt->execute();
$r = $stmt->get_result(); while ($row = $r->fetch_assoc()) $teamBookings[] = $row; $stmt->close();

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS c FROM prenotazioni p
     JOIN utenti u ON p.id_utente = u.id_utente
     WHERE u.id_coordinatore = ?"
);
$stmt->bind_param("i", $id_utente); $stmt->execute();
$totalTeamBookings = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// ── I miei dipendenti ─────────────────────────────────
$employees = [];
$stmt = $conn->prepare(
    "SELECT u.id_utente, u.nome, u.cognome,
            (SELECT COUNT(*) FROM prenotazioni p WHERE p.id_utente = u.id_utente AND p.data_fine >= NOW()) AS prenotazioni_attive
     FROM utenti u
     WHERE u.id_coordinatore = ?
     ORDER BY u.cognome, u.nome"
);
$stmt->bind_param("i", $id_utente); $stmt->execute();
$r = $stmt->get_result(); while ($row = $r->fetch_assoc()) $employees[] = $row; $stmt->close();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Northstar</title>
    <link rel="stylesheet" href="../../admin/dashboard/dashboard.css">
    <link rel="stylesheet" href="./dashboard.css">
</head>
<body>

<!-- ── Header ─────────────────────────────────────────── -->
<header class="header">
    <div class="header-left"><h1>Northstar</h1></div>
    <a href="?action=signout" class="signout-btn">Esci</a>
</header>

<div class="co-page">

    <!-- ── Welcome ───────────────────────────────────────── -->
    <div class="co-welcome">
        <div class="co-welcome-left">
            <h2>Benvenuto, <?= htmlspecialchars($userInfo['nome']) ?></h2>
            <p class="co-welcome-sub">Gestisci le tue prenotazioni e monitora il tuo team</p>
        </div>
        <span class="co-role-badge"><?= htmlspecialchars($userInfo['ruolo']) ?></span>
    </div>

    <!-- ── Stats ─────────────────────────────────────────── -->
    <div class="co-stats">
        <div class="co-stat-card">
            <div class="co-stat-icon">📅</div>
            <div>
                <div class="co-stat-label">Mie prenotazioni attive</div>
                <div class="co-stat-num"><?= $analytics['mie_prenotazioni'] ?></div>
            </div>
        </div>
        <div class="co-stat-card">
            <div class="co-stat-icon">👥</div>
            <div>
                <div class="co-stat-label">Prenotazioni del team</div>
                <div class="co-stat-num"><?= $analytics['team_prenotazioni'] ?></div>
            </div>
        </div>
        <div class="co-stat-card">
            <div class="co-stat-icon">👷</div>
            <div>
                <div class="co-stat-label">Dipendenti gestiti</div>
                <div class="co-stat-num"><?= $analytics['dipendenti'] ?></div>
            </div>
        </div>
    </div>

    <!-- ── Prenota banner ─────────────────────────────────── -->
    <a href="./asset-disponibili.php" class="co-avail-banner">
        <span class="co-avail-icon">➕</span>
        <div>
            <div class="co-avail-title">Nuova prenotazione</div>
            <div class="co-avail-sub">Prenota sale riunioni, scrivanie o parcheggi</div>
        </div>
        <span class="co-avail-arrow">→</span>
    </a>

    <!-- ── Tre pannelli ───────────────────────────────────── -->
    <div class="co-panels-grid">

        <!-- Le tue prenotazioni -->
        <div class="co-panel">
            <div class="co-panel-head">
                <span class="co-panel-title">Le tue prenotazioni</span>
                <span class="co-panel-badge"><?= $totalUserBookings ?></span>
            </div>
            <div class="co-panel-body">
                <?php if (empty($userBookings)): ?>
                    <div class="co-empty"><span class="co-empty-icon">📅</span>Nessuna prenotazione attiva</div>
                <?php else: ?>
                    <div class="co-booking-list">
                        <?php foreach ($userBookings as $b):
                            $dtS   = new DateTime($b['data_inizio']);
                            $dtE   = new DateTime($b['data_fine']);
                            $now   = new DateTime();
                            $isNow = $now >= $dtS && $now <= $dtE;
                            $diff  = $dtS->diff($dtE);
                            $dur   = ($diff->h > 0 ? $diff->h . 'h ' : '') . $diff->i . 'm';
                        ?>
                        <div class="co-booking-item">
                            <div class="co-booking-meta">
                                <div class="co-booking-asset"><?= htmlspecialchars($b['codice_asset']) ?></div>
                                <div class="co-booking-dates"><?= $dtS->format('d/m/Y') ?> · <?= $dtS->format('H:i') ?>–<?= $dtE->format('H:i') ?></div>
                            </div>
                            <div class="co-booking-right">
                                <span class="co-status-pill co-status--<?= $isNow ? 'now' : 'future' ?>">
                                    <?= $isNow ? 'In corso' : 'Programmata' ?>
                                </span>
                                <span class="co-dur"><?= $dur ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($totalUserBookings > 3): ?>
                    <div class="co-more-note">+<?= $totalUserBookings - 3 ?> altra/e prenotazion<?= ($totalUserBookings - 3) !== 1 ? 'i' : 'e' ?> non mostrata/e</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="co-panel-foot">
                <button class="co-show-more-btn" onclick="openUserBookingsModal()">Vedi tutte le prenotazioni →</button>
            </div>
        </div>

        <!-- Prenotazioni del team -->
        <div class="co-panel">
            <div class="co-panel-head">
                <span class="co-panel-title">Prenotazioni del team</span>
                <span class="co-panel-badge"><?= $totalTeamBookings ?></span>
            </div>
            <div class="co-panel-body">
                <?php if (empty($teamBookings)): ?>
                    <div class="co-empty"><span class="co-empty-icon">👥</span>Nessuna prenotazione del team</div>
                <?php else: ?>
                    <div class="co-booking-list">
                        <?php foreach ($teamBookings as $b):
                            $dtS   = new DateTime($b['data_inizio']);
                            $dtE   = new DateTime($b['data_fine']);
                            $now   = new DateTime();
                            $isNow = $now >= $dtS && $now <= $dtE;
                            $diff  = $dtS->diff($dtE);
                            $dur   = ($diff->h > 0 ? $diff->h . 'h ' : '') . $diff->i . 'm';
                        ?>
                        <div class="co-booking-item">
                            <div class="co-booking-meta">
                                <div class="co-booking-asset"><?= htmlspecialchars($b['codice_asset']) ?></div>
                                <div class="co-booking-user"><?= htmlspecialchars($b['nome'] . ' ' . $b['cognome']) ?></div>
                                <div class="co-booking-dates"><?= $dtS->format('d/m/Y') ?> · <?= $dtS->format('H:i') ?>–<?= $dtE->format('H:i') ?></div>
                            </div>
                            <div class="co-booking-right">
                                <span class="co-status-pill co-status--<?= $isNow ? 'now' : 'future' ?>">
                                    <?= $isNow ? 'In corso' : 'Programmata' ?>
                                </span>
                                <span class="co-dur"><?= $dur ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($totalTeamBookings > 4): ?>
                    <div class="co-more-note">+<?= $totalTeamBookings - 4 ?> altra/e prenotazion<?= ($totalTeamBookings - 4) !== 1 ? 'i' : 'e' ?> non mostrata/e</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="co-panel-foot">
                <button class="co-show-more-btn" onclick="openTeamBookingsModal()">Vedi tutte le prenotazioni →</button>
            </div>
        </div>

        <!-- I miei dipendenti -->
        <div class="co-panel">
            <div class="co-panel-head">
                <span class="co-panel-title">I miei dipendenti</span>
                <?php if (!empty($employees)): ?>
                <span class="co-panel-badge"><?= count($employees) ?></span>
                <?php endif; ?>
            </div>
            <div class="co-panel-body">
                <?php if (empty($employees)): ?>
                    <div class="co-empty"><span class="co-empty-icon">👷</span>Nessun dipendente assegnato</div>
                <?php else: ?>
                    <div class="co-employee-list">
                        <?php foreach ($employees as $emp): ?>
                        <div class="co-employee-item">
                            <div class="co-employee-info">
                                <div class="co-employee-name"><?= htmlspecialchars($emp['nome'] . ' ' . $emp['cognome']) ?></div>
                                <div class="co-employee-sub">
                                    <?php if ($emp['prenotazioni_attive'] > 0): ?>
                                        <?= (int)$emp['prenotazioni_attive'] ?> prenotazion<?= $emp['prenotazioni_attive'] > 1 ? 'i' : 'e' ?> attiv<?= $emp['prenotazioni_attive'] > 1 ? 'e' : 'a' ?>
                                    <?php else: ?>
                                        Nessuna prenotazione attiva
                                    <?php endif; ?>
                                </div>
                            </div>
                            <button class="co-employee-btn"
                                    onclick="location.href='../utenti/index.php?id=<?= $emp['id_utente'] ?>'">
                                Gestisci
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /.co-panels-grid -->

</div><!-- /.co-page -->

<!-- ── Modals ─────────────────────────────────────────── -->
<div id="user-bookings-modal" class="modal-overlay" style="display:none;">
    <div class="modal-container"></div>
</div>

<div id="team-bookings-modal" class="modal-overlay" style="display:none;">
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
        .catch(() => { container.innerHTML = '<div class="error-message">Errore nel caricamento.</div>'; });
}

function closeUserBookingsModal() {
    document.getElementById('user-bookings-modal').style.display = 'none';
}

function reloadUserBookingsModal() {
    const container = document.querySelector('#user-bookings-modal .modal-container');
    fetch('modali/user-bookings-modal.php')
        .then(r => r.text())
        .then(html => { container.innerHTML = html; })
        .catch(() => { container.innerHTML = '<div class="error-message">Errore nel ricaricamento.</div>'; });
}

// ── Modale: prenotazioni team ─────────────────────────
function openTeamBookingsModal() {
    const modal     = document.getElementById('team-bookings-modal');
    const container = modal.querySelector('.modal-container');
    modal.style.display = 'flex';
    fetch('modali/team-bookings-modal.php')
        .then(r => r.text())
        .then(html => { container.innerHTML = html; })
        .catch(() => { container.innerHTML = '<div class="error-message">Errore nel caricamento.</div>'; });
}

function closeTeamBookingsModal() {
    document.getElementById('team-bookings-modal').style.display = 'none';
}

// ── Chiudi tutti i modali ─────────────────────────────
function closeModal() {
    document.querySelectorAll('.modal-overlay').forEach(m => m.style.display = 'none');
}

document.addEventListener('click',   e => { if (e.target.classList.contains('modal-overlay')) closeModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

// ── Annulla prenotazione (propria) ────────────────────
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

// ── Modifica prenotazione (propria) ───────────────────
function showUserEditForm(id) {
    document.getElementById('user-edit-form-' + id).style.display = 'block';
}

function hideUserEditForm(id) {
    const panel = document.getElementById('user-edit-form-' + id);
    const err   = document.getElementById('user-edit-error-' + id);
    panel.style.display = 'none';
    err.style.display   = 'none';
    err.textContent     = '';
}

function updateUserBooking(id) {
    const dataInizio = document.getElementById('user-edit-start-' + id).value;
    const dataFine   = document.getElementById('user-edit-end-'   + id).value;
    const errBox     = document.getElementById('user-edit-error-' + id);

    const showErr = msg => { errBox.textContent = msg; errBox.style.display = 'block'; };
    errBox.style.display = 'none';

    if (!dataInizio || !dataFine) { showErr('Compila entrambe le date.'); return; }

    const dtI = new Date(dataInizio);
    const dtF = new Date(dataFine);
    const now = new Date();

    if (dtI < now) { showErr('Non puoi impostare una data di inizio nel passato.'); return; }
    if (dtF <= dtI) { showErr('La data di fine deve essere successiva alla data di inizio.'); return; }

    const minI = dtI.getHours() * 60 + dtI.getMinutes();
    const minF = dtF.getHours() * 60 + dtF.getMinutes();
    const open  = 9  * 60;   // 09:00
    const close = 19 * 60;   // 19:00

    if (minI < open || minI >= close) { showErr('L\'orario di inizio deve essere compreso tra le 09:00 e le 19:00.'); return; }
    if (minF > close || minF <= open) { showErr('L\'orario di fine deve essere compreso tra le 09:00 e le 19:00.'); return; }

    fetch('modali/user-bookings-modal.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=update&id_prenotazione=${id}&data_inizio=${encodeURIComponent(dataInizio)}&data_fine=${encodeURIComponent(dataFine)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            reloadUserBookingsModal();
        } else {
            errBox.textContent = data.error || 'Errore sconosciuto.';
            errBox.style.display = 'block';
        }
    })
    .catch(() => {
        errBox.textContent = 'Errore durante la comunicazione con il server.';
        errBox.style.display = 'block';
    });
}
</script>

</body>
</html>
