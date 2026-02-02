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

    <form method="POST" action="">
      
      <div class="field">
        <label for="email">ID utente</label>
        <input 
          type="email" 
          id="email" 
          name="email" 
          placeholder="es. cAr5tAmA77Ia">
      </div>

      <div class="field password-field">
        <label for="password">Password</label>
        <input 
          type="password" 
          id="password" 
          name="password" 
          placeholder="************">
      </div>

      <button type="submit" class="login-btn">
        Login
      </button>

      <div class="divider">
        <span>Oppure</span>
      </div>

      <a href="#" class="register-link">Registrati</a>

    </form>
  </div>

</body>
</html>
