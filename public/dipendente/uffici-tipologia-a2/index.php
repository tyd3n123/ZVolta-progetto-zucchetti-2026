<?php
session_start();
require_once __DIR__ . "/../../../config/config.php";

// ── Auth + dipendente check ─────────────────────────────────
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['id_utente'])) {
    header("Location: ../../login.php"); exit();
}
$id_utente = (int)$_SESSION['id_utente'];

$stmt = $conn->prepare("SELECT r.nome_ruolo FROM utenti u JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE u.id_utente = ?");
$stmt->bind_param("i", $id_utente); $stmt->execute();
$callerRole = $stmt->get_result()->fetch_assoc()['nome_ruolo'] ?? '';
$stmt->close();
if (strtolower($callerRole) !== 'dipendente') { header("Location: ../../login.php"); exit(); }

// ── Info utente ────────────────────────────────────────
$stmt = $conn->prepare("SELECT u.nome, u.cognome, r.nome_ruolo AS ruolo FROM utenti u LEFT JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE u.id_utente = ? LIMIT 1");
$stmt->bind_param("i", $id_utente); $stmt->execute();
$userInfo = $stmt->get_result()->fetch_assoc() ?? ['nome'=>'','cognome'=>'','ruolo'=>''];
$stmt->close();

// ── Blocco: già prenotato oggi ────────────────────────
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM prenotazioni WHERE id_utente = ? AND DATE(data_inizio) = CURDATE()");
$stmt->bind_param("i", $id_utente); $stmt->execute();
$hasBookingToday = (int)$stmt->get_result()->fetch_assoc()['c'] > 0;
$stmt->close();
if ($hasBookingToday) {
    header("Location: ../dashboard/index.php?errore=gia_prenotato");
    exit();
}

// ── POST handlers ──────────────────────────────────────
$feedback = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'book') {
        $id_asset    = (int)($_POST['id_asset'] ?? 0);
        $data_inizio = $_POST['data_inizio'] ?? '';
        $data_fine   = $_POST['data_fine']   ?? '';

        $tsInizio = strtotime($data_inizio);
        $tsFine   = strtotime($data_fine);
        $hInizio  = (int)date('H', $tsInizio) * 60 + (int)date('i', $tsInizio);
        $hFine    = (int)date('H', $tsFine)   * 60 + (int)date('i', $tsFine);

        if (!$id_asset || !$data_inizio || !$data_fine) {
            $feedback = ['type'=>'error','msg'=>'Compila tutti i campi prima di procedere.'];
        } elseif ($tsInizio < time()) {
            $feedback = ['type'=>'error','msg'=>'Non puoi prenotare nel passato.'];
        } elseif ($tsFine <= $tsInizio) {
            $feedback = ['type'=>'error','msg'=>'La data fine deve essere successiva alla data di inizio.'];
        } elseif (date('Y-m-d', $tsInizio) !== date('Y-m-d', $tsFine)) {
            $feedback = ['type'=>'error','msg'=>'La prenotazione deve essere nella stessa giornata (09:00–19:00).'];
        } elseif ($hInizio < 9 * 60) {
            $feedback = ['type'=>'error','msg'=>'L\'orario di inizio non può essere prima delle 09:00.'];
        } elseif ($hInizio >= 19 * 60) {
            $feedback = ['type'=>'error','msg'=>'L\'orario di inizio non può essere dalle 19:00 in poi.'];
        } elseif ($hFine > 19 * 60) {
            $feedback = ['type'=>'error','msg'=>'L\'orario di fine non può superare le 19:00.'];
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM prenotazioni WHERE id_asset=? AND data_inizio<? AND data_fine>?");
            $stmt->bind_param("iss", $id_asset, $data_fine, $data_inizio); $stmt->execute();
            $overlap = $stmt->get_result()->fetch_assoc()['c']; $stmt->close();

            if ($overlap > 0) {
                $feedback = ['type'=>'error','msg'=>"L'asset è già occupato nel periodo selezionato."];
            } else {
                try {
                    $conn->begin_transaction();
                    $stmt = $conn->prepare("INSERT INTO prenotazioni (id_utente,id_asset,data_inizio,data_fine) VALUES (?,?,?,?)");
                    $stmt->bind_param("iiss", $id_utente, $id_asset, $data_inizio, $data_fine);
                    if (!$stmt->execute()) throw new Exception($stmt->error); $stmt->close();
                    $stmt = $conn->prepare("UPDATE asset SET stato='Occupato' WHERE id_asset=?");
                    $stmt->bind_param("i", $id_asset);
                    if (!$stmt->execute()) throw new Exception($stmt->error); $stmt->close();
                    $conn->commit();
                    $feedback = ['type'=>'success','msg'=>'Prenotazione effettuata con successo!'];
                } catch (Exception $e) {
                    $conn->rollback();
                    $feedback = ['type'=>'error','msg'=>'Errore: '.$e->getMessage()];
                }
            }
        }
    }

    if ($action === 'cancel_booking') {
        $id_pren = (int)($_POST['id_prenotazione'] ?? 0);
        if ($id_pren > 0) {
            $stmt = $conn->prepare("SELECT id_asset FROM prenotazioni WHERE id_prenotazione=?");
            $stmt->bind_param("i", $id_pren); $stmt->execute();
            $assetRow = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($assetRow) {
                $assetId = $assetRow['id_asset'];
                $stmt = $conn->prepare("DELETE FROM prenotazioni WHERE id_prenotazione=?");
                $stmt->bind_param("i", $id_pren);
                if ($stmt->execute()) {
                    $stmt->close();
                    $stmt2 = $conn->prepare("SELECT COUNT(*) AS c FROM prenotazioni WHERE id_asset=? AND data_fine>=NOW()");
                    $stmt2->bind_param("i", $assetId); $stmt2->execute();
                    $rem = $stmt2->get_result()->fetch_assoc()['c']; $stmt2->close();
                    if ($rem == 0) {
                        $stmt3 = $conn->prepare("UPDATE asset SET stato='Disponibile' WHERE id_asset=?");
                        $stmt3->bind_param("i", $assetId); $stmt3->execute(); $stmt3->close();
                    }
                    $feedback = ['type'=>'success','msg'=>'Prenotazione cancellata con successo.'];
                } else { $stmt->close(); $feedback = ['type'=>'error','msg'=>'Errore durante la cancellazione.']; }
            }
        }
    }
}

