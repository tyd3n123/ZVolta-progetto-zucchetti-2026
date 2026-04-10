<?php
session_start();
require_once __DIR__ . "/../../../config/config.php";

// ── Auth check ─────────────────────────────────────────
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['id_utente'])) {
    header("Location: ../login.php");
    exit();
}

$id_utente = (int)$_SESSION['id_utente'];

// ── Info utente ────────────────────────────────────────
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
    $id_asset    = (int)($_POST['id_asset']   ?? 0);
    $data_inizio = $_POST['data_inizio'] ?? '';
    $data_fine   = $_POST['data_fine']   ?? '';

    if (!$id_asset || !$data_inizio || !$data_fine) {
        $feedback = ['type' => 'error', 'msg' => 'Seleziona le date prima di procedere.'];
    } elseif (strtotime($data_inizio) < time()) {
        $feedback = ['type' => 'error', 'msg' => 'Non puoi prenotare nel passato.'];
    } elseif (strtotime($data_fine) <= strtotime($data_inizio)) {
        $feedback = ['type' => 'error', 'msg' => 'La data di fine deve essere successiva alla data di inizio.'];
    } else {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS c FROM prenotazioni
             WHERE id_asset = ? AND data_inizio < ? AND data_fine > ?"
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

// ── Fetch parcheggi ────────────────────────────────────
$parkingSpots = [];
$result = $conn->query(
    "SELECT a.id_asset, a.codice_asset, a.stato,
            COALESCE(p.numero_posto, '-')         AS numero_posto,
            COALESCE(p.coperto, 0)                AS coperto,
            COALESCE(p.colonnina_elettrica, 0)    AS colonnina_elettrica,
            COALESCE(p.posizione, '-')            AS posizione
     FROM asset a
     LEFT JOIN parcheggio_dettagli p ON p.id_asset = a.id_asset
     WHERE a.mappa = 'Parcheggio'
     ORDER BY a.codice_asset"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $parkingSpots[] = [
            'id'                  => (int)$row['id_asset'],
            'name'                => $row['codice_asset'],
            'status'              => $row['stato'],
            'numero_posto'        => $row['numero_posto'],
            'coperto'             => (int)$row['coperto'],
            'colonnina_elettrica' => (int)$row['colonnina_elettrica'],
            'posizione'           => $row['posizione'],
        ];
    }
}

// ── Stats ──────────────────────────────────────────────
$totalSpots = count($parkingSpots);
$availCount = count(array_filter($parkingSpots, fn($s) => strtolower($s['status']) !== 'occupato'));
$occCount   = $totalSpots - $availCount;

// ── Prenotazioni attive dell'utente ───────────────────
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

// ── Slot occupati per asset ────────────────────────────
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

// Id da riaprire dopo POST
$reopenAssetId = (!empty($_POST['id_asset'])) ? (int)$_POST['id_asset'] : 0;
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

<!-- ── Header ──────────────────────────────────────────── -->
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

