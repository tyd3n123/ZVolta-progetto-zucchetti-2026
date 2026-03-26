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
    $id_asset     = (int)($_POST['id_asset']     ?? 0);
    $data_inizio  = $_POST['data_inizio']  ?? '';
    $data_fine    = $_POST['data_fine']    ?? '';
    $tipo_veicolo = trim($_POST['tipo_veicolo'] ?? '');

    if (!$id_asset || !$data_inizio || !$data_fine || !$tipo_veicolo) {
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
            $feedback = ['type' => 'error', 'msg' => 'Il parcheggio è già occupato nel periodo selezionato.'];
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
                $feedback = ['type' => 'success', 'msg' => 'Parcheggio prenotato con successo!'];

            } catch (Exception $e) {
                $conn->rollback();
                $feedback = ['type' => 'error', 'msg' => 'Errore durante la prenotazione: ' . $e->getMessage()];
            }
        }
    }
}

// ── Fetch parcheggi (categoria = 'Parcheggio') ────────
$parkingSpots = []; // id_asset => [name, status, capacita, ...]
$sql = "SELECT a.id_asset, a.codice_asset, a.stato,
               COALESCE(p.numero_posto, '-')          AS numero_posto,
               COALESCE(p.coperto, '-')               AS coperto,
               COALESCE(p.colonnina_elettrica, '-')   AS colonnina_elettrica,
               COALESCE(p.posizione, '-')             AS posizione
        FROM asset a
        LEFT JOIN parcheggio_dettagli p ON p.id_asset = a.id_asset
        WHERE a.mappa = 'Parcheggio'
        ORDER BY a.codice_asset";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $parkingSpots[$row['id_asset']] = [
            'name'                 => $row['codice_asset'],
            'status'               => $row['stato'],
            'numero_posto'         => $row['numero_posto'],
            'coperto'              => $row['coperto'],
            'colonnina_elettrica'  => $row['colonnina_elettrica'],
            'posizione'            => $row['posizione'],
        ];
    }
}

// ── Fetch prenotazioni attive dell'utente (parcheggi) ─
$userBookings = [];
$stmt = $conn->prepare(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine,
            a.codice_asset, a.id_asset
     FROM prenotazioni p
     JOIN asset a ON p.id_asset = a.id_asset
     WHERE p.id_utente = ?
       AND a.mappa = 'Parcheggio'
       AND p.data_fine >= NOW()
     ORDER BY p.data_inizio ASC"
);
$stmt->bind_param("i", $id_utente);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $userBookings[] = $row;
$stmt->close();