// ── Fetch scrivanie TIPO-A2 (id_tipologia=2) ──────────
$desks = [];
$result = $conn->query(
    "SELECT a.id_asset, a.codice_asset,
            CASE WHEN EXISTS (
                SELECT 1 FROM prenotazioni pr
                WHERE pr.id_asset = a.id_asset
                  AND pr.data_fine > NOW()
                  AND DATE(pr.data_inizio) = CURDATE()
            ) THEN 'Occupato' ELSE 'Disponibile' END AS stato
     FROM asset a
     WHERE a.id_tipologia=2 AND a.mappa='Sede'
     ORDER BY a.codice_asset"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $desks[] = ['id' => (int)$row['id_asset'], 'name' => $row['codice_asset'], 'status' => $row['stato']];
    }
}

// ── Slot occupati per scrivanie ────────────────────────
$allDeskIds = array_column($desks, 'id');
$occupiedSlotsByAsset = [];
if (!empty($allDeskIds)) {
    $ids = implode(',', array_map('intval', $allDeskIds));
    $result = $conn->query("SELECT id_asset, data_inizio, data_fine FROM prenotazioni WHERE id_asset IN ($ids) AND data_fine >= NOW() ORDER BY data_inizio ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $occupiedSlotsByAsset[(int)$row['id_asset']][] = ['inizio'=>$row['data_inizio'],'fine'=>$row['data_fine']];
        }
    }
}

// ── Prenotazioni attive utente (solo scrivanie) ────────
$userBookings = [];
$stmt = $conn->prepare(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine, a.codice_asset, a.id_asset
     FROM prenotazioni p JOIN asset a ON p.id_asset = a.id_asset
     WHERE p.id_utente = ? AND a.id_tipologia = 2 AND p.data_fine >= NOW()
     ORDER BY p.data_inizio ASC"
);
$stmt->bind_param("i", $id_utente); $stmt->execute();
$result = $stmt->get_result(); while ($row = $result->fetch_assoc()) $userBookings[] = $row; $stmt->close();

// ── Stats ──────────────────────────────────────────────
$totalDesks = count($desks);
$availDesks = count(array_filter($desks, fn($d) => strtolower($d['status']) !== 'occupato'));
$occDesks   = $totalDesks - $availDesks;

$reopenAssetId = (!empty($_POST['id_asset'])) ? (int)$_POST['id_asset'] : 0;
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Scrivanie Tipologia A2 | Northstar</title>
<link rel="stylesheet" href="../dashboard/dashboard.css">
<link rel="stylesheet" href="./uffici.css">
</head>
<body>

<!-- ── Header ──────────────────────────────────────────── -->
<header class="header">
  <div class="header-left">
    <h1>Northstar</h1>
    <nav class="header-breadcrumb">
      <a href="../dashboard/index.php">Dashboard</a>
      <span class="bc-sep">/</span>
      <span class="bc-current">Scrivanie Tipologia A2</span>
    </nav>
  </div>
  <div class="uf-user-pill">
    <?= htmlspecialchars($userInfo['nome'].' '.$userInfo['cognome']) ?>
    <span class="uf-role"><?= htmlspecialchars($userInfo['ruolo']) ?></span>
  </div>
</header>

