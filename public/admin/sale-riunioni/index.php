<?php
session_start();
require_once __DIR__ . "/../../../config/config.php";

// ── Signout ───────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'signout') {
    session_unset();
    session_destroy();
    header("Location: ../login.php");
    exit();
}

// ── Auth redirect ─────────────────────────────────────
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit();
}

// ── User info ─────────────────────────────────────────
$userInfo = ['nome' => '', 'cognome' => '', 'ruolo' => ''];

if (isset($_SESSION['id_utente'])) {
    $stmt = $conn->prepare(
        "SELECT u.nome, u.cognome, r.nome_ruolo as ruolo
         FROM utenti u LEFT JOIN ruoli r ON u.id_ruolo = r.id_ruolo
         WHERE u.id_utente = ? LIMIT 1"
    );
    $stmt->bind_param("i", $_SESSION['id_utente']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) $userInfo = ['nome' => $row['nome'], 'cognome' => $row['cognome'], 'ruolo' => $row['ruolo']];
}

// ── Feedback state ────────────────────────────────────
$feedback = ['type' => '', 'message' => ''];

// ── Handle booking submission ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'book') {
    $id_asset    = (int)($_POST['id_asset']    ?? 0);
    $data_inizio = $_POST['data_inizio'] ?? '';
    $data_fine   = $_POST['data_fine']   ?? '';

    if (!$id_asset || !$data_inizio || !$data_fine) {
        $feedback = ['type' => 'error', 'message' => 'Compila tutti i campi prima di procedere.'];
    } elseif (strtotime($data_fine) <= strtotime($data_inizio)) {
        $feedback = ['type' => 'error', 'message' => 'La data di fine deve essere successiva alla data di inizio.'];
    } elseif (strtotime($data_inizio) < time()) {
        $feedback = ['type' => 'error', 'message' => 'Non puoi prenotare una data nel passato.'];
    } else {
        // Check for overlapping bookings on this asset
        $stmt = $conn->prepare(
            "SELECT COUNT(*) as cnt FROM prenotazioni
             WHERE id_asset = ?
               AND data_inizio < ?
               AND data_fine   > ?"
        );
        $stmt->bind_param("iss", $id_asset, $data_fine, $data_inizio);
        $stmt->execute();
        $overlap = $stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
        $stmt->close();

        if ($overlap > 0) {
            $feedback = ['type' => 'error', 'message' => 'La sala è già occupata in questo intervallo. Scegli un orario diverso.'];
        } else {
            // Insert booking
            $stmt = $conn->prepare(
                "INSERT INTO prenotazioni (id_utente, id_asset, data_inizio, data_fine) VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param("iiss", $_SESSION['id_utente'], $id_asset, $data_inizio, $data_fine);
            if ($stmt->execute()) {
                $feedback = ['type' => 'success', 'message' => 'Prenotazione effettuata con successo!'];
            } else {
                $feedback = ['type' => 'error', 'message' => 'Errore durante il salvataggio. Riprova.'];
            }
            $stmt->close();
        }
    }
}

// ── AJAX: fetch occupied slots for a room ────────────
if (isset($_GET['slots']) && isset($_GET['id_asset'])) {
    header('Content-Type: application/json');
    $id = (int)$_GET['id_asset'];
    $slots = [];
    $stmt = $conn->prepare(
        "SELECT p.data_inizio, p.data_fine, u.nome, u.cognome
         FROM prenotazioni p
         JOIN utenti u ON p.id_utente = u.id_utente
         WHERE p.id_asset = ? AND p.data_fine >= NOW()
         ORDER BY p.data_inizio ASC
         LIMIT 20"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $slots[] = $r; }
    $stmt->close();
    echo json_encode($slots);
    exit();
}

// ── Fetch rooms ───────────────────────────────────────
$rooms = []; // id_asset => [name, status, capacita, attrezzatura, apertura, chiusura]
$sql = "SELECT a.id_asset, a.codice_asset, a.stato,
               COALESCE(s.capacita, '-')          AS capacita,
               COALESCE(s.attrezzatura, '-')       AS attrezzatura,
               COALESCE(s.orario_apertura, '')     AS orario_apertura,
               COALESCE(s.orario_chiusura, '')     AS orario_chiusura
        FROM asset a
        LEFT JOIN sala_dettagli s ON s.id_asset = a.id_asset
        ORDER BY a.codice_asset";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $disp = '';
        if ($row['orario_apertura'] && $row['orario_chiusura']) {
            $disp = $row['orario_apertura'] . ' – ' . $row['orario_chiusura'];
        }
        $rooms[$row['id_asset']] = [
            'name'        => $row['codice_asset'],
            'status'      => $row['stato'],
            'capacita'    => $row['capacita'],
            'attrezzatura'=> $row['attrezzatura'],
            'disponibilita'=> $disp ?: '–',
        ];
    }
}

