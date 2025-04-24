<!DOCTYPE html>
<html>
<head>
    <title>Welcome</title>
    <style>
        body {
            font-family: sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            background-color: #f0f0f0; 
        }
        .welcome-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            width: 300px; 
            text-align: center;
        }
        .bus-image {
            width: 200px; 
            margin-bottom: 20px;
        }
        .welcome-message {
            margin-bottom: 20px;
        }
        button {
            background-color: black;
            color: white;
            padding: 12px;
            border: 2px solid white;
            border-radius: 30px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
            transition: 0.3s ease;
        }
        button:hover {
            background-color: white;
            color: black;
            border: 2px solid black;
        }
    </style>
</head>
<body>
    <div class="welcome-container">
        <img class="bus-image" src="BUS.png" alt="Bus">
        <div class="welcome-message">
            <h2>Welcome!</h2>
            <p>Ready to get started? Book your appointment with us now - quick and easy!</p>
        </div>
        <button onclick="bookNow()">> BOOK NOW</button>
    </div>

    <script>
        function bookNow() {
            window.location.href = "indexBookingScreen.php"; 
        }
    </script>
</body>
</html>