<div class="uf-page">

  <!-- ── Titolo + Stats ──────────────────────────────── -->
  <div class="uf-title-row">
    <div>
      <h2 class="uf-page-title">Scrivanie Tipologia A2</h2>
      <p class="uf-page-sub">Clicca su una scrivania nella planimetria per prenotarla</p>
    </div>
    <div class="uf-stats-row">
      <span class="uf-stat-chip uf-stat-chip--total"> <?= $totalDesks ?> scrivanie</span>
      <span class="uf-stat-chip uf-stat-chip--avail"> <?= $availDesks ?> libere</span>
      <?php if ($occDesks > 0):?>
      <span class="uf-stat-chip uf-stat-chip--occ"> <?= $occDesks ?> occupate</span>
      <?php endif;?>
    </div>
  </div>

  <!-- ── Feedback ────────────────────────────────────── -->
  <?php if ($feedback):?>
  <div class="uf-feedback uf-feedback--<?= $feedback['type'] ?>">
    <?= $feedback['type'] === 'success' ? '&#x2705;' : '&#x26A0;&#xFE0F;' ?>
    <?= htmlspecialchars($feedback['msg']) ?>
  </div>
  <?php endif;?>

  <!-- Backdrop sfocato -->
  <div class="uf-panel-backdrop" id="uf-backdrop" onclick="closePanel()"></div>

  <!-- ── Mappa planimetrica ──────────────────────────── -->
  <div class="uf-map-wrap" id="map-wrap">

    <!-- Canvas zone -->
    <div class="uf-canvas-zone" id="canvas-zone">
      <canvas id="floorCanvas"></canvas>

      <!-- Legenda sovrapposta in basso a sinistra -->
      <div class="uf-map-legend">
        <div class="uf-leg-item">
          <span class="uf-leg-dot" style="background:#22c55e;border-color:#16a34a"></span>Disponibile
        </div>
        <div class="uf-leg-item">
          <span class="uf-leg-dot" style="background:#ef4444;border-color:#dc2626"></span>Occupato
        </div>
        <div class="uf-leg-item">
          <span class="uf-leg-dot" style="background:#818cf8;border-color:#6366f1"></span>Selezionato
        </div>
      </div>
    </div><!-- /.uf-canvas-zone -->

  </div><!-- /.uf-map-wrap -->

  <!-- Pannello laterale fixed overlay -->
  <div class="uf-side-panel" id="side-panel">
      <div class="uf-panel-inner">

        <div class="uf-panel-top">
          <div>
            <h3 class="uf-panel-name" id="panel-title">—</h3>
            <div id="panel-badge" style="margin-top:5px"></div>
          </div>
          <button class="uf-panel-close" onclick="closePanel()" title="Chiudi">&#x2715;</button>
        </div>

        <!-- Info tiles -->
        <div class="uf-info-grid" id="panel-info-grid"></div>

        <div class="uf-psep"></div>

        <!-- Timeline oggi -->
        <div>
          <p class="uf-section-lbl" style="margin-bottom:10px"> Disponibilita oggi</p>
          <div id="panel-timeline"></div>
        </div>

        <div class="uf-psep"></div>

        <!-- Prossimi slot liberi -->
        <div>
          <p class="uf-section-lbl" style="margin-bottom:4px"> Prossimi periodi liberi</p>
          <p style="font-size:11px;color:var(--clr-text-3);margin:0 0 10px">Clicca un periodo per precompilare le date</p>
          <div id="panel-free-slots"></div>
        </div>

        <div class="uf-psep"></div>

        <!-- Form prenotazione -->
        <div>
          <p class="uf-section-lbl" style="margin-bottom:12px"> Prenota</p>
          <form method="POST" id="booking-form">
            <input type="hidden" name="action"   value="book">
            <input type="hidden" name="id_asset" id="panel-asset-id" value="">

            <div class="uf-field" style="margin-bottom:10px">
              <label class="uf-field-lbl">Data Inizio</label>
              <input class="uf-field-input" type="datetime-local" name="data_inizio" id="data-inizio"
                     value="<?= htmlspecialchars($_POST['data_inizio'] ?? '') ?>"
                     required onchange="updateDuration()">
            </div>
            <div class="uf-field" style="margin-bottom:10px">
              <label class="uf-field-lbl">Data Fine</label>
              <input class="uf-field-input" type="datetime-local" name="data_fine" id="data-fine"
                     value="<?= htmlspecialchars($_POST['data_fine'] ?? '') ?>"
                     required onchange="updateDuration()">
            </div>

            <div id="dur-preview" class="uf-dur-preview" style="display:none;margin-bottom:10px"></div>
            <div id="form-error"  class="uf-form-error"  style="display:none;margin-bottom:10px"></div>

            <button type="submit" class="uf-submit-btn" id="submit-btn">Conferma prenotazione</button>
          </form>
        </div>

      </div><!-- /.uf-panel-inner -->
  </div><!-- /.uf-side-panel -->

  <!-- ── Prenotazioni attive utente ──────────────────── -->
  <div class="uf-bookings-strip">
    <div class="uf-strip-header">
      <p class="uf-strip-title">Le tue prenotazioni attive</p>
      <span class="uf-count-badge"><?= count($userBookings) ?></span>
    </div>
    <?php if (empty($userBookings)):?>
      <div class="uf-empty"><span></span><p>Nessuna prenotazione attiva</p></div>
    <?php else:?>
      <div class="uf-bookings-list">
        <?php foreach ($userBookings as $b):
          $start = new DateTime($b['data_inizio']); $end = new DateTime($b['data_fine']); $now = new DateTime();
          $isActive = $start <= $now && $end >= $now;
          $sClass = $isActive ? 'uf-status--now' : 'uf-status--future';
          $sLabel = $isActive ? 'In corso' : 'Programmato';
          $diff = $start->diff($end);
          $durStr = $diff->days > 0 ? "{$diff->days}g {$diff->h}h" : "{$diff->h}h {$diff->i}m";
        ?>
        <div class="uf-booking-item">
          <div class="uf-booking-top">
            <span class="uf-asset-pill"><?= htmlspecialchars($b['codice_asset']) ?></span>
            <span class="uf-status-pill <?= $sClass ?>"><?= $sLabel ?></span>
            <span class="uf-dur"><?= $durStr ?></span>
          </div>
          <div class="uf-booking-dates">
            <?= date('d/m/Y H:i', strtotime($b['data_inizio'])) ?>
            <span class="uf-arrow">&#x2192;</span>
            <?= date('d/m/Y H:i', strtotime($b['data_fine'])) ?>
          </div>
        </div>
        <?php endforeach;?>
      </div>
    <?php endif;?>
  </div>

