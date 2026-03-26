<?php
session_start();
require_once __DIR__ . "/../../../config/config.php";

// ── Auth check ────────────────────────────────────────
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['id_utente'])) {
    header("Location: ../login.php");
    exit();
}

$id_utente = (int)$_SESSION['id_utente'];

// ── Info utente ───────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT u.nome, u.cognome, r.nome_ruolo AS ruolo
     FROM utenti u
     LEFT JOIN ruoli r ON u.id_ruolo = r.id_ruolo
     WHERE u.id_utente = ? LIMIT 1"
);
$stmt->bind_param("i", $id_utente);
$stmt->execute();
$userInfo = $stmt->get_result()->fetch_assoc() ?? ['nome' => '', 'cognome' => '', 'ruolo' => ''];
$stmt->close();

// ── Handle POST: nuova prenotazione ───────────────────
$feedback = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'book') {
    $id_asset    = (int)($_POST['id_asset']    ?? 0);
    $data_inizio = $_POST['data_inizio'] ?? '';
    $data_fine   = $_POST['data_fine']   ?? '';

    if (!$id_asset || !$data_inizio || !$data_fine) {
        $feedback = ['type' => 'error', 'msg' => 'Compila tutti i campi prima di procedere.'];
    } elseif (strtotime($data_fine) <= strtotime($data_inizio)) {
        $feedback = ['type' => 'error', 'msg' => 'La data di fine deve essere successiva alla data di inizio.'];
    } else {
        // Controlla sovrapposizioni
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS c FROM prenotazioni
             WHERE id_asset = ?
               AND data_inizio < ? AND data_fine > ?"
        );
        $stmt->bind_param("iss", $id_asset, $data_fine, $data_inizio);
        $stmt->execute();
        $overlap = $stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        if ($overlap > 0) {
            $feedback = ['type' => 'error', 'msg' => "La sala è già occupata nel periodo selezionato."];
        } else {
            try {
                $conn->begin_transaction();

                $stmt = $conn->prepare(
                    "INSERT INTO prenotazioni (id_utente, id_asset, data_inizio, data_fine)
                     VALUES (?, ?, ?, ?)"
                );
                $stmt->bind_param("iiss", $id_utente, $id_asset, $data_inizio, $data_fine);
                if (!$stmt->execute()) throw new Exception($stmt->error);
                $stmt->close();

                $stmt = $conn->prepare("UPDATE asset SET stato = 'Occupato' WHERE id_asset = ?");
                $stmt->bind_param("i", $id_asset);
                if (!$stmt->execute()) throw new Exception($stmt->error);
                $stmt->close();

                $conn->commit();
                $feedback = ['type' => 'success', 'msg' => 'Sala prenotata con successo!'];

            } catch (Exception $e) {
                $conn->rollback();
                $feedback = ['type' => 'error', 'msg' => 'Errore durante la prenotazione: ' . $e->getMessage()];
            }
        }
    }
}

// ── Fetch sale riunioni ──────────────────────────────
$roomSpots = [];
$sql = "SELECT a.id_asset, a.codice_asset, a.stato,
               COALESCE(s.capacita, '-')          AS capacita,
               COALESCE(s.attrezzatura, '-')       AS attrezzatura,
               COALESCE(s.orario_apertura, '')     AS orario_apertura,
               COALESCE(s.orario_chiusura, '')     AS orario_chiusura
        FROM asset a
        LEFT JOIN sala_dettagli s ON s.id_asset = a.id_asset
        WHERE a.mappa = 'Sede' AND a.codice_asset LIKE 'Sala%'
        ORDER BY a.codice_asset";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $disp = '';
        if ($row['orario_apertura'] && $row['orario_chiusura']) {
            $disp = $row['orario_apertura'] . ' – ' . $row['orario_chiusura'];
        }
        $roomSpots[$row['id_asset']] = [
            'name'         => $row['codice_asset'],
            'status'       => $row['stato'],
            'capacita'     => $row['capacita'],
            'attrezzatura'=> $row['attrezzatura'],
            'disponibilita'=> $disp ?: '–',
        ];
    }
}