// ── Fetch user's bookings (active + future) ───────────
$userBookings = [];
if (isset($_SESSION['id_utente'])) {
    $stmt = $conn->prepare(
        "SELECT p.id_prenotazione, p.data_inizio, p.data_fine, a.codice_asset
         FROM prenotazioni p
         JOIN asset a ON p.id_asset = a.id_asset
         WHERE p.id_utente = ? AND p.data_fine >= NOW()
         ORDER BY p.data_inizio ASC"
    );
    $stmt->bind_param("i", $_SESSION['id_utente']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $userBookings[] = $row; }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Sale Riunioni | Northstar</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
    <a href="?action=signout" class="signout-btn">Esci</a>
</header>

<!-- ── Page ───────────────────────────────────────────── -->
<div class="sr-page">

    <!-- ── Page title ─────────────────────────────────── -->
    <div class="sr-title-row">
        <div>
            <h2 class="sr-page-title">Sale Riunioni</h2>
            <p class="sr-page-sub">Prenota uno spazio per il tuo prossimo meeting</p>
        </div>
        <span class="sr-user-pill">
            <?php echo htmlspecialchars($userInfo['nome'] . ' ' . $userInfo['cognome']); ?>
            <span class="sr-role"><?php echo htmlspecialchars($userInfo['ruolo']); ?></span>
        </span>
    </div>

    <!-- ── Feedback banner ────────────────────────────── -->
    <?php if ($feedback['message']): ?>
    <div class="sr-feedback sr-feedback--<?php echo $feedback['type']; ?>">
        <?php echo $feedback['type'] === 'success' ? '✓' : '✕'; ?>
        <?php echo htmlspecialchars($feedback['message']); ?>
    </div>
    <?php endif; ?>

    <!-- ── Main grid ──────────────────────────────────── -->
    <div class="sr-grid">

        <!-- ═══ LEFT: Form ════════════════════════════ -->
        <div class="sr-panel">
            <h3 class="sr-panel-title">Nuova prenotazione</h3>

            <form method="POST" id="booking-form" novalidate>
                <input type="hidden" name="action"      value="book">
                <input type="hidden" name="id_asset"    id="hidden-id-asset">
                <input type="hidden" name="data_inizio" id="hidden-data-inizio">
                <input type="hidden" name="data_fine"   id="hidden-data-fine">

                <!-- Room selector -->
                <div class="sr-field">
                    <label for="select-room">Sala</label>
                    <select id="select-room" required>
                        <option value="">— Scegli una sala —</option>
                        <?php foreach ($rooms as $id => $room): ?>
                            <option value="<?php echo $id; ?>">
                                <?php echo htmlspecialchars($room['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Room detail card (populated by JS) -->
                <div class="sr-detail-card" id="room-detail" style="display:none;">
                    <div class="sr-detail-row">
                        <span class="sr-detail-key">👥 Capacità</span>
                        <span class="sr-detail-val" id="detail-cap">–</span>
                    </div>
                    <div class="sr-detail-row">
                        <span class="sr-detail-key">🔧 Attrezzatura</span>
                        <span class="sr-detail-val" id="detail-attr">–</span>
                    </div>
                    <div class="sr-detail-row">
                        <span class="sr-detail-key">🕐 Orario</span>
                        <span class="sr-detail-val" id="detail-disp">–</span>
                    </div>
                    <div class="sr-detail-row">
                        <span class="sr-detail-key">📌 Stato</span>
                        <span id="detail-status-pill"></span>
                    </div>
                </div>

                <!-- Occupied slots (populated by AJAX) -->
                <div class="sr-slots-section" id="slots-section" style="display:none;">
                    <p class="sr-slots-title">Orari già occupati</p>
                    <div id="slots-list" class="sr-slots-list"></div>
                </div>

                <!-- Date/time -->
                <div class="sr-fields-row">
                    <div class="sr-field">
                        <label for="start-datetime">Data e ora inizio</label>
                        <input type="datetime-local" id="start-datetime" required>
                    </div>
                    <div class="sr-field">
                        <label for="end-datetime">Data e ora fine</label>
                        <input type="datetime-local" id="end-datetime" required>
                    </div>
                </div>

                <!-- Duration preview -->
                <div class="sr-duration-preview" id="duration-preview" style="display:none;">
                    <span class="sr-duration-icon">⏱</span>
                    <span id="duration-text"></span>
                </div>

                <!-- Inline form error -->
                <div class="sr-form-error" id="form-error" style="display:none;"></div>

                <button type="submit" class="sr-submit-btn" id="submit-btn">
                    Conferma prenotazione
                </button>
            </form>
        </div>

        <!-- ═══ RIGHT: Info ═══════════════════════════ -->
        <div class="sr-right">

            <!-- Active bookings -->
            <div class="sr-panel">
                <div class="sr-panel-header">
                    <h3 class="sr-panel-title">Le tue prenotazioni attive</h3>
                    <span class="sr-count-badge"><?php echo count($userBookings); ?></span>
                </div>

                <?php if (empty($userBookings)): ?>
                    <div class="sr-empty">
                        <span>📭</span>
                        <p>Nessuna prenotazione attiva.</p>
                    </div>
                <?php else: ?>
                    <div class="sr-bookings-list">
                    <?php
                    $nowDt = new DateTime();
                    foreach ($userBookings as $b):
                        $s     = new DateTime($b['data_inizio']);
                        $e     = new DateTime($b['data_fine']);
                        $isNow = $s <= $nowDt && $e >= $nowDt;
                        $diff  = $s->diff($e);
                        $hh    = ($diff->days * 24) + $diff->h;
                        $dur   = $hh > 0 ? "{$hh}h {$diff->i}m" : "{$diff->i}m";
                    ?>
                        <div class="sr-booking-item">
                            <div class="sr-booking-top">
                                <span class="sr-asset-pill"><?php echo htmlspecialchars($b['codice_asset']); ?></span>
                                <?php if ($isNow): ?>
                                    <span class="sr-status-pill sr-status--now">In corso</span>
                                <?php else: ?>
                                    <span class="sr-status-pill sr-status--future">Futura</span>
                                <?php endif; ?>
                            </div>
                            <div class="sr-booking-dates">
                                <span><?php echo date('d/m/Y H:i', strtotime($b['data_inizio'])); ?></span>
                                <span class="sr-arrow">→</span>
                                <span><?php echo date('d/m/Y H:i', strtotime($b['data_fine'])); ?></span>
                                <span class="sr-dur"><?php echo $dur; ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- All rooms table -->
            <div class="sr-panel">
                <h3 class="sr-panel-title">Disponibilità sale</h3>
                <div class="sr-table-wrap">
                    <table class="sr-table">
                        <thead>
                            <tr>
                                <th>Sala</th>
                                <th>Capienza</th>
                                <th>Stato</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rooms as $id => $room):
                            $stLower = strtolower($room['status']);
                        ?>
                            <tr class="sr-table-row" data-id="<?php echo $id; ?>"
                                onclick="selectRoom(<?php echo $id; ?>)" title="Clicca per selezionare">
                                <td>
                                    <span class="sr-asset-pill sr-asset-pill--sm">
                                        <?php echo htmlspecialchars($room['name']); ?>
                                    </span>
                                </td>
                                <td class="sr-cap-cell"><?php echo htmlspecialchars($room['capacita']); ?></td>
                                <td>
                                    <span class="sr-status-pill sr-status--<?php echo $stLower === 'disponibile' ? 'avail' : 'occ'; ?>">
                                        <?php echo htmlspecialchars($room['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- /sr-right -->
    </div><!-- /sr-grid -->
</div><!-- /sr-page -->

<script>
const roomData = <?php echo json_encode($rooms); ?>;

// ── Room detail card + fetch occupied slots ───────────
function updateDetail(id) {
    const card = document.getElementById('room-detail');
    if (!id || !roomData[id]) {
        card.style.display = 'none';
        document.getElementById('slots-section').style.display = 'none';
        return;
    }
    const r = roomData[id];
    document.getElementById('detail-cap').textContent  = r.capacita;
    document.getElementById('detail-attr').textContent = r.attrezzatura;
    document.getElementById('detail-disp').textContent = r.disponibilita;

    const pill = document.getElementById('detail-status-pill');
    const st   = (r.status || '').toLowerCase();
    pill.className   = 'sr-status-pill sr-status--' + (st === 'disponibile' ? 'avail' : 'occ');
    pill.textContent = r.status;
    card.style.display = 'block';

    // Highlight table row
    document.querySelectorAll('.sr-table-row').forEach(row => {
        row.classList.toggle('sr-table-row--selected', row.dataset.id == id);
    });

    // Fetch occupied slots via AJAX
    const section  = document.getElementById('slots-section');
    const list     = document.getElementById('slots-list');
    list.innerHTML = '<span class="sr-slots-loading">Caricamento orari…</span>';
    section.style.display = 'block';

    fetch(`?slots=1&id_asset=${id}`)
        .then(res => res.json())
        .then(slots => {
            if (!slots.length) {
                list.innerHTML = '<span class="sr-slots-empty">Nessun orario occupato — sala completamente libera</span>';
                return;
            }
            const fmt = v => new Date(v).toLocaleString('it-IT', {
                day: '2-digit', month: '2-digit', year: 'numeric',
                hour: '2-digit', minute: '2-digit'
            });
            list.innerHTML = slots.map(s => `
                <div class="sr-slot-item">
                    <span class="sr-slot-range">
                        ${fmt(s.data_inizio)}
                        <span class="sr-arrow">→</span>
                        ${fmt(s.data_fine)}
                    </span>
                </div>
            `).join('');
        })
        .catch(() => {
            list.innerHTML = '<span class="sr-slots-empty">Impossibile caricare gli orari.</span>';
        });
}

document.getElementById('select-room').addEventListener('change', function () {
    updateDetail(this.value);
    updateDuration();
});

// Click row in table → select room in form
function selectRoom(id) {
    const sel = document.getElementById('select-room');
    sel.value = id;
    sel.dispatchEvent(new Event('change'));
    document.getElementById('booking-form').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Duration preview ──────────────────────────────────
function updateDuration() {
    const start   = document.getElementById('start-datetime').value;
    const end     = document.getElementById('end-datetime').value;
    const preview = document.getElementById('duration-preview');
    const text    = document.getElementById('duration-text');

    if (start && end && new Date(end) > new Date(start)) {
        const ms = new Date(end) - new Date(start);
        const h  = Math.floor(ms / 3600000);
        const m  = Math.floor((ms % 3600000) / 60000);
        text.textContent = h > 0 ? `Durata: ${h}h ${m}m` : `Durata: ${m}m`;
        preview.style.display = 'flex';
    } else {
        preview.style.display = 'none';
    }
}

document.getElementById('start-datetime').addEventListener('change', updateDuration);
document.getElementById('end-datetime').addEventListener('change', updateDuration);

// ── Set minimum datetime to now ───────────────────────
(function () {
    const pad = n => String(n).padStart(2, '0');
    const now = new Date();
    const min = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
    document.getElementById('start-datetime').min = min;
    document.getElementById('end-datetime').min   = min;
})();

// ── Form submit: validate + handle response inline ────
<?php if ($feedback['type'] === 'success'): ?>
// Reset form fields after successful booking
document.getElementById('select-room').value = '';
document.getElementById('start-datetime').value = '';
document.getElementById('end-datetime').value = '';
document.getElementById('duration-preview').style.display = 'none';
document.getElementById('room-detail').style.display = 'none';
document.getElementById('slots-section').style.display = 'none';
document.querySelectorAll('.sr-table-row').forEach(r => r.classList.remove('sr-table-row--selected'));
// Scroll to feedback
document.querySelector('.sr-feedback')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
<?php endif; ?>

document.getElementById('booking-form').addEventListener('submit', function (e) {
    const room  = document.getElementById('select-room').value;
    const start = document.getElementById('start-datetime').value;
    const end   = document.getElementById('end-datetime').value;
    const err   = document.getElementById('form-error');

    const showErr = msg => {
        err.textContent   = '✕ ' + msg;
        err.style.display = 'flex';
        err.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        e.preventDefault();
    };

    err.style.display = 'none';

    if (!room)  return showErr('Seleziona una sala.');
    if (!start) return showErr('Inserisci la data di inizio.');
    if (!end)   return showErr('Inserisci la data di fine.');
    if (new Date(end) <= new Date(start))
        return showErr('La data di fine deve essere successiva alla data di inizio.');

    document.getElementById('hidden-id-asset').value    = room;
    document.getElementById('hidden-data-inizio').value = start;
    document.getElementById('hidden-data-fine').value   = end;

    const btn = document.getElementById('submit-btn');
    btn.disabled    = true;
    btn.textContent = 'Prenotazione in corso…';
});
</script>
</body>
</html>