</div><!-- /.uf-page -->

<!-- =======================================================
     JAVASCRIPT — Planimetria Canvas
     ======================================================= -->
<script>
// ── Dati PHP ───────────────────────────────────────────
const desksData     = <?= json_encode(array_values($desks)) ?>;
const occupiedSlots = <?= json_encode($occupiedSlotsByAsset) ?>;
const reopenId      = <?= $reopenAssetId ?>;

// ── Canvas ─────────────────────────────────────────────
const canvas = document.getElementById('floorCanvas');
const ctx    = canvas.getContext('2d');
const zone   = document.getElementById('canvas-zone');

let selectedId = null;
let hitboxes   = [];

// ════════════════════════════════════════════════════════
// PALETTE
// ════════════════════════════════════════════════════════
const C = {
    floorBg:      '#f5f1ea',
    floorLine:    'rgba(190,170,140,0.28)',
    corridorBg:   '#ede8df',
    corridorLine: 'rgba(190,170,140,0.45)',
    wall:         '#2d3142',

    deskAvailFill:'rgba(34,197,94,0.18)',
    deskAvailBrd: '#22c55e',
    deskOccFill:  'rgba(239,68,68,0.18)',
    deskOccBrd:   '#ef4444',
    deskSelFill:  'rgba(129,140,248,0.28)',
    deskSelBrd:   '#818cf8',
    deskSurface:  '#c8b89a',

    textDark:     '#1e293b',
    textMid:      '#475569',
};

// ════════════════════════════════════════════════════════
// LAYOUT — proporzionale a W e H
// ════════════════════════════════════════════════════════
function buildLayout(W, H) {
    const DW  = W * 0.080;   // larghezza scrivania (più larga: solo 2 pod affiancati in cima)
    const DH  = H * 0.150;   // altezza scrivania
    const DG  = W * 0.007;   // gap tra scrivanie

    // Riga 1 (sopra): Pod A sinistra, Pod B destra
    const PAX = W * 0.025;
    const PBX = W * 0.510;   // PAX + podWidth(0.435) + corridoio(0.050)

    // Riga 2 (sotto): Pod C centrato
    const podW = 5 * (DW + DG) - DG;   // larghezza un pod
    const PCX  = (W - podW) / 2;

    // Corridoio nord
    const NCY = H * 0.060;
    const NCH = H * 0.055;

    // Sezione superiore (Pod A e B)
    const R1Y     = NCY + NCH;
    const R2Y     = R1Y + DH + H * 0.045;
    const TOP_RSY = R2Y + DH;

    // Corridoio orizzontale centrale
    const MCY = TOP_RSY;
    const MCH = H * 0.048;

    // Sezione inferiore (Pod C)
    const R3Y     = MCY + MCH;
    const R4Y     = R3Y + DH + H * 0.045;
    const BOT_RSY = R4Y + DH;

    return {
        W, H, DW, DH, DG,
        PAX, PBX, PCX, podW,
        NCY, NCH,
        R1Y, R2Y, TOP_RSY,
        MCY, MCH,
        R3Y, R4Y, BOT_RSY
    };
}

// ════════════════════════════════════════════════════════
// DRAW PRINCIPALE
// ════════════════════════════════════════════════════════
function draw() {
    const W = canvas.width, H = canvas.height;
    const l = buildLayout(W, H);

    ctx.clearRect(0, 0, W, H);
    drawFloor(W, H);
    drawCorridors(W, H, l);
    drawOuterWalls(W, H);

    hitboxes = [];
    drawDeskPods(W, H, l);
    drawEntrance(W, H);
}

// ── Pavimento parquet ──────────────────────────────────
function drawFloor(W, H) {
    ctx.fillStyle = C.floorBg;
    ctx.fillRect(0, 0, W, H);
    ctx.strokeStyle = C.floorLine;
    ctx.lineWidth = 0.7;
    const sh = H / 18;
    for (let y = 0; y < H; y += sh) {
        ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(W, y); ctx.stroke();
    }
    ctx.lineWidth = 0.4;
    for (let r = 0; r < 18; r++) {
        const y = r * sh;
        const off = (r % 3) * (W / 3);
        for (let x = off - W; x < W * 2; x += W / 2.5) {
            ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(x, y + sh); ctx.stroke();
        }
    }
}

// ── Corridoi ───────────────────────────────────────────
function drawCorridors(W, H, l) {
    ctx.fillStyle = C.corridorBg;
    // Corridoio nord
    ctx.fillRect(0, l.NCY, W, l.NCH);
    // Corridoio verticale tra Pod A e Pod B (sezione superiore)
    const cX = l.PAX + l.podW;
    const cW = l.PBX - cX;
    ctx.fillRect(cX, l.R1Y, cW, l.TOP_RSY - l.R1Y);
    // Corridoio orizzontale centrale (tra sezione superiore e inferiore)
    ctx.fillRect(0, l.MCY, W, l.MCH);
    // Zona sud (sotto Pod C)
    ctx.fillRect(0, l.BOT_RSY, W, H - l.BOT_RSY);

    ctx.strokeStyle = C.corridorLine;
    ctx.lineWidth = 1;
    ctx.setLineDash([6, 4]);
    ctx.beginPath(); ctx.moveTo(0, l.NCY);       ctx.lineTo(W, l.NCY);       ctx.stroke();
    ctx.beginPath(); ctx.moveTo(0, l.NCY+l.NCH); ctx.lineTo(W, l.NCY+l.NCH); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(0, l.MCY);       ctx.lineTo(W, l.MCY);       ctx.stroke();
    ctx.beginPath(); ctx.moveTo(0, l.MCY+l.MCH); ctx.lineTo(W, l.MCY+l.MCH); ctx.stroke();
    ctx.setLineDash([]);
}

