

<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Login | Z Volta</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="./login.css">
</head>
<body>
    <div class="navbar">
      <a href="./hp.php" class="navbar-home">Home</a>
      <span class="navbar-separator">|</span>
      <span class="navbar-current">Log in</span>
    </div>
    
    <div class="login-container">
      <h1>Z VOLTA</h1>

      <form method="POST" action="auth/login-handler.php" id="loginForm">
        <div class="field">
          <label for="User">ID utente</label>
          <input type="text" id="User" name="username" placeholder="es. cAr5tAmA77Ia" required>
        </div>

        <div class="field password-field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" placeholder="************" required>
        </div>


        <input type="hidden" id="mouse_data" name="mouse_data" value="">

        <button type="submit" class="login-btn">Login</button>
      </form>
    </div>

</body>
</html>