<div class="pk-page">

    <!-- ── Titolo + Stats ──────────────────────────────── -->
    <div class="pk-title-row">
        <div>
            <h2 class="pk-page-title">🚗 Parcheggi</h2>
            <p class="pk-page-sub">Clicca su uno stallo nella cartina per prenotarlo</p>
        </div>
    </div>

    <!-- ── Feedback ────────────────────────────────────── -->
    <?php if ($feedback): ?>
    <div class="pk-feedback pk-feedback--<?= $feedback['type'] ?>">
        <?= $feedback['type'] === 'success' ? '✅' : '⚠️' ?>
        <?= htmlspecialchars($feedback['msg']) ?>
    </div>
    <?php endif; ?>

    <!-- Backdrop overlay pannello -->
    <div class="pk-panel-backdrop" id="pk-backdrop" onclick="closeParkingPanel()"></div>

    <!-- ── Mappa ───────────────────────────────────────── -->
    <div class="pk-map-layout" id="map-layout">

        <!-- Zona canvas -->
        <div class="pk-map-zone" id="map-zone">

            <!-- Stats bar -->
            <div class="pk-map-topbar">
                <span class="pk-map-label">📍 Mappa Parcheggi — Sede</span>
                <div class="pk-map-chips">
                    <span class="pk-chip pk-chip--total">🚗 <?= $totalSpots ?> stalli</span>
                    <span class="pk-chip pk-chip--avail">✓ <?= $availCount ?> liberi</span>
                    <?php if ($occCount > 0): ?>
                    <span class="pk-chip pk-chip--occ">✗ <?= $occCount ?> occupati</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Canvas parcheggio -->
            <div class="pk-canvas-wrap" id="pk-canvas-wrap">
                <canvas id="parkingCanvas"></canvas>
            </div>

            <!-- Legenda -->
            <div class="pk-legend">
                <div class="pk-leg-item"><span class="pk-leg-dot pk-leg--avail"></span>Disponibile</div>
                <div class="pk-leg-item"><span class="pk-leg-dot pk-leg--occ"></span>Occupato</div>
                <div class="pk-leg-item"><span class="pk-leg-icon">🏠</span>Coperto</div>
                <div class="pk-leg-item"><span class="pk-leg-icon">⚡</span>Colonnina</div>
            </div>

        </div><!-- /.pk-map-zone -->

        <!-- Pannello laterale slide-in -->
        <div class="pk-side-panel" id="side-panel">

            <div class="pk-panel-top">
                <div>
                    <h3 class="pk-panel-parking-name" id="panel-title">—</h3>
                    <div id="panel-status-pill" style="margin-top:6px"></div>
                </div>
                <button class="pk-panel-close" onclick="closeParkingPanel()" title="Chiudi">✕</button>
            </div>

            <div class="pk-panel-body">

                <!-- Sezione 1: Info stallo -->
                <div class="pk-panel-section">
                    <div class="pk-info-grid" id="panel-info-grid"></div>
                </div>

                <!-- Sezione 2: Timeline oggi -->
                <div class="pk-panel-section">
                    <p class="pk-panel-section-title">🕐 Disponibilità oggi</p>
                    <div id="panel-timeline"></div>
                </div>

                <!-- Sezione 3: Prossimi slot liberi -->
                <div class="pk-panel-section">
                    <p class="pk-panel-section-title">✅ Prossimi periodi liberi</p>
                    <p class="pk-panel-section-hint">Clicca un periodo per compilare automaticamente le date</p>
                    <div id="panel-free-slots"></div>
                </div>

                <!-- Sezione 4: Form prenotazione -->
                <div class="pk-panel-section pk-panel-form-section">
                    <p class="pk-panel-section-title">✏️ Prenota questo stallo</p>

                    <form method="POST" id="booking-form">
                        <input type="hidden" name="action"   value="book">
                        <input type="hidden" name="id_asset" id="panel-asset-id" value="">

                        <div class="pk-fields-row">
                            <div class="pk-field">
                                <label>Data Inizio</label>
                                <input type="datetime-local" name="data_inizio" id="data-inizio"
                                       value="<?= htmlspecialchars($_POST['data_inizio'] ?? '') ?>"
                                       required onchange="updateDuration()">
                            </div>
                            <div class="pk-field">
                                <label>Data Fine</label>
                                <input type="datetime-local" name="data_fine" id="data-fine"
                                       value="<?= htmlspecialchars($_POST['data_fine'] ?? '') ?>"
                                       required onchange="updateDuration()">
                            </div>
                        </div>

                        <div id="pk-duration-preview" class="pk-duration-preview" style="display:none;"></div>
                        <div id="pk-form-error" class="pk-form-error" style="display:none;"></div>

                        <button type="submit" class="pk-submit-btn" id="submit-btn">
                            Conferma prenotazione
                        </button>
                    </form>
                </div>

            </div><!-- /.pk-panel-body -->
        </div><!-- /.pk-side-panel -->

    </div><!-- /.pk-map-layout -->



    <!-- ── Prenotazioni attive utente ───────────────────── -->
    <div class="pk-bookings-strip">
        <div class="pk-strip-header">
            <p class="pk-strip-title">Le tue prenotazioni attive</p>
            <span class="pk-count-badge"><?= count($userBookings) ?></span>
        </div>

        <?php if (empty($userBookings)): ?>
            <div class="pk-empty"><span>🚗</span><p>Nessuna prenotazione parcheggio attiva</p></div>
        <?php else: ?>
            <div class="pk-bookings-list">
                <?php foreach ($userBookings as $b):
                    $start    = new DateTime($b['data_inizio']);
                    $end      = new DateTime($b['data_fine']);
                    $now      = new DateTime();
                    $isActive = $start <= $now && $end >= $now;
                    $sClass   = $isActive ? 'pk-status--now' : 'pk-status--future';
                    $sLabel   = $isActive ? 'In corso' : 'Programmato';
                    $diff     = $start->diff($end);
                    $durStr   = $diff->days > 0
                        ? $diff->days.'g '.$diff->h.'h'
                        : $diff->h.'h '.$diff->i.'m';
                ?>
                <div class="pk-booking-item">
                    <div class="pk-booking-top">
                        <span class="pk-asset-pill"><?= htmlspecialchars($b['codice_asset']) ?></span>
                        <span class="pk-status-pill <?= $sClass ?>"><?= $sLabel ?></span>
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