// ── Muri perimetrali ───────────────────────────────────
function drawOuterWalls(W, H) {
    ctx.strokeStyle = C.wall;
    ctx.lineWidth = 8;
    ctx.strokeRect(4, 4, W - 8, H - 8);
    ctx.strokeStyle = 'rgba(45,49,66,0.25)';
    ctx.lineWidth = 2;
    ctx.strokeRect(10, 10, W - 20, H - 20);
}

// ── Ingresso ───────────────────────────────────────────
function drawEntrance(W, H) {
    const ex = W / 2 - 36;
    ctx.fillStyle = C.corridorBg;
    ctx.fillRect(ex, H - 12, 72, 14);
    ctx.fillStyle = '#f59e0b';
    ctx.font = 'bold 10px system-ui';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText('\u25B2 INGRESSO', W / 2, H - 20);
}

// ════════════════════════════════════════════════════════
// SCRIVANIE — 2 pod in alto (A,B) + 1 pod in basso (C)
// ════════════════════════════════════════════════════════
function drawDeskPods(W, H, l) {
    const pods = [
        { start: 0,  startX: l.PAX, rowY1: l.R1Y, rowY2: l.R2Y, label: 'Pod A' },
        { start: 10, startX: l.PBX, rowY1: l.R1Y, rowY2: l.R2Y, label: 'Pod B' },
        { start: 20, startX: l.PCX, rowY1: l.R3Y, rowY2: l.R4Y, label: 'Pod C' },
    ];
    for (const pod of pods) {
        for (let i = 0; i < 5; i++) {
            const d = desksData[pod.start + i];
            if (!d) continue;
            const dx = pod.startX + i * (l.DW + l.DG);
            drawOneDesk(dx, pod.rowY1, l.DW, l.DH, d, 'south');
            hitboxes.push({ x: dx, y: pod.rowY1, w: l.DW, h: l.DH, item: d });
        }
        for (let i = 0; i < 5; i++) {
            const d = desksData[pod.start + 5 + i];
            if (!d) continue;
            const dx = pod.startX + i * (l.DW + l.DG);
            drawOneDesk(dx, pod.rowY2, l.DW, l.DH, d, 'north');
            hitboxes.push({ x: dx, y: pod.rowY2, w: l.DW, h: l.DH, item: d });
        }
        // Bordo pod
        const ph = l.DH + (pod.rowY2 - pod.rowY1 - l.DH) + l.DH;
        ctx.strokeStyle = 'rgba(180,160,130,0.45)';
        ctx.lineWidth = 1;
        ctx.setLineDash([4, 3]);
        ctx.strokeRect(pod.startX - 4, pod.rowY1 - 4, l.podW + 8, ph + 8);
        ctx.setLineDash([]);
        // Etichetta pod
        ctx.fillStyle = 'rgba(100,80,60,0.55)';
        ctx.font = 'bold 9px system-ui';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'top';
        ctx.fillText(pod.label, pod.startX, pod.rowY1 - 14);
    }
}

function drawOneDesk(x, y, w, h, desk, facing) {
    if (!desk) return;
    const isOcc = desk.status.toLowerCase() === 'occupato';
    const isSel = desk.id === selectedId;

    ctx.fillStyle = isSel ? C.deskSelFill : (isOcc ? C.deskOccFill : C.deskAvailFill);
    roundRect(ctx, x, y, w, h, 5); ctx.fill();
    ctx.strokeStyle = isSel ? C.deskSelBrd : (isOcc ? C.deskOccBrd : C.deskAvailBrd);
    ctx.lineWidth = isSel ? 2 : 1.5;
    ctx.stroke();

    const pw = w * 0.70, ph = h * 0.40;
    const px2 = x + (w - pw) / 2;
    const py2 = facing === 'south' ? y + h * 0.10 : y + h * 0.50;
    ctx.fillStyle = C.deskSurface;
    roundRect(ctx, px2, py2, pw, ph, 3); ctx.fill();

    const mw = pw * 0.42, mh = ph * 0.62;
    const mx2 = px2 + (pw - mw) / 2;
    const my2 = py2 + (ph - mh) / 2;
    ctx.fillStyle = isOcc ? '#ef4444' : (isSel ? '#818cf8' : '#22c55e');
    ctx.globalAlpha = 0.75;
    roundRect(ctx, mx2, my2, mw, mh, 2); ctx.fill();
    ctx.globalAlpha = 1;

    const chairY = facing === 'south' ? y + h - 9 : y + 9;
    ctx.fillStyle = isSel ? 'rgba(129,140,248,0.50)' : (isOcc ? 'rgba(239,68,68,0.40)' : 'rgba(34,197,94,0.40)');
    ctx.beginPath(); ctx.arc(x + w/2, chairY, 7, 0, Math.PI * 2); ctx.fill();

    const code = desk.name.replace('TIPO-A2-', 'A2-');
    ctx.fillStyle = isSel ? '#4c1d95' : C.textDark;
    ctx.font = 'bold ' + Math.max(7, Math.min(9, Math.floor(w/7))) + 'px system-ui';
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    const labelY = facing === 'south' ? y + h * 0.73 : y + h * 0.27;
    ctx.fillText(code, x + w / 2, labelY);
}

