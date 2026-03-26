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
            $feedback = ['type' => 'error', 'msg' => "L'ufficio è già occupato nel periodo selezionato."];
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
                $feedback = ['type' => 'success', 'msg' => 'Ufficio prenotato con successo!'];

            } catch (Exception $e) {
                $conn->rollback();
                $feedback = ['type' => 'error', 'msg' => 'Errore durante la prenotazione: ' . $e->getMessage()];
            }
        }
    }
}

// ── Fetch uffici ──────────────────────────────────────
$officeSpots = [];
$sql = "SELECT a.id_asset, a.codice_asset, a.stato,
               COALESCE(u.numero_ufficio, '-')   AS numero_ufficio,
               COALESCE(u.piano, '-')            AS piano,
               COALESCE(u.capacita, '-')         AS capacita,
               COALESCE(u.telefono_interno, '-') AS telefono_interno
        FROM asset a
        LEFT JOIN ufficio_dettagli u ON u.id_asset = a.id_asset
        WHERE a.mappa = 'Sede' AND a.codice_asset LIKE 'Ufficio%'
        ORDER BY a.codice_asset";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $officeSpots[$row['id_asset']] = [
            'name'             => $row['codice_asset'],
            'status'           => $row['stato'],
            'numero_ufficio'   => $row['numero_ufficio'],
            'piano'            => $row['piano'],
            'capacita'         => $row['capacita'],
            'telefono_interno' => $row['telefono_interno'],
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
       AND a.codice_asset LIKE 'Ufficio%'
       AND p.data_fine >= NOW()
     ORDER BY p.data_inizio ASC"
);
$stmt->bind_param("i", $id_utente);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $userBookings[] = $row;
$stmt->close();

