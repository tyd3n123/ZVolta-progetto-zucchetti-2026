<?php
session_start();
require_once __DIR__ . "/../../../../config/config.php";

// ── Auth check ────────────────────────────────────────
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['id_utente'])) {
    header("Location: ../../login.php");
    exit();
}

$sql = "SELECT r.nome_ruolo FROM utenti u JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE u.id_utente = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $_SESSION['id_utente']);
$stmt->execute();
$callerRole = $stmt->get_result()->fetch_assoc()['nome_ruolo'] ?? '';
$stmt->close();

if (strtolower($callerRole) !== 'admin') {
    header("Location: ../../login.php");
    exit();
}

// ── Handle DELETE ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id_prenotazione = (int)($_POST['id_prenotazione'] ?? 0);

    $stmt = $conn->prepare("SELECT id_asset FROM prenotazioni WHERE id_prenotazione = ?");
    $stmt->bind_param("i", $id_prenotazione);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($booking) {
        $stmt = $conn->prepare("UPDATE asset SET stato = 'Disponibile' WHERE id_asset = ?");
        $stmt->bind_param("i", $booking['id_asset']);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM prenotazioni WHERE id_prenotazione = ?");
        $stmt->bind_param("i", $id_prenotazione);
        $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Prenotazione non trovata.']);
    }
    exit();
}

// ── Handle UPDATE ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $id_prenotazione = (int)($_POST['id_prenotazione'] ?? 0);
    $data_inizio     = $_POST['data_inizio'] ?? '';
    $data_fine       = $_POST['data_fine']   ?? '';

    if (!$id_prenotazione || !$data_inizio || !$data_fine) {
        echo json_encode(['success' => false, 'error' => 'Dati mancanti.']);
        exit();
    }

    // Fetch original booking (day + asset)
    $stmt = $conn->prepare("SELECT id_asset, data_inizio AS orig_inizio FROM prenotazioni WHERE id_prenotazione = ?");
    $stmt->bind_param("i", $id_prenotazione);
    $stmt->execute();
    $orig = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$orig) {
        echo json_encode(['success' => false, 'error' => 'Prenotazione non trovata.']);
        exit();
    }

    $id_asset   = (int)$orig['id_asset'];
    $origDay    = date('Y-m-d', strtotime($orig['orig_inizio']));
    $newStartTs = strtotime($data_inizio);
    $newEndTs   = strtotime($data_fine);
    $now        = time();

    // 1. Non nel passato
    if ($newStartTs < $now) {
        echo json_encode(['success' => false, 'error' => 'Non puoi spostare una prenotazione nel passato.']);
        exit();
    }

    // 2. Fine > inizio
    if ($newEndTs <= $newStartTs) {
        echo json_encode(['success' => false, 'error' => 'La data di fine deve essere successiva alla data di inizio.']);
        exit();
    }

    // 3. Stesso giorno della prenotazione originale
    $newStartDay = date('Y-m-d', $newStartTs);
    $newEndDay   = date('Y-m-d', $newEndTs);
    if ($newStartDay !== $origDay || $newEndDay !== $origDay) {
        echo json_encode(['success' => false, 'error' => "Puoi modificare solo l'orario, non la giornata (deve restare il {$origDay})."]);
        exit();
    }

    // 4. Nessun conflitto con altre prenotazioni dello stesso asset (esclusa se stessa)
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM prenotazioni
         WHERE id_asset = ? AND id_prenotazione != ? AND data_inizio < ? AND data_fine > ?"
    );
    $stmt->bind_param("iiss", $id_asset, $id_prenotazione, $data_fine, $data_inizio);
    $stmt->execute();
    $overlap = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    if ($overlap > 0) {
        echo json_encode(['success' => false, 'error' => 'Il periodo selezionato si sovrappone a un\'altra prenotazione esistente.']);
        exit();
    }

    // Salva
    $stmt = $conn->prepare("UPDATE prenotazioni SET data_inizio = ?, data_fine = ? WHERE id_prenotazione = ?");
    $stmt->bind_param("ssi", $data_inizio, $data_fine, $id_prenotazione);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $stmt->error]);
    }
    $stmt->close();
    exit();
}

// ── Fetch all bookings ────────────────────────────────
$allBookings = [];
$result = $conn->query(
    "SELECT p.id_prenotazione, p.data_inizio, p.data_fine,
            a.codice_asset, a.id_asset,
            u.nome, u.cognome, r.nome_ruolo
     FROM prenotazioni p
     JOIN asset a ON p.id_asset = a.id_asset
     JOIN utenti u ON p.id_utente = u.id_utente
     JOIN ruoli r ON u.id_ruolo = r.id_ruolo
     ORDER BY p.data_inizio DESC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $allBookings[] = $row;
    }
}

