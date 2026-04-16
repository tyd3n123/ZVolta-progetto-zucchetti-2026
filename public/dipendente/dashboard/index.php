<?php
session_start();
require_once __DIR__ . "/../../../config/config.php";

// ── Signout ────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'signout') {
    session_unset(); session_destroy();
    header("Location: ../../login.php"); exit();
}

// ── Auth + Dipendente check ────────────────────────────
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['id_utente'])) {
    header("Location: ../../login.php"); exit();
}
$id_utente = (int)$_SESSION['id_utente'];

$stmt = $conn->prepare("SELECT r.nome_ruolo FROM utenti u JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE u.id_utente = ?");
$stmt->bind_param("i", $id_utente); $stmt->execute();
$role = $stmt->get_result()->fetch_assoc()['nome_ruolo'] ?? '';
$stmt->close();
if (strtolower($role) !== 'dipendente') { header("Location: ../../login.php"); exit(); }

// ── Info utente ────────────────────────────────────────
$stmt = $conn->prepare("SELECT u.nome, u.cognome, r.nome_ruolo AS ruolo FROM utenti u LEFT JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE u.id_utente = ? LIMIT 1");
$stmt->bind_param("i", $id_utente); $stmt->execute();
$userInfo = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── Prenotazione di oggi ───────────────────────────────
$todayBooking = null;
$stmt = $conn->prepare(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine,
            a.codice_asset,
            COALESCE(t.descrizione, 'Parcheggio') AS nome_tipologia
     FROM prenotazioni p
     JOIN asset a ON p.id_asset = a.id_asset
     LEFT JOIN tipologie_asset t ON a.id_tipologia = t.id_tipologia
     WHERE p.id_utente = ? AND DATE(p.data_inizio) = CURDATE()
     LIMIT 1"
);
$stmt->bind_param("i", $id_utente); $stmt->execute();
$todayBooking = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

// ── Prossime prenotazioni (future, non oggi) ───────────
$upcoming = [];
$stmt = $conn->prepare(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine,
            a.codice_asset,
            COALESCE(t.descrizione, 'Parcheggio') AS nome_tipologia
     FROM prenotazioni p
     JOIN asset a ON p.id_asset = a.id_asset
     LEFT JOIN tipologie_asset t ON a.id_tipologia = t.id_tipologia
     WHERE p.id_utente = ? AND DATE(p.data_inizio) > CURDATE()
     ORDER BY p.data_inizio ASC
     LIMIT 5"
);
$stmt->bind_param("i", $id_utente); $stmt->execute();
$r = $stmt->get_result(); while ($row = $r->fetch_assoc()) $upcoming[] = $row; $stmt->close();

// ── Stats ──────────────────────────────────────────────
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM prenotazioni WHERE id_utente = ?");
$stmt->bind_param("i", $id_utente); $stmt->execute();
$statTotal = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM prenotazioni WHERE id_utente = ? AND data_fine >= NOW()");
$stmt->bind_param("i", $id_utente); $stmt->execute();
$statActive = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM prenotazioni WHERE id_utente = ? AND MONTH(data_inizio) = MONTH(CURDATE()) AND YEAR(data_inizio) = YEAR(CURDATE())");
$stmt->bind_param("i", $id_utente); $stmt->execute();
$statMonth = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// ── Helper ─────────────────────────────────────────────
$giorni = ['Sunday'=>'Domenica','Monday'=>'Lunedì','Tuesday'=>'Martedì','Wednesday'=>'Mercoledì','Thursday'=>'Giovedì','Friday'=>'Venerdì','Saturday'=>'Sabato'];
$mesi   = ['January'=>'gennaio','February'=>'febbraio','March'=>'marzo','April'=>'aprile','May'=>'maggio','June'=>'giugno','July'=>'luglio','August'=>'agosto','September'=>'settembre','October'=>'ottobre','November'=>'novembre','December'=>'dicembre'];
$oggi   = new DateTime();
$dataOggi = $giorni[$oggi->format('l')] . ', ' . $oggi->format('j') . ' ' . $mesi[$oggi->format('F')] . ' ' . $oggi->format('Y');

function durStr(string $a, string $b): string {
    $d = (new DateTime($a))->diff(new DateTime($b));
    $h = $d->days * 24 + $d->h;
    return $h > 0 ? "{$h}h {$d->i}m" : "{$d->i}m";
}
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