<!-- ═══════════════════════════════════════════════════════
     JAVASCRIPT — Canvas parcheggio
     ═══════════════════════════════════════════════════════ -->
<script>
// ── Dati da PHP ────────────────────────────────────────
const parkingData   = <?= json_encode($parkingSpots) ?>;
const occupiedSlots = <?= json_encode($occupiedSlotsByAsset) ?>;
const reopenId      = <?= $reopenAssetId ?>;

// ── Canvas ─────────────────────────────────────────────
const canvas = document.getElementById('parkingCanvas');
const ctx    = canvas.getContext('2d');
const wrap   = document.getElementById('pk-canvas-wrap');

// ── Stato ──────────────────────────────────────────────
let selectedId = null;
let hitboxes   = [];   // {x, y, w, h, spot}

// ════════════════════════════════════════════════════════
// COSTANTI DISEGNO
// ════════════════════════════════════════════════════════
const C = {
    asphalt:     '#1c1f27',
    asphaltLane: '#232630',
    lineWhite:   'rgba(255,255,255,0.55)',
    lineYellow:  '#f5c518',
    dashLine:    'rgba(245,197,24,0.55)',
    avail:       'rgba(74,222,128,0.22)',
    availBrd:    '#4ade80',
    occ:         'rgba(248,113,113,0.25)',
    occBrd:      '#f87171',
    sel:         'rgba(129,140,248,0.35)',
    selBrd:      '#818cf8',
    empty:       'rgba(255,255,255,0.04)',
    emptyBrd:    'rgba(255,255,255,0.18)',
    textMain:    '#ffffff',
    textMuted:   'rgba(255,255,255,0.45)',
    entrata:     '#f5c518',
    SPACE_W: 74,
    SPACE_H: 118,
    GAP:      2,
    LANE_H:  88,
    MX:      52,   // margin orizzontale
    MY:      36,   // margin verticale
    MAX_ROW:  8,   // max stalli per fila
};

// ════════════════════════════════════════════════════════
// LAYOUT
// ════════════════════════════════════════════════════════
function computeLayout() {
    const W = canvas.width;
    const availW = W - 2 * C.MX;
    const perRow = Math.max(1, Math.min(C.MAX_ROW, Math.floor(availW / (C.SPACE_W + C.GAP))));
    const n      = parkingData.length;
    const nRows  = Math.ceil(n / perRow);

    // Centra le file
    const rowUsedW = perRow * C.SPACE_W + (perRow - 1) * C.GAP;
    const startX   = C.MX + Math.max(0, (availW - rowUsedW) / 2);

    // Y di partenza per ogni fila
    const rowY = [];
    let y = C.MY;
    for (let r = 0; r < nRows; r++) {
        rowY.push(y);
        y += C.SPACE_H;
        if (r < nRows - 1) y += C.LANE_H;
    }

    // Altezza totale canvas
    const needH = y + C.MY;
    if (canvas.height !== needH) canvas.height = Math.max(280, needH);

    return { perRow, startX, rowY, nRows, n };
}

// ════════════════════════════════════════════════════════
// DISEGNO PRINCIPALE
// ════════════════════════════════════════════════════════
function draw() {
    const W = canvas.width, H = canvas.height;
    const layout = computeLayout();

    ctx.clearRect(0, 0, W, H);

    // Sfondo asfalto
    ctx.fillStyle = C.asphalt;
    ctx.fillRect(0, 0, W, H);

    // Texture asfalto (grana sottile)
    drawAsphaltTexture(W, H);

    // Corsie (prima delle file, così gli stalli vengono sopra)
    for (let r = 0; r < layout.nRows - 1; r++) {
        const laneY = layout.rowY[r] + C.SPACE_H;
        drawLane(layout.startX, laneY, layout.perRow * C.SPACE_W + (layout.perRow - 1) * C.GAP, C.LANE_H, r);
    }

    // File di stalli
    hitboxes = [];
    for (let r = 0; r < layout.nRows; r++) {
        const startIdx = r * layout.perRow;
        const rowSpots = parkingData.slice(startIdx, startIdx + layout.perRow);
        const facing   = (r % 2 === 0) ? 'down' : 'up';
        drawRow(rowSpots, layout.startX, layout.rowY[r], facing, layout.perRow);
    }

    // Indicatori entrata/uscita
    if (layout.nRows > 0) {
        const laneY = layout.nRows > 1
            ? layout.rowY[0] + C.SPACE_H
            : layout.rowY[0] + C.SPACE_H / 2 - C.LANE_H / 2;
        drawEntrance(W, laneY, C.LANE_H);
    }
}

