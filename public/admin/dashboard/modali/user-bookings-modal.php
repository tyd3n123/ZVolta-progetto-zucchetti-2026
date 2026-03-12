<?php
session_start();
require_once __DIR__ . "/../../../../config/config.php";

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['id_utente'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Fetch user's bookings
$userBookings = [];
$sql = "SELECT p.id_prenotazione, p.data_inizio, p.data_fine, a.codice_asset, a.id_asset
        FROM prenotazioni p 
        JOIN asset a ON p.id_asset = a.id_asset 
        WHERE p.id_utente = ? 
        ORDER BY p.data_inizio DESC";
$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("i", $_SESSION['id_utente']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $userBookings[] = $row;
    }
    $stmt->close();
}

// Return HTML content for modal
ob_start();
?>
<div class="modal-content">
    <div class="modal-header">
        <h3>Le Tue Prenotazioni</h3>
        <button class="modal-close" onclick="closeUserBookingsModal()">&times;</button>
    </div>
    
    <div class="modal-body">
        <?php if (empty($userBookings)): ?>
            <div class="empty-state">
                <h4>Nessuna prenotazione disponibile</h4>
                <p>Non hai ancora effettuato nessuna prenotazione.</p>
            </div>
        <?php else: ?>
            <div class="bookings-list-container">
                <?php foreach ($userBookings as $booking): ?>
                    <div class="booking-item-detail">
                        <div class="booking-header">
                            <h4><?php echo htmlspecialchars($booking['codice_asset']); ?></h4>
                            <span class="booking-id">#<?php echo $booking['id_prenotazione']; ?></span>
                        </div>
                        <div class="booking-details">
                            <div class="detail-item">
                                <i class="icon">📅</i>
                                <div>
                                    <strong>Data Inizio:</strong><br>
                                    <?php echo date('d/m/Y H:i', strtotime($booking['data_inizio'])); ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <i class="icon">🕐</i>
                                <div>
                                    <strong>Data Fine:</strong><br>
                                    <?php echo date('d/m/Y H:i', strtotime($booking['data_fine'])); ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <i class="icon">⏱️</i>
                                <div>
                                    <strong>Durata:</strong><br>
                                    <?php 
                                    $start = new DateTime($booking['data_inizio']);
                                    $end = new DateTime($booking['data_fine']);
                                    $duration = $start->diff($end);
                                    echo $duration->format('%h ore %i minuti');
                                    ?>
                                </div>
                            </div>
                        </div>
                        <div class="booking-actions">
                            <button class="btn-modify" onclick="modifyBooking(<?php echo $booking['id_prenotazione']; ?>)">Modifica</button>
                            <button class="btn-cancel" onclick="cancelBooking(<?php echo $booking['id_prenotazione']; ?>)">Annulla</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="modali/user-bookings-modal.js"></script>
<?php
// Handle cancel action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
    $id_prenotazione = $_POST['id_prenotazione'];
    
    // Get asset info before deleting
    $sql = "SELECT id_asset FROM prenotazioni WHERE id_prenotazione = ? AND id_utente = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $id_prenotazione, $_SESSION['id_utente']);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking = $result->fetch_assoc();
    $stmt->close();

    if ($booking) {
        // Update asset status to 'Disponibile'
        $update_sql = "UPDATE asset SET stato = 'Disponibile' WHERE id_asset = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $booking['id_asset']);
        $update_stmt->execute();
        $update_stmt->close();
        
        // Delete booking
        $delete_sql = "DELETE FROM prenotazioni WHERE id_prenotazione = ? AND id_utente = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("ii", $id_prenotazione, $_SESSION['id_utente']);
        $delete_stmt->execute();
        $delete_stmt->close();
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit();
}

$html = ob_get_clean();
echo $html;
?>