// ── Fetch slot occupati per tutti gli uffici ──────────
$occupiedSlotsByAsset = [];
$result = $conn->query(
    "SELECT p.id_asset, p.data_inizio, p.data_fine
     FROM prenotazioni p
     JOIN asset a ON p.id_asset = a.id_asset
     WHERE a.mappa = 'Sede'
       AND a.codice_asset LIKE 'Ufficio%'
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
$totalOffices = count($officeSpots);
$availCount   = count(array_filter($officeSpots, fn($s) => strtolower($s['status']) !== 'occupato'));
$occCount     = $totalOffices - $availCount;

// ── Quale ufficio riaprire dopo il POST ───────────────
$reopenAssetId = (!empty($_POST['id_asset'])) ? (int)$_POST['id_asset'] : 0;
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Uffici | Northstar</title>
    <link rel="stylesheet" href="../dashboard/dashboard.css">
    <link rel="stylesheet" href="./uffici.css">
</head>
<body>

<!-- ── Header ─────────────────────────────────────────── -->
<header class="header">
    <div class="header-left">
        <h1>Northstar</h1>
        <nav class="header-breadcrumb">
            <a href="../dashboard/index.php">Dashboard</a>
            <span class="bc-sep">/</span>
            <span class="bc-current">Uffici</span>
        </nav>
    </div>
    <div class="uf-user-pill">
        <?= htmlspecialchars($userInfo['nome'] . ' ' . $userInfo['cognome']) ?>
        <span class="uf-role"><?= htmlspecialchars($userInfo['ruolo']) ?></span>
    </div>
</header>

<!-- ── Page ───────────────────────────────────────────── -->
<div class="uf-page">

    <!-- Title row -->
    <div class="uf-title-row">
        <div>
            <h2 class="uf-page-title">🏢 Uffici</h2>
            <p class="uf-page-sub">Seleziona un ufficio dalla mappa per prenotarlo</p>
        </div>
    </div>

    <!-- Feedback banner -->
    <?php if ($feedback): ?>
        <div class="uf-feedback uf-feedback--<?= $feedback['type'] ?>">
            <?= $feedback['type'] === 'success' ? '✅' : '⚠️' ?>
            <?= htmlspecialchars($feedback['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- ══ MAPPA + PANNELLO ════════════════════════════ -->
    <div class="uf-map-layout" id="map-layout">

        <!-- ── Zona mappa (sempre visibile) ────────────── -->
        <div class="uf-map-zone" id="map-zone">

            <div class="uf-map-header">
                <p class="uf-map-title">Mappa Uffici — Sede</p>
                <div class="uf-map-legend">
                    <div class="uf-legend-item">
                        <span class="uf-legend-dot uf-legend-dot--avail"></span>
                        Disponibile
                    </div>
                    <div class="uf-legend-item">
                        <span class="uf-legend-dot uf-legend-dot--occ"></span>
                        Occupato
                    </div>
                </div>
            </div>

            <!-- Stats bar -->
            <div class="uf-map-stats">
                <span class="uf-stat-chip uf-stat-chip--total">🏢 <?= $totalOffices ?> uffici totali</span>
                <span class="uf-stat-chip uf-stat-chip--avail">✓ <?= $availCount ?> disponibili</span>
                <?php if ($occCount > 0): ?>
                    <span class="uf-stat-chip uf-stat-chip--occ">✗ <?= $occCount ?> occupati</span>
                <?php endif; ?>
            </div>

            <!-- Griglia uffici -->
            <?php if (empty($officeSpots)): ?>
                <div class="uf-empty">
                    <span>🏢</span>
                    <p>Nessun ufficio disponibile</p>
                </div>
            <?php else: ?>
                <div class="uf-office-grid">
                    <?php foreach ($officeSpots as $id => $spot): ?>
                        <?php
                            $isOcc     = strtolower($spot['status']) === 'occupato';
                            $cardCls   = $isOcc ? 'uf-office-card--occ'   : 'uf-office-card--avail';
                            $statusCls = $isOcc ? 'uf-card-status--occ'   : 'uf-card-status--avail';
                            $statusLbl = $isOcc ? 'Occupato' : 'Disponibile';
                        ?>
                        <div class="uf-office-card <?= $cardCls ?>"
                             id="card-<?= $id ?>"
                             data-id="<?= $id ?>"
                             data-code="<?= htmlspecialchars($spot['name']) ?>"
                             data-stato="<?= htmlspecialchars($spot['status']) ?>"
                             data-numero="<?= htmlspecialchars($spot['numero_ufficio']) ?>"
                             data-piano="<?= htmlspecialchars($spot['piano']) ?>"
                             data-capacita="<?= htmlspecialchars($spot['capacita']) ?>"
                             data-telefono="<?= htmlspecialchars($spot['telefono_interno']) ?>"
                             onclick="openOfficePanel(<?= $id ?>)">
                            <span class="uf-card-icon"><?= $isOcc ? '🔴' : '🟢' ?></span>
                            <div class="uf-card-name"><?= htmlspecialchars($spot['name']) ?></div>
                            <div class="uf-card-meta">
                                <?php if ($spot['piano'] !== '-'): ?>
                                    <div class="uf-card-meta-row">🏗️ Piano <?= htmlspecialchars($spot['piano']) ?></div>
                                <?php endif; ?>
                                <?php if ($spot['capacita'] !== '-'): ?>
                                    <div class="uf-card-meta-row">👥 <?= htmlspecialchars($spot['capacita']) ?> persone</div>
                                <?php endif; ?>
                            </div>
                            <span class="uf-card-status <?= $statusCls ?>"><?= $statusLbl ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div><!-- /.uf-map-zone -->

        <!-- ── Pannello laterale slide-in (2/3) ─────────── -->
        <div class="uf-side-panel" id="side-panel">

            <div class="uf-panel-top">
                <div>
                    <h3 class="uf-panel-office-name" id="panel-title">—</h3>
                    <div id="panel-status-pill"></div>
                </div>
                <button class="uf-panel-close" onclick="closeOfficePanel()" title="Chiudi">✕</button>
            </div>

            <div class="uf-panel-body">

                <!-- Info tiles -->
                <div class="uf-info-section">
                    <div class="uf-info-grid" id="panel-info-grid"></div>

                    <p class="uf-panel-section-title">📅 Periodi già occupati</p>
                    <div class="uf-panel-slots" id="panel-slots"></div>
                </div>

                <!-- Form prenotazione -->
                <div class="uf-panel-form-wrap full-width">
                    <p class="uf-panel-section-title">✏️ Nuova Prenotazione</p>

                    <form method="POST" id="booking-form">
                        <input type="hidden" name="action"   value="book">
                        <input type="hidden" name="id_asset" id="panel-asset-id" value="">

                        <div class="uf-fields-row">
                            <div class="uf-field">
                                <label for="data-inizio">Data Inizio</label>
                                <input type="datetime-local" id="data-inizio" name="data_inizio"
                                       value="<?= htmlspecialchars($_POST['data_inizio'] ?? '') ?>"
                                       required onchange="updateDuration()">
                            </div>
                            <div class="uf-field">
                                <label for="data-fine">Data Fine</label>
                                <input type="datetime-local" id="data-fine" name="data_fine"
                                       value="<?= htmlspecialchars($_POST['data_fine'] ?? '') ?>"
                                       required onchange="updateDuration()">
                            </div>
                        </div>

                        <div id="uf-duration-preview" class="uf-duration-preview" style="display:none;">
                            <span class="uf-duration-icon">⏱️</span>
                            <span id="uf-duration-text"></span>
                        </div>

                        <div id="uf-form-error" class="uf-form-error" style="display:none;"></div>

                        <button type="submit" class="uf-submit-btn" id="submit-btn">
                            Conferma Prenotazione
                        </button>
                    </form>
                </div>

            </div><!-- /.uf-panel-body -->
        </div><!-- /.uf-side-panel -->

    </div><!-- /.uf-map-layout -->

    <!-- ── Le tue prenotazioni attive ─────────────────── -->
    <div class="uf-bookings-strip">
        <div class="uf-strip-header">
            <p class="uf-strip-title">Le tue prenotazioni attive</p>
            <span class="uf-count-badge"><?= count($userBookings) ?></span>
        </div>

        <?php if (empty($userBookings)): ?>
            <div class="uf-empty">
                <span>🏢</span>
                <p>Nessuna prenotazione ufficio attiva</p>
            </div>
        <?php else: ?>
            <div class="uf-bookings-list">
                <?php foreach ($userBookings as $b): ?>
                    <?php
                        $start    = new DateTime($b['data_inizio']);
                        $end      = new DateTime($b['data_fine']);
                        $now      = new DateTime();
                        $isActive = $start <= $now && $end >= $now;
                        $statusClass = $isActive ? 'uf-status--now'    : 'uf-status--future';
                        $statusLabel = $isActive ? 'In corso' : 'Programmato';
                        $diff   = $start->diff($end);
                        $days   = $diff->days;
                        $hours  = $diff->h;
                        $durStr = $days > 0 ? "{$days}g {$hours}h" : "{$hours}h {$diff->i}m";
                    ?>
                    <div class="uf-booking-item">
                        <div class="uf-booking-top">
                            <span class="uf-asset-pill"><?= htmlspecialchars($b['codice_asset']) ?></span>
                            <span class="uf-status-pill <?= $statusClass ?>"><?= $statusLabel ?></span>
                            <span class="uf-dur"><?= $durStr ?></span>
                        </div>
                        <div class="uf-booking-dates">
                            <?= date('d/m/Y H:i', strtotime($b['data_inizio'])) ?>
                            <span class="uf-arrow">→</span>
                            <?= date('d/m/Y H:i', strtotime($b['data_fine'])) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div><!-- /.uf-page -->

<script>
// ── Dati slot occupati (PHP → JS) ─────────────────────
const occupiedSlots  = <?= json_encode($occupiedSlotsByAsset) ?>;
const reopenAssetId  = <?= $reopenAssetId ?>;

// ── Apri pannello laterale ────────────────────────────
function openOfficePanel(id) {
    const card = document.getElementById('card-' + id);
    if (!card) return;

    // Selezione visiva card
    document.querySelectorAll('.uf-office-card').forEach(c => c.classList.remove('uf-office-card--selected'));
    card.classList.add('uf-office-card--selected');

    const code     = card.dataset.code;
    const stato    = card.dataset.stato;
    const numero   = card.dataset.numero;
    const piano    = card.dataset.piano;
    const capacita = card.dataset.capacita;
    const telefono = card.dataset.telefono;
    const isOcc    = stato.toLowerCase() === 'occupato';

    // Header pannello
    document.getElementById('panel-title').textContent = code;
    document.getElementById('panel-status-pill').innerHTML =
        `<span class="uf-status-pill ${isOcc ? 'uf-status--occ' : 'uf-status--avail'}">
            ${isOcc ? 'Occupato' : 'Disponibile'}
         </span>`;

    // Info tiles
    document.getElementById('panel-info-grid').innerHTML = `
        <div class="uf-info-tile">
            <span class="uf-info-tile-label">N° Ufficio</span>
            <span class="uf-info-tile-val">${numero}</span>
        </div>
        <div class="uf-info-tile">
            <span class="uf-info-tile-label">Piano</span>
            <span class="uf-info-tile-val">${piano}</span>
        </div>
        <div class="uf-info-tile">
            <span class="uf-info-tile-label">Capacità</span>
            <span class="uf-info-tile-val">${capacita}</span>
        </div>
        <div class="uf-info-tile">
            <span class="uf-info-tile-label">Tel. Interno</span>
            <span class="uf-info-tile-val">${telefono}</span>
        </div>`;

    // Slot occupati
    const slots   = occupiedSlots[id] || [];
    const slotsEl = document.getElementById('panel-slots');
    if (slots.length > 0) {
        slotsEl.innerHTML = slots.map(s => `
            <div class="uf-slot-item">
                <span class="uf-slot-dot"></span>
                <span>${formatDate(s.inizio)}</span>
                <span class="uf-slot-arrow">→</span>
                <span>${formatDate(s.fine)}</span>
            </div>`).join('');
    } else {
        slotsEl.innerHTML = '<div class="uf-slots-empty-msg">✓ Nessun periodo occupato — ufficio libero</div>';
    }

    // Collega id_asset al form
    document.getElementById('panel-asset-id').value = id;

    // Apri pannello con animazione
    document.getElementById('side-panel').classList.add('open');
}

// ── Chiudi pannello ───────────────────────────────────
function closeOfficePanel() {
    document.getElementById('side-panel').classList.remove('open');
    document.querySelectorAll('.uf-office-card').forEach(c => c.classList.remove('uf-office-card--selected'));
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeOfficePanel(); });

// ── Preview durata ────────────────────────────────────
function updateDuration() {
    const start   = document.getElementById('data-inizio').value;
    const end     = document.getElementById('data-fine').value;
    const preview = document.getElementById('uf-duration-preview');
    const text    = document.getElementById('uf-duration-text');
    const error   = document.getElementById('uf-form-error');

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
    // Riapri pannello ufficio selezionato dopo POST (es. errore o conferma)
    if (reopenAssetId) openOfficePanel(reopenAssetId);
})();
</script>

</body>
</html>