// ── Texture asfalto ────────────────────────────────────
function drawAsphaltTexture(W, H) {
    ctx.save();
    ctx.globalAlpha = 0.025;
    for (let i = 0; i < 800; i++) {
        const x = Math.random() * W, y = Math.random() * H;
        const r = Math.random() * 1.2;
        ctx.fillStyle = Math.random() > 0.5 ? '#fff' : '#000';
        ctx.beginPath(); ctx.arc(x, y, r, 0, Math.PI * 2); ctx.fill();
    }
    ctx.restore();
}

// ── Corsia tra due file ────────────────────────────────
function drawLane(startX, laneY, rowUsedW, laneH, rowIdx) {
    const W = canvas.width;

    // Sfondo corsia (leggermente più chiaro dell'asfalto)
    ctx.fillStyle = C.asphaltLane;
    ctx.fillRect(0, laneY, W, laneH);

    // Linea tratteggiata centrale gialla
    const cy = laneY + laneH / 2;
    ctx.strokeStyle = C.dashLine;
    ctx.lineWidth   = 1.5;
    ctx.setLineDash([18, 14]);
    ctx.beginPath();
    ctx.moveTo(C.MX, cy);
    ctx.lineTo(W - C.MX, cy);
    ctx.stroke();
    ctx.setLineDash([]);

    // Frecce di direzione (alternano destra/sinistra per corsie diverse)
    const goRight = rowIdx % 2 === 0;
    const arrowCount = Math.max(2, Math.floor((W - 2 * C.MX) / 200));
    for (let i = 1; i <= arrowCount; i++) {
        const ax = C.MX + i * (W - 2 * C.MX) / (arrowCount + 1);
        drawArrow(ax, cy, goRight ? 0 : Math.PI);
    }

    // Linee gialle laterali della corsia (bordi con asfalto)
    ctx.strokeStyle = C.lineYellow;
    ctx.lineWidth   = 2;
    ctx.globalAlpha = 0.6;
    ctx.beginPath();
    ctx.moveTo(C.MX - 1, laneY);
    ctx.lineTo(W - C.MX + 1, laneY);
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(C.MX - 1, laneY + laneH);
    ctx.lineTo(W - C.MX + 1, laneY + laneH);
    ctx.stroke();
    ctx.globalAlpha = 1;
}

// ── Freccia direzionale ────────────────────────────────
function drawArrow(x, y, angle) {
    ctx.save();
    ctx.translate(x, y);
    ctx.rotate(angle);
    ctx.fillStyle = 'rgba(255,255,255,0.12)';
    ctx.beginPath();
    ctx.moveTo(14, 0);
    ctx.lineTo(-8, -8);
    ctx.lineTo(-4, 0);
    ctx.lineTo(-8, 8);
    ctx.closePath();
    ctx.fill();
    ctx.restore();
}

// ── Indicatore entrata/uscita ─────────────────────────
function drawEntrance(W, laneY, laneH) {
    const eW = 44, eH = laneH * 0.5, eX = W - C.MX + 4, eY = laneY + (laneH - eH) / 2;

    ctx.fillStyle = C.entrata;
    ctx.globalAlpha = 0.9;
    // Triangolo a freccia "entrata →"
    ctx.beginPath();
    ctx.moveTo(eX, eY);
    ctx.lineTo(eX + eW * 0.55, eY + eH / 2);
    ctx.lineTo(eX, eY + eH);
    ctx.closePath();
    ctx.fill();
    ctx.globalAlpha = 1;

    ctx.fillStyle = C.entrata;
    ctx.font = 'bold 8px system-ui';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';
    ctx.fillText('IN/OUT', eX + eW * 0.65, eY + eH / 2);
}

// ── Fila di stalli ─────────────────────────────────────
function drawRow(rowSpots, startX, rowY, facing, perRow) {
    const rowUsedW = perRow * C.SPACE_W + (perRow - 1) * C.GAP;

    // Muro posteriore (linea gialla piena)
    ctx.strokeStyle = C.lineYellow;
    ctx.lineWidth   = 3;
    ctx.globalAlpha = 0.7;
    ctx.beginPath();
    if (facing === 'down') {
        ctx.moveTo(startX, rowY);
        ctx.lineTo(startX + rowUsedW, rowY);
    } else {
        ctx.moveTo(startX, rowY + C.SPACE_H);
        ctx.lineTo(startX + rowUsedW, rowY + C.SPACE_H);
    }
    ctx.stroke();
    ctx.globalAlpha = 1;

    // Stalli
    rowSpots.forEach((spot, i) => {
        const sx  = startX + i * (C.SPACE_W + C.GAP);
        const sel = spot && spot.id === selectedId;
        drawSpace(sx, rowY, spot, facing, sel);
        if (spot) hitboxes.push({ x: sx, y: rowY, w: C.SPACE_W, h: C.SPACE_H, spot });
    });

    // Linee divisorie tra stalli (linee bianche verticali)
    ctx.strokeStyle = C.lineWhite;
    ctx.lineWidth   = 1.5;
    for (let i = 0; i <= rowSpots.length; i++) {
        const lx = startX + i * (C.SPACE_W + C.GAP) - (i > 0 ? C.GAP : 0);
        ctx.beginPath();
        ctx.moveTo(lx, rowY);
        ctx.lineTo(lx, rowY + C.SPACE_H);
        ctx.stroke();
    }
}