<div class="dp-page">

    <!-- ── Errore accesso ────────────────────────────────── -->
    <?php if (($_GET['errore'] ?? '') === 'gia_prenotato'): ?>
    <div class="dp-alert dp-alert--warning">
        <span>⚠️</span>
        <span>Hai già una prenotazione per oggi. Puoi effettuarne una nuova solo da domani.</span>
    </div>
    <?php endif; ?>

    <!-- ── Welcome ──────────────────────────────────────── -->
    <div class="dp-welcome">
        <div class="dp-welcome-left">
            <h2>Ciao, <?= htmlspecialchars($userInfo['nome']) ?> 👋</h2>
            <p class="dp-date"><?= $dataOggi ?></p>
        </div>
        <span class="dp-role-badge"><?= htmlspecialchars($userInfo['ruolo']) ?></span>
    </div>

    <!-- ── Prenotazione di oggi ──────────────────────────── -->
    <?php if ($todayBooking):
        $dtS  = new DateTime($todayBooking['data_inizio']);
        $dtE  = new DateTime($todayBooking['data_fine']);
        $now  = new DateTime();
        $isNow = $now >= $dtS && $now <= $dtE;
    ?>
    <div class="dp-today dp-today--booked">
        <div class="dp-today-left">
            <div class="dp-today-label">
                <span class="dp-today-dot dp-today-dot--<?= $isNow ? 'active' : 'future' ?>"></span>
                <?= $isNow ? 'In corso ora' : 'Prenotazione di oggi' ?>
            </div>
            <div class="dp-today-asset"><?= htmlspecialchars($todayBooking['codice_asset']) ?></div>
            <div class="dp-today-type"><?= htmlspecialchars($todayBooking['nome_tipologia']) ?></div>
        </div>
        <div class="dp-today-center">
            <div class="dp-today-time-block">
                <span class="dp-today-time"><?= $dtS->format('H:i') ?></span>
                <span class="dp-today-arrow">→</span>
                <span class="dp-today-time"><?= $dtE->format('H:i') ?></span>
            </div>
            <div class="dp-today-dur"><?= durStr($todayBooking['data_inizio'], $todayBooking['data_fine']) ?></div>
        </div>
        <div class="dp-today-right">
            <span class="dp-today-status dp-today-status--<?= $isNow ? 'active' : 'future' ?>">
                <?= $isNow ? 'In corso' : 'Programmata' ?>
            </span>
            <button class="dp-cancel-today" onclick="cancelBookingToday(<?= $todayBooking['id_prenotazione'] ?>)">
                Annulla
            </button>
        </div>
    </div>
    <?php else: ?>
    <div class="dp-today dp-today--empty">
        <div class="dp-today-empty-icon">📅</div>
        <div class="dp-today-empty-text">
            <strong>Nessuna prenotazione per oggi</strong>
            <span>Hai ancora tempo per prenotare un posto</span>
        </div>
        <a href="./asset-sede.php" class="dp-book-cta">Prenota ora →</a>
    </div>
    <?php endif; ?>

    <!-- ── Stats ─────────────────────────────────────────── -->
    <div class="dp-stats">
        <div class="dp-stat-card">
            <div class="dp-stat-icon">🔖</div>
            <div>
                <div class="dp-stat-label">Prenotazioni attive</div>
                <div class="dp-stat-num"><?= $statActive ?></div>
            </div>
        </div>
        <div class="dp-stat-card">
            <div class="dp-stat-icon">📆</div>
            <div>
                <div class="dp-stat-label">Questo mese</div>
                <div class="dp-stat-num"><?= $statMonth ?></div>
            </div>
        </div>
        <div class="dp-stat-card">
            <div class="dp-stat-icon">📊</div>
            <div>
                <div class="dp-stat-label">Totale prenotazioni</div>
                <div class="dp-stat-num"><?= $statTotal ?></div>
            </div>
        </div>
        <div class="dp-stat-card dp-stat-card--action" onclick="location.href='./asset-sede.php'">
            <div class="dp-stat-icon">➕</div>
            <div>
                <div class="dp-stat-label">Nuova prenotazione</div>
                <div class="dp-stat-num dp-stat-num--small">Prenota</div>
            </div>
        </div>
    </div>

    <!-- ── Prossime prenotazioni ────────────────────────── -->
    <div class="dp-grid">

        <div class="dp-panel">
            <div class="dp-panel-head">
                <span class="dp-panel-title">Prossime prenotazioni</span>
                <?php if (!empty($upcoming)): ?>
                <span class="dp-panel-badge"><?= count($upcoming) ?></span>
                <?php endif; ?>
            </div>
            <div class="dp-panel-body">
                <?php if (empty($upcoming)): ?>
                <div class="dp-empty">
                    <span class="dp-empty-icon">🗓️</span>
                    <?= $todayBooking ? 'Nessun\'altra prenotazione in programma' : 'Nessuna prenotazione futura' ?>
                </div>
                <?php else: ?>
                <?php foreach ($upcoming as $b):
                    $dtS = new DateTime($b['data_inizio']);
                    $dtE = new DateTime($b['data_fine']);
                ?>
                <div class="dp-booking-row">
                    <div class="dp-booking-dot dp-booking-dot--future"></div>
                    <div class="dp-booking-info">
                        <div class="dp-booking-asset"><?= htmlspecialchars($b['codice_asset']) ?></div>
                        <div class="dp-booking-meta">
                            <span class="dp-type-pill"><?= htmlspecialchars($b['nome_tipologia']) ?></span>
                            <span class="dp-booking-dates"><?= $dtS->format('d/m/Y') ?> · <?= $dtS->format('H:i') ?>–<?= $dtE->format('H:i') ?></span>
                        </div>
                    </div>
                    <div class="dp-booking-dur"><?= durStr($b['data_inizio'], $b['data_fine']) ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="dp-panel-foot">
                <button class="dp-show-all-btn" onclick="openAllBookingsModal()">Vedi tutte le prenotazioni →</button>
            </div>
        </div>

    </div><!-- /.dp-grid -->

