<?php
session_start();

$messaggio = "";

// 1. Genera il codice di 5 lettere da copiare
if (!isset($_SESSION['text_captcha'])) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $_SESSION['text_captcha'] = substr(str_shuffle($chars), 0, 5);
}

// Controlla se il form è stato inviato
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user = $_POST['User'] ?? '';
    $password = $_POST['password'] ?? '';
    $captchaInserito = trim($_POST['captcha'] ?? '');
    
    // Recupera i dati del mouse inviati tramite JavaScript
    $mouseDataJson = $_POST['mouse_data'] ?? '[]';
    
    $isBot = false;
    $motivoErrore = "";

    // A. Controllo del testo copiato (case-insensitive)
    if (strtoupper($captchaInserito) !== $_SESSION['text_captcha']) {
        $isBot = true;
        $motivoErrore = "Il codice di testo non corrisponde.";
    } else {
        // B. IL VERO CAPTCHA: Analisi dei movimenti del mouse
        $mouseData = json_decode($mouseDataJson, true);
        
        // 1. Controllo quantità movimenti: un umano genera decine di eventi mousemove
        if (!is_array($mouseData) || count($mouseData) < 5) {
            $isBot = true;
            $motivoErrore = "Movimento assente (rilevato possibile bot).";
        } else {
            // 2. Controllo precisione robotica (se è una linea retta perfetta)
            $isLinear = true;
            $dx0 = $mouseData[1]['x'] - $mouseData[0]['x'];
            $dy0 = $mouseData[1]['y'] - $mouseData[0]['y'];
            
            for ($i = 2; $i < count($mouseData); $i++) {
                $dx = $mouseData[$i]['x'] - $mouseData[$i-1]['x'];
                $dy = $mouseData[$i]['y'] - $mouseData[$i-1]['y'];
                
                // Se il prodotto incrociato non è 0, c'è una curva/tremolio (comportamento umano)
                if (($dx0 * $dy) - ($dy0 * $dx) != 0) {
                    $isLinear = false;
                    break;
                }
            }
            
            if ($isLinear) {
                $isBot = true;
                $motivoErrore = "Movimento del mouse troppo preciso e innaturale.";
            }
        }
    }

    // Risultato finale
    if (!$isBot) {
        // Se passa tutti i controlli, qui verifichi i dati col database
        $messaggio = "<p style='color: #155724; background: #d4edda; padding: 10px; border-radius: 5px; text-align: center;'>✅ Login consentito! Sei umano.</p>";
    } else {
        $messaggio = "<p style='color: #721c24; background: #f8d7da; padding: 10px; border-radius: 5px; text-align: center;'>❌ Accesso negato: $motivoErrore</p>";
    }
    
    // Rigenera sempre il codice dopo un tentativo
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $_SESSION['text_captcha'] = substr(str_shuffle($chars), 0, 5);
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Login | Z Volta</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="./login.css">
</head>
<body>
    <div class="login-container">
      <h1>Z VOLTA</h1>
      
      <?php if(!empty($messaggio)) echo $messaggio; ?>

      <form method="POST" action="" id="loginForm">
        <div class="field">
          <label for="User">ID utente</label>
          <input type="text" id="User" name="User" placeholder="es. cAr5tAmA77Ia" required>
        </div>

        <div class="field password-field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" placeholder="************" required>
        </div>

        <div class="field">
          <label for="captcha">Copia questo codice:</label>
          <div style="text-align: center; margin-bottom: 10px; background: rgba(255,255,255,0.2); padding: 10px; border-radius: 25px;">
            <strong style="color: white; font-size: 24px; letter-spacing: 5px; user-select: all;">
                <?php echo $_SESSION['text_captcha']; ?>
            </strong>
          </div>
          <input type="text" id="captcha" name="captcha" maxlength="5" placeholder="Incolla il codice qui" required autocomplete="off">
        </div>

        <input type="hidden" id="mouse_data" name="mouse_data" value="">

        <button type="submit" class="login-btn">Login</button>
      </form>
    </div>

    <script>
      // Array dove salveremo le coordinate X e Y
      let mouseTrack = [];
      
      // Ascolta il movimento del mouse su tutta la pagina
      document.addEventListener('mousemove', function(e) {
          // Salviamo un massimo di 50 punti per non appesantire i dati inviati al server
          if (mouseTrack.length < 50) {
              mouseTrack.push({ x: e.clientX, y: e.clientY });
          }
      });

      // Quando l'utente clicca "Login", convertiamo i dati in testo e li mettiamo nell'input nascosto
      document.getElementById('loginForm').addEventListener('submit', function() {
          document.getElementById('mouse_data').value = JSON.stringify(mouseTrack);
      });
    </script>
</body>
</html>