// Build per-asset booking map for JS conflict check (all bookings except each booking's own)
$bookingsByAsset = []; // [assetId => [[id, inizio_ts_ms, fine_ts_ms], ...]]
foreach ($allBookings as $b) {
    $aid = (int)$b['id_asset'];
    if (!isset($bookingsByAsset[$aid])) $bookingsByAsset[$aid] = [];
    $bookingsByAsset[$aid][] = [
        'id'    => (int)$b['id_prenotazione'],
        'start' => strtotime($b['data_inizio']) * 1000, // ms for JS
        'end'   => strtotime($b['data_fine'])   * 1000,
    ];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Prenotazioni | Northstar</title>
    <link rel="stylesheet" href="../dashboard.css">
    <link rel="stylesheet" href="./gestione-prenotazioni.css">
</head>
<body>
    <div class="modal-container">
        <div class="modal-header">
            <h1>Gestione Prenotazioni</h1>
            <button class="close-btn" onclick="window.close()">Chiudi</button>
        </div>

        <div class="modal-content">
            <?php if (empty($allBookings)): ?>
                <div class="empty-state">
                    <h3>Nessuna prenotazione presente</h3>
                    <p>Non ci sono prenotazioni nel sistema.</p>
                </div>
            <?php else: ?>
                <table class="bookings-table">
                    <thead>
                        <tr>
                            <th>Asset</th>
                            <th>Utente</th>
                            <th>Data Inizio</th>
                            <th>Data Fine</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allBookings as $b):
                            $origDay  = date('Y-m-d', strtotime($b['data_inizio']));
                            $dayStart = $origDay . 'T00:00';
                            $dayEnd   = $origDay . 'T23:59';
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($b['codice_asset']) ?></td>
                                <td>
                                    <?= htmlspecialchars($b['nome'] . ' ' . $b['cognome']) ?>
                                    <span class="user-badge"><?= htmlspecialchars($b['nome_ruolo']) ?></span>
                                </td>
                                <td><?= date('d/m/Y H:i', strtotime($b['data_inizio'])) ?></td>
                                <td><?= date('d/m/Y H:i', strtotime($b['data_fine'])) ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn-edit"
                                            onclick="showEditForm(
                                                <?= $b['id_prenotazione'] ?>,
                                                '<?= $origDay ?>',
                                                <?= (int)$b['id_asset'] ?>
                                            )">Modifica</button>
                                        <button class="btn-delete" onclick="deleteBooking(<?= $b['id_prenotazione'] ?>)">Elimina</button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Riga form modifica -->
                            <tr id="edit-form-<?= $b['id_prenotazione'] ?>" style="display:none;"
                                data-booking-id="<?= $b['id_prenotazione'] ?>"
                                data-asset-id="<?= (int)$b['id_asset'] ?>"
                                data-orig-day="<?= $origDay ?>"
                                data-day-start="<?= $dayStart ?>"
                                data-day-end="<?= $dayEnd ?>">
                                <td colspan="5">
                                    <div class="edit-form">
                                        <h4>Modifica Prenotazione #<?= $b['id_prenotazione'] ?> — <span class="edit-day-label"><?= date('d/m/Y', strtotime($b['data_inizio'])) ?></span></h4>
                                        <p class="edit-hint">Puoi modificare solo l'orario. La giornata non può essere cambiata.</p>
                                        <div class="form-row">
                                            <div class="form-group">
                                                <label>Data Inizio</label>
                                                <input type="datetime-local"
                                                    id="start-<?= $b['id_prenotazione'] ?>"
                                                    value="<?= date('Y-m-d\TH:i', strtotime($b['data_inizio'])) ?>"
                                                    min="<?= $dayStart ?>"
                                                    max="<?= $dayEnd ?>"
                                                    onchange="validateEdit(<?= $b['id_prenotazione'] ?>)">
                                            </div>
                                            <div class="form-group">
                                                <label>Data Fine</label>
                                                <input type="datetime-local"
                                                    id="end-<?= $b['id_prenotazione'] ?>"
                                                    value="<?= date('Y-m-d\TH:i', strtotime($b['data_fine'])) ?>"
                                                    min="<?= $dayStart ?>"
                                                    max="<?= $dayEnd ?>"
                                                    onchange="validateEdit(<?= $b['id_prenotazione'] ?>)">
                                            </div>
                                        </div>
                                        <div id="edit-error-<?= $b['id_prenotazione'] ?>" class="edit-error" style="display:none;"></div>
                                        <div class="form-buttons">
                                            <button class="btn-save" id="save-btn-<?= $b['id_prenotazione'] ?>"
                                                onclick="updateBooking(<?= $b['id_prenotazione'] ?>)">Salva</button>
                                            <button class="btn-cancel" onclick="hideEditForm(<?= $b['id_prenotazione'] ?>)">Annulla</button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // Mappa prenotazioni per asset (per conflict check client-side)
    const bookingsByAsset = <?= json_encode($bookingsByAsset) ?>;

    // ── Mostra/nascondi form ──────────────────────────
    function showEditForm(id, origDay, assetId) {
        // Chiudi gli altri form aperti
        document.querySelectorAll('[id^="edit-form-"]').forEach(r => r.style.display = 'none');

        const row = document.getElementById('edit-form-' + id);
        row.style.display = 'table-row';

        // Aggiorna min in base a "ora" (non nel passato) ma max resta fine giornata
        const now     = new Date();
        const nowISO  = toLocalISO(now);
        const dayMin  = origDay + 'T00:00';
        const minVal  = nowISO > dayMin ? nowISO : dayMin; // il maggiore tra ora e inizio giornata

        const startEl = document.getElementById('start-' + id);
        const endEl   = document.getElementById('end-' + id);

        startEl.min = minVal;
        endEl.min   = minVal;

        validateEdit(id);
    }

    function hideEditForm(id) {
        document.getElementById('edit-form-' + id).style.display = 'none';
        clearError(id);
    }

    // ── Validazione client-side ───────────────────────
    function validateEdit(id) {
        const row      = document.getElementById('edit-form-' + id);
        const assetId  = parseInt(row.dataset.assetId);
        const origDay  = row.dataset.origDay;
        const startVal = document.getElementById('start-' + id).value;
        const endVal   = document.getElementById('end-' + id).value;
        const saveBtn  = document.getElementById('save-btn-' + id);

        if (!startVal || !endVal) { clearError(id); return; }

        const startDate = new Date(startVal);
        const endDate   = new Date(endVal);
        const now       = new Date();

        // 1. Non nel passato
        if (startDate < now) {
            return showError(id, '⚠️ Non puoi spostare la prenotazione nel passato.', saveBtn);
        }

        // 2. Fine > inizio
        if (endDate <= startDate) {
            return showError(id, '⚠️ La data di fine deve essere successiva alla data di inizio.', saveBtn);
        }

        // 3. Stesso giorno
        const startDay = startVal.slice(0, 10);
        const endDay   = endVal.slice(0, 10);
        if (startDay !== origDay || endDay !== origDay) {
            return showError(id, `⚠️ Puoi modificare solo l'orario — la giornata deve restare il ${fmtDay(origDay)}.`, saveBtn);
        }

        // 4. Conflitto con altre prenotazioni dello stesso asset
        const slots = (bookingsByAsset[assetId] || []).filter(s => s.id !== id);
        const hasConflict = slots.some(s => startDate < new Date(s.end) && endDate > new Date(s.start));
        if (hasConflict) {
            return showError(id, '⚠️ Il nuovo orario si sovrappone a un\'altra prenotazione esistente.', saveBtn);
        }

        clearError(id);
    }

    function showError(id, msg, btn) {
        const el = document.getElementById('edit-error-' + id);
        el.innerHTML = msg;
        el.style.display = '';
        if (btn) btn.disabled = true;
    }

    function clearError(id) {
        const el = document.getElementById('edit-error-' + id);
        el.style.display = 'none';
        const btn = document.getElementById('save-btn-' + id);
        if (btn) btn.disabled = false;
    }

    // ── Salva (AJAX) ──────────────────────────────────
    function updateBooking(id) {
        const startVal = document.getElementById('start-' + id).value;
        const endVal   = document.getElementById('end-' + id).value;

        if (!startVal || !endVal) {
            showError(id, '⚠️ Compila entrambe le date.', document.getElementById('save-btn-' + id));
            return;
        }

        const saveBtn = document.getElementById('save-btn-' + id);
        saveBtn.disabled = true;
        saveBtn.textContent = 'Salvataggio…';

        fetch('gestione-prenotazioni.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=update&id_prenotazione=${id}&data_inizio=${encodeURIComponent(startVal)}&data_fine=${encodeURIComponent(endVal)}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                showError(id, '⚠️ ' + (data.error || 'Errore sconosciuto.'), saveBtn);
                saveBtn.disabled = false;
                saveBtn.textContent = 'Salva';
            }
        })
        .catch(() => {
            showError(id, '⚠️ Errore di comunicazione con il server.', saveBtn);
            saveBtn.disabled = false;
            saveBtn.textContent = 'Salva';
        });
    }

    // ── Elimina (AJAX) ────────────────────────────────
    function deleteBooking(id) {
        if (!confirm('Eliminare definitivamente questa prenotazione?')) return;

        fetch('elimina-prenotazioni.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=delete&id_prenotazione=${id}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Errore: ' + (data.message || data.error || 'Errore sconosciuto'));
            }
        })
        .catch(() => alert('Errore di comunicazione con il server.'));
    }

    // ── Helpers ───────────────────────────────────────
    function toLocalISO(date) {
        const p = n => String(n).padStart(2, '0');
        return `${date.getFullYear()}-${p(date.getMonth()+1)}-${p(date.getDate())}T${p(date.getHours())}:${p(date.getMinutes())}`;
    }

    function fmtDay(iso) { // "YYYY-MM-DD" → "DD/MM/YYYY"
        const [y, m, d] = iso.split('-');
        return `${d}/${m}/${y}`;
    }
    </script>
</body>
</html>