</div><!-- /.dp-page -->

<!-- ── Modal: tutte le prenotazioni ───────────────────── -->
<div id="all-bookings-modal" class="modal-overlay" style="display:none;">
    <div class="modal-container"></div>
</div>

<script>
function openAllBookingsModal() {
    const modal     = document.getElementById('all-bookings-modal');
    const container = modal.querySelector('.modal-container');
    modal.style.display = 'flex';
    fetch('modali/user-bookings-modal.php')
        .then(r => r.text())
        .then(html => { container.innerHTML = html; })
        .catch(() => { container.innerHTML = '<div class="dp-empty">Errore nel caricamento.</div>'; });
}

function closeAllBookingsModal() {
    document.getElementById('all-bookings-modal').style.display = 'none';
}

function reloadAllBookingsModal() {
    const container = document.querySelector('#all-bookings-modal .modal-container');
    fetch('modali/user-bookings-modal.php')
        .then(r => r.text())
        .then(html => { container.innerHTML = html; })
        .catch(() => {});
}

function cancelBookingToday(id) {
    if (!confirm('Sei sicuro di voler annullare la prenotazione di oggi?')) return;
    fetch('modali/user-bookings-modal.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=cancel&id_prenotazione=${id}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) location.reload();
        else alert('Errore: ' + (data.error || 'Errore sconosciuto.'));
    })
    .catch(() => alert('Errore durante la comunicazione con il server.'));
}

function cancelBooking(id) {
    if (!confirm('Sei sicuro di voler annullare questa prenotazione?')) return;
    fetch('modali/user-bookings-modal.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=cancel&id_prenotazione=${id}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) reloadAllBookingsModal();
        else alert('Errore: ' + (data.error || 'Errore sconosciuto.'));
    })
    .catch(() => alert('Errore durante la comunicazione con il server.'));
}

function showUserEditForm(id) {
    const panel = document.getElementById('user-edit-form-' + id);
    if (!panel) return;
    panel.style.display = 'block';
    // Nascondi l'errore se era visibile
    const err = document.getElementById('user-edit-error-' + id);
    if (err) { err.style.display = 'none'; err.textContent = ''; }
}

function hideUserEditForm(id) {
    const panel = document.getElementById('user-edit-form-' + id);
    if (panel) panel.style.display = 'none';
    const err = document.getElementById('user-edit-error-' + id);
    if (err) { err.style.display = 'none'; err.textContent = ''; }
}

function updateUserBooking(id) {
    const inizio = document.getElementById('edit-inizio-' + id)?.value ?? '';
    const fine   = document.getElementById('edit-fine-'   + id)?.value ?? '';
    const errEl  = document.getElementById('user-edit-error-' + id);

    const showErr = msg => {
        if (errEl) { errEl.textContent = msg; errEl.style.display = 'block'; }
    };

    if (!inizio || !fine) return showErr('Compila entrambi i campi data.');

    const dtStart = new Date(inizio);
    const dtEnd   = new Date(fine);
    const now     = new Date();
    const startM  = dtStart.getHours() * 60 + dtStart.getMinutes();
    const endM    = dtEnd.getHours()   * 60 + dtEnd.getMinutes();
    const sameDay = dtStart.toDateString() === dtEnd.toDateString();

    if (dtStart < now)       return showErr('Non puoi impostare una data di inizio nel passato.');
    if (dtEnd <= dtStart)    return showErr('La data di fine deve essere successiva alla data di inizio.');
    if (!sameDay)            return showErr('La prenotazione deve iniziare e finire nella stessa giornata (09:00–19:00).');
    if (startM < 9  * 60)   return showErr("L'orario di inizio non può essere prima delle 09:00.");
    if (startM >= 19 * 60)  return showErr("L'orario di inizio non può essere dalle 19:00 in poi.");
    if (endM   > 19 * 60)   return showErr("L'orario di fine non può superare le 19:00.");

    if (errEl) { errEl.style.display = 'none'; errEl.textContent = ''; }

    fetch('modali/user-bookings-modal.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    `action=update&id_prenotazione=${id}&data_inizio=${encodeURIComponent(inizio.replace('T', ' '))}&data_fine=${encodeURIComponent(fine.replace('T', ' '))}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) reloadAllBookingsModal();
        else showErr(data.error || 'Errore sconosciuto.');
    })
    .catch(() => showErr('Errore durante la comunicazione con il server.'));
}

document.addEventListener('click',   e => { if (e.target.classList.contains('modal-overlay')) closeAllBookingsModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAllBookingsModal(); });
</script>

</body>
</html>
