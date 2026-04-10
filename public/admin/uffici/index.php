<?php
session_start();
require_once __DIR__ . "/../../../config/config.php";

// ── Auth + Admin check ─────────────────────────────────
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['id_utente'])) {
    header("Location: ../../login.php"); exit();
}
$id_utente = (int)$_SESSION['id_utente'];

$stmt = $conn->prepare("SELECT r.nome_ruolo FROM utenti u JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE u.id_utente = ?");
$stmt->bind_param("i", $id_utente); $stmt->execute();
$callerRole = $stmt->get_result()->fetch_assoc()['nome_ruolo'] ?? '';
$stmt->close();
if (strtolower($callerRole) !== 'admin') { header("Location: ../../login.php"); exit(); }

// ── Info utente ────────────────────────────────────────
$stmt = $conn->prepare("SELECT u.nome, u.cognome, r.nome_ruolo AS ruolo FROM utenti u LEFT JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE u.id_utente = ? LIMIT 1");
$stmt->bind_param("i", $id_utente); $stmt->execute();
$userInfo = $stmt->get_result()->fetch_assoc() ?? ['nome'=>'','cognome'=>'','ruolo'=>''];
$stmt->close();

// ── POST handlers ──────────────────────────────────────
$feedback = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Nuova prenotazione ─────────────────────────────
    if ($action === 'book') {
        $id_asset    = (int)($_POST['id_asset'] ?? 0);
        $data_inizio = $_POST['data_inizio'] ?? '';
        $data_fine   = $_POST['data_fine']   ?? '';

        if (!$id_asset || !$data_inizio || !$data_fine) {
            $feedback = ['type'=>'error','msg'=>'Compila tutti i campi prima di procedere.'];
        } elseif (strtotime($data_fine) <= strtotime($data_inizio)) {
            $feedback = ['type'=>'error','msg'=>'La data fine deve essere successiva alla data di inizio.'];
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM prenotazioni WHERE id_asset=? AND data_inizio<? AND data_fine>?");
            $stmt->bind_param("iss", $id_asset, $data_fine, $data_inizio); $stmt->execute();
            $overlap = $stmt->get_result()->fetch_assoc()['c']; $stmt->close();

            if ($overlap > 0) {
                $feedback = ['type'=>'error','msg'=>"L'ufficio è già occupato nel periodo selezionato."];
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
                    $feedback = ['type'=>'success','msg'=>'Ufficio prenotato con successo!'];
                } catch (Exception $e) {
                    $conn->rollback();
                    $feedback = ['type'=>'error','msg'=>'Errore: '.$e->getMessage()];
                }
            }
        }
    }

    // ── Cancella prenotazione (admin) ──────────────────
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
                } else {
                    $stmt->close();
                    $feedback = ['type'=>'error','msg'=>'Errore durante la cancellazione.'];
                }
            }
        }
    }
}

// ── Fetch uffici (ordinati per piano poi nome) ─────────
$officeSpots = [];
$result = $conn->query(
    "SELECT a.id_asset, a.codice_asset, a.stato,
            COALESCE(u.numero_ufficio,'-') AS numero_ufficio,
            COALESCE(u.piano,'-')          AS piano,
            COALESCE(u.capacita,'-')       AS capacita,
            COALESCE(u.telefono_interno,'-') AS telefono_interno
     FROM asset a
     LEFT JOIN ufficio_dettagli u ON u.id_asset = a.id_asset
     WHERE a.mappa = 'Sede' AND a.codice_asset LIKE 'Ufficio%'
     ORDER BY u.piano, a.codice_asset"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $officeSpots[$row['id_asset']] = [
            'id'               => (int)$row['id_asset'],
            'name'             => $row['codice_asset'],
            'status'           => $row['stato'],
            'numero_ufficio'   => $row['numero_ufficio'],
            'piano'            => $row['piano'],
            'capacita'         => $row['capacita'],
            'telefono_interno' => $row['telefono_interno'],
        ];
    }
}

// ── Raggruppa per piano ────────────────────────────────
$floorMap = [];
foreach ($officeSpots as $id => $spot) {
    $piano = ($spot['piano'] !== '-' && $spot['piano'] !== '') ? (string)$spot['piano'] : '1';
    $floorMap[$piano][] = $spot;
}
ksort($floorMap, SORT_NATURAL);
$floors = array_keys($floorMap);

// ── Prenotazioni attive dell'utente ───────────────────
$userBookings = [];
$stmt = $conn->prepare(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine, a.codice_asset, a.id_asset
     FROM prenotazioni p JOIN asset a ON p.id_asset = a.id_asset
     WHERE p.id_utente = ? AND a.mappa = 'Sede' AND a.codice_asset LIKE 'Ufficio%'
       AND p.data_fine >= NOW()
     ORDER BY p.data_inizio ASC"
);
$stmt->bind_param("i", $id_utente); $stmt->execute();
$result = $stmt->get_result(); while ($row = $result->fetch_assoc()) $userBookings[] = $row; $stmt->close();

// ── Slot occupati per asset ────────────────────────────
$occupiedSlotsByAsset = [];
$result = $conn->query(
    "SELECT p.id_asset, p.data_inizio, p.data_fine
     FROM prenotazioni p JOIN asset a ON p.id_asset = a.id_asset
     WHERE a.mappa = 'Sede' AND a.codice_asset LIKE 'Ufficio%' AND p.data_fine >= NOW()
     ORDER BY p.data_inizio ASC"
);
if ($result) while ($row = $result->fetch_assoc()) $occupiedSlotsByAsset[$row['id_asset']][] = ['inizio'=>$row['data_inizio'],'fine'=>$row['data_fine']];

