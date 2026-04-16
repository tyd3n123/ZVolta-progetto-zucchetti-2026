<?php
session_start();
require_once __DIR__ . "/../../../config/config.php";

// ── Auth + Coordinatore check ──────────────────────────
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['id_utente'])) {
    header("Location: ../../login.php");
    exit();
}

$id_coordinatore = (int)$_SESSION['id_utente'];

$stmt = $conn->prepare("SELECT r.nome_ruolo FROM utenti u JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE u.id_utente = ?");
$stmt->bind_param("i", $id_coordinatore); $stmt->execute();
$callerRole = $stmt->get_result()->fetch_assoc()['nome_ruolo'] ?? '';
$stmt->close();

if (strtolower($callerRole) !== 'coordinatore') {
    header("Location: ../../login.php");
    exit();
}

// ── Target user ID ─────────────────────────────────────
$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($userId === 0) {
    header("Location: ../dashboard/index.php");
    exit();
}

// ── Ownership check: must be one of the coordinator's employees ──
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM utenti WHERE id_utente = ? AND id_coordinatore = ?");
$stmt->bind_param("ii", $userId, $id_coordinatore); $stmt->execute();
$owns = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

if ($owns === 0) {
    header("Location: ../dashboard/index.php");
    exit();
}


// ── Fetch user details ─────────────────────────────────
$stmt = $conn->prepare(
    "SELECT u.id_utente, u.nome, u.cognome, u.username, u.id_ruolo, r.nome_ruolo AS ruolo
     FROM utenti u
     JOIN ruoli r ON u.id_ruolo = r.id_ruolo
     WHERE u.id_utente = ? AND u.id_coordinatore = ?
     LIMIT 1"
);
$stmt->bind_param("ii", $userId, $id_coordinatore); $stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    header("Location: ../dashboard/index.php");
    exit();
}
$user = $result->fetch_assoc();
$stmt->close();

// ── Booking counts ─────────────────────────────────────
$today = date('Y-m-d H:i:s');

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM prenotazioni WHERE id_utente = ?");
$stmt->bind_param("i", $userId); $stmt->execute();
$bookingTotal = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM prenotazioni WHERE id_utente = ? AND data_fine >= ?");
$stmt->bind_param("is", $userId, $today); $stmt->execute();
$bookingActive = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

// ── Active bookings ────────────────────────────────────
$activeBookings = [];
$stmt = $conn->prepare(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine, a.codice_asset
     FROM prenotazioni p JOIN asset a ON p.id_asset = a.id_asset
     WHERE p.id_utente = ? AND p.data_fine >= ?
     ORDER BY p.data_inizio ASC"
);
$stmt->bind_param("is", $userId, $today); $stmt->execute();
$r = $stmt->get_result(); while ($row = $r->fetch_assoc()) $activeBookings[] = $row; $stmt->close();

// ── History bookings ───────────────────────────────────
$historyBookings = [];
$stmt = $conn->prepare(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine, a.codice_asset
     FROM prenotazioni p JOIN asset a ON p.id_asset = a.id_asset
     WHERE p.id_utente = ? AND p.data_fine < ?
     ORDER BY p.data_fine DESC"
);
$stmt->bind_param("is", $userId, $today); $stmt->execute();
$r = $stmt->get_result(); while ($row = $r->fetch_assoc()) $historyBookings[] = $row; $stmt->close();

$initials = strtoupper(substr($user['nome'], 0, 1) . substr($user['cognome'], 0, 1));

