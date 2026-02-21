<?php
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>NorthStar – Parcheggi, Uffici & Sale Riunioni</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    <?php

    ?>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      background: #f8f9fc;
      color: #1a1a2e;
      line-height: 1.5;
    }

    .container {
      width: 100%;
      max-width: 1240px;
      margin: 0 auto;
      padding: 0 24px;
    }

    /* Header */
    .header {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      height: 80px;
      background: white;
      border-bottom: 1px solid #e5e7eb;
      z-index: 1000;
    }

    .header-inner {
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .logo-text {
      font-size: 30px;
      font-weight: 600;
      color: #6366f1;
      margin-left: -200px;
    }

    .s1 button, .s2 button, .s3 button, .s4 button {
      background: white;
      color: #1a1a2e;
      border: 1px solid #e5e7eb;
      padding: 12px 20px;
      border-radius: 8px;
      font-weight: 500;
    }

    .dropdown-content {
      visibility: hidden;
      opacity: 0;
      position: absolute;
      top: 100%;
      left: 0;
      right: 0;
      width: 1920px;
      height: 5cm;
      background-color: white;
      border: 1px solid #e5e7eb;
      padding: 10px;
      box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
      transition: opacity 0.3s ease, visibility 0.1s;
    }

    .s1:hover .dropdown-content,
    .s2:hover .dropdown-content,
    .s3:hover .dropdown-content,
    .s4:hover .dropdown-content {
      visibility: visible;
      opacity: 1;
    }

    .login .btn {
      padding: 10px 20px;
      border-radius: 8px;
      font-weight: 500;
      cursor: pointer;
    }

    .btn-outline {
      background: transparent;
      border: 1px solid #6366f1;
      color: #6366f1;
    }

    .btn-outline:hover {
      background: #6366f11a;
    }

    .menu-toggle {
      font-size: 1.8rem;
      background: none;
      border: none;
      cursor: pointer;
      color: #1a1a2e;
      display: none;
    }

    /* Hero */
    .hero {
      min-height: 100vh;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      display: flex;
      align-items: center;
      padding-top: 80px; 
    }

    .hero-content {
      max-width: 800px;
      text-align: center;
    }

    h1 {
      font-size: clamp(3.2rem, 8vw, 4.8rem);
      font-weight: 700;
      line-height: 1.05;
      margin-bottom: 1.2rem;
      animation: fadeIn 1.5s ease-in forwards;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to   { opacity: 1; }
    }

    .sottotitolo {
      font-size: clamp(1.2rem, 2vw, 1.4rem);
      font-weight: 300;
      line-height: 1.05;
      margin-bottom: 1.2rem;
      animation: fadeIn 3s ease-in forwards;
    }

    /* Responsive */
    @media (max-width: 980px) {
      .s1, .s2, .s3, .s4, .login {
        display: none;
      }
      .menu-toggle {
        display: block;
      }
    }

    @media (max-width: 640px) {
      .hero {
        padding: 100px 0 80px;
      }
      h1 {
        font-size: 2.8rem;
      }
      .sottotitolo {
        font-size: 1.2rem;
      }
    }
  </style>
</head>
<body>

  <header class="header">
    <div class="container header-inner">
      <button class="menu-toggle" aria-label="Open menu">☰</button>

      <div class="logo-text">
        <h2>NorthStar</h2>
      </div>

      <div class="s1">
        <button>Come funziona</button>
        <div class="dropdown-content">
          <p>ciao1</p>
        </div>
      </div>

      <div class="s2">
        <button>Sedi</button>
        <div class="dropdown-content">
          <p>ciao2</p>
        </div>
      </div>

      <div class="s3">
        <button>Per Business</button>
        <div class="dropdown-content">
          <p>ciao3</p>
        </div>
      </div>

      <div class="s4">
        <button>Supporto</button>
        <div class="dropdown-content">
          <p>ciao4</p>
        </div>
      </div>

      <div class="login">
        <button class="btn btn-outline">Log in</button>
      </div>
    </div>
  </header>

  <!-- Hero Section -->
  <section class="hero">
    <div class="container hero-content">
      <h1>Benvenuti in NorthStar</h1>
      <br>
      <p class="sottotitolo">
        Parcheggi, uffici e sale riunioni.<br>
        Qualsiasi cosa voi abbiate bisogno, NorthStar ve lo può fornire.
      </p>
    </div>
  </section>

</body>
</html>