// ── Prenotazione in corso per asset (vista admin) ──────
$currentBookingByAsset = [];
$result = $conn->query(
    "SELECT p.id_asset, p.id_prenotazione, p.data_inizio, p.data_fine, u.nome, u.cognome
     FROM prenotazioni p
     JOIN utenti u ON p.id_utente = u.id_utente
     JOIN asset a ON p.id_asset = a.id_asset
     WHERE a.mappa = 'Sede' AND a.codice_asset LIKE 'Ufficio%'
       AND p.data_inizio <= NOW() AND p.data_fine >= NOW()"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $currentBookingByAsset[$row['id_asset']] = [
            'id_prenotazione' => $row['id_prenotazione'],
            'nome'            => $row['nome'],
            'cognome'         => $row['cognome'],
            'data_inizio'     => $row['data_inizio'],
            'data_fine'       => $row['data_fine'],
        ];
    }
}

// ── Stats ──────────────────────────────────────────────
$totalOffices = count($officeSpots);
$availCount   = count(array_filter($officeSpots, fn($s) => strtolower($s['status']) !== 'occupato'));
$occCount     = $totalOffices - $availCount;
$reopenAssetId = (!empty($_POST['id_asset'])) ? (int)$_POST['id_asset'] : 0;
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Uffici | Northstar</title>
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
      <span class="bc-current">Uffici</span>
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
      <h2 class="uf-page-title">🏢 Uffici</h2>
      <p class="uf-page-sub">Clicca su una postazione nella mappa per prenotarla o gestirla</p>
    </div>
    <div class="uf-stats-row">
      <span class="uf-stat-chip uf-stat-chip--total">🏢 <?= $totalOffices ?> uffici</span>
      <span class="uf-stat-chip uf-stat-chip--avail">✓ <?= $availCount ?> liberi</span>
      <?php if ($occCount > 0):?><span class="uf-stat-chip uf-stat-chip--occ">✗ <?= $occCount ?> occupati</span><?php endif;?>
    </div>
  </div>

  <!-- ── Feedback ────────────────────────────────────── -->
  <?php if ($feedback):?>
  <div class="uf-feedback uf-feedback--<?= $feedback['type'] ?>">
    <?= $feedback['type'] === 'success' ? '✅' : '⚠️' ?> <?= htmlspecialchars($feedback['msg']) ?>
  </div>
  <?php endif;?>

  <!-- ── Tab piano (solo se più di un piano) ─────────── -->
  <?php if (count($floors) > 1):?>
  <div class="uf-floor-tabs" role="tablist">
    <?php foreach ($floors as $i => $floor):?>
    <button class="uf-floor-tab<?= $i === 0 ? ' active' : '' ?>"
            role="tab"
            data-floor="<?= htmlspecialchars($floor) ?>"
            onclick="setFloor('<?= htmlspecialchars($floor) ?>')">
      Piano <?= htmlspecialchars($floor) ?>
      <span class="uf-tab-count"><?= count($floorMap[$floor]) ?></span>
    </button>
    <?php endforeach;?>
  </div>
  <?php endif;?>

  <!-- ── Mappa + Pannello laterale ───────────────────── -->
  <div class="uf-map-wrap" id="uf-map-wrap">

    <!-- Canvas floorplan -->
    <div class="uf-canvas-zone" id="uf-canvas-zone">
      <canvas id="floorCanvas"></canvas>
      <div class="uf-map-legend">
        <div class="uf-leg-item"><span class="uf-leg-dot" style="background:#4ade80"></span>Disponibile</div>
        <div class="uf-leg-item"><span class="uf-leg-dot" style="background:#f87171"></span>Occupato</div>
        <div class="uf-leg-item"><span class="uf-leg-dot" style="background:#94a3b8"></span>Zona comune</div>
        <div class="uf-leg-item"><span class="uf-leg-dot" style="background:#cbd5e1;border:1px dashed #94a3b8"></span>Vuoto</div>
      </div>
    </div>

    <!-- Pannello laterale -->
    <div class="uf-side-panel" id="uf-side-panel">
      <div class="uf-panel-inner">

        <div class="uf-panel-top">
          <div>
            <h3 class="uf-panel-name" id="p-name">—</h3>
            <div id="p-badge" style="margin-top:6px"></div>
          </div>
          <button class="uf-panel-close" onclick="closePanel()">✕</button>
        </div>

        <!-- Info tiles -->
        <div class="uf-info-grid" id="p-info"></div>

        <div class="uf-psep"></div>

        <!-- Occupazione attuale (admin) -->
        <p class="uf-section-lbl">👤 Occupazione attuale</p>
        <div id="p-current-booker"></div>

        <div class="uf-psep"></div>

        <!-- Slot prenotati -->
        <p class="uf-section-lbl">📅 Periodi prenotati</p>
        <div id="p-slots"></div>

        <div class="uf-psep"></div>

        <!-- Form nuova prenotazione -->
        <p class="uf-section-lbl">✏️ Nuova prenotazione</p>
        <form method="POST" id="booking-form">
          <input type="hidden" name="action" value="book">
          <input type="hidden" name="id_asset" id="f-asset-id" value="">
          <div class="uf-field">
            <label class="uf-field-lbl">Data inizio</label>
            <input class="uf-field-input" type="datetime-local" name="data_inizio" id="f-start"
                   value="<?= htmlspecialchars($_POST['data_inizio'] ?? '') ?>" required onchange="updateDur()">
          </div>
          <div class="uf-field">
            <label class="uf-field-lbl">Data fine</label>
            <input class="uf-field-input" type="datetime-local" name="data_fine" id="f-end"
                   value="<?= htmlspecialchars($_POST['data_fine'] ?? '') ?>" required onchange="updateDur()">
          </div>
          <div id="f-dur" class="uf-dur-preview" style="display:none"></div>
          <div id="f-err" class="uf-form-error" style="display:none"></div>
          <button type="submit" class="uf-submit-btn" id="f-btn">Conferma prenotazione</button>
        </form>

      </div>
    </div><!-- /.uf-side-panel -->

  </div><!-- /.uf-map-wrap -->

  <!-- ── Prenotazioni attive utente ──────────────────── -->
  <div class="uf-bookings-strip">
    <div class="uf-strip-header">
      <p class="uf-strip-title">Le tue prenotazioni attive</p>
      <span class="uf-count-badge"><?= count($userBookings) ?></span>
    </div>
    <?php if (empty($userBookings)):?>
      <div class="uf-empty"><span>🏢</span><p>Nessuna prenotazione ufficio attiva</p></div>
    <?php else:?>
      <div class="uf-bookings-list">
        <?php foreach ($userBookings as $b):
          $start = new DateTime($b['data_inizio']); $end = new DateTime($b['data_fine']); $now = new DateTime();
          $isActive = $start <= $now && $end >= $now;
          $sClass = $isActive ? 'uf-status--now' : 'uf-status--future';
          $sLabel = $isActive ? 'In corso' : 'Programmato';
          $diff = $start->diff($end); $days = $diff->days; $hours = $diff->h;
          $durStr = $days > 0 ? "{$days}g {$hours}h" : "{$hours}h {$diff->i}m";
        ?>
        <div class="uf-booking-item">
          <div class="uf-booking-top">
            <span class="uf-asset-pill"><?= htmlspecialchars($b['codice_asset']) ?></span>
            <span class="uf-status-pill <?= $sClass ?>"><?= $sLabel ?></span>
            <span class="uf-dur"><?= $durStr ?></span>
          </div>
          <div class="uf-booking-dates">
            <?= date('d/m/Y H:i', strtotime($b['data_inizio'])) ?> <span class="uf-arrow">→</span> <?= date('d/m/Y H:i', strtotime($b['data_fine'])) ?>
          </div>
        </div>
        <?php endforeach;?>
      </div>
    <?php endif;?>
  </div>