// ── Rettangoli arrotondati ─────────────────────────────
function roundRect(ctx, x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.lineTo(x + w - r, y);
    ctx.quadraticCurveTo(x + w, y, x + w, y + r);
    ctx.lineTo(x + w, y + h - r);
    ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
    ctx.lineTo(x + r, y + h);
    ctx.quadraticCurveTo(x, y + h, x, y + h - r);
    ctx.lineTo(x, y + r);
    ctx.quadraticCurveTo(x, y, x + r, y);
    ctx.closePath();
}

// ════════════════════════════════════════════════════════
// INTERAZIONI
// ════════════════════════════════════════════════════════
canvas.addEventListener('click', function(e) {
    const rect = canvas.getBoundingClientRect();
    const sx = canvas.width / rect.width, sy = canvas.height / rect.height;
    const mx = (e.clientX - rect.left) * sx, my = (e.clientY - rect.top) * sy;

    let hit = null;
    for (const box of hitboxes) {
        if (mx >= box.x && mx <= box.x + box.w && my >= box.y && my <= box.y + box.h) { hit = box; break; }
    }
    if (hit) {
        selectedId = hit.item.id;
        openPanel(hit.item);
    } else {
        selectedId = null; closePanel();
    }
    draw();
});

canvas.addEventListener('mousemove', function(e) {
    const rect = canvas.getBoundingClientRect();
    const sx = canvas.width / rect.width, sy = canvas.height / rect.height;
    const mx = (e.clientX - rect.left) * sx, my = (e.clientY - rect.top) * sy;
    canvas.style.cursor = hitboxes.some(b => mx>=b.x && mx<=b.x+b.w && my>=b.y && my<=b.y+b.h) ? 'pointer' : 'default';
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { selectedId = null; closePanel(); draw(); }
});

// ════════════════════════════════════════════════════════
// PANNELLO LATERALE
// ════════════════════════════════════════════════════════
function openPanel(item) {
    document.getElementById('uf-backdrop').classList.add('visible');
    const isOcc = item.status.toLowerCase() === 'occupato';

    document.getElementById('panel-title').textContent = item.name;
    document.getElementById('panel-badge').innerHTML =
        `<span class="uf-badge ${isOcc ? 'uf-badge--occ' : 'uf-badge--avail'}">${isOcc ? 'Occupato' : 'Disponibile'}</span>`;

    document.getElementById('panel-info-grid').innerHTML =
        `<div class="uf-info-tile"><div class="uf-tile-lbl">Tipologia</div><div class="uf-tile-val">Scrivania A2</div></div>
         <div class="uf-info-tile"><div class="uf-tile-lbl">Codice</div><div class="uf-tile-val" style="font-size:12px">${item.name}</div></div>`;
    document.getElementById('panel-timeline').innerHTML   = renderTimeline(item.id);
    document.getElementById('panel-free-slots').innerHTML = renderFreeSlots(item.id);

    document.getElementById('panel-asset-id').value = item.id;
    document.getElementById('data-inizio').value    = '';
    document.getElementById('data-fine').value      = '';
    document.getElementById('dur-preview').style.display = 'none';
    document.getElementById('form-error').style.display  = 'none';
    document.getElementById('submit-btn').disabled = false;

    const minVal = toLocalISO(new Date());
    document.getElementById('data-inizio').min = minVal;
    document.getElementById('data-fine').min   = minVal;

    document.getElementById('side-panel').classList.add('open');
}

function closePanel() {
    document.getElementById('side-panel').classList.remove('open');
    document.getElementById('uf-backdrop').classList.remove('visible');
    selectedId = null;
    draw();
}