// ── Singolo stallo ─────────────────────────────────────
function drawSpace(x, y, spot, facing, isSelected) {
    const isOcc   = spot && spot.status.toLowerCase() === 'occupato';
    const isEmpty = !spot;

    // Fill colorato
    if (isSelected) {
        ctx.fillStyle = C.sel;
    } else if (isEmpty) {
        ctx.fillStyle = C.empty;
    } else {
        ctx.fillStyle = isOcc ? C.occ : C.avail;
    }
    ctx.fillRect(x, y, C.SPACE_W, C.SPACE_H);

    // Bordo selezione
    if (isSelected) {
        ctx.strokeStyle = C.selBrd;
        ctx.lineWidth   = 2.5;
        ctx.strokeRect(x + 1.25, y + 1.25, C.SPACE_W - 2.5, C.SPACE_H - 2.5);
    }

    if (isEmpty) return;

    // ── Contenuto stallo ────────────────────────────────
    const cx = x + C.SPACE_W / 2;
    const cy = y + C.SPACE_H / 2;

    // Cerchio status
    const circR = 16;
    const circY = facing === 'down' ? y + C.SPACE_H * 0.32 : y + C.SPACE_H * 0.68;

    ctx.fillStyle = isOcc ? '#ef4444' : '#22c55e';
    ctx.beginPath(); ctx.arc(cx, circY, circR, 0, Math.PI * 2); ctx.fill();

    // Alone cerchio
    ctx.fillStyle = isOcc ? 'rgba(239,68,68,0.2)' : 'rgba(34,197,94,0.2)';
    ctx.beginPath(); ctx.arc(cx, circY, circR + 5, 0, Math.PI * 2); ctx.fill();

    // Icona dentro cerchio
    ctx.fillStyle = '#ffffff';
    ctx.font = 'bold 14px system-ui';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(isOcc ? '✕' : 'P', cx, circY);

    // Codice stallo
    const code = spot.name.replace(/Parcheggio\s*/i, '').trim() || spot.name;
    ctx.fillStyle = isSelected ? '#c7d2fe' : C.textMain;
    ctx.font = 'bold 11px system-ui';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    const labelY = facing === 'down'
        ? y + C.SPACE_H * 0.62
        : y + C.SPACE_H * 0.38;
    ctx.fillText(code, cx, labelY);

    // Numero posto (piccolo, sotto il codice)
    if (spot.numero_posto && spot.numero_posto !== '-') {
        ctx.fillStyle = C.textMuted;
        ctx.font = '9px system-ui';
        const numY = facing === 'down' ? labelY + 13 : labelY - 13;
        ctx.fillText('#' + spot.numero_posto, cx, numY);
    }

    // Icone (coperto, colonnina) — fila in basso/alto
    const icons = [];
    if (spot.coperto == 1)             icons.push('🏠');
    if (spot.colonnina_elettrica == 1) icons.push('⚡');

    if (icons.length > 0) {
        ctx.font = '11px system-ui';
        const iconsStr = icons.join(' ');
        const iconY = facing === 'down'
            ? y + C.SPACE_H - 11
            : y + 11;
        ctx.fillText(iconsStr, cx, iconY);
    }

    // Numero stallo (angolo in basso/alto a sinistra, discreto)
    if (isSelected) {
        ctx.fillStyle = 'rgba(129,140,248,0.8)';
        ctx.font = '600 9px system-ui';
        ctx.textAlign = 'left';
        ctx.textBaseline = facing === 'down' ? 'bottom' : 'top';
        ctx.fillText('▶ selezionato', x + 6, facing === 'down' ? y + C.SPACE_H - 3 : y + 3);
    }
}

// ════════════════════════════════════════════════════════
// INTERAZIONI
// ════════════════════════════════════════════════════════
canvas.addEventListener('click', function (e) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width  / rect.width;
    const scaleY = canvas.height / rect.height;
    const mx = (e.clientX - rect.left) * scaleX;
    const my = (e.clientY - rect.top)  * scaleY;

    let hit = null;
    for (const box of hitboxes) {
        if (mx >= box.x && mx <= box.x + box.w && my >= box.y && my <= box.y + box.h) {
            hit = box; break;
        }
    }

    if (hit) {
        selectedId = hit.spot.id;
        openParkingPanel(hit.spot);
    } else {
        selectedId = null;
        closeParkingPanel();
    }
    draw();
});

