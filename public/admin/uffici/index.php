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
$officeSpots = []; // id_asset => [name, status, ...]
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

// ── Fetch prenotazioni attive dell'utente (uffici) ────
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
            <p class="uf-page-sub">Prenota un ufficio per il periodo desiderato</p>
        </div>
    </div>

    <!-- Feedback banner -->
    <?php if ($feedback): ?>
        <div class="uf-feedback uf-feedback--<?= $feedback['type'] ?>">
            <?= $feedback['type'] === 'success' ? '✅' : '⚠️' ?>
            <?= htmlspecialchars($feedback['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- Main grid -->
    <div class="uf-grid">

        <!-- ── LEFT: form prenotazione ─────────────────── -->
        <div class="uf-panel">
            <p class="uf-panel-title">Nuova Prenotazione</p>

            <form method="POST" id="booking-form">
                <input type="hidden" name="action" value="book">

                <!-- Selezione ufficio -->
                <div class="uf-field">
                    <label for="select-office">Ufficio</label>
                    <select id="select-office" name="id_asset" required onchange="onOfficeChange(this)">
                        <option value="">— Seleziona un ufficio —</option>
                        <?php foreach ($officeSpots as $id => $spot): ?>
                            <option value="<?= $id ?>"
                                    data-code="<?= htmlspecialchars($spot['name']) ?>"
                                    data-stato="<?= htmlspecialchars($spot['status']) ?>"
                                    data-numero="<?= htmlspecialchars($spot['numero_ufficio']) ?>"
                                    data-piano="<?= htmlspecialchars($spot['piano']) ?>"
                                    data-capacita="<?= htmlspecialchars($spot['capacita']) ?>"
                                    data-telefono="<?= htmlspecialchars($spot['telefono_interno']) ?>"
                                    <?= (!empty($_POST['id_asset']) && $_POST['id_asset'] == $id) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($spot['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Detail card (visibile dopo selezione) -->
                <div id="uf-detail-card" class="uf-detail-card" style="display:none;"></div>

                <!-- Slot occupati -->
                <div id="uf-slots-section" class="uf-slots-section" style="display:none;">
                    <p class="uf-slots-title">📅 Periodi già occupati</p>
                    <div class="uf-slots-list" id="uf-slots-list"></div>
                </div>

                <!-- Date -->
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

                <!-- Duration preview -->
                <div id="uf-duration-preview" class="uf-duration-preview" style="display:none;">
                    <span class="uf-duration-icon">⏱️</span>
                    <span id="uf-duration-text"></span>
                </div>

                <!-- Form error -->
                <div id="uf-form-error" class="uf-form-error" style="display:none;"></div>

                <button type="submit" class="uf-submit-btn" id="submit-btn">
                    Conferma Prenotazione
                </button>
            </form>
        </div>

        <!-- ── RIGHT ───────────────────────────────────── -->
        <div class="uf-right">

            <!-- Le tue prenotazioni attive -->
            <div class="uf-panel">
                <div class="uf-panel-header">
                    <p class="uf-panel-title">Le tue prenotazioni attive</p>
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
                                $statusClass = $isActive ? 'uf-status--now' : 'uf-status--future';
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

            <!-- Elenco uffici -->
            <div class="uf-panel">
                <div class="uf-panel-header">
                    <p class="uf-panel-title">Elenco Uffici</p>
                    <span class="uf-count-badge"><?= count($officeSpots) ?></span>
                </div>

                <?php if (empty($officeSpots)): ?>
                    <div class="uf-empty">
                        <span>🏢</span>
                        <p>Nessun ufficio disponibile</p>
                    </div>
                <?php else: ?>
                    <div class="uf-table-wrap">
                        <table class="uf-table">
                            <thead>
                                <tr>
                                    <th>Codice</th>
                                    <th>N° Ufficio</th>
                                    <th>Piano</th>
                                    <th>Capacità</th>
                                    <th>Tel. Interno</th>
                                    <th>Stato</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($officeSpots as $id => $spot): ?>
                                    <?php
                                        $isOcc   = strtolower($spot['status']) === 'occupato';
                                        $pillCls = $isOcc ? 'uf-status--occ' : 'uf-status--avail';
                                        $pillLbl = $isOcc ? 'Occupato' : 'Disponibile';
                                    ?>
                                    <tr class="uf-table-row" id="row-<?= $id ?>"
                                        onclick="selectOfficeFromTable(<?= $id ?>)">
                                        <td>
                                            <span class="uf-asset-pill uf-asset-pill--sm">
                                                <?= htmlspecialchars($spot['name']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($spot['numero_ufficio']) ?></td>
                                        <td><?= htmlspecialchars($spot['piano']) ?></td>
                                        <td><?= htmlspecialchars($spot['capacita']) ?></td>
                                        <td><?= htmlspecialchars($spot['telefono_interno']) ?></td>
                                        <td>
                                            <span class="uf-status-pill <?= $pillCls ?>">
                                                <?= $pillLbl ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- /.uf-right -->
    </div><!-- /.uf-grid -->
</div><!-- /.uf-page -->

<script>
// ── Dati slot occupati (PHP → JS) ─────────────────────
const occupiedSlots = <?= json_encode($occupiedSlotsByAsset) ?>;

// ── Selezione ufficio da dropdown ─────────────────────
function onOfficeChange(select) {
    const opt    = select.options[select.selectedIndex];
    const id     = select.value;
    const detail = document.getElementById('uf-detail-card');
    const slots  = document.getElementById('uf-slots-section');

    // Highlight riga tabella
    document.querySelectorAll('.uf-table-row').forEach(r => r.classList.remove('uf-table-row--selected'));
    if (id) document.getElementById('row-' + id)?.classList.add('uf-table-row--selected');

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
        <div class="uf-detail-row">
            <span class="uf-detail-key">Codice</span>
            <span class="uf-detail-val">${opt.dataset.code}</span>
        </div>
        <div class="uf-detail-row">
            <span class="uf-detail-key">N° Ufficio</span>
            <span class="uf-detail-val">${opt.dataset.numero || '—'}</span>
        </div>
        <div class="uf-detail-row">
            <span class="uf-detail-key">Piano</span>
            <span class="uf-detail-val">${opt.dataset.piano || '—'}</span>
        </div>
        <div class="uf-detail-row">
            <span class="uf-detail-key">Capacità</span>
            <span class="uf-detail-val">${opt.dataset.capacita || '—'}</span>
        </div>
        <div class="uf-detail-row">
            <span class="uf-detail-key">Tel. Interno</span>
            <span class="uf-detail-val">${opt.dataset.telefono || '—'}</span>
        </div>
        <div class="uf-detail-row">
            <span class="uf-detail-key">Stato attuale</span>
            <span class="uf-detail-val">
                <span class="uf-status-pill ${isOcc ? 'uf-status--occ' : 'uf-status--avail'}">
                    ${isOcc ? 'Occupato' : 'Disponibile'}
                </span>
            </span>
        </div>
    `;

    // Slot occupati
    const assetSlots = occupiedSlots[id] || [];
    slots.style.display = '';
    const list = document.getElementById('uf-slots-list');
    if (assetSlots.length > 0) {
        list.innerHTML = assetSlots.map(s => `
            <div class="uf-slot-item">
                <div class="uf-slot-range">
                    <span>${formatDate(s.inizio)}</span>
                    <span>→</span>
                    <span>${formatDate(s.fine)}</span>
                </div>
            </div>
        `).join('');
    } else {
        list.innerHTML = '<p class="uf-slots-empty">✓ Nessun periodo occupato</p>';
    }
}

// ── Selezione ufficio da riga tabella ─────────────────
function selectOfficeFromTable(id) {
    const select = document.getElementById('select-office');
    select.value = id;
    onOfficeChange(select);
    select.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// ── Aggiorna preview durata ───────────────────────────
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
    const sel = document.getElementById('select-office');
    if (sel.value) onOfficeChange(sel);
    updateDuration();
})();
</script>

</body>
</html>