</div><!-- /.uf-page -->

<!-- ═══════════════════════════════════════════════════
     JAVASCRIPT — Canvas floorplan + interazioni
     ═══════════════════════════════════════════════════ -->
<script>
// ── Dati da PHP ───────────────────────────────────────
const floorMap        = <?= json_encode($floorMap) ?>;
const occupiedSlots   = <?= json_encode($occupiedSlotsByAsset) ?>;
const currentBookings = <?= json_encode($currentBookingByAsset) ?>;
const reopenAssetId   = <?= $reopenAssetId ?>;
const floors          = <?= json_encode($floors) ?>;

let currentFloor        = floors[0] || null;
let currentFloorOffices = currentFloor ? (floorMap[currentFloor] || []) : [];
let selectedId          = null;

// ── Canvas setup ──────────────────────────────────────
const canvas = document.getElementById('floorCanvas');
const ctx    = canvas.getContext('2d');
const zone   = document.getElementById('uf-canvas-zone');
const isDark = matchMedia('(prefers-color-scheme:dark)').matches;

// Palette colori
const T = {
  wall:'#c8c4bb', wallFill:'#e8e4dc', floor:'#f4f1ec',
  wood:'#c8a87a', woodDark:'#b89060',
  glass:'rgba(180,220,255,0.45)', glassBrd:'rgba(150,200,255,0.7)',
  desk:'#d6cabc', deskTop:'#e8ddd0', monitor:'#2d2d2d', screen:'#0d2040',
  chair:'#64748b',
  avail:'#4ade80', occ:'#f87171', sel:'#818cf8',
  selGlow:'rgba(129,140,248,0.35)', selRing:'#818cf8',
  empty:'#cbd5e1', emptyFill:'#e2e8f0',
  plant:'#4ade80', plantDrk:'#16a34a',
  rug0:'rgba(129,140,248,0.08)',
};
if (isDark) Object.assign(T, {
  wall:'#3a3832', wallFill:'#292722', floor:'#1e1c18',
  wood:'#3d3020', woodDark:'#2e2418',
  glass:'rgba(120,160,210,0.18)', glassBrd:'rgba(120,160,210,0.35)',
  desk:'#3a3530', deskTop:'#4a4035', monitor:'#2a2a2a',
  empty:'#334155', emptyFill:'#1e293b',
  rug0:'rgba(129,140,248,0.12)',
});

const MAP_W = 860, MAP_H = 520;
let spots = [];

function getOffset() {
  return { ox: Math.max(0, (canvas.width - MAP_W) / 2), oy: Math.max(0, (canvas.height - MAP_H) / 2) };
}
function resize() { canvas.width = zone.clientWidth; canvas.height = zone.clientHeight; draw(); }

