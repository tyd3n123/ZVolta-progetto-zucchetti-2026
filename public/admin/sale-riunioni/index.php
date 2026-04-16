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

    $tsInizio = strtotime($data_inizio);
    $tsFine   = strtotime($data_fine);
    $hInizio  = (int)date('H', $tsInizio) * 60 + (int)date('i', $tsInizio);
    $hFine    = (int)date('H', $tsFine)   * 60 + (int)date('i', $tsFine);

    if (!$id_asset || !$data_inizio || !$data_fine) {
        $feedback = ['type' => 'error', 'msg' => 'Compila tutti i campi prima di procedere.'];
    } elseif ($tsInizio < time()) {
        $feedback = ['type' => 'error', 'msg' => 'Non puoi prenotare nel passato.'];
    } elseif ($tsFine <= $tsInizio) {
        $feedback = ['type' => 'error', 'msg' => 'La data di fine deve essere successiva alla data di inizio.'];
    } elseif (date('Y-m-d', $tsInizio) !== date('Y-m-d', $tsFine)) {
        $feedback = ['type' => 'error', 'msg' => 'La prenotazione deve essere nella stessa giornata (09:00–19:00).'];
    } elseif ($hInizio < 9 * 60) {
        $feedback = ['type' => 'error', 'msg' => 'L\'orario di inizio non può essere prima delle 09:00.'];
    } elseif ($hInizio >= 19 * 60) {
        $feedback = ['type' => 'error', 'msg' => 'L\'orario di inizio non può essere dalle 19:00 in poi.'];
    } elseif ($hFine > 19 * 60) {
        $feedback = ['type' => 'error', 'msg' => 'L\'orario di fine non può superare le 19:00.'];
    } else {
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

// ── Fetch sale riunioni (id_tipologia = 3) ───────────
$roomSpots = [];
$result = $conn->query(
    "SELECT a.id_asset, a.codice_asset, a.piano,
            CASE WHEN EXISTS (
                SELECT 1 FROM prenotazioni pr
                WHERE pr.id_asset = a.id_asset
                  AND NOW() BETWEEN pr.data_inizio AND pr.data_fine
            ) THEN 'Occupato' ELSE 'Disponibile' END AS stato,
            COALESCE(s.capacita, '-')        AS capacita,
            COALESCE(s.attrezzatura, '-')    AS attrezzatura,
            COALESCE(s.orario_apertura, '')  AS orario_apertura,
            COALESCE(s.orario_chiusura, '')  AS orario_chiusura
     FROM asset a
     LEFT JOIN sala_dettagli s ON s.id_asset = a.id_asset
     WHERE a.id_tipologia = 3
     ORDER BY a.piano ASC, a.codice_asset ASC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $disp = ($row['orario_apertura'] && $row['orario_chiusura'])
            ? $row['orario_apertura'] . ' – ' . $row['orario_chiusura']
            : '–';
        $roomSpots[] = [
            'id'           => (int)$row['id_asset'],
            'name'         => $row['codice_asset'],
            'status'       => $row['stato'],
            'piano'        => (int)$row['piano'],
            'capacita'     => $row['capacita'],
            'attrezzatura' => $row['attrezzatura'],
            'disponibilita'=> $disp,
        ];
    }
}

// ── Stats ─────────────────────────────────────────────
$totalRooms = count($roomSpots);
$availCount = count(array_filter($roomSpots, fn($s) => strtolower($s['status']) !== 'occupato'));
$occCount   = $totalRooms - $availCount;

// ── Prenotazioni attive dell'utente ───────────────────
$userBookings = [];
$stmt = $conn->prepare(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine,
            a.codice_asset, a.id_asset
     FROM prenotazioni p
     JOIN asset a ON p.id_asset = a.id_asset
     WHERE p.id_utente = ?
       AND a.id_tipologia = 3
       AND p.data_fine >= NOW()
     ORDER BY p.data_inizio ASC"
);
$stmt->bind_param("i", $id_utente);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $userBookings[] = $row;
$stmt->close();