// ════════════════════════════════════════════════════════
// TIMELINE OGGI
// ════════════════════════════════════════════════════════
function renderTimeline(assetId) {
    const now      = new Date();
    const today    = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const dayStart = new Date(today.getFullYear(), today.getMonth(), today.getDate(), 9, 0, 0);
    const dayEnd   = new Date(today.getFullYear(), today.getMonth(), today.getDate(), 19, 0, 0);
    const dayMs    = 10 * 3600000; // 09:00–19:00 = 10 h

    const rawSlots = (occupiedSlots[assetId] || [])
        .map(s => ({ start: new Date(s.inizio), end: new Date(s.fine) }))
        .filter(s => s.end > dayStart && s.start < dayEnd)
        .sort((a, b) => a.start - b.start);

    const segs = [];
    let ptr = dayStart.getTime();
    for (const occ of rawSlots) {
        const oS = Math.max(occ.start.getTime(), dayStart.getTime());
        const oE = Math.min(occ.end.getTime(), dayEnd.getTime());
        if (oS > ptr) segs.push({ type:'free', start:ptr, end:oS });
        segs.push({ type:'occ', start:oS, end:oE });
        ptr = oE;
    }
    if (ptr < dayEnd.getTime()) segs.push({ type:'free', start:ptr, end:dayEnd.getTime() });

    const bars = segs.map(s => {
        const pct  = ((s.end - s.start) / dayMs * 100).toFixed(2);
        const bg   = s.type === 'occ' ? '#ef444466' : '#22c55e33';
        const brd  = s.type === 'occ' ? '#ef4444' : '#22c55e';
        const tip  = (s.type === 'occ' ? 'Occupato' : 'Libero') + ': ' + fmtTime(new Date(s.start)) + ' \u2013 ' + fmtTime(new Date(s.end));
        return `<div style="width:${pct}%;height:100%;background:${bg};border-right:1px solid ${brd}" title="${tip}"></div>`;
    }).join('');

    const nowPct = ((now - dayStart) / dayMs * 100).toFixed(2);
    const nowMark = nowPct >= 0 && nowPct <= 100
        ? `<div style="position:absolute;top:-3px;left:${nowPct}%;transform:translateX(-50%);display:flex;flex-direction:column;align-items:center;pointer-events:none;z-index:2">
               <div style="width:2px;height:26px;background:#f59e0b;border-radius:1px"></div>
               <span style="font-size:9px;font-weight:700;color:#f59e0b;margin-top:2px">ora</span></div>` : '';

    const labels = [9,11,13,15,17,19].map(hh =>
        `<span style="position:absolute;left:${(hh-9)/10*100}%;transform:translateX(-50%);font-size:9px;color:var(--clr-text-3);font-weight:600;font-family:var(--font-mono)">${String(hh).padStart(2,'0')}:00</span>`
    ).join('');

    const n = rawSlots.length;
    const summary = n === 0
        ? `<span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:20px;background:var(--clr-success-bg);color:var(--clr-success)">Libero tutto il giorno</span>`
        : `<span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:3px 9px;border-radius:20px;background:var(--clr-danger-bg);color:var(--clr-danger)">${n} prenotazion${n>1?'i':'e'} oggi</span>`;

    return `
        <div style="position:relative;padding-bottom:20px">
            <div style="display:flex;height:20px;border-radius:4px;overflow:hidden;border:1px solid var(--clr-border)">${bars}</div>
            ${nowMark}
            <div style="position:absolute;bottom:0;left:0;width:100%;height:16px">${labels}</div>
        </div>
        <div style="margin-top:8px">${summary}</div>`;
}

// ════════════════════════════════════════════════════════
// SLOT LIBERI SUGGERITI
// ════════════════════════════════════════════════════════
function getFreeWindows(assetId) {
    const now = new Date();
    const occupied = (occupiedSlots[assetId] || [])
        .map(s => ({ start: new Date(s.inizio), end: new Date(s.fine) }))
        .sort((a, b) => a.start - b.start);

    const windows = [];
    for (let d = 0; d < 7 && windows.length < 4; d++) {
        const base     = new Date(now.getFullYear(), now.getMonth(), now.getDate() + d);
        const winStart = new Date(base); winStart.setHours(9, 0, 0, 0);
        const winEnd   = new Date(base); winEnd.setHours(19, 0, 0, 0);

        let ptr;
        if (d === 0) {
            ptr = new Date(now);
            ptr.setSeconds(0, 0);
            ptr.setMinutes(Math.ceil(ptr.getMinutes() / 15) * 15);
            if (ptr <= now) ptr.setMinutes(ptr.getMinutes() + 15);
            if (ptr < winStart) ptr = new Date(winStart);
        } else {
            ptr = new Date(winStart);
        }
        if (ptr >= winEnd) continue;

        const dayOcc = occupied.filter(o => o.end > winStart && o.start < winEnd);
        for (const occ of dayOcc) {
            const oS = Math.max(occ.start.getTime(), winStart.getTime());
            const oE = Math.min(occ.end.getTime(), winEnd.getTime());
            if (oS > ptr.getTime()) {
                windows.push({ start: new Date(ptr), end: new Date(oS) });
                if (windows.length >= 4) break;
            }
            if (oE > ptr.getTime()) ptr = new Date(oE);
        }
        if (windows.length < 4 && ptr.getTime() < winEnd.getTime()) {
            windows.push({ start: new Date(ptr), end: new Date(winEnd) });
        }
    }
    return windows.slice(0, 4);
}

function renderFreeSlots(assetId) {
    const windows = getFreeWindows(assetId);
    if (!windows.length) return `<div style="padding:10px 12px;background:var(--clr-surface-2);border:1px solid var(--clr-border);border-radius:var(--radius-md);font-size:12px;color:var(--clr-text-3)">\uD83D\uDD12 Nessun periodo libero nei prossimi 7 giorni</div>`;

    return windows.map(fw => {
        const durMs  = fw.end - fw.start;
        const durH   = Math.floor(durMs / 3600000);
        const durM   = Math.floor((durMs % 3600000) / 60000);
        const durLbl = durH > 0 ? `${durH}h${durM > 0 ? ' ' + durM + 'm' : ''}` : `${durM}m`;
        return `<div onclick="prefillSlot('${toLocalISO(fw.start)}','${toLocalISO(fw.end)}')"
                     onmouseover="this.style.background='#bbf7d0'" onmouseout="this.style.background='var(--clr-success-bg)'"
                     style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;
                            background:var(--clr-success-bg);border:1px solid #86efac;border-left:3px solid var(--clr-success);
                            border-radius:var(--radius-md);cursor:pointer;margin-bottom:6px;transition:var(--transition)">
                    <div>
                        <div style="font-size:10px;font-weight:700;color:var(--clr-success);text-transform:uppercase;letter-spacing:.5px">${fmtDay(fw.start)}</div>
                        <div style="font-size:12px;font-weight:600;color:var(--clr-text-1);font-family:var(--font-mono)">${fmtTime(fw.start)} \u2192 ${fmtTime(fw.end)}</div>
                    </div>
                    <div style="text-align:right">
                        <div style="font-size:11px;font-weight:700;color:var(--clr-text-2);font-family:var(--font-mono)">${durLbl}</div>
                        <div style="font-size:10px;font-weight:700;color:var(--clr-success);text-transform:uppercase;letter-spacing:.4px">Prenota \u2192</div>
                    </div>
                </div>`;
    }).join('');
}