// Rettangolo con angoli arrotondati
function rr(x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x+r, y); ctx.arcTo(x+w,y,x+w,y+h,r); ctx.arcTo(x+w,y+h,x,y+h,r);
  ctx.arcTo(x,y+h,x,y,r); ctx.arcTo(x,y,x+w,y,r); ctx.closePath();
}

// ── Disegno principale ────────────────────────────────
function draw() {
  const {ox, oy} = getOffset();
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  ctx.fillStyle = T.floor; ctx.fillRect(0, 0, canvas.width, canvas.height);
  drawShell(ox, oy);
  drawAreas(ox, oy);
  drawFurniture(ox, oy);
  drawDesks(ox, oy);
  drawDeco(ox, oy);
  drawFloorLabel(ox, oy);
}

// ── Struttura edificio ────────────────────────────────
function drawShell(ox, oy) {
  // Perimetro
  rr(ox+10, oy+10, MAP_W-20, MAP_H-20, 16);
  ctx.fillStyle = T.wallFill; ctx.fill();
  ctx.strokeStyle = T.wall; ctx.lineWidth = 10; ctx.stroke(); ctx.lineWidth = 1;

  // Pavimento con piastrelle
  ctx.save(); ctx.clip();
  ctx.fillStyle = isDark ? '#1a1816' : '#ede8e0'; ctx.fillRect(ox+15, oy+15, MAP_W-30, MAP_H-30);
  ctx.strokeStyle = isDark ? 'rgba(255,255,255,0.04)' : 'rgba(0,0,0,0.05)'; ctx.lineWidth = 0.5;
  for (let yy = oy+15; yy < oy+MAP_H-15; yy += 24) {
    const off = ((yy/24) % 2) * 60;
    for (let xx = ox+15-off; xx < ox+MAP_W-15; xx += 120) {
      ctx.beginPath(); ctx.rect(xx, yy, 119, 23); ctx.stroke();
    }
  }
  ctx.restore();

  // Finestre laterali
  [[ox+10,oy+80,8,80],[ox+10,oy+220,8,80],[ox+10,oy+360,8,80],
   [ox+MAP_W-18,oy+80,8,80],[ox+MAP_W-18,oy+220,8,80],[ox+MAP_W-18,oy+360,8,80]
  ].forEach(([x,y,w,h]) => {
    ctx.fillStyle = T.glass; ctx.fillRect(x,y,w,h);
    ctx.strokeStyle = T.glassBrd; ctx.lineWidth = 1; ctx.strokeRect(x,y,w,h);
    ctx.lineWidth = 0.5;
    ctx.beginPath(); ctx.moveTo(x+w/2,y); ctx.lineTo(x+w/2,y+h); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(x,y+h/2); ctx.lineTo(x+w,y+h/2); ctx.stroke();
  });

  // Finestre superiori
  [[ox+150,oy+10,100,8],[ox+380,oy+10,100,8],[ox+590,oy+10,100,8]
  ].forEach(([x,y,w,h]) => {
    ctx.fillStyle = T.glass; ctx.fillRect(x,y,w,h);
    ctx.strokeStyle = T.glassBrd; ctx.lineWidth = 1; ctx.strokeRect(x,y,w,h);
    ctx.lineWidth = 0.5;
    ctx.beginPath(); ctx.moveTo(x+w/3,y); ctx.lineTo(x+w/3,y+h); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(x+2*w/3,y); ctx.lineTo(x+2*w/3,y+h); ctx.stroke();
  });
}

// ── Zone funzionali ───────────────────────────────────
function drawAreas(ox, oy) {
  const zones = [
    {x:ox+20,  y:oy+20,  w:190, h:170, c:'rgba(99,102,241',  l:'SALA RIUNIONI', lx:ox+115,  ly:oy+40},
    {x:ox+20,  y:oy+340, w:165, h:155, c:'rgba(20,184,166',  l:'ZONA BREAK',    lx:ox+102,  ly:oy+360},
    {x:ox+MAP_W-175,y:oy+20,w:155,h:115,c:'rgba(148,163,184',l:'SERVIZI',       lx:ox+MAP_W-97,ly:oy+40},
  ];
  zones.forEach(z => {
    ctx.fillStyle = z.c+',0.09)'; rr(z.x,z.y,z.w,z.h,8); ctx.fill();
    ctx.strokeStyle = z.c+',0.3)'; ctx.lineWidth = 1; rr(z.x,z.y,z.w,z.h,8); ctx.stroke();
    ctx.fillStyle = z.c+',0.7)'; ctx.font = '600 11px system-ui';
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillText(z.l, z.lx, z.ly);
  });

  // Divisorio servizi
  ctx.strokeStyle = T.wall; ctx.lineWidth = 2;
  ctx.beginPath();
  ctx.moveTo(ox+MAP_W-175+78, oy+20); ctx.lineTo(ox+MAP_W-175+78, oy+135); ctx.stroke();

  // Tappeto centrale
  ctx.fillStyle = T.rug0;
  rr(ox+280, oy+175, 310, 155, 6); ctx.fill();
  ctx.strokeStyle = isDark ? 'rgba(129,140,248,0.2)' : 'rgba(129,140,248,0.15)'; ctx.lineWidth = 0.5;
  rr(ox+280, oy+175, 310, 155, 6); ctx.stroke();
  rr(ox+290, oy+185, 290, 135, 4); ctx.stroke();
}