canvas.addEventListener('mousemove', function (e) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width  / rect.width;
    const scaleY = canvas.height / rect.height;
    const mx = (e.clientX - rect.left) * scaleX;
    const my = (e.clientY - rect.top)  * scaleY;
    canvas.style.cursor = hitboxes.some(
        b => mx >= b.x && mx <= b.x + b.w && my >= b.y && my <= b.y + b.h
    ) ? 'pointer' : 'default';
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { selectedId = null; closeParkingPanel(); draw(); }
});

// ════════════════════════════════════════════════════════
// PANNELLO LATERALE
// ════════════════════════════════════════════════════════
function openParkingPanel(spot) {
    document.getElementById('pk-backdrop').classList.add('visible');

    const isOcc = spot.status.toLowerCase() === 'occupato';

    // Titolo + badge stato
    document.getElementById('panel-title').textContent = spot.name;
    document.getElementById('panel-status-pill').innerHTML =
        `<span class="pk-status-pill ${isOcc ? 'pk-status--occ' : 'pk-status--avail'}">
            ${isOcc ? 'Occupato' : 'Disponibile'}
         </span>`;

    // Info tiles
    document.getElementById('panel-info-grid').innerHTML = `
        <div class="pk-info-tile">
            <span class="pk-info-tile-label">N° Posto</span>
            <span class="pk-info-tile-val">${spot.numero_posto}</span>
        </div>
        <div class="pk-info-tile">
            <span class="pk-info-tile-label">Posizione</span>
            <span class="pk-info-tile-val">${spot.posizione}</span>
        </div>
        <div class="pk-info-tile pk-info-tile--icon">
            <span class="pk-info-tile-label">Coperto</span>
            <span class="pk-info-tile-val">${spot.coperto == 1 ? '<span class="pk-tile-yes">🏠 Sì</span>' : '<span class="pk-tile-no">No</span>'}</span>
        </div>
        <div class="pk-info-tile pk-info-tile--icon">
            <span class="pk-info-tile-label">Colonnina</span>
            <span class="pk-info-tile-val">${spot.colonnina_elettrica == 1 ? '<span class="pk-tile-yes">⚡ Sì</span>' : '<span class="pk-tile-no">No</span>'}</span>
        </div>`;

    // Timeline oggi
    document.getElementById('panel-timeline').innerHTML = renderTimeline(spot.id);

    // Prossimi slot liberi
    document.getElementById('panel-free-slots').innerHTML = renderFreeSlots(spot.id);

    // Collega id_asset al form
    document.getElementById('panel-asset-id').value = spot.id;

    // Apri pannello e azzera form
    document.getElementById('side-panel').classList.add('open');
    document.getElementById('data-inizio').value = '';
    document.getElementById('data-fine').value   = '';
    document.getElementById('pk-duration-preview').style.display = 'none';
    document.getElementById('pk-form-error').style.display       = 'none';
    document.getElementById('submit-btn').disabled = false;

    // Impedisci selezione di date passate
    const minVal = toLocalISO(new Date());
    document.getElementById('data-inizio').min = minVal;
    document.getElementById('data-fine').min   = minVal;
}

function closeParkingPanel() {
    document.getElementById('side-panel').classList.remove('open');
    document.getElementById('pk-backdrop').classList.remove('visible');
    selectedId = null;
    draw();
}