// ── Fetch slot occupati per tutti i parcheggi ─────────
$occupiedSlotsByAsset = [];
$result = $conn->query(
    "SELECT p.id_asset, p.data_inizio, p.data_fine
     FROM prenotazioni p
     JOIN asset a ON p.id_asset = a.id_asset
     WHERE a.mappa = 'Parcheggio'
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
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parcheggi | Northstar</title>
    <link rel="stylesheet" href="../dashboard/dashboard.css">
    <link rel="stylesheet" href="./parcheggi.css">
</head>
<body>

<!-- ── Header ─────────────────────────────────────────── -->
<header class="header">
    <div class="header-left">
        <h1>Northstar</h1>
        <nav class="header-breadcrumb">
            <a href="../dashboard/index.php">Dashboard</a>
            <span class="bc-sep">/</span>
            <span class="bc-current">Parcheggi</span>
        </nav>
    </div>
    <div class="pk-user-pill">
        <?= htmlspecialchars($userInfo['nome'] . ' ' . $userInfo['cognome']) ?>
        <span class="pk-role"><?= htmlspecialchars($userInfo['ruolo']) ?></span>
    </div>
</header>

<!-- ── Page ───────────────────────────────────────────── -->
<div class="pk-page">

    <!-- Title row -->
    <div class="pk-title-row">
        <div>
            <h2 class="pk-page-title">🚗 Parcheggi</h2>
            <p class="pk-page-sub">Prenota un posto auto o moto per il periodo desiderato</p>
        </div>
    </div>

    <!-- Feedback banner -->
    <?php if ($feedback): ?>
        <div class="pk-feedback pk-feedback--<?= $feedback['type'] ?>">
            <?= $feedback['type'] === 'success' ? '✅' : '⚠️' ?>
            <?= htmlspecialchars($feedback['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- ══ MAPPA + PANNELLO ════════════════════════════ -->
    <div class="pk-map-layout" id="map-layout">

        <!-- ── Zona mappa (sempre visibile) ────────────── -->
        <div class="pk-map-zone" id="map-zone">

            <div class="pk-map-header">
                <p class="pk-map-title">Mappa Parcheggi — Sede</p>
                <div class="pk-map-legend">
                    <div class="pk-legend-item">
                        <span class="pk-legend-dot pk-legend-dot--avail"></span>
                        Disponibile
                    </div>
                    <div class="pk-legend-item">
                        <span class="pk-legend-dot pk-legend-dot--occ"></span>
                        Occupato
                    </div>
                </div>
            </div>

            <!-- Stats bar -->
            <div class="pk-map-stats">
                <span class="pk-stat-chip pk-stat-chip--total">🚗 <?= count($parkingSpots) ?> parcheggi totali</span>
                <?php $availCount = 0; foreach ($parkingSpots as $spot) if (strtolower($spot['status']) !== 'occupato') $availCount++; ?>
                <span class="pk-stat-chip pk-stat-chip--avail">✓ <?= $availCount ?> disponibili</span>
                <?php $occCount = 0; foreach ($parkingSpots as $spot) if (strtolower($spot['status']) === 'occupato') $occCount++; ?>
                <?php if ($occCount > 0): ?>
                    <span class="pk-stat-chip pk-stat-chip--occ">✗ <?= $occCount ?> occupati</span>
                <?php endif; ?>
            </div>

            <!-- Griglia parcheggi -->
            <?php if (empty($parkingSpots)): ?>
                <div class="pk-empty">
                    <span>�</span>
                    <p>Nessun parcheggio disponibile</p>
                </div>
            <?php else: ?>
                <div class="pk-parking-grid">
                    <?php foreach ($parkingSpots as $id => $spot): ?>
                        <?php
                            $isOcc     = strtolower($spot['status']) === 'occupato';
                            $cardCls   = $isOcc ? 'pk-parking-card--occ'   : 'pk-parking-card--avail';
                            $statusCls = $isOcc ? 'pk-card-status--occ'   : 'pk-card-status--avail';
                            $statusLbl = $isOcc ? 'Occupato' : 'Disponibile';
                        ?>
                        <div class="pk-parking-card <?= $cardCls ?>"
                             id="card-<?= $id ?>"
                             data-id="<?= $id ?>"
                             data-code="<?= htmlspecialchars($spot['name']) ?>"
                             data-stato="<?= htmlspecialchars($spot['status']) ?>"
                             data-numero-posto="<?= htmlspecialchars($spot['numero_posto']) ?>"
                             data-coperto="<?= $spot['coperto'] ? 'Sì' : 'No' ?>"
                             data-colonnina="<?= $spot['colonnina_elettrica'] ? 'Sì' : 'No' ?>"
                             data-posizione="<?= htmlspecialchars($spot['posizione']) ?>"
                             onclick="openParkingPanel(<?= $id ?>)">
                            <span class="pk-card-icon"><?= $isOcc ? '🔴' : '🟢' ?></span>
                            <div class="pk-card-name"><?= htmlspecialchars($spot['name']) ?></div>
                            <div class="pk-card-meta">
                                <?php if ($spot['numero_posto'] !== '-'): ?>
                                    <div class="pk-card-meta-row">🅿️ Posto <?= htmlspecialchars($spot['numero_posto']) ?></div>
                                <?php endif; ?>
                                <?php if ($spot['coperto'] && $spot['coperto'] !== '-'): ?>
                                    <div class="pk-card-meta-row">🏠 Coperto: <?= htmlspecialchars($spot['coperto']) ?></div>
                                <?php endif; ?>
                                <?php if ($spot['colonnina_elettrica'] && $spot['colonnina_elettrica'] !== '-'): ?>
                                    <div class="pk-card-meta-row">⚡ Colonnina: <?= htmlspecialchars($spot['colonnina_elettrica']) ?></div>
                                <?php endif; ?>
                            </div>
                            <span class="pk-card-status <?= $statusCls ?>"><?= $statusLbl ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div><!-- /.pk-map-zone -->

        <!-- ── Pannello laterale slide-in (2/3 della larghezza) ─────────── -->
        <div class="pk-side-panel" id="side-panel">

            <div class="pk-panel-top">
                <div>
                    <h3 class="pk-panel-parking-name" id="panel-title">—</h3>
                    <div id="panel-status-pill"></div>
                </div>
                <button class="pk-panel-close" onclick="closeParkingPanel()" title="Chiudi">✕</button>
            </div>

            <div class="pk-panel-body">

                <!-- Info tiles -->
                <div class="pk-info-section">
                    <div class="pk-info-grid" id="panel-info-grid"></div>

                    <p class="pk-panel-section-title">📅 Periodi già occupati</p>
                    <div class="pk-panel-slots" id="panel-slots"></div>
                </div>

                <!-- Form prenotazione -->
                <div class="pk-panel-form-wrap full-width">
                    <p class="pk-panel-section-title">✏️ Nuova Prenotazione</p>

                    <form method="POST" id="booking-form">
                        <input type="hidden" name="action"   value="book">
                        <input type="hidden" name="id_asset" id="panel-asset-id" value="">

                        <!-- Tipo veicolo -->
                        <div class="pk-field">
                            <label for="tipo-veicolo">Tipo Veicolo</label>
                            <select id="tipo-veicolo" name="tipo_veicolo" required>
                                <option value="">— Seleziona tipo —</option>
                                <option value="Auto">🚗 Auto</option>
                                <option value="Moto">�️ Moto</option>
                            </select>
                        </div>

                        <div class="pk-fields-row">
                            <div class="pk-field">
                                <label for="data-inizio">Data Inizio</label>
                                <input type="datetime-local" id="data-inizio" name="data_inizio"
                                       value="<?= htmlspecialchars($_POST['data_inizio'] ?? '') ?>"
                                       required onchange="updateDuration()">
                            </div>
                            <div class="pk-field">
                                <label for="data-fine">Data Fine</label>
                                <input type="datetime-local" id="data-fine" name="data_fine"
                                       value="<?= htmlspecialchars($_POST['data_fine'] ?? '') ?>"
                                       required onchange="updateDuration()">
                            </div>
                        </div>

                        <div id="pk-duration-preview" class="pk-duration-preview" style="display:none;">
                            <span class="pk-duration-icon">⏱️</span>
                            <span id="pk-duration-text"></span>
                        </div>

                        <div id="pk-form-error" class="pk-form-error" style="display:none;"></div>

                        <button type="submit" class="pk-submit-btn" id="submit-btn">
                            Conferma Prenotazione
                        </button>
                    </form>
                </div>

            </div><!-- /.pk-panel-body -->
        </div><!-- /.pk-side-panel -->

    </div><!-- /.pk-map-layout -->

    <!-- ── Le tue prenotazioni attive ─────────────────── -->
    <div class="pk-bookings-strip">
        <div class="pk-strip-header">
            <p class="pk-strip-title">Le tue prenotazioni attive</p>
            <span class="pk-count-badge"><?= count($userBookings) ?></span>
        </div>

        <?php if (empty($userBookings)): ?>
            <div class="pk-empty">
                <span>🚗</span>
                <p>Nessuna prenotazione parcheggio attiva</p>
            </div>
        <?php else: ?>
            <div class="pk-bookings-list">
                <?php foreach ($userBookings as $b): ?>
                    <?php
                        $start    = new DateTime($b['data_inizio']);
                        $end      = new DateTime($b['data_fine']);
                        $now      = new DateTime();
                        $isActive = $start <= $now && $end >= $now;
                        $statusClass = $isActive ? 'pk-status--now'    : 'pk-status--future';
                        $statusLabel = $isActive ? 'In corso' : 'Programmato';
                        $diff   = $start->diff($end);
                        $days   = $diff->days;
                        $hours  = $diff->h;
                        $durStr = $days > 0 ? "{$days}g {$hours}h" : "{$hours}h {$diff->i}m";
                    ?>
                    <div class="pk-booking-item">
                        <div class="pk-booking-top">
                            <span class="pk-asset-pill"><?= htmlspecialchars($b['codice_asset']) ?></span>
                            <span class="pk-status-pill <?= $statusClass ?>"><?= $statusLabel ?></span>
                            <span class="pk-dur"><?= $durStr ?></span>
                        </div>
                        <div class="pk-booking-dates">
                            <?= date('d/m/Y H:i', strtotime($b['data_inizio'])) ?>
                            <span class="pk-arrow">→</span>
                            <?= date('d/m/Y H:i', strtotime($b['data_fine'])) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div><!-- /.pk-page -->

<script>
// ── Dati slot occupati (PHP → JS) ─────────────────────
const occupiedSlots = <?= json_encode($occupiedSlotsByAsset) ?>;

// ── Apri pannello parcheggio ──────────────────────────
function openParkingPanel(id) {
    const card = document.getElementById('card-' + id);
    if (!card) return;

    // Selezione visiva card
    document.querySelectorAll('.pk-parking-card').forEach(c => c.classList.remove('pk-parking-card--selected'));
    card.classList.add('pk-parking-card--selected');

    const code     = card.dataset.code;
    const stato    = card.dataset.stato;
    const numero   = card.dataset.numeroPosto;
    const coperto  = card.dataset.coperto;
    const colonnina = card.dataset.colonnina;
    const posizione = card.dataset.posizione;
    const isOcc    = stato.toLowerCase() === 'occupato';

    // Header pannello
    document.getElementById('panel-title').textContent = code;
    document.getElementById('panel-status-pill').innerHTML =
        `<span class="pk-status-pill ${isOcc ? 'pk-status--occ' : 'pk-status--avail'}">
            ${isOcc ? 'Occupato' : 'Disponibile'}
         </span>`;

    // Info tiles
    document.getElementById('panel-info-grid').innerHTML = `
        <div class="pk-info-tile">
            <span class="pk-info-tile-label">Posto</span>
            <span class="pk-info-tile-val">${numero}</span>
        </div>
        <div class="pk-info-tile">
            <span class="pk-info-tile-label">Coperto</span>
            <span class="pk-info-tile-val">${coperto}</span>
        </div>
        <div class="pk-info-tile">
            <span class="pk-info-tile-label">Colonnina</span>
            <span class="pk-info-tile-val">${colonnina}</span>
        </div>
        <div class="pk-info-tile">
            <span class="pk-info-tile-label">Posizione</span>
            <span class="pk-info-tile-val">${posizione}</span>
        </div>`;

    // Slot occupati
    const slots   = occupiedSlots[id] || [];
    const slotsEl = document.getElementById('panel-slots');
    if (slots.length > 0) {
        slotsEl.innerHTML = slots.map(s => `
            <div class="pk-slot-item">
                <span class="pk-slot-dot"></span>
                <span>${formatDate(s.inizio)}</span>
                <span class="pk-slot-arrow">→</span>
                <span>${formatDate(s.fine)}</span>
            </div>`).join('');
    } else {
        slotsEl.innerHTML = '<div class="pk-slots-empty-msg">✓ Nessun periodo occupato — parcheggio libero</div>';
    }

    // Collega id_asset al form
    document.getElementById('panel-asset-id').value = id;

    // Apri pannello con animazione
    document.getElementById('side-panel').classList.add('open');
}

// ── Chiudi pannello ───────────────────────────────────
function closeParkingPanel() {
    document.getElementById('side-panel').classList.remove('open');
    document.querySelectorAll('.pk-parking-card').forEach(c => c.classList.remove('pk-parking-card--selected'));
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeParkingPanel(); });

// ── Selezione parcheggio da dropdown ─────────────────
function onParkingChange(select) {
    const opt    = select.options[select.selectedIndex];
    const id     = select.value;
    const detail = document.getElementById('pk-detail-card');
    const slots  = document.getElementById('pk-slots-section');

    // Highlight riga tabella
    document.querySelectorAll('.pk-table-row').forEach(r => r.classList.remove('pk-table-row--selected'));
    if (id) document.getElementById('row-' + id)?.classList.add('pk-table-row--selected');

    if (!id) {
        detail.style.display = 'none';
        slots.style.display  = 'none';
        return;
    }

    // Detail card
    const stato = opt.dataset.stato || '—';
    const isOcc = stato.toLowerCase() === 'occupato';
    detail.style.display = '';
    detail.innerHTML = `
        <div class="pk-detail-row">
            <span class="pk-detail-key">Codice</span>
            <span class="pk-detail-val">${opt.dataset.code}</span>
        </div>
        <div class="pk-detail-row">
            <span class="pk-detail-key">Capacità</span>
            <span class="pk-detail-val">${opt.dataset.capacita || '—'}</span>
        </div>
        <div class="pk-detail-row">
            <span class="pk-detail-key">Tipo Posto</span>
            <span class="pk-detail-val">${opt.dataset.tipo || '—'}</span>
        </div>
        <div class="pk-detail-row">
            <span class="pk-detail-key">Piano</span>
            <span class="pk-detail-val">${opt.dataset.piano || '—'}</span>
        </div>
        <div class="pk-detail-row">
            <span class="pk-detail-key">Stato attuale</span>
            <span class="pk-detail-val">
                <span class="pk-status-pill ${isOcc ? 'pk-status--occ' : 'pk-status--avail'}">
                    ${isOcc ? 'Occupato' : 'Disponibile'}
                </span>
            </span>
        </div>
    `;

    // Slot occupati
    const assetSlots = occupiedSlots[id] || [];
    slots.style.display = '';
    const list = document.getElementById('pk-slots-list');
    if (assetSlots.length > 0) {
        list.innerHTML = assetSlots.map(s => `
            <div class="pk-slot-item">
                <div class="pk-slot-range">
                    <span>${formatDate(s.inizio)}</span>
                    <span>→</span>
                    <span>${formatDate(s.fine)}</span>
                </div>
            </div>
        `).join('');
    } else {
        list.innerHTML = '<p class="pk-slots-empty">✓ Nessun periodo occupato</p>';
    }
}

// ── Selezione parcheggio da riga tabella ──────────────
function selectParkingFromTable(id) {
    const select = document.getElementById('select-parking');
    select.value = id;
    onParkingChange(select);
    select.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// ── Aggiorna preview durata ───────────────────────────
function updateDuration() {
    const start   = document.getElementById('data-inizio').value;
    const end     = document.getElementById('data-fine').value;
    const preview = document.getElementById('pk-duration-preview');
    const text    = document.getElementById('pk-duration-text');
    const error   = document.getElementById('pk-form-error');

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

    let parts = [];
    if (days > 0) parts.push(`${days} giorn${days > 1 ? 'i' : 'o'}`);
    if (hrs  > 0) parts.push(`${hrs} or${hrs > 1 ? 'e' : 'a'}`);
    if (mins > 0) parts.push(`${mins} minut${mins > 1 ? 'i' : 'o'}`);
    text.textContent = 'Durata: ' + (parts.join(' ') || 'meno di un minuto');
}

// ── Helper: formatta data ─────────────────────────────
function formatDate(str) {
    const d = new Date(str);
    return d.toLocaleString('it-IT', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
}

// ── Init al caricamento ───────────────────────────────
(function init() {
    const sel = document.getElementById('select-parking');
    if (sel.value) onParkingChange(sel);
    updateDuration();
})();
</script>

</body>
</html>