// ── Arredo ────────────────────────────────────────────
function drawFurniture(ox, oy) {
  // Tavolo sala riunioni (ellisse)
  ctx.fillStyle = T.wood;
  ctx.beginPath(); ctx.ellipse(ox+115, oy+100, 72, 44, 0, 0, Math.PI*2); ctx.fill();
  ctx.strokeStyle = T.woodDark; ctx.lineWidth = 1; ctx.stroke();
  // Riflesso
  ctx.fillStyle = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(255,255,255,0.3)';
  ctx.beginPath(); ctx.ellipse(ox+105, oy+91, 46, 25, -0.3, 0, Math.PI*2); ctx.fill();
  // Sedie riunioni
  [[ox+115,oy+48],[ox+115,oy+152],[ox+55,oy+80],[ox+55,oy+120],
   [ox+175,oy+80],[ox+175,oy+120],[ox+80,oy+50],[ox+150,oy+50]
  ].forEach(([cx,cy]) => { ctx.fillStyle = T.chair; rr(cx-9,cy-7,18,14,3); ctx.fill(); });

  // Mobile break room
  ctx.fillStyle = isDark ? '#2a2822' : '#d1c8b8';
  rr(ox+25, oy+345, 150, 37, 4); ctx.fill();
  ctx.strokeStyle = T.woodDark; ctx.lineWidth = 0.5; rr(ox+25,oy+345,150,37,4); ctx.stroke();
  ctx.fillStyle = isDark ? '#1e1c18' : '#b8b0a0'; rr(ox+33,oy+351,32,22,3); ctx.fill();
  ctx.fillStyle = isDark ? '#2a2822' : '#c0b8a8'; rr(ox+75,oy+351,25,24,3); ctx.fill();
  ctx.fillStyle = isDark ? '#0d2040' : '#1a3a6a'; rr(ox+79,oy+354,17,10,2); ctx.fill();
  ctx.fillStyle = isDark ? '#1a1816' : '#b8b0a0'; rr(ox+110,oy+351,28,22,3); ctx.fill();
  ctx.fillStyle = isDark ? '#0d2040' : '#1a3a6a'; rr(ox+113,oy+354,16,12,2); ctx.fill();

  // Scaffalatura bassa
  ctx.fillStyle = isDark ? '#3a3530' : '#b8b0a0'; rr(ox+35,oy+418,120,30,5); ctx.fill();
  ctx.fillStyle = isDark ? '#2e2a26' : '#a8a098'; rr(ox+35,oy+398,120,22,5); ctx.fill();
  ctx.strokeStyle = isDark ? '#4a4540' : '#988e82'; ctx.lineWidth = 0.5;
  rr(ox+35,oy+398,120,50,5); ctx.stroke();
  ctx.fillStyle = isDark ? 'rgba(99,102,241,0.3)' : 'rgba(99,102,241,0.25)';
  rr(ox+45,oy+402,46,13,3); ctx.fill(); rr(ox+99,oy+402,46,13,3); ctx.fill();

  // Tavolo break
  ctx.fillStyle = T.wood; rr(ox+75,oy+452,60,35,5); ctx.fill();
  ctx.strokeStyle = T.woodDark; ctx.lineWidth = 0.5; rr(ox+75,oy+452,60,35,5); ctx.stroke();

  // Sanitari (area servizi)
  [[ox+MAP_W-168,oy+42],[ox+MAP_W-95,oy+42]].forEach(([x,y]) => {
    ctx.fillStyle = isDark ? '#2a2822' : '#f0ece4';
    ctx.strokeStyle = isDark ? '#4a4540' : '#d0c8b8'; ctx.lineWidth = 0.5;
    rr(x+4,y,30,40,8); ctx.fill(); ctx.stroke();
    ctx.beginPath(); ctx.ellipse(x+19,y+30,13,10,0,0,Math.PI*2); ctx.fill(); ctx.stroke();
    ctx.fillStyle = isDark ? '#2a2822' : '#e8e4dc'; rr(x+4,y+44,30,22,4); ctx.fill();
    ctx.fillStyle = isDark ? '#1e1c18' : '#d0c8b8'; rr(x+8,y+48,22,14,3); ctx.fill();
  });
}

// ── Piante decorative ─────────────────────────────────
function drawPlant(x, y, r) {
  ctx.fillStyle = T.plantDrk; ctx.beginPath(); ctx.arc(x,y,r+1,0,Math.PI*2); ctx.fill();
  ctx.fillStyle = T.plant;
  [[x-r*.4,y-r*.3,r*.7],[x+r*.4,y-r*.3,r*.7],[x,y-r*.6,r*.8]].forEach(([px,py,pr]) => {
    ctx.beginPath(); ctx.arc(px,py,pr,0,Math.PI*2); ctx.fill();
  });
  ctx.fillStyle = isDark ? '#5a4a3a' : '#c8a87a';
  rr(x-r*.5, y+r*.2, r, r*.8, 2); ctx.fill();
}

