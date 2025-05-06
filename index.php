<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ceres Bus for ISAT-U Commuters</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="user/css/index.css">
    <link rel="stylesheet" href="user/css/navfot.css">
    <style>
        :root {
            --primary: #ffb100;
            --dark: #1d3557;
            --light: #f8f9fa;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
            background-color: #f8f9fa;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Hero Section */
        .hero-section {
            background: url('user/assets/Ceres_Bus.JPG') center/cover no-repeat;
            color: white;
            padding: 8rem 0;
            flex-grow: 1;
            display: flex;
            align-items: center;
        }

        .hero-content {
            max-width: 600px;
            margin: 0 auto;
        }

        .hero-section h1 {
            font-size: 2.8rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
        }

        .hero-section p {
            font-size: 1.2rem;
            margin-bottom: 2rem;
            opacity: 0.9;
        }

        .btn-warning {
            background-color: var(--primary);
            border-color: var(--primary);
            color: var(--dark);
            font-weight: 600;
            padding: 0.7rem 1.5rem;
        }

        .btn-warning:hover {
            background-color: #ffa000;
            border-color: #ffa000;
        }

        .btn-outline-light:hover {
            background-color: var(--primary);
            color: var(--dark);
            border-color: var(--primary);
        }

        /* Footer */
        footer {
            background-color: var(--dark);
            color: white;
            padding: 1rem 0;
            text-align: center;
        }

        .auth-buttons .btn {
            min-width: 120px;
            margin: 0 0.5rem;
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand d-flex flex-wrap align-items-center" href="dashboard.php">
                <i class="fas fa-bus-alt me-2"></i>
                <span class="text-wrap">Ceres Bus for ISAT-U Commuters</span>
            </a>
            <div class="d-flex">
                <a href="user/login.php" class="btn btn-outline-light me-2">Login</a>
                <a href="user/registration.php" class="btn btn-warning">Register</a>
            </div>
        </div>
    </nav>

    <div class="hero-section">
        <div class="container text-center">
            <div class="hero-content">
                <h1>Easy Bus Travel for ISAT-U Community</h1>
                <p>Book your Ceres bus tickets online with our simple platform designed exclusively for ISAT-U students, faculty, and staff.</p>
                <div class="auth-buttons">
                    <a href="user/registration.php" class="btn btn-warning btn-lg">Get Started</a>
                    <a href="#" class="btn btn-outline-light btn-lg">Learn More</a>
                </div>
            </div>
        </div>
    </div>

    <footer>
        <div class="container">
            <p>&copy; 2025 Ceres Bus - ISAT-U Commuters Ticket System. All rights reserved.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>