// ── Slot occupati per tutte le sale ──────────────────
$occupiedSlotsByAsset = [];
$result = $conn->query(
    "SELECT p.id_asset, p.data_inizio, p.data_fine
     FROM prenotazioni p
     JOIN asset a ON p.id_asset = a.id_asset
     WHERE a.id_tipologia = 3
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
            <h2 class="sr-page-title">Sale Riunioni</h2>
            <p class="sr-page-sub">Clicca su una sala nella planimetria per prenotarla</p>
        </div>
    </div>

    <!-- Feedback banner -->
    <?php if ($feedback): ?>
        <div class="sr-feedback sr-feedback--<?= $feedback['type'] ?>">
            <?= $feedback['type'] === 'success' ? '✅' : '⚠️' ?>
            <?= htmlspecialchars($feedback['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- Backdrop sfocato -->
    <div class="sr-panel-backdrop" id="sr-backdrop" onclick="closeRoomPanel()"></div>

    <!-- ══ MAPPA ═══════════════════════════════════════ -->
    <div class="sr-map-layout" id="map-layout">

        <!-- ── Zona mappa canvas ───────────────────────── -->
        <div class="sr-map-zone" id="map-zone">

            <div class="sr-map-header">
                <p class="sr-map-title">Planimetria Sale Riunioni — Sede</p>
                <div class="sr-map-legend">
                    <div class="sr-legend-item">
                        <span class="sr-legend-dot sr-legend-dot--avail"></span>Disponibile
                    </div>
                    <div class="sr-legend-item">
                        <span class="sr-legend-dot sr-legend-dot--occ"></span>Occupata
                    </div>
                </div>
            </div>

            <!-- Stats bar -->
            <div class="sr-map-stats">
                <span class="sr-stat-chip sr-stat-chip--total"><?= $totalRooms ?> sale</span>
                <span class="sr-stat-chip sr-stat-chip--avail">✓ <?= $availCount ?> disponibili</span>
                <?php if ($occCount > 0): ?>
                    <span class="sr-stat-chip sr-stat-chip--occ">✗ <?= $occCount ?> occupate</span>
                <?php endif; ?>
            </div>

            <!-- Canvas planimetria -->
            <?php if (empty($roomSpots)): ?>
                <div class="sr-empty">
                    <span></span>
                    <p>Nessuna sala riunione disponibile</p>
                </div>
            <?php else: ?>
                <div class="sr-canvas-wrap" id="sr-canvas-wrap">
                    <canvas id="floorCanvas"></canvas>
                </div>
            <?php endif; ?>

        </div><!-- /.sr-map-zone -->

    </div><!-- /.sr-map-layout -->

    <!-- Pannello laterale fixed overlay -->
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
                </div>

                <div class="sr-panel-sep"></div>

                <!-- Timeline oggi -->
                <div>
                    <p class="sr-panel-section-title">Disponibilità oggi (09:00–19:00)</p>
                    <div id="panel-timeline"></div>
                </div>

                <div class="sr-panel-sep"></div>

                <!-- Prossimi slot liberi -->
                <div>
                    <p class="sr-panel-section-title">Prossimi periodi liberi</p>
                    <p style="font-size:11px;color:var(--clr-text-3);margin:0 0 10px">Clicca un periodo per precompilare le date</p>
                    <div id="panel-free-slots"></div>
                </div>

                <div class="sr-panel-sep"></div>

                <!-- Form prenotazione -->
                <div>
                    <p class="sr-panel-section-title">Prenota</p>
                    <form method="POST" id="booking-form">
                        <input type="hidden" name="action"   value="book">
                        <input type="hidden" name="id_asset" id="panel-asset-id" value="">

                        <div class="sr-field" style="margin-bottom:10px">
                            <label class="sr-field-lbl">Data Inizio</label>
                            <input class="sr-field-input" type="datetime-local" id="data-inizio" name="data_inizio"
                                   value="<?= htmlspecialchars($_POST['data_inizio'] ?? '') ?>"
                                   required onchange="updateDuration()">
                        </div>
                        <div class="sr-field" style="margin-bottom:10px">
                            <label class="sr-field-lbl">Data Fine</label>
                            <input class="sr-field-input" type="datetime-local" id="data-fine" name="data_fine"
                                   value="<?= htmlspecialchars($_POST['data_fine'] ?? '') ?>"
                                   required onchange="updateDuration()">
                        </div>

                        <div id="sr-duration-preview" class="sr-duration-preview" style="display:none;margin-bottom:10px"></div>
                        <div id="sr-form-error"       class="sr-form-error"       style="display:none;margin-bottom:10px"></div>

                        <button type="submit" class="sr-submit-btn" id="submit-btn">
                            Conferma Prenotazione
                        </button>
                    </form>
                </div>

            </div><!-- /.sr-panel-body -->
    </div><!-- /.sr-side-panel -->

    <!-- ── Le tue prenotazioni attive ─────────────────── -->
    <div class="sr-bookings-strip">
        <div class="sr-strip-header">
            <p class="sr-strip-title">Le tue prenotazioni attive</p>
            <span class="sr-count-badge"><?= count($userBookings) ?></span>
        </div>

        <?php if (empty($userBookings)): ?>
            <div class="sr-empty">
                <span></span>
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
                        $durStr = $diff->days > 0 ? "{$diff->days}g {$diff->h}h" : "{$diff->h}h {$diff->i}m";
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

<!-- ═══════════════════════════════════════════════════════
     JAVASCRIPT — Canvas planimetria sale riunioni
     ═══════════════════════════════════════════════════════ -->
<script>
// ── Dati da PHP ────────────────────────────────────────
const roomsData      = <?= json_encode(array_values($roomSpots)) ?>;
const occupiedSlots  = <?= json_encode($occupiedSlotsByAsset) ?>;
const reopenAssetId  = <?= $reopenAssetId ?>;

// ── Canvas setup ───────────────────────────────────────
const canvas = document.getElementById('floorCanvas');
const ctx    = canvas.getContext('2d');
const wrap   = document.getElementById('sr-canvas-wrap');

// ── Stato ──────────────────────────────────────────────
let selectedId = null;
let hitboxes   = [];  // {x, y, w, h, room}

// ════════════════════════════════════════════════════════
// COSTANTI DISEGNO
// ════════════════════════════════════════════════════════
const C = {
    // Colori edificio
    bg:          '#f0f2f5',
    wall:        '#c8cdd6',
    wallDark:    '#9aa0ae',
    floorBg:     '#e8ebf0',
    floorStripe: '#dde1e8',
    corridor:    '#d0d4dc',
    corridorLine:'#bbbfc9',

    // Colori sale
    avail:       'rgba(34,197,94,0.18)',
    availBrd:    '#16a34a',
    availFill:   '#dcfce7',
    occ:         'rgba(239,68,68,0.15)',
    occBrd:      '#dc2626',
    occFill:     '#fee2e2',
    sel:         'rgba(91,79,207,0.18)',
    selBrd:      '#5b4fcf',
    selFill:     '#ede9fe',

    // Testo
    textDark:    '#1e1e2e',
    textMid:     '#4a5568',
    textLight:   '#718096',
    textWhite:   '#ffffff',

    // Floor label
    floorLabelBg: '#2d3748',
    floorLabelTx: '#ffffff',

    // Dimensioni sala
    ROOM_W:     160,
    ROOM_H:     110,
    ROOM_GAP:    40,   // gap tra sale sulla stessa fila
    FLOOR_PAD_X: 80,   // padding laterale dentro il piano
    FLOOR_PAD_Y: 28,   // padding top/bottom dentro il piano
    LABEL_W:     64,   // larghezza etichetta piano a sinistra
    CORRIDOR_H:  36,   // altezza corridoio tra piano e sala
    FLOOR_HDR:   32,   // altezza header "Piano N"
};

// ════════════════════════════════════════════════════════
// RAGGRUPPA SALE PER PIANO
// ════════════════════════════════════════════════════════
function groupByFloor() {
    const floors = {};
    roomsData.forEach(r => {
        const p = r.piano || 1;
        if (!floors[p]) floors[p] = [];
        floors[p].push(r);
    });
    return floors;
}

// ════════════════════════════════════════════════════════
// LAYOUT
// ════════════════════════════════════════════════════════
function computeLayout(W) {
    const floors     = groupByFloor();
    const floorKeys  = Object.keys(floors).map(Number).sort((a,b) => a-b);
    const innerW     = W - C.LABEL_W;

    // Per ogni piano calcola altezza necessaria
    const floorLayouts = [];
    let totalH = 16; // top padding

    for (const fp of floorKeys) {
        const rooms    = floors[fp];
        const nRooms   = rooms.length;
        const usedW    = nRooms * C.ROOM_W + (nRooms - 1) * C.ROOM_GAP;
        const startX   = C.LABEL_W + C.FLOOR_PAD_X + Math.max(0, (innerW - 2 * C.FLOOR_PAD_X - usedW) / 2);
        const floorH   = C.FLOOR_HDR + C.CORRIDOR_H + C.ROOM_H + C.FLOOR_PAD_Y * 2;

        floorLayouts.push({
            piano: fp,
            rooms,
            startX,
            roomY: totalH + C.FLOOR_HDR + C.FLOOR_PAD_Y + C.CORRIDOR_H,
            floorY: totalH,
            floorH,
        });

        totalH += floorH + 12; // 12px gap tra piani
    }

    totalH += 16; // bottom padding
    return { floorLayouts, totalH };
}

// ════════════════════════════════════════════════════════
// DISEGNO
// ════════════════════════════════════════════════════════
function draw() {
    const W = canvas.width;
    const layout = computeLayout(W);

    if (canvas.height !== layout.totalH) {
        canvas.height = Math.max(400, layout.totalH);
    }
    const H = canvas.height;

    ctx.clearRect(0, 0, W, H);

    // Sfondo generale
    ctx.fillStyle = C.bg;
    ctx.fillRect(0, 0, W, H);

    // Texture sottile sfondo
    drawBgTexture(W, H);

    hitboxes = [];

    for (const fl of layout.floorLayouts) {
        drawFloor(fl, W);
    }
}

// ── Texture sfondo ─────────────────────────────────────
function drawBgTexture(W, H) {
    ctx.save();
    ctx.globalAlpha = 0.04;
    ctx.strokeStyle = '#94a3b8';
    ctx.lineWidth = 0.5;
    const step = 28;
    for (let x = 0; x < W; x += step) {
        ctx.beginPath(); ctx.moveTo(x, 0); ctx.lineTo(x, H); ctx.stroke();
    }
    for (let y = 0; y < H; y += step) {
        ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(W, y); ctx.stroke();
    }
    ctx.restore();
}

// ── Disegna un piano ──────────────────────────────────
function drawFloor(fl, W) {
    const { piano, rooms, startX, roomY, floorY, floorH } = fl;

    // ── Contenitore piano (rettangolo arrotondato) ─────
    roundRect(ctx, C.LABEL_W + 8, floorY, W - C.LABEL_W - 16, floorH, 10);
    ctx.fillStyle = C.floorBg;
    ctx.fill();
    ctx.strokeStyle = C.wall;
    ctx.lineWidth = 1.5;
    ctx.stroke();

    // ── Etichetta piano a sinistra ─────────────────────
    roundRect(ctx, 4, floorY, C.LABEL_W - 8, floorH, 8);
    ctx.fillStyle = C.floorLabelBg;
    ctx.fill();

    ctx.fillStyle = C.floorLabelTx;
    ctx.font = 'bold 11px system-ui';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText('PIANO', 4 + (C.LABEL_W - 8) / 2, floorY + floorH / 2 - 9);
    ctx.font = 'bold 22px system-ui';
    ctx.fillText(piano, 4 + (C.LABEL_W - 8) / 2, floorY + floorH / 2 + 12);

    // ── Header "Piano N" nella zona destra ────────────
    const hdrY = floorY;
    ctx.fillStyle = C.wall;
    ctx.fillRect(C.LABEL_W + 8, hdrY, W - C.LABEL_W - 16, C.FLOOR_HDR);

    // Linea decorativa sinistra
    ctx.fillStyle = C.wallDark;
    ctx.fillRect(C.LABEL_W + 8, hdrY, 4, C.FLOOR_HDR);

    ctx.fillStyle = C.textDark;
    ctx.font = 'bold 12px system-ui';
    ctx.textAlign = 'left';
    ctx.textBaseline = 'middle';
    ctx.fillText(`Piano ${piano} — Sale Riunioni`, C.LABEL_W + 22, hdrY + C.FLOOR_HDR / 2);

    // Contatore sale piano
    ctx.textAlign = 'right';
    ctx.fillStyle = C.textLight;
    ctx.font = '11px system-ui';
    ctx.fillText(`${rooms.length} sal${rooms.length > 1 ? 'e' : 'a'}`, W - 28, hdrY + C.FLOOR_HDR / 2);

    // ── Corridoio ──────────────────────────────────────
    const corrY = floorY + C.FLOOR_HDR;
    ctx.fillStyle = C.corridor;
    ctx.fillRect(C.LABEL_W + 8, corrY, W - C.LABEL_W - 16, C.CORRIDOR_H);

    // Linea tratteggiata centrale corridoio
    const cy = corrY + C.CORRIDOR_H / 2;
    ctx.setLineDash([14, 10]);
    ctx.strokeStyle = C.corridorLine;
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(C.LABEL_W + 20, cy);
    ctx.lineTo(W - 20, cy);
    ctx.stroke();
    ctx.setLineDash([]);

    // ── Sale ──────────────────────────────────────────
    rooms.forEach((room, i) => {
        const rx = startX + i * (C.ROOM_W + C.ROOM_GAP);
        const ry = roomY;
        const isSel = room.id === selectedId;
        drawRoom(rx, ry, room, isSel);
        hitboxes.push({ x: rx, y: ry, w: C.ROOM_W, h: C.ROOM_H, room });

        // Porta (segmento tra corridoio e sala)
        const doorX = rx + C.ROOM_W / 2;
        const doorTopY = corrY + C.CORRIDOR_H;
        const doorBotY = ry;
        ctx.strokeStyle = C.wallDark;
        ctx.lineWidth = 2;
        ctx.setLineDash([4, 4]);
        ctx.beginPath();
        ctx.moveTo(doorX, doorTopY);
        ctx.lineTo(doorX, doorBotY);
        ctx.stroke();
        ctx.setLineDash([]);

        // Simbolo porta
        ctx.fillStyle = C.wallDark;
        ctx.beginPath();
        ctx.arc(doorX, doorBotY + 4, 4, 0, Math.PI * 2);
        ctx.fill();
    });
}

// ── Singola sala ───────────────────────────────────────
function drawRoom(x, y, room, isSelected) {
    const isOcc = room.status.toLowerCase() === 'occupato';

    // Ombra
    ctx.save();
    ctx.shadowColor   = 'rgba(0,0,0,0.12)';
    ctx.shadowBlur    = 8;
    ctx.shadowOffsetY = 3;

    // Fill sala
    if (isSelected) {
        ctx.fillStyle = C.selFill;
    } else {
        ctx.fillStyle = isOcc ? C.occFill : C.availFill;
    }
    roundRect(ctx, x, y, C.ROOM_W, C.ROOM_H, 8);
    ctx.fill();
    ctx.restore();

    // Bordo sala
    ctx.lineWidth = isSelected ? 2.5 : 1.5;
    ctx.strokeStyle = isSelected ? C.selBrd : (isOcc ? C.occBrd : C.availBrd);
    roundRect(ctx, x, y, C.ROOM_W, C.ROOM_H, 8);
    ctx.stroke();

    // Striscia colorata in alto
    const stripeColor = isSelected ? C.selBrd : (isOcc ? C.occBrd : C.availBrd);
    ctx.fillStyle = stripeColor;
    ctx.beginPath();
    ctx.roundRect(x, y, C.ROOM_W, 6, [8, 8, 0, 0]);
    ctx.fill();

    // ── Contenuto sala ─────────────────────────────────
    const cx = x + C.ROOM_W / 2;

    // Icona status (cerchio)
    const circY = y + 30;
    const circR = 14;
    ctx.fillStyle = isOcc ? '#ef4444' : '#22c55e';
    ctx.beginPath();
    ctx.arc(cx, circY, circR, 0, Math.PI * 2);
    ctx.fill();

    // Alone cerchio
    ctx.fillStyle = isOcc ? 'rgba(239,68,68,0.15)' : 'rgba(34,197,94,0.15)';
    ctx.beginPath();
    ctx.arc(cx, circY, circR + 5, 0, Math.PI * 2);
    ctx.fill();

    // Icona dentro cerchio
    ctx.fillStyle = '#fff';
    ctx.font = 'bold 13px system-ui';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(isOcc ? '✕' : '✓', cx, circY);

    // Nome sala
    ctx.fillStyle = isSelected ? '#3730a3' : C.textDark;
    ctx.font = 'bold 12px system-ui';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(room.name, cx, y + 62);

    // Stato
    ctx.fillStyle = isOcc ? '#dc2626' : '#16a34a';
    ctx.font = '10px system-ui';
    ctx.fillText(isOcc ? 'Occupata' : 'Disponibile', cx, y + 78);

    // Capacità (se disponibile)
    if (room.capacita && room.capacita !== '-') {
        ctx.fillStyle = C.textLight;
        ctx.font = '10px system-ui';
        ctx.fillText('👥 ' + room.capacita + ' posti', cx, y + 94);
    }

    // Hover cursor (visivo: bordo selection glow)
    if (isSelected) {
        ctx.strokeStyle = 'rgba(91,79,207,0.25)';
        ctx.lineWidth = 6;
        roundRect(ctx, x - 2, y - 2, C.ROOM_W + 4, C.ROOM_H + 4, 10);
        ctx.stroke();
    }
}

// ── Helper: rettangolo arrotondato ─────────────────────
function roundRect(c, x, y, w, h, r) {
    if (c.roundRect) {
        c.beginPath();
        c.roundRect(x, y, w, h, r);
    } else {
        c.beginPath();
        c.moveTo(x + r, y);
        c.lineTo(x + w - r, y);
        c.quadraticCurveTo(x + w, y, x + w, y + r);
        c.lineTo(x + w, y + h - r);
        c.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
        c.lineTo(x + r, y + h);
        c.quadraticCurveTo(x, y + h, x, y + h - r);
        c.lineTo(x, y + r);
        c.quadraticCurveTo(x, y, x + r, y);
        c.closePath();
    }
}

// ════════════════════════════════════════════════════════
// RESPONSIVE RESIZE
// ════════════════════════════════════════════════════════
function resize() {
    const wrapW = wrap.clientWidth || 700;
    canvas.width = Math.max(500, wrapW);
    draw();
}

// ════════════════════════════════════════════════════════
// CLICK SUL CANVAS
// ════════════════════════════════════════════════════════
canvas.addEventListener('click', function(e) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width  / rect.width;
    const scaleY = canvas.height / rect.height;
    const mx = (e.clientX - rect.left) * scaleX;
    const my = (e.clientY - rect.top)  * scaleY;

    for (const hb of hitboxes) {
        if (mx >= hb.x && mx <= hb.x + hb.w &&
            my >= hb.y && my <= hb.y + hb.h) {
            openRoomPanel(hb.room.id);
            return;
        }
    }
});

// ── Cursor pointer sull'hover ──────────────────────────
canvas.addEventListener('mousemove', function(e) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width  / rect.width;
    const scaleY = canvas.height / rect.height;
    const mx = (e.clientX - rect.left) * scaleX;
    const my = (e.clientY - rect.top)  * scaleY;

    const onRoom = hitboxes.some(hb =>
        mx >= hb.x && mx <= hb.x + hb.w &&
        my >= hb.y && my <= hb.y + hb.h
    );
    canvas.style.cursor = onRoom ? 'pointer' : 'default';
});

// ════════════════════════════════════════════════════════
// PANNELLO LATERALE
// ════════════════════════════════════════════════════════
function openRoomPanel(id) {
    const room = roomsData.find(r => r.id === id);
    if (!room) return;

    selectedId = id;
    draw();

    const isOcc = room.status.toLowerCase() === 'occupato';

    document.getElementById('panel-title').textContent = room.name;
    document.getElementById('panel-status-pill').innerHTML =
        `<span class="sr-status-pill ${isOcc ? 'sr-status--occ' : 'sr-status--avail'}">
            ${isOcc ? 'Occupata' : 'Disponibile'}
         </span>`;

    const pianoLabel = room.piano ? `Piano ${room.piano}` : '–';
    document.getElementById('panel-info-grid').innerHTML = `
        <div class="sr-info-tile">
            <span class="sr-info-tile-label">Piano</span>
            <span class="sr-info-tile-val">${pianoLabel}</span>
        </div>
        <div class="sr-info-tile">
            <span class="sr-info-tile-label">Capacità</span>
            <span class="sr-info-tile-val">${room.capacita !== '-' ? room.capacita + ' posti' : '–'}</span>
        </div>
        <div class="sr-info-tile">
            <span class="sr-info-tile-label">Attrezzatura</span>
            <span class="sr-info-tile-val" style="font-size:11px">${room.attrezzatura}</span>
        </div>
        <div class="sr-info-tile">
            <span class="sr-info-tile-label">Orario</span>
            <span class="sr-info-tile-val" style="font-size:11px">${room.disponibilita}</span>
        </div>`;

    document.getElementById('panel-timeline').innerHTML   = renderTimeline(id);
    document.getElementById('panel-free-slots').innerHTML = renderFreeSlots(id);

    document.getElementById('panel-asset-id').value = id;
    document.getElementById('data-inizio').value    = '';
    document.getElementById('data-fine').value      = '';
    document.getElementById('sr-duration-preview').style.display = 'none';
    document.getElementById('sr-form-error').style.display       = 'none';
    document.getElementById('submit-btn').disabled = false;

    const minVal = toLocalISO(new Date());
    document.getElementById('data-inizio').min = minVal;
    document.getElementById('data-fine').min   = minVal;

    document.getElementById('sr-backdrop').classList.add('visible');
    document.getElementById('side-panel').classList.add('open');
}

function closeRoomPanel() {
    document.getElementById('side-panel').classList.remove('open');
    document.getElementById('sr-backdrop').classList.remove('visible');
    selectedId = null;
    draw();
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeRoomPanel(); });