function drawDeco(ox, oy) {
  drawPlant(ox+MAP_W-38, oy+MAP_H-38, 18);
  drawPlant(ox+240, oy+MAP_H-34, 13);
  drawPlant(ox+MAP_W-38, oy+310, 13);
  drawPlant(ox+220, oy+20, 11);
  // Orologio da parete
  ctx.fillStyle = isDark ? '#3a3530' : '#ffffff';
  ctx.strokeStyle = isDark ? '#4a4540' : '#d0c8b8'; ctx.lineWidth = 1;
  ctx.beginPath(); ctx.arc(ox+MAP_W/2, oy+26, 15, 0, Math.PI*2); ctx.fill(); ctx.stroke();
  ctx.strokeStyle = isDark ? '#8a8480' : '#3d3830'; ctx.lineWidth = 1.5;
  ctx.beginPath(); ctx.moveTo(ox+MAP_W/2, oy+26); ctx.lineTo(ox+MAP_W/2, oy+16); ctx.stroke();
  ctx.lineWidth = 1;
  ctx.beginPath(); ctx.moveTo(ox+MAP_W/2, oy+26); ctx.lineTo(ox+MAP_W/2+7, oy+29); ctx.stroke();
}

// ── Etichetta piano sul canvas ────────────────────────
function drawFloorLabel(ox, oy) {
  if (!currentFloor) return;
  ctx.save();
  ctx.fillStyle = isDark ? 'rgba(255,255,255,0.12)' : 'rgba(0,0,0,0.10)';
  ctx.font = '600 11px system-ui';
  ctx.textAlign = 'right';
  ctx.textBaseline = 'bottom';
  ctx.fillText('Piano ' + currentFloor, ox + MAP_W - 22, oy + MAP_H - 18);
  ctx.restore();
}

// ══════════════════════════════════════════════════════
// POSTAZIONI — layout planimetrico (fino a 20 uffici)
// ══════════════════════════════════════════════════════
const DESK_W = 62, DESK_H = 40, SEAT_R = 13;

// Posizioni hardcoded nella planimetria (aggiunte fino a 20)
const deskDefs = [
  // Fila superiore (tra sala riunioni e servizi)
  {x:250,y:45,rot:'up'},  // 0
  {x:332,y:45,rot:'up'},  // 1
  {x:414,y:45,rot:'up'},  // 2
  {x:496,y:45,rot:'up'},  // 3
  {x:578,y:45,rot:'up'},  // 4
  // Colonna sinistra (area centrale sinistra)
  {x:225,y:205,rot:'right'}, // 5
  {x:225,y:285,rot:'right'}, // 6
  // Colonna destra (area centrale destra)
  {x:588,y:205,rot:'left'},  // 7
  {x:588,y:285,rot:'left'},  // 8
  // Fila inferiore (sopra zona break)
  {x:300,y:380,rot:'up'}, // 9
  {x:382,y:380,rot:'up'}, // 10
  {x:464,y:380,rot:'up'}, // 11
  {x:546,y:380,rot:'up'}, // 12
  // Cluster centrale (visibile sul tappeto)
  {x:325,y:200,rot:'up'}, // 13
  {x:407,y:200,rot:'up'}, // 14
  {x:489,y:200,rot:'up'}, // 15
  {x:325,y:285,rot:'up'}, // 16
  {x:407,y:285,rot:'up'}, // 17
  {x:489,y:285,rot:'up'}, // 18
  // Extra fila inferiore laterale
  {x:628,y:380,rot:'up'}, // 19
];

// ── Disegna tutte le postazioni ───────────────────────
function drawDesks(ox, oy) {
  spots = [];
  deskDefs.forEach((d, idx) => {
    const office = currentFloorOffices[idx] || null;
    const rot    = d.rot || 'up';
    const isOcc  = office && office.status.toLowerCase() === 'occupato';
    const isSel  = office && office.id == selectedId;
    const dx = ox + d.x, dy = oy + d.y;

    // Hit-box per click
    let hx = dx, hy = dy, hw = DESK_W, hh = DESK_H + SEAT_R*2 + 8;
    if (rot === 'right' || rot === 'left') { hw = DESK_H + SEAT_R*2 + 8; hh = DESK_W; }

    if (office) spots.push({id: office.id, office, hx, hy, hw, hh});

    if (isSel) { ctx.shadowColor = T.selRing; ctx.shadowBlur = 20; }
    drawDesk(dx, dy, rot, isOcc, isSel, office);
    if (isSel) { ctx.shadowColor = 'transparent'; ctx.shadowBlur = 0; }
  });
}

