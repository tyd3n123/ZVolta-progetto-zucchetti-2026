// Per avere maggiore sicurezza in ogni file, includere questo file all'inizio 
// di ogni pagina che necessita di autenticazione. Se l'utente non è loggato,
// verrà reindirizzato alla pagina di login.

<?php
 echo "Controllo";
session_start();

if (!isset($_SESSION["logged_in"]) !== true) {
   // header("Location: ../login.php");
    echo "Accesso consentito";
}
else {
    echo "Accesso non consentito";
   
} exit();
?>