// ── Fetch prenotazioni attive dell'utente ─────────────
$userBookings = [];
$stmt = $conn->prepare(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine,
            a.codice_asset, a.id_asset
     FROM prenotazioni p
     JOIN asset a ON p.id_asset = a.id_asset
     WHERE p.id_utente = ?
       AND a.mappa = 'Sede'
       AND a.codice_asset LIKE 'Sala%'
       AND p.data_fine >= NOW()
     ORDER BY p.data_inizio ASC"
);
$stmt->bind_param("i", $id_utente);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $userBookings[] = $row;
$stmt->close();

// ── Fetch slot occupati per tutte le sale ──────────
$occupiedSlotsByAsset = [];
$result = $conn->query(
    "SELECT p.id_asset, p.data_inizio, p.data_fine
     FROM prenotazioni p
     JOIN asset a ON p.id_asset = a.id_asset
     WHERE a.mappa = 'Sede'
       AND a.codice_asset LIKE 'Sala%'
       AND p.data_fine >= NOW()
     ORDER BY p.data_inizio ASC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $occupiedSlotsByAsset[$row['id_asset']][] = [
            'inizio' => $row['data_inizio'],
            'fine'   => $row['data_fine'],
        ];
    }
}

// ── Stats ─────────────────────────────────────────────
$totalRooms = count($roomSpots);
$availCount   = count(array_filter($roomSpots, fn($s) => strtolower($s['status']) !== 'occupato'));
$occCount     = $totalRooms - $availCount;

// ── Quale sala riaprire dopo il POST ───────────────
$reopenAssetId = (!empty($_POST['id_asset'])) ? (int)$_POST['id_asset'] : 0;
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sale Riunioni | Northstar</title>
    <link rel="stylesheet" href="../dashboard/dashboard.css">
    <link rel="stylesheet" href="./sale-riunioni.css">
</head>
<body>

<!-- ── Header ─────────────────────────────────────────── -->
<header class="header">
    <div class="header-left">
        <h1>Northstar</h1>
        <nav class="header-breadcrumb">
            <a href="../dashboard/index.php">Dashboard</a>
            <span class="bc-sep">/</span>
            <span class="bc-current">Sale Riunioni</span>
        </nav>
    </div>
    <div class="sr-user-pill">
        <?= htmlspecialchars($userInfo['nome'] . ' ' . $userInfo['cognome']) ?>
        <span class="sr-role"><?= htmlspecialchars($userInfo['ruolo']) ?></span>
    </div>
</header>

