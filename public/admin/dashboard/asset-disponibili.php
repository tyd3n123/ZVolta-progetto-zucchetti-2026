<?php
session_start();
require_once __DIR__ . "/../../../config/config.php";

// Verifica autenticazione
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !isset($_SESSION['id_utente'])) {
    header("Location: ../../auth/login.php"); 
    exit();
}

$id_utente = (int)$_SESSION['id_utente'];

// Ottieni informazioni utente
$stmt = $conn->prepare("SELECT u.nome, u.cognome, r.nome_ruolo AS ruolo FROM utenti u LEFT JOIN ruoli r ON u.id_ruolo = r.id_ruolo WHERE u.id_utente = ? LIMIT 1");
$stmt->bind_param("i", $id_utente); 
$stmt->execute();
$userInfo = $stmt->get_result()->fetch_assoc() ?? ['nome'=>'','cognome'=>'','ruolo'=>''];
$stmt->close();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Asset Disponibili | Northstar</title>
    <link rel="stylesheet" href="../dashboard/dashboard.css">
    <link rel="stylesheet" href="./asset-disponibili.css">
</head>
<body>

<header class="header">
    <div class="header-left">
        <h1>Northstar</h1>
        <nav class="header-breadcrumb">
            <a href="../dashboard/index.php">Dashboard</a>
            <span class="bc-sep">/</span>
            <span class="bc-current">Asset Disponibili</span>
        </nav>
    </div>
    <div class="pk-user-pill">
        <?= htmlspecialchars($userInfo['nome'] . ' ' . $userInfo['cognome']) .  ' - ' ?>
        <span class="pk-role"><?= htmlspecialchars($userInfo['ruolo']) ?></span>
    </div>
</header>

<div class="asset-disponibili-page">

    <div class="page-header">
        <h1 class="page-title">Scegli la Mappa</h1>
        <p class="page-subtitle">Seleziona la mappa che desideri visualizzare per consultare le disponibilità e prenotare gli asset</p>
    </div>

    <div class="selection-container">
        
        <!-- Card Mappa Parcheggi -->
        <div class="map-card" onclick="location.href='../parcheggi/index.php'" tabindex="0" role="button" aria-label="Vai alla mappa parcheggi">
            <div class="card-icon parking-icon">
                🚗
            </div>
            <div class="card-content">
                <h2 class="card-title">Mappa Parcheggi</h2>
                <p class="card-description">
                    Visualizza la planimetria completa dei parcheggi e prenota il tuo posto auto o moto. 
                    Consulta in tempo reale la disponibilità delle diverse aree di parcheggio.
                </p>
                <div class="card-features">
                    <div class="feature-item">
                        <span class="feature-icon">✓</span>
                        <span>Posti auto e moto</span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-icon">✓</span>
                        <span>Aree coperte e scoperte</span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-icon">✓</span>
                        <span>Accesso controllato</span>
                    </div>
                </div>
            </div>
            <button class="card-button">
                Accedi alla mappa
                <span class="button-arrow">→</span>
            </button>
        </div>

        <!-- Card Mappa Sede -->
        <div class="map-card" onclick="location.href='asset-sede.php'" tabindex="0" role="button" aria-label="Vai alla scelta asset sede">
            <div class="card-icon sede-icon">
                🏢
            </div>
            <div class="card-content">
                <h2 class="card-title">Mappa Sede</h2>
                <p class="card-description">
                    Esplora la planimetria completa della sede con uffici, sale riunioni e aree comuni. 
                    Prenota la tua postazione di lavoro o sala meeting preferita.
                </p>
                <div class="card-features">
                    <div class="feature-item">
                        <span class="feature-icon">✓</span>
                        <span>Uffici e postazioni</span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-icon">✓</span>
                        <span>Sale riunioni attrezzate</span>
                    </div>
                    <div class="feature-item">
                        <span class="feature-icon">✓</span>
                        <span>Aree comuni e servizi</span>
                    </div>
                </div>
            </div>
            <button class="card-button">
                Accedi alla mappa
                <span class="button-arrow">→</span>
            </button>
        </div>

    </div>

</div>

<script>
// Aggiungi navigazione con tastiera
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.map-card');
    
    cards.forEach(card => {
        card.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                card.click();
            }
        });
    });
});

// Animazione di ingresso
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('.map-card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(30px)';
        
        setTimeout(() => {
            card.style.transition = 'all 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 200);
    });
});
</script>

</body>
</html>