function prefillSlot(startISO, endISO) {
    document.getElementById('data-inizio').value = startISO;
    document.getElementById('data-fine').value   = endISO;
    updateDuration();
    document.getElementById('booking-form').scrollIntoView({ behavior:'smooth', block:'nearest' });
}

// ════════════════════════════════════════════════════════
// FORM — durata + validazione live
// ════════════════════════════════════════════════════════
function hasConflict(startVal, endVal, assetId) {
    if (!startVal || !endVal || !assetId) return false;
    const s = new Date(startVal), e = new Date(endVal);
    return (occupiedSlots[assetId] || []).some(sl => s < new Date(sl.fine) && e > new Date(sl.inizio));
}

function updateDuration() {
    const startVal = document.getElementById('data-inizio').value;
    const endVal   = document.getElementById('data-fine').value;
    const preview  = document.getElementById('dur-preview');
    const error    = document.getElementById('form-error');
    const btn      = document.getElementById('submit-btn');
    const assetId  = parseInt(document.getElementById('panel-asset-id').value);

    function showErr(msg) {
        preview.style.display = 'none';
        error.style.display   = '';
        error.innerHTML       = '\u26A0\uFE0F ' + msg;
        btn.disabled          = true;
    }

    if (!startVal || !endVal) { preview.style.display='none'; error.style.display='none'; btn.disabled=false; return; }
    if (startVal) document.getElementById('data-fine').min = startVal;

    const sDate = new Date(startVal);
    const eDate = new Date(endVal);
    const startMins = sDate.getHours() * 60 + sDate.getMinutes();
    const endMins   = eDate.getHours() * 60 + eDate.getMinutes();
    const sameDay   = sDate.toDateString() === eDate.toDateString();

    if (sDate < new Date())     { showErr('Non puoi prenotare nel passato.'); return; }
    if (eDate <= sDate)         { showErr('La data fine deve essere successiva alla data di inizio.'); return; }
    if (!sameDay)               { showErr('La prenotazione deve essere nella stessa giornata (09:00\u201319:00).'); return; }
    if (startMins < 9 * 60)    { showErr('L\'orario di inizio non pu\u00f2 essere prima delle 09:00.'); return; }
    if (startMins >= 19 * 60)  { showErr('L\'orario di inizio non pu\u00f2 essere dalle 19:00 in poi.'); return; }
    if (endMins > 19 * 60)     { showErr('L\'orario di fine non pu\u00f2 superare le 19:00.'); return; }
    if (hasConflict(startVal, endVal, assetId)) {
        showErr('Periodo sovrapposto a una prenotazione esistente.<br><small>Scegli uno dei periodi liberi suggeriti sopra.</small>'); return;
    }

    error.style.display='none'; btn.disabled=false;
    const ms = eDate - sDate;
    const days=Math.floor(ms/86400000), hrs=Math.floor((ms%86400000)/3600000), mins=Math.floor((ms%3600000)/60000);
    const parts=[];
    if (days>0) parts.push(days+' giorn'+(days>1?'i':'o'));
    if (hrs>0)  parts.push(hrs+' or'+(hrs>1?'e':'a'));
    if (mins>0) parts.push(mins+' minut'+(mins>1?'i':'o'));
    preview.style.display='';
    preview.innerHTML='\u23F1\uFE0F <strong>'+(parts.join(' ')||'meno di un minuto')+'</strong> \u2014 '+fmtDateTime(sDate)+' \u2192 '+fmtDateTime(eDate);
}

// ════════════════════════════════════════════════════════
// HELPERS DATA/ORA
// ════════════════════════════════════════════════════════
function toLocalISO(d) {
    const p = n => String(n).padStart(2,'0');
    return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}
function fmtDay(d) {
    const t = new Date(); t.setHours(0,0,0,0);
    const diff = Math.round((new Date(d.getFullYear(),d.getMonth(),d.getDate()) - t) / 86400000);
    if (diff===0) return 'Oggi'; if (diff===1) return 'Domani'; if (diff===2) return 'Dopodomani';
    return d.toLocaleDateString('it-IT',{weekday:'short',day:'2-digit',month:'short'});
}
function fmtTime(d)     { return d.toLocaleTimeString('it-IT',{hour:'2-digit',minute:'2-digit'}); }
function fmtDateTime(d) { return d.toLocaleString('it-IT',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}); }

// ════════════════════════════════════════════════════════
// RESIZE & INIT
// ════════════════════════════════════════════════════════
function resize() {
    canvas.width  = zone.clientWidth;
    canvas.height = zone.clientHeight;
    draw();
}

const ro = new ResizeObserver(resize);
ro.observe(zone);
resize();
updateDuration();

// Riapri pannello dopo POST
if (reopenId) {
    const hitItem = desksData.find(d => d.id == reopenId);
    if (hitItem) {
        selectedId = reopenId;
        setTimeout(() => { openPanel(hitItem); draw(); }, 80);
    }
}
</script>

</body>
</html>
