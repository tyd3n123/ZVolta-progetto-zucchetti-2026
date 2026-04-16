<?php
session_start();
require_once __DIR__ . "/../../../config/config.php";

// ── Auth check ────────────────────────────────────────
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../../login.php");
    exit();
}

// Verify caller is admin
$sql = "SELECT r.nome_ruolo FROM utenti u JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE u.id_utente = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $_SESSION['id_utente']);
$stmt->execute();
$callerRole = $stmt->get_result()->fetch_assoc()['nome_ruolo'] ?? '';
$stmt->close();

if (strtolower($callerRole) !== 'admin') {
    header("Location: ../../login.php");
    exit();
}

// ── Target user ID ────────────────────────────────────
$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($userId === 0) {
    header("Location: ../dashboard/index.php");
    exit();
}

// ── Handle POST actions ───────────────────────────────
$feedback = ['type' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'change_role') {
        $newRoleId = (int)($_POST['id_ruolo'] ?? 0);
        if ($newRoleId > 0) {
            $sql = "UPDATE utenti SET id_ruolo = ? WHERE id_utente = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $newRoleId, $userId);
            if ($stmt->execute()) {
                $feedback = ['type' => 'success', 'message' => 'Ruolo aggiornato con successo.'];
            } else {
                $feedback = ['type' => 'error', 'message' => "Errore durante l'aggiornamento del ruolo."];
            }
            $stmt->close();
        }
    }

    if ($action === 'delete_user') {
        $sql = "DELETE FROM prenotazioni WHERE id_utente = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();

        $sql = "DELETE FROM utenti WHERE id_utente = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $userId);
        if ($stmt->execute()) {
            header("Location: ../dashboard/index.php?deleted=1");
            exit();
        } else {
            $feedback = ['type' => 'error', 'message' => "Errore durante l'eliminazione dell'utente."];
        }
        $stmt->close();
    }
}

// ── Fetch user details ────────────────────────────────
$sql = "SELECT u.id_utente, u.nome, u.cognome, u.username, u.id_ruolo, r.nome_ruolo as ruolo
        FROM utenti u
        JOIN ruoli r ON u.id_ruolo = r.id_ruolo
        WHERE u.id_utente = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    header("Location: ../dashboard/index.php");
    exit();
}
$user = $result->fetch_assoc();
$stmt->close();

// ── Fetch all roles ───────────────────────────────────
$roles = [];
$result = $conn->query("SELECT id_ruolo, nome_ruolo FROM ruoli ORDER BY nome_ruolo");
if ($result) {
    while ($row = $result->fetch_assoc()) { $roles[] = $row; }
}

// ── Booking counts ────────────────────────────────────
$today = date('Y-m-d H:i:s');

$stmt = $conn->prepare("SELECT COUNT(*) as c FROM prenotazioni WHERE id_utente = ?");
$stmt->bind_param("i", $userId); $stmt->execute();
$bookingTotal = $stmt->get_result()->fetch_assoc()['c'] ?? 0;
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as c FROM prenotazioni WHERE id_utente = ? AND data_fine >= ?");
$stmt->bind_param("is", $userId, $today); $stmt->execute();
$bookingActive = $stmt->get_result()->fetch_assoc()['c'] ?? 0;
$stmt->close();

// ── Active bookings ───────────────────────────────────
$activeBookings = [];
$stmt = $conn->prepare(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine, a.codice_asset
     FROM prenotazioni p JOIN asset a ON p.id_asset = a.id_asset
     WHERE p.id_utente = ? AND p.data_fine >= ?
     ORDER BY p.data_inizio ASC"
);
$stmt->bind_param("is", $userId, $today); $stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) { $activeBookings[] = $row; }
$stmt->close();

// ── History bookings ──────────────────────────────────
$historyBookings = [];
$stmt = $conn->prepare(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine, a.codice_asset
     FROM prenotazioni p JOIN asset a ON p.id_asset = a.id_asset
     WHERE p.id_utente = ? AND p.data_fine < ?
     ORDER BY p.data_fine DESC"
);
$stmt->bind_param("is", $userId, $today); $stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) { $historyBookings[] = $row; }
$stmt->close();

$initials = strtoupper(substr($user['nome'], 0, 1) . substr($user['cognome'], 0, 1));

// Helper: duration string
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
    <title><?php echo htmlspecialchars($user['nome'] . ' ' . $user['cognome']); ?> | Northstar</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../dashboard/dashboard.css">
    <link rel="stylesheet" href="./utenti.css">
</head>
<body>

<header class="header">
    <div class="header-left">
        <h1>Northstar</h1>
        <nav class="header-breadcrumb">
            <a href="../dashboard/index.php">Dashboard</a>
            <span class="bc-sep">/</span>
            <span class="bc-current"><?php echo htmlspecialchars($user['nome'] . ' ' . $user['cognome']); ?></span>
        </nav>
    </div>
</header>