// ── Disegna singola postazione ────────────────────────
function drawDesk(dx, dy, rot, isOcc, isSel, office) {
  const isEmpty = !office;
  const sc = isEmpty ? T.empty : (isOcc ? T.occ : T.avail);

  let sx, sy, deskX, deskY, dw, dh;
  if (rot === 'up') {
    deskX = dx; deskY = dy + SEAT_R*2 + 4;
    sx = dx + DESK_W/2; sy = dy + SEAT_R;
    dw = DESK_W; dh = DESK_H;
  } else if (rot === 'down') {
    deskX = dx; deskY = dy;
    sx = dx + DESK_W/2; sy = dy + DESK_H + SEAT_R + 4;
    dw = DESK_W; dh = DESK_H;
  } else if (rot === 'right') {
    deskX = dx + SEAT_R*2 + 4; deskY = dy + (DESK_W - DESK_H) / 2;
    sx = dx + SEAT_R; sy = dy + DESK_W/2;
    dw = DESK_H; dh = DESK_W;
  } else { // left
    deskX = dx; deskY = dy + (DESK_W - DESK_H) / 2;
    sx = dx + DESK_H + SEAT_R + 4; sy = dy + DESK_W/2;
    dw = DESK_H; dh = DESK_W;
  }

  // Alone colorato intorno alla sedia
  const glowColor = isEmpty
    ? 'rgba(148,163,184,0.15)'
    : (isSel ? T.selGlow : (isOcc ? 'rgba(248,113,113,0.2)' : 'rgba(74,222,128,0.2)'));
  ctx.fillStyle = glowColor;
  ctx.beginPath(); ctx.arc(sx, sy, SEAT_R+5, 0, Math.PI*2); ctx.fill();

  // Sedia (cerchio status)
  ctx.fillStyle = sc;
  ctx.strokeStyle = '#ffffff'; ctx.lineWidth = 2;
  ctx.beginPath(); ctx.arc(sx, sy, SEAT_R, 0, Math.PI*2); ctx.fill(); ctx.stroke();

  // Anello selezione
  if (isSel) {
    ctx.strokeStyle = T.selRing; ctx.lineWidth = 2.5;
    ctx.beginPath(); ctx.arc(sx, sy, SEAT_R+5, 0, Math.PI*2); ctx.stroke();
  }

  // Codice ufficio nella sedia
  if (office) {
    const code = office.name.replace(/Ufficio\s*/i, '').substring(0, 4);
    ctx.fillStyle = '#ffffff';
    ctx.font = '600 9px system-ui';
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    ctx.fillText(code, sx, sy);
  }

  // Scrivania — ombra
  ctx.fillStyle = isDark ? 'rgba(0,0,0,0.4)' : 'rgba(0,0,0,0.07)';
  rr(deskX+2, deskY+2, dw, dh, 5); ctx.fill();

  // Scrivania — piano
  if (isEmpty) {
    ctx.fillStyle = T.emptyFill;
    rr(deskX, deskY, dw, dh, 5); ctx.fill();
    ctx.strokeStyle = T.empty; ctx.lineWidth = 1;
    rr(deskX, deskY, dw, dh, 5); ctx.stroke();
    // Tratteggio "vuoto"
    ctx.save(); rr(deskX, deskY, dw, dh, 5); ctx.clip();
    ctx.strokeStyle = isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.07)';
    ctx.lineWidth = 0.7; ctx.setLineDash([4, 4]);
    for (let yy = deskY; yy < deskY+dh+dw; yy += 7) {
      ctx.beginPath(); ctx.moveTo(deskX, yy); ctx.lineTo(deskX+dw, yy-dw); ctx.stroke();
    }
    ctx.setLineDash([]); ctx.restore();
    return; // non disegno monitor su posto vuoto
  }

  ctx.fillStyle = T.deskTop;
  rr(deskX, deskY, dw, dh, 5); ctx.fill();
  ctx.strokeStyle = T.desk; ctx.lineWidth = 0.8;
  rr(deskX, deskY, dw, dh, 5); ctx.stroke();

  // Monitor
  const mx = deskX + dw/2, my = deskY + dh/2;
  ctx.fillStyle = T.monitor; rr(mx-10, my-8, 20, 13, 2); ctx.fill();
  ctx.fillStyle = T.screen;  rr(mx-8.5, my-6.5, 17, 9, 1); ctx.fill();
  ctx.fillStyle = T.monitor; ctx.fillRect(mx-2, my+5, 4, 4); ctx.fillRect(mx-5, my+8, 10, 2);

  // Mouse
  ctx.fillStyle = isDark ? '#7c6a50' : '#c8a87a';
  ctx.beginPath(); ctx.arc(deskX+dw-10, deskY+dh-8, 4, 0, Math.PI*2); ctx.fill();
  ctx.fillStyle = isDark ? '#0d1520' : '#3d2010';
  ctx.beginPath(); ctx.arc(deskX+dw-10, deskY+dh-8, 2.5, 0, Math.PI*2); ctx.fill();

  // Carta / documenti
  ctx.fillStyle = isDark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.06)';
  rr(deskX+5, deskY+5, 14, 10, 1); ctx.fill();
  rr(deskX+7, deskY+7, 14, 10, 1); ctx.fill();
}

// ── Interazioni canvas ────────────────────────────────
canvas.addEventListener('click', function(e) {
  const r = canvas.getBoundingClientRect();
  const mx = e.clientX - r.left, my = e.clientY - r.top;
  let hit = null;
  for (const s of spots) {
    if (mx >= s.hx && mx <= s.hx+s.hw && my >= s.hy && my <= s.hy+s.hh) { hit = s; break; }
  }
  if (hit) { selectedId = hit.id; openPanel(hit.office); }
  else      { selectedId = null;  closePanel(); }
  draw();
});

canvas.addEventListener('mousemove', function(e) {
  const r = canvas.getBoundingClientRect();
  const mx = e.clientX - r.left, my = e.clientY - r.top;
  canvas.style.cursor = spots.some(s => mx >= s.hx && mx <= s.hx+s.hw && my >= s.hy && my <= s.hy+s.hh)
    ? 'pointer' : 'default';
});

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { selectedId = null; closePanel(); draw(); }
});