// ════════════════════════════════════════════════════════
// TIMELINE OGGI (09:00–19:00)
// ════════════════════════════════════════════════════════
function renderTimeline(roomId) {
    const now      = new Date();
    const dayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate(),  9, 0, 0);
    const dayEnd   = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 19, 0, 0);
    const dayMs    = 10 * 3600000;

    const rawSlots = (occupiedSlots[roomId] || [])
        .map(s => ({ start: new Date(s.inizio), end: new Date(s.fine) }))
        .filter(s => s.end > dayStart && s.start < dayEnd)
        .sort((a, b) => a.start - b.start);

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
        const bg  = s.type === 'occ' ? '#ef444466' : '#22c55e33';
        const brd = s.type === 'occ' ? '#ef4444'   : '#22c55e';
        const tip = (s.type === 'occ' ? 'Occupata' : 'Libera') + ': ' + fmtTime(new Date(s.start)) + ' – ' + fmtTime(new Date(s.end));
        return `<div style="width:${pct}%;height:100%;background:${bg};border-right:1px solid ${brd}" title="${tip}"></div>`;
    }).join('');

    const nowPct = ((now - dayStart) / dayMs * 100).toFixed(2);
    const nowMark = nowPct >= 0 && nowPct <= 100
        ? `<div style="position:absolute;top:-3px;left:${nowPct}%;transform:translateX(-50%);display:flex;flex-direction:column;align-items:center;pointer-events:none;z-index:2">
               <div style="width:2px;height:26px;background:#f59e0b;border-radius:1px"></div>
               <span style="font-size:9px;font-weight:700;color:#f59e0b;margin-top:2px">ora</span></div>` : '';

    const labels = [9, 11, 13, 15, 17, 19].map(h => {
        const pct = ((h - 9) / 10 * 100).toFixed(1);
        return `<span style="position:absolute;left:${pct}%;transform:translateX(-50%);font-size:9px;color:var(--clr-text-3);font-weight:600">${String(h).padStart(2,'0')}:00</span>`;
    }).join('');

    const n = rawSlots.length;
    const summary = n === 0
        ? `<span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:20px;background:var(--clr-success-bg);color:var(--clr-success)">Libera tutto il giorno</span>`
        : `<span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:20px;background:var(--clr-danger-bg);color:var(--clr-danger)">${n} prenotazion${n > 1 ? 'i' : 'e'} oggi</span>`;

    return `
        <div style="position:relative;padding-bottom:20px">
            <div style="display:flex;height:20px;border-radius:4px;overflow:hidden;border:1px solid var(--clr-border)">${bars}</div>
            ${nowMark}
            <div style="position:absolute;bottom:0;left:0;width:100%;height:16px">${labels}</div>
        </div>
        <div style="margin-top:8px">${summary}</div>`;
}

