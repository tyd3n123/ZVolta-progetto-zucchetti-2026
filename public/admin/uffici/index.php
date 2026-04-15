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


// ── Stats ──────────────────────────────────────────────
$totalOffices = count($officeSpots);
$availCount   = count(array_filter($officeSpots, fn($s) => strtolower($s['status']) !== 'occupato'));
$occCount     = $totalOffices - $availCount;
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
      <p class="uf-page-sub">Gestisci le prenotazioni degli uffici</p>
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


</body>
</html>