<div class="utenti-page">

    <?php if ($feedback['message']): ?>
    <div class="feedback-banner feedback-<?php echo $feedback['type']; ?>">
        <?php if ($feedback['type'] === 'success'): ?>✓<?php else: ?>✕<?php endif; ?>
        <?php echo htmlspecialchars($feedback['message']); ?>
    </div>
    <?php endif; ?>

    <!-- ═══ LEFT ASIDE ═══════════════════════════════ -->
    <aside class="utenti-aside">

        <div class="profile-card">
            <div class="profile-avatar"><?php echo $initials; ?></div>
            <h2 class="profile-name"><?php echo htmlspecialchars($user['nome'] . ' ' . $user['cognome']); ?></h2>
            <span class="profile-username">@<?php echo htmlspecialchars($user['username']); ?></span>
            <span class="role-pill role-pill--aside"><?php echo htmlspecialchars($user['ruolo']); ?></span>
        </div>

        <div class="stats-card">
            <div class="stat-item">
                <span class="stat-number"><?php echo $bookingTotal; ?></span>
                <span class="stat-label">Totale</span>
            </div>
            <div class="stat-divider"></div>
            <div class="stat-item">
                <span class="stat-number stat-active"><?php echo $bookingActive; ?></span>
                <span class="stat-label">Attive</span>
            </div>
            <div class="stat-divider"></div>
            <div class="stat-item">
                <span class="stat-number"><?php echo max(0, $bookingTotal - $bookingActive); ?></span>
                <span class="stat-label">Storico</span>
            </div>
        </div>

        <div class="action-card">
            <h3 class="action-card-title">Cambia ruolo</h3>
            <form method="POST">
                <input type="hidden" name="action" value="change_role">
                <select name="id_ruolo" class="role-select">
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo $role['id_ruolo']; ?>"
                            <?php echo $role['id_ruolo'] == $user['id_ruolo'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($role['nome_ruolo']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-primary btn-full">Salva ruolo</button>
            </form>
        </div>

        <div class="action-card danger-card">
            <h3 class="action-card-title danger-title">Zona pericolosa</h3>
            <p class="danger-desc">L'eliminazione è permanente e rimuove anche tutte le prenotazioni associate.</p>
            <button class="btn-danger btn-full" onclick="openDeleteModal()">Elimina utente</button>
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
                    <span class="info-val"><?php echo htmlspecialchars($user['nome']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-key">Cognome</span>
                    <span class="info-val"><?php echo htmlspecialchars($user['cognome']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-key">Username</span>
                    <span class="info-val mono">@<?php echo htmlspecialchars($user['username']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-key">Ruolo</span>
                    <span class="info-val">
                        <span class="role-pill"><?php echo htmlspecialchars($user['ruolo']); ?></span>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-key">ID utente</span>
                    <span class="info-val mono">#<?php echo $user['id_utente']; ?></span>
                </div>
            </div>
        </div>

        <!-- Active bookings -->
        <div class="panel">
            <div class="panel-header">
                <h3 class="panel-title">Prenotazioni attive</h3>
                <span class="panel-badge"><?php echo count($activeBookings); ?></span>
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
                            $start  = new DateTime($b['data_inizio']);
                            $end    = new DateTime($b['data_fine']);
                            $isNow  = $now >= $start && $now <= $end;
                        ?>
                            <tr>
                                <td><span class="asset-code"><?php echo htmlspecialchars($b['codice_asset']); ?></span></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($b['data_inizio'])); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($b['data_fine'])); ?></td>
                                <td class="mono"><?php echo durStr($b['data_inizio'], $b['data_fine']); ?></td>
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
                <span class="panel-badge panel-badge--muted"><?php echo count($historyBookings); ?></span>
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
                                <td><span class="asset-code asset-code--muted"><?php echo htmlspecialchars($b['codice_asset']); ?></span></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($b['data_inizio'])); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($b['data_fine'])); ?></td>
                                <td class="mono"><?php echo durStr($b['data_inizio'], $b['data_fine']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- Delete modal -->
<div id="delete-modal" class="modal-overlay" style="display:none;">
    <div class="modal-container delete-confirm">
        <div class="delete-icon">⚠️</div>
        <h3>Eliminare questo utente?</h3>
        <p>Stai per eliminare permanentemente <strong><?php echo htmlspecialchars($user['nome'] . ' ' . $user['cognome']); ?></strong> e tutte le sue prenotazioni. L'operazione non è reversibile.</p>
        <div class="delete-actions">
            <button class="btn-ghost" onclick="closeDeleteModal()">Annulla</button>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="delete_user">
                <button type="submit" class="btn-danger">Sì, elimina definitivamente</button>
            </form>
        </div>
    </div>
</div>

<script>
    function openDeleteModal()  { document.getElementById('delete-modal').style.display = 'flex'; }
    function closeDeleteModal() { document.getElementById('delete-modal').style.display = 'none'; }
    document.getElementById('delete-modal').addEventListener('click', e => { if (e.target === e.currentTarget) closeDeleteModal(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDeleteModal(); });
</script>
</body>
</html>