function durStr(string $start, string $end): string {
    $diff = (new DateTime($start))->diff(new DateTime($end));
    $h = ($diff->days * 24) + $diff->h;
    return $h > 0 ? "{$h}h {$diff->i}m" : "{$diff->i}m";
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($user['nome'] . ' ' . $user['cognome']) ?> | Northstar</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../../admin/dashboard/dashboard.css">
    <link rel="stylesheet" href="../../admin/utenti/utenti.css">
</head>
<body>

<header class="header">
    <div class="header-left">
        <h1>Northstar</h1>
        <nav class="header-breadcrumb">
            <a href="../dashboard/index.php">Dashboard</a>
            <span class="bc-sep">/</span>
            <span class="bc-current"><?= htmlspecialchars($user['nome'] . ' ' . $user['cognome']) ?></span>
        </nav>
    </div>
</header>

<div class="utenti-page">

    <!-- ═══ LEFT ASIDE ═══════════════════════════════ -->
    <aside class="utenti-aside">

        <div class="profile-card">
            <div class="profile-avatar"><?= $initials ?></div>
            <h2 class="profile-name"><?= htmlspecialchars($user['nome'] . ' ' . $user['cognome']) ?></h2>
            <span class="profile-username">@<?= htmlspecialchars($user['username']) ?></span>
            <span class="role-pill role-pill--aside"><?= htmlspecialchars($user['ruolo']) ?></span>
        </div>

        <div class="stats-card">
            <div class="stat-item">
                <span class="stat-number"><?= $bookingTotal ?></span>
                <span class="stat-label">Totale</span>
            </div>
            <div class="stat-divider"></div>
            <div class="stat-item">
                <span class="stat-number stat-active"><?= $bookingActive ?></span>
                <span class="stat-label">Attive</span>
            </div>
            <div class="stat-divider"></div>
            <div class="stat-item">
                <span class="stat-number"><?= max(0, $bookingTotal - $bookingActive) ?></span>
                <span class="stat-label">Storico</span>
            </div>
        </div>

    </aside>

    <!-- ═══ MAIN CONTENT ═════════════════════════════ -->
    <main class="utenti-main">

        <!-- Account info -->
        <div class="panel">
            <h3 class="panel-title">Informazioni account</h3>
            <div class="info-rows">
                <div class="info-row">
                    <span class="info-key">Nome</span>
                    <span class="info-val"><?= htmlspecialchars($user['nome']) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-key">Cognome</span>
                    <span class="info-val"><?= htmlspecialchars($user['cognome']) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-key">Username</span>
                    <span class="info-val mono">@<?= htmlspecialchars($user['username']) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-key">Ruolo</span>
                    <span class="info-val">
                        <span class="role-pill"><?= htmlspecialchars($user['ruolo']) ?></span>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-key">ID utente</span>
                    <span class="info-val mono">#<?= $user['id_utente'] ?></span>
                </div>
            </div>
        </div>

        <!-- Active bookings -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title">Prenotazioni attive</h3>
                <span class="panel-badge"><?= count($activeBookings) ?></span>
            </div>
            <?php if (empty($activeBookings)): ?>
                <div class="empty-state">
                    <span class="empty-icon">📭</span>
                    <p>Nessuna prenotazione attiva al momento.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Asset</th>
                                <th>Inizio</th>
                                <th>Fine</th>
                                <th>Durata</th>
                                <th>Stato</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $now = new DateTime();
                        foreach ($activeBookings as $b):
                            $start = new DateTime($b['data_inizio']);
                            $end   = new DateTime($b['data_fine']);
                            $isNow = $now >= $start && $now <= $end;
                        ?>
                            <tr>
                                <td><span class="asset-code"><?= htmlspecialchars($b['codice_asset']) ?></span></td>
                                <td><?= date('d/m/Y H:i', strtotime($b['data_inizio'])) ?></td>
                                <td><?= date('d/m/Y H:i', strtotime($b['data_fine'])) ?></td>
                                <td class="mono"><?= durStr($b['data_inizio'], $b['data_fine']) ?></td>
                                <td>
                                    <?php if ($isNow): ?>
                                        <span class="status-pill status-incorso">In corso</span>
                                    <?php else: ?>
                                        <span class="status-pill status-futura">Futura</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- History -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title">Cronologia prenotazioni</h3>
                <span class="panel-badge panel-badge--muted"><?= count($historyBookings) ?></span>
            </div>
            <?php if (empty($historyBookings)): ?>
                <div class="empty-state">
                    <span class="empty-icon">📋</span>
                    <p>Nessuna prenotazione passata.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table data-table--muted">
                        <thead>
                            <tr>
                                <th>Asset</th>
                                <th>Inizio</th>
                                <th>Fine</th>
                                <th>Durata</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($historyBookings as $b): ?>
                            <tr>
                                <td><span class="asset-code asset-code--muted"><?= htmlspecialchars($b['codice_asset']) ?></span></td>
                                <td><?= date('d/m/Y H:i', strtotime($b['data_inizio'])) ?></td>
                                <td><?= date('d/m/Y H:i', strtotime($b['data_fine'])) ?></td>
                                <td class="mono"><?= durStr($b['data_inizio'], $b['data_fine']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

</body>
</html>