// ════════════════════════════════════════════════════════
// SLOT LIBERI SUGGERITI (09:00–19:00)
// ════════════════════════════════════════════════════════
function getFreeWindows(roomId) {
    const now = new Date();

    const cursor = new Date(now);
    cursor.setMinutes(Math.ceil(cursor.getMinutes() / 15) * 15, 0, 0);
    if (cursor <= now) cursor.setMinutes(cursor.getMinutes() + 15);

    const occupied = (occupiedSlots[roomId] || [])
        .map(s => ({ start: new Date(s.inizio), end: new Date(s.fine) }))
        .sort((a, b) => a.start - b.start);

    const windows = [];

    for (let d = 0; d < 7 && windows.length < 4; d++) {
        const base     = new Date(now.getFullYear(), now.getMonth(), now.getDate() + d);
        const winStart = new Date(base.getFullYear(), base.getMonth(), base.getDate(),  9, 0, 0);
        const winEnd   = new Date(base.getFullYear(), base.getMonth(), base.getDate(), 19, 0, 0);

        const from = d === 0
            ? new Date(Math.max(cursor.getTime(), winStart.getTime()))
            : winStart;

        if (from >= winEnd) continue;

        const dayOcc = occupied.filter(s => s.end > from && s.start < winEnd);
        let ptr = new Date(from);

        for (const occ of dayOcc) {
            if (occ.start > ptr) {
                const slotEnd = new Date(Math.min(occ.start.getTime(), winEnd.getTime()));
                windows.push({ start: new Date(ptr), end: slotEnd });
                if (windows.length >= 4) break;
            }
            if (occ.end > ptr) ptr = new Date(Math.min(occ.end.getTime(), winEnd.getTime()));
        }

        if (windows.length < 4 && ptr < winEnd) {
            windows.push({ start: new Date(ptr), end: winEnd });
        }
    }

    return windows.slice(0, 4);
}