// ── Pannello laterale ─────────────────────────────────
function openPanel(office) {
  const isOcc = office.status.toLowerCase() === 'occupato';

  // Nome + badge stato
  document.getElementById('p-name').textContent = office.name;
  document.getElementById('p-badge').innerHTML =
    `<span class="uf-badge ${isOcc ? 'uf-badge--occ' : 'uf-badge--avail'}">${isOcc ? 'Occupato' : 'Disponibile'}</span>`;

  // Info tiles
  document.getElementById('p-info').innerHTML = `
    <div class="uf-info-tile"><div class="uf-tile-lbl">N° Ufficio</div><div class="uf-tile-val">${office.numero_ufficio}</div></div>
    <div class="uf-info-tile"><div class="uf-tile-lbl">Piano</div><div class="uf-tile-val">${office.piano}</div></div>
    <div class="uf-info-tile"><div class="uf-tile-lbl">Capacità</div><div class="uf-tile-val">${office.capacita} pers.</div></div>
    <div class="uf-info-tile"><div class="uf-tile-lbl">Tel. interno</div><div class="uf-tile-val">${office.telefono_interno}</div></div>
  `;

  // Occupazione attuale (admin feature)
  const cb  = currentBookings[office.id];
  const cbEl = document.getElementById('p-current-booker');
  if (cb) {
    cbEl.innerHTML = `
      <div class="uf-current-booking">
        <div class="uf-cb-header">
          <span class="uf-cb-avatar">${initials(cb.nome, cb.cognome)}</span>
          <div class="uf-cb-info">
            <span class="uf-cb-name">${esc(cb.nome)} ${esc(cb.cognome)}</span>
            <span class="uf-cb-pill">In corso</span>
          </div>
        </div>
        <div class="uf-cb-dates">${fmtDate(cb.data_inizio)} → ${fmtDate(cb.data_fine)}</div>
        <form method="POST" onsubmit="return confirm('Annullare questa prenotazione?')">
          <input type="hidden" name="action" value="cancel_booking">
          <input type="hidden" name="id_prenotazione" value="${cb.id_prenotazione}">
          <button type="submit" class="uf-cancel-btn">✕ Annulla prenotazione</button>
        </form>
      </div>`;
  } else {
    cbEl.innerHTML = `<div class="uf-slot-free">✓ Nessuna prenotazione in corso</div>`;
  }

  // Slot prenotati futuri
  const sl    = (occupiedSlots[office.id] || []).filter(s => new Date(s.fine) > new Date());
  const slEl  = document.getElementById('p-slots');
  slEl.innerHTML = sl.length > 0
    ? sl.map(s => `<div class="uf-slot-item"><span class="uf-slot-dot"></span><span>${fmtDate(s.inizio)}</span><span class="uf-slot-arrow">→</span><span>${fmtDate(s.fine)}</span></div>`).join('')
    : `<div class="uf-slot-free">✓ Nessun periodo prenotato</div>`;

  // Collega id_asset al form
  document.getElementById('f-asset-id').value = office.id;

  // Apri pannello
  document.getElementById('uf-side-panel').classList.add('open');
  document.getElementById('uf-map-wrap').classList.add('panel-open');
  updateDur();
}

function closePanel() {
  document.getElementById('uf-side-panel').classList.remove('open');
  document.getElementById('uf-map-wrap').classList.remove('panel-open');
}

// ── Selezione piano ───────────────────────────────────
function setFloor(floor) {
  currentFloor        = floor;
  currentFloorOffices = floorMap[floor] || [];
  selectedId          = null;
  closePanel();
  draw();
  document.querySelectorAll('.uf-floor-tab').forEach(t => {
    t.classList.toggle('active', t.dataset.floor === floor);
  });
}

// ── Preview durata ────────────────────────────────────
function updateDur() {
  const s = document.getElementById('f-start').value;
  const e = document.getElementById('f-end').value;
  const el  = document.getElementById('f-dur');
  const err = document.getElementById('f-err');
  const btn = document.getElementById('f-btn');
  if (!s || !e) { el.style.display = 'none'; err.style.display = 'none'; btn.disabled = false; return; }
  const ms = new Date(e) - new Date(s);
  if (ms <= 0) {
    el.style.display = 'none';
    err.style.display = ''; err.textContent = "⚠ La data fine deve essere successiva all'inizio";
    btn.disabled = true; return;
  }
  err.style.display = 'none'; btn.disabled = false;
  const d = Math.floor(ms/86400000), h = Math.floor((ms%86400000)/3600000), m = Math.floor((ms%3600000)/60000);
  const parts = []; if(d>0)parts.push(d+'g'); if(h>0)parts.push(h+'h'); if(m>0)parts.push(m+'m');
  el.style.display = ''; el.textContent = '⏱ Durata: ' + (parts.join(' ') || '< 1m');
}

// ── Helpers ───────────────────────────────────────────
function fmtDate(str) {
  return new Date(str).toLocaleString('it-IT', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
}
function initials(nome, cognome) {
  return ((nome[0]||'') + (cognome[0]||'')).toUpperCase();
}
function esc(s) {
  const d = document.createElement('div'); d.textContent = s; return d.innerHTML;
}

// ── Init ──────────────────────────────────────────────
const ro = new ResizeObserver(resize); ro.observe(zone); resize(); updateDur();

// Riapri pannello dopo un POST (es. prenotazione riuscita)
if (reopenAssetId) {
  for (const [floor, offices] of Object.entries(floorMap)) {
    const found = offices.find(o => o.id == reopenAssetId);
    if (found) {
      if (floor !== currentFloor) setFloor(floor);
      selectedId = reopenAssetId;
      setTimeout(() => { openPanel(found); draw(); }, 80);
      break;
    }
  }
}
</script>

</body>
</html>