<!-- ── Page ───────────────────────────────────────────── -->
<div class="sr-page">

    <!-- Title row -->
    <div class="sr-title-row">
        <div>
            <h2 class="sr-page-title">🏢 Sale Riunioni</h2>
            <p class="sr-page-sub">Seleziona una sala dalla mappa per prenotarla</p>
        </div>
    </div>

    <!-- Feedback banner -->
    <?php if ($feedback): ?>
        <div class="sr-feedback sr-feedback--<?= $feedback['type'] ?>">
            <?= $feedback['type'] === 'success' ? '✅' : '⚠️' ?>
            <?= htmlspecialchars($feedback['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- ══ MAPPA + PANNELLO ════════════════════════════ -->
    <div class="sr-map-layout" id="map-layout">

        <!-- ── Zona mappa (sempre visibile) ────────────── -->
        <div class="sr-map-zone" id="map-zone">

            <div class="sr-map-header">
                <p class="sr-map-title">Mappa Sale Riunioni — Sede</p>
                <div class="sr-map-legend">
                    <div class="sr-legend-item">
                        <span class="sr-legend-dot sr-legend-dot--avail"></span>
                        Disponibile
                    </div>
                    <div class="sr-legend-item">
                        <span class="sr-legend-dot sr-legend-dot--occ"></span>
                        Occupato
                    </div>
                </div>
            </div>

            <!-- Stats bar -->
            <div class="sr-map-stats">
                <span class="sr-stat-chip sr-stat-chip--total">🏢 <?= $totalRooms ?> sale totali</span>
                <span class="sr-stat-chip sr-stat-chip--avail">✓ <?= $availCount ?> disponibili</span>
                <?php if ($occCount > 0): ?>
                    <span class="sr-stat-chip sr-stat-chip--occ">✗ <?= $occCount ?> occupate</span>
                <?php endif; ?>
            </div>

            <!-- Griglia sale -->
            <?php if (empty($roomSpots)): ?>
                <div class="sr-empty">
                    <span>🏢</span>
                    <p>Nessuna sala riunione disponibile</p>
                </div>
            <?php else: ?>
                <div class="sr-room-grid">
                    <?php foreach ($roomSpots as $id => $spot): ?>
                        <?php
                            $isOcc     = strtolower($spot['status']) === 'occupato';
                            $cardCls   = $isOcc ? 'sr-room-card--occ'   : 'sr-room-card--avail';
                            $statusCls = $isOcc ? 'sr-card-status--occ'   : 'sr-card-status--avail';
                            $statusLbl = $isOcc ? 'Occupato' : 'Disponibile';
                        ?>
                        <div class="sr-room-card <?= $cardCls ?>"
                             id="card-<?= $id ?>"
                             data-id="<?= $id ?>"
                             data-code="<?= htmlspecialchars($spot['name']) ?>"
                             data-stato="<?= htmlspecialchars($spot['status']) ?>"
                             data-capacita="<?= htmlspecialchars($spot['capacita']) ?>"
                             data-attrezzatura="<?= htmlspecialchars($spot['attrezzatura']) ?>"
                             data-disponibilita="<?= htmlspecialchars($spot['disponibilita']) ?>"
                             onclick="openRoomPanel(<?= $id ?>)">
                            <span class="sr-card-icon"><?= $isOcc ? '🔴' : '🟢' ?></span>
                            <div class="sr-card-name"><?= htmlspecialchars($spot['name']) ?></div>
                            <div class="sr-card-meta">
                                <?php if ($spot['capacita'] !== '-'): ?>
                                    <div class="sr-card-meta-row">👥 <?= htmlspecialchars($spot['capacita']) ?> persone</div>
                                <?php endif; ?>
                                <?php if ($spot['disponibilita'] !== '-'): ?>
                                    <div class="sr-card-meta-row">🕐 <?= htmlspecialchars($spot['disponibilita']) ?></div>
                                <?php endif; ?>
                            </div>
                            <span class="sr-card-status <?= $statusCls ?>"><?= $statusLbl ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div><!-- /.sr-map-zone -->

        <!-- ── Pannello laterale slide-in (2/3) ─────────── -->
        <div class="sr-side-panel" id="side-panel">

            <div class="sr-panel-top">
                <div>
                    <h3 class="sr-panel-room-name" id="panel-title">—</h3>
                    <div id="panel-status-pill"></div>
                </div>
                <button class="sr-panel-close" onclick="closeRoomPanel()" title="Chiudi">✕</button>
            </div>

            <div class="sr-panel-body">

                <!-- Info tiles -->
                <div class="sr-info-section">
                    <div class="sr-info-grid" id="panel-info-grid"></div>

                    <p class="sr-panel-section-title">📅 Periodi già occupati</p>
                    <div class="sr-panel-slots" id="panel-slots"></div>
                </div>

                <!-- Form prenotazione -->
                <div class="sr-panel-form-wrap full-width">
                    <p class="sr-panel-section-title">✏️ Nuova Prenotazione</p>

                    <form method="POST" id="booking-form">
                        <input type="hidden" name="action"   value="book">
                        <input type="hidden" name="id_asset" id="panel-asset-id" value="">

                        <div class="sr-fields-row">
                            <div class="sr-field">
                                <label for="data-inizio">Data Inizio</label>
                                <input type="datetime-local" id="data-inizio" name="data_inizio"
                                       value="<?= htmlspecialchars($_POST['data_inizio'] ?? '') ?>"
                                       required onchange="updateDuration()">
                            </div>
                            <div class="sr-field">
                                <label for="data-fine">Data Fine</label>
                                <input type="datetime-local" id="data-fine" name="data_fine"
                                       value="<?= htmlspecialchars($_POST['data_fine'] ?? '') ?>"
                                       required onchange="updateDuration()">
                            </div>
                        </div>

                        <div id="sr-duration-preview" class="sr-duration-preview" style="display:none;">
                            <span class="sr-duration-icon">⏱️</span>
                            <span id="sr-duration-text"></span>
                        </div>

                        <div id="sr-form-error" class="sr-form-error" style="display:none;"></div>

                        <button type="submit" class="sr-submit-btn" id="submit-btn">
                            Conferma Prenotazione
                        </button>
                    </form>
                </div>

            </div><!-- /.sr-panel-body -->
        </div><!-- /.sr-side-panel -->

    </div><!-- /.sr-map-layout -->

    <!-- ── Le tue prenotazioni attive ─────────────────── -->
    <div class="sr-bookings-strip">
        <div class="sr-strip-header">
            <p class="sr-strip-title">Le tue prenotazioni attive</p>
            <span class="sr-count-badge"><?= count($userBookings) ?></span>
        </div>

        <?php if (empty($userBookings)): ?>
            <div class="sr-empty">
                <span>🏢</span>
                <p>Nessuna prenotazione sala attiva</p>
            </div>
        <?php else: ?>
            <div class="sr-bookings-list">
                <?php foreach ($userBookings as $b): ?>
                    <?php
                        $start    = new DateTime($b['data_inizio']);
                        $end      = new DateTime($b['data_fine']);
                        $now      = new DateTime();
                        $isActive = $start <= $now && $end >= $now;
                        $statusClass = $isActive ? 'sr-status--now'    : 'sr-status--future';
                        $statusLabel = $isActive ? 'In corso' : 'Programmato';
                        $diff   = $start->diff($end);
                        $days   = $diff->days;
                        $hours  = $diff->h;
                        $durStr = $days > 0 ? "{$days}g {$hours}h" : "{$hours}h {$diff->i}m";
                    ?>
                    <div class="sr-booking-item">
                        <div class="sr-booking-top">
                            <span class="sr-asset-pill"><?= htmlspecialchars($b['codice_asset']) ?></span>
                            <span class="sr-status-pill <?= $statusClass ?>"><?= $statusLabel ?></span>
                            <span class="sr-dur"><?= $durStr ?></span>
                        </div>
                        <div class="sr-booking-dates">
                            <?= date('d/m/Y H:i', strtotime($b['data_inizio'])) ?>
                            <span class="sr-arrow">→</span>
                            <?= date('d/m/Y H:i', strtotime($b['data_fine'])) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div><!-- /.sr-page -->

<script>
// ── Dati slot occupati (PHP → JS) ─────────────────────
const occupiedSlots  = <?= json_encode($occupiedSlotsByAsset) ?>;
const reopenAssetId  = <?= $reopenAssetId ?>;

// ── Apri pannello laterale ────────────────────────────
function openRoomPanel(id) {
    const card = document.getElementById('card-' + id);
    if (!card) return;

    // Selezione visiva card
    document.querySelectorAll('.sr-room-card').forEach(c => c.classList.remove('sr-room-card--selected'));
    card.classList.add('sr-room-card--selected');

    const code         = card.dataset.code;
    const stato       = card.dataset.stato;
    const capacita    = card.dataset.capacita;
    const attrezzatura= card.dataset.attrezzatura;
    const disponibilita= card.dataset.disponibilita;
    const isOcc       = stato.toLowerCase() === 'occupato';

    // Header pannello
    document.getElementById('panel-title').textContent = code;
    document.getElementById('panel-status-pill').innerHTML =
        `<span class="sr-status-pill ${isOcc ? 'sr-status--occ' : 'sr-status--avail'}">
            ${isOcc ? 'Occupato' : 'Disponibile'}
         </span>`;

    // Info tiles
    document.getElementById('panel-info-grid').innerHTML = `
        <div class="sr-info-tile">
            <span class="sr-info-tile-label">Capacità</span>
            <span class="sr-info-tile-val">${capacita}</span>
        </div>
        <div class="sr-info-tile">
            <span class="sr-info-tile-label">Attrezzatura</span>
            <span class="sr-info-tile-val">${attrezzatura}</span>
        </div>
        <div class="sr-info-tile">
            <span class="sr-info-tile-label">Disponibilità</span>
            <span class="sr-info-tile-val">${disponibilita}</span>
        </div>
        <div class="sr-info-tile">
            <span class="sr-info-tile-label">Stato</span>
            <span class="sr-info-tile-val">${stato}</span>
        </div>`;

    // Slot occupati
    const slots   = occupiedSlots[id] || [];
    const slotsEl = document.getElementById('panel-slots');
    if (slots.length > 0) {
        slotsEl.innerHTML = slots.map(s => `
            <div class="sr-slot-item">
                <span class="sr-slot-dot"></span>
                <span>${formatDate(s.inizio)}</span>
                <span class="sr-slot-arrow">→</span>
                <span>${formatDate(s.fine)}</span>
            </div>`).join('');
    } else {
        slotsEl.innerHTML = '<div class="sr-slots-empty-msg">✓ Nessun periodo occupato — sala libera</div>';
    }

    // Collega id_asset al form
    document.getElementById('panel-asset-id').value = id;

    // Apri pannello con animazione
    document.getElementById('side-panel').classList.add('open');
}

// ── Chiudi pannello ───────────────────────────────────
function closeRoomPanel() {
    document.getElementById('side-panel').classList.remove('open');
    document.querySelectorAll('.sr-room-card').forEach(c => c.classList.remove('sr-room-card--selected'));
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeRoomPanel(); });

// ── Preview durata ────────────────────────────────────
function updateDuration() {
    const start   = document.getElementById('data-inizio').value;
    const end     = document.getElementById('data-fine').value;
    const preview = document.getElementById('sr-duration-preview');
    const text    = document.getElementById('sr-duration-text');
    const error   = document.getElementById('sr-form-error');

    if (!start || !end) { preview.style.display = 'none'; return; }

    const ms   = new Date(end) - new Date(start);
    const days = Math.floor(ms / 86400000);
    const hrs  = Math.floor((ms % 86400000) / 3600000);
    const mins = Math.floor((ms % 3600000)  / 60000);

    if (ms <= 0) {
        preview.style.display = 'none';
        error.style.display   = '';
        error.textContent     = '⚠️ La data di fine deve essere successiva alla data di inizio.';
        document.getElementById('submit-btn').disabled = true;
        return;
    }

    error.style.display   = 'none';
    preview.style.display = '';
    document.getElementById('submit-btn').disabled = false;

    const parts = [];
    if (days > 0) parts.push(`${days} giorn${days > 1 ? 'i' : 'o'}`);
    if (hrs  > 0) parts.push(`${hrs} or${hrs > 1 ? 'e' : 'a'}`);
    if (mins > 0) parts.push(`${mins} minut${mins > 1 ? 'i' : 'o'}`);
    text.textContent = 'Durata: ' + (parts.join(' ') || 'meno di un minuto');
}

// ── Helper: formatta data ─────────────────────────────
function formatDate(str) {
    return new Date(str).toLocaleString('it-IT', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
}

// ── Init ──────────────────────────────────────────────
(function init() {
    updateDuration();
    // Riapri pannello sala selezionato dopo POST (es. errore o conferma)
    if (reopenAssetId) openRoomPanel(reopenAssetId);
})();
</script>
</body>
</html>