// ════════════════════════════════════════════════════════
// TIMELINE OGGI
// ════════════════════════════════════════════════════════
function renderTimeline(spotId) {
    const now      = new Date();
    const dayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0, 0);
    const dayEnd   = new Date(dayStart.getTime() + 86400000);
    const dayMs    = 86400000;

    const rawSlots = (occupiedSlots[spotId] || [])
        .map(s => ({ start: new Date(s.inizio), end: new Date(s.fine) }))
        .filter(s => s.end > dayStart && s.start < dayEnd)
        .sort((a, b) => a.start - b.start);

    // Costruisci segmenti libero/occupato
    const segs = [];
    let ptr = dayStart.getTime();
    for (const occ of rawSlots) {
        const oS = Math.max(occ.start.getTime(), dayStart.getTime());
        const oE = Math.min(occ.end.getTime(),   dayEnd.getTime());
        if (oS > ptr) segs.push({ type: 'free', start: ptr, end: oS });
        segs.push({ type: 'occ', start: oS, end: oE });
        ptr = oE;
    }
    if (ptr < dayEnd.getTime()) segs.push({ type: 'free', start: ptr, end: dayEnd.getTime() });

    const bars = segs.map(s => {
        const pct = ((s.end - s.start) / dayMs * 100).toFixed(2);
        return `<div class="pk-tl-seg pk-tl-seg--${s.type}" style="width:${pct}%"
                     title="${s.type === 'occ' ? 'Occupato' : 'Libero'}: ${fmtTime(new Date(s.start))} – ${fmtTime(new Date(s.end))}">
                </div>`;
    }).join('');

    // Indicatore ora attuale
    const nowPct = ((now - dayStart) / dayMs * 100).toFixed(2);
    const nowMarker = nowPct >= 0 && nowPct <= 100
        ? `<div class="pk-tl-now" style="left:${nowPct}%">
               <span class="pk-tl-now-tip">ora</span>
           </div>`
        : '';

    // Etichette ore
    const labels = [0, 6, 12, 18, 24].map(h => {
        const pct = (h / 24 * 100).toFixed(1);
        return `<span class="pk-tl-label" style="left:${pct}%">${String(h).padStart(2,'0')}:00</span>`;
    }).join('');

    // Riepilogo testuale
    const todayOcc = rawSlots.length;
    const summary = todayOcc === 0
        ? `<span class="pk-tl-summary pk-tl-summary--free">Stallo libero per tutta la giornata</span>`
        : `<span class="pk-tl-summary pk-tl-summary--occ">${todayOcc} prenotazion${todayOcc > 1 ? 'i' : 'e'} oggi</span>`;

    return `
        <div class="pk-timeline">
            <div class="pk-tl-wrap">
                <div class="pk-tl-bar">${bars}</div>
                ${nowMarker}
                <div class="pk-tl-labels">${labels}</div>
            </div>
            <div style="margin-top:8px">${summary}</div>
        </div>`;
}

// ════════════════════════════════════════════════════════
// SLOT LIBERI SUGGERITI
// ════════════════════════════════════════════════════════
function getFreeWindows(spotId) {
    const now = new Date();
    // Arrotonda ai prossimi 15 min
    const cursor = new Date(now);
    cursor.setMinutes(Math.ceil(cursor.getMinutes() / 15) * 15, 0, 0);
    if (cursor <= now) cursor.setMinutes(cursor.getMinutes() + 15);

    const horizon = new Date(cursor.getTime() + 7 * 86400000);

    const occupied = (occupiedSlots[spotId] || [])
        .map(s => ({ start: new Date(s.inizio), end: new Date(s.fine) }))
        .filter(s => s.end > cursor)
        .sort((a, b) => a.start - b.start);

    const windows = [];
    let ptr = new Date(cursor);

    for (const occ of occupied) {
        if (occ.start > ptr) {
            windows.push({ start: new Date(ptr), end: new Date(occ.start) });
        }
        if (occ.end > ptr) ptr = new Date(occ.end);
        if (windows.length >= 4) break;
    }
    if (windows.length < 4 && ptr < horizon) {
        windows.push({ start: new Date(ptr), end: horizon });
    }
    return windows.slice(0, 4);
}

function renderFreeSlots(spotId) {
    const windows = getFreeWindows(spotId);

    if (windows.length === 0) {
        return `<div class="pk-no-slots">Nessun periodo libero nei prossimi 7 giorni</div>`;
    }

    return windows.map((fw, i) => {
        const durMs   = fw.end - fw.start;
        const durH    = Math.floor(durMs / 3600000);
        const durM    = Math.floor((durMs % 3600000) / 60000);
        const isLong  = durMs > 86400000;

        const durLabel = isLong
            ? 'Più di 1 giorno'
            : durH > 0 ? `${durH}h${durM > 0 ? ' ' + durM + 'm' : ''}` : `${durM}m`;

        const endLabel = isLong
            ? fmtDay(fw.end) + ' e oltre'
            : fmtTime(fw.end);

        // Suggerisci max 4h come durata precompilata (o fino alla fine se < 4h)
        const suggestEnd = new Date(Math.min(
            fw.start.getTime() + 4 * 3600000,
            fw.end.getTime()
        ));

        return `
            <div class="pk-free-slot" onclick="prefillSlot('${toLocalISO(fw.start)}', '${toLocalISO(suggestEnd)}')">
                <div class="pk-fs-left">
                    <span class="pk-fs-day">${fmtDay(fw.start)}</span>
                    <span class="pk-fs-range">${fmtTime(fw.start)} → ${endLabel}</span>
                </div>
                <div class="pk-fs-right">
                    <span class="pk-fs-dur">${durLabel}</span>
                    <span class="pk-fs-cta">Prenota →</span>
                </div>
            </div>`;
    }).join('');
}