function renderFreeSlots(roomId) {
    const windows = getFreeWindows(roomId);
    if (!windows.length) return `<div style="padding:10px 12px;background:var(--clr-surface-2);border:1px solid var(--clr-border);border-radius:var(--radius-md);font-size:12px;color:var(--clr-text-3)">🔒 Nessun periodo libero nei prossimi 7 giorni</div>`;

    return windows.map(fw => {
        const durMs  = fw.end - fw.start;
        const durH   = Math.floor(durMs / 3600000);
        const durM   = Math.floor((durMs % 3600000) / 60000);
        const durLbl = durH > 0 ? `${durH}h${durM > 0 ? ' ' + durM + 'm' : ''}` : `${durM}m`;

        return `<div onclick="prefillSlot('${toLocalISO(fw.start)}','${toLocalISO(fw.end)}')"
                     onmouseover="this.style.background='#bbf7d0'" onmouseout="this.style.background='var(--clr-success-bg)'"
                     style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;
                            background:var(--clr-success-bg);border:1px solid #86efac;border-left:3px solid var(--clr-success);
                            border-radius:var(--radius-md);cursor:pointer;margin-bottom:6px;transition:background .15s">
                    <div>
                        <div style="font-size:10px;font-weight:700;color:var(--clr-success);text-transform:uppercase;letter-spacing:.5px">${fmtDay(fw.start)}</div>
                        <div style="font-size:12px;font-weight:600;color:var(--clr-text-1)">${fmtTime(fw.start)} → ${fmtTime(fw.end)}</div>
                    </div>
                    <div style="text-align:right">
                        <div style="font-size:11px;font-weight:700;color:var(--clr-text-2)">${durLbl}</div>
                        <div style="font-size:10px;font-weight:700;color:var(--clr-success);text-transform:uppercase;letter-spacing:.4px">Prenota →</div>
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
// FORM — validazione 09:00-19:00 + conflitti
// ════════════════════════════════════════════════════════
function hasConflict(startVal, endVal, roomId) {
    if (!startVal || !endVal || !roomId) return false;
    const s = new Date(startVal), e = new Date(endVal);
    return (occupiedSlots[roomId] || []).some(sl => s < new Date(sl.fine) && e > new Date(sl.inizio));
}

function updateDuration() {
    const startVal = document.getElementById('data-inizio').value;
    const endVal   = document.getElementById('data-fine').value;
    const preview  = document.getElementById('sr-duration-preview');
    const error    = document.getElementById('sr-form-error');
    const btn      = document.getElementById('submit-btn');
    const roomId   = parseInt(document.getElementById('panel-asset-id').value);

    if (!startVal || !endVal) {
        preview.style.display = 'none';
        error.style.display   = 'none';
        btn.disabled = false;
        return;
    }

    const startDate = new Date(startVal);
    const endDate   = new Date(endVal);
    const ms        = endDate - startDate;
    const startMins = startDate.getHours() * 60 + startDate.getMinutes();
    const endMins   = endDate.getHours()   * 60 + endDate.getMinutes();
    const sameDay   = startDate.toDateString() === endDate.toDateString();

    if (startVal) document.getElementById('data-fine').min = startVal;

    const showErr = msg => {
        preview.style.display = 'none';
        error.style.display   = '';
        error.innerHTML = ' ' + msg;
        btn.disabled = true;
    };

    if (startDate < new Date()) return showErr('Non puoi prenotare nel passato.');
    if (ms <= 0)                return showErr('La data di fine deve essere successiva alla data di inizio.');
    if (startMins < 9 * 60)    return showErr('L\'orario di inizio non può essere prima delle 09:00.');
    if (startMins >= 19 * 60)  return showErr('L\'orario di inizio non può essere dalle 19:00 in poi.');
    if (endMins > 19 * 60)     return showErr('L\'orario di fine non può superare le 19:00.');
    if (!sameDay)               return showErr('La prenotazione deve essere nella stessa giornata (09:00–19:00).');
    if (hasConflict(startVal, endVal, roomId))
        return showErr('Periodo sovrapposto a una prenotazione esistente.<br><small>Scegli uno dei periodi liberi suggeriti sopra.</small>');

    error.style.display = 'none';
    btn.disabled = false;

    const hrs  = Math.floor(ms / 3600000);
    const mins = Math.floor((ms % 3600000) / 60000);
    const parts = [];
    if (hrs  > 0) parts.push(`${hrs} or${hrs > 1 ? 'e' : 'a'}`);
    if (mins > 0) parts.push(`${mins} minut${mins > 1 ? 'i' : 'o'}`);
    preview.style.display = '';
    preview.innerHTML = ` <strong>${parts.join(' ') || 'meno di un minuto'}</strong> — ${fmtDateTime(startDate)} → ${fmtDateTime(endDate)}`;
}

// ════════════════════════════════════════════════════════
// HELPERS DATA/ORA
// ════════════════════════════════════════════════════════
function toLocalISO(d) {
    const p = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}
function fmtTime(d)     { return d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' }); }
function fmtDateTime(d) { return d.toLocaleString('it-IT', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }); }
function fmtDay(d) {
    const t = new Date(); t.setHours(0,0,0,0);
    const diff = Math.round((new Date(d.getFullYear(), d.getMonth(), d.getDate()) - t) / 86400000);
    if (diff === 0) return 'Oggi';
    if (diff === 1) return 'Domani';
    if (diff === 2) return 'Dopodomani';
    return d.toLocaleDateString('it-IT', { weekday: 'short', day: '2-digit', month: 'short' });
}

// ════════════════════════════════════════════════════════
// INIT
// ════════════════════════════════════════════════════════
window.addEventListener('resize', resize);
resize();
updateDuration();
if (reopenAssetId) openRoomPanel(reopenAssetId);
</script>
</body>
</html>