function prefillSlot(startISO, endISO) {
    document.getElementById('data-inizio').value = startISO;
    document.getElementById('data-fine').value   = endISO;
    updateDuration();
    document.getElementById('booking-form').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// ════════════════════════════════════════════════════════
// FORM — durata + controllo conflitti
// ════════════════════════════════════════════════════════
function hasConflict(startVal, endVal, spotId) {
    if (!startVal || !endVal || !spotId) return false;
    const s = new Date(startVal), e = new Date(endVal);
    return (occupiedSlots[spotId] || []).some(sl => {
        return s < new Date(sl.fine) && e > new Date(sl.inizio);
    });
}

function updateDuration() {
    const startVal = document.getElementById('data-inizio').value;
    const endVal   = document.getElementById('data-fine').value;
    const preview  = document.getElementById('pk-duration-preview');
    const error    = document.getElementById('pk-form-error');
    const btn      = document.getElementById('submit-btn');
    const spotId   = parseInt(document.getElementById('panel-asset-id').value);

    if (!startVal || !endVal) {
        preview.style.display = 'none';
        error.style.display   = 'none';
        btn.disabled = false;
        return;
    }

    const ms   = new Date(endVal) - new Date(startVal);
    const days = Math.floor(ms / 86400000);
    const hrs  = Math.floor((ms % 86400000) / 3600000);
    const mins = Math.floor((ms % 3600000) / 60000);

    // Aggiorna min della data fine in modo che non preceda l'inizio
    if (startVal) {
        document.getElementById('data-fine').min = startVal;
    }

    const now = new Date();
    if (new Date(startVal) < now) {
        preview.style.display = 'none';
        error.style.display   = '';
        error.innerHTML = '⚠️ Non puoi prenotare nel passato.';
        btn.disabled = true;
        return;
    }

    if (ms <= 0) {
        preview.style.display = 'none';
        error.style.display   = '';
        error.innerHTML = '⚠️ La data di fine deve essere successiva alla data di inizio.';
        btn.disabled = true;
        return;
    }

    if (hasConflict(startVal, endVal, spotId)) {
        preview.style.display = 'none';
        error.style.display   = '';
        error.innerHTML = `⚠️ Questo periodo si sovrappone a una prenotazione esistente.<br>
            <small>Scegli uno dei periodi liberi suggeriti sopra.</small>`;
        btn.disabled = true;
        return;
    }

    error.style.display = 'none';
    btn.disabled = false;

    const parts = [];
    if (days > 0) parts.push(`${days} giorn${days > 1 ? 'i' : 'o'}`);
    if (hrs  > 0) parts.push(`${hrs} or${hrs > 1 ? 'e' : 'a'}`);
    if (mins > 0) parts.push(`${mins} minut${mins > 1 ? 'i' : 'o'}`);
    const durText = parts.join(' ') || 'meno di un minuto';

    preview.style.display = '';
    preview.innerHTML = `<span class="pk-dur-icon">⏱️</span> <strong>${durText}</strong> — ${fmtDateTime(new Date(startVal))} → ${fmtDateTime(new Date(endVal))}`;
}

// ════════════════════════════════════════════════════════
// HELPERS DATA/ORA
// ════════════════════════════════════════════════════════
function toLocalISO(date) {
    const p = n => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${p(date.getMonth()+1)}-${p(date.getDate())}T${p(date.getHours())}:${p(date.getMinutes())}`;
}

function fmtDay(date) {
    const now = new Date();
    const t   = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const d   = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const diff = Math.round((d - t) / 86400000);
    if (diff === 0) return 'Oggi';
    if (diff === 1) return 'Domani';
    if (diff === 2) return 'Dopodomani';
    return date.toLocaleDateString('it-IT', { weekday: 'short', day: '2-digit', month: 'short' });
}

function fmtTime(date) {
    return date.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
}

function fmtDateTime(date) {
    return date.toLocaleString('it-IT', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

function fmtDate(str) {
    return new Date(str).toLocaleString('it-IT', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
}

// ════════════════════════════════════════════════════════
// RESIZE & INIT
// ════════════════════════════════════════════════════════
function resize() {
    canvas.width = wrap.clientWidth;
    draw();
}

const ro = new ResizeObserver(resize);
ro.observe(wrap);
resize();
updateDuration();

// Riapri pannello dopo POST
if (reopenId) {
    const spot = parkingData.find(s => s.id == reopenId);
    if (spot) {
        selectedId = reopenId;
        setTimeout(() => { openParkingPanel(spot); draw(); }, 80);
    }
}
</script>

</body>
</html>
