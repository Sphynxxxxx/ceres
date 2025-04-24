<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            background: url('Ceres_Bus.JPG') no-repeat center center/cover;
            text-align: center;
            color: white;
            backdrop-filter: blur(2px);
        }
        .container {
            background: linear-gradient(135deg, rgba(0, 0, 50, 0.8), rgba(26, 26, 252, 0.8));
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3);
            width: 320px;
            text-align: center;
            animation: fadeIn 1.5s;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        img {
            width: 100px;
            margin-bottom: 15px;
            filter: drop-shadow(2px 2px 5px rgba(255, 255, 255, 0.5));
        }
        .welcome-box {
            background: linear-gradient(135deg, #007bff, #00c6ff);
            padding: 15px;
            border-radius: 10px;
            font-size: 18px;
            font-weight: bold;
            box-shadow: 0 4px 8px rgba(255, 255, 255, 0.2);
            border: 2px solid white;
        }
        h1 {
            margin: 0;
            font-size: 22px;
            line-height: 1.4;
        }
        .btn-container {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }
        button {
            background: linear-gradient(135deg,rgb(255, 76, 115),rgb(255, 43, 61));
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 30px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
            max-width: 200px;
            transition: 0.3s ease-in-out;
            box-shadow: 0 4px 8px rgba(255, 255, 255, 0.2);
        }
        button:hover {
            background: linear-gradient(135deg,rgb(112, 243, 226),rgb(15, 170, 190));
            transform: scale(1.05);
        }
    </style>
</head>
<body>
    <div class="container">
        <img src="Logo_ISAT.png" alt="ISAT-U Logo">  
        <div class="welcome-box">
            <h1>WELCOME<br>TO ISAT-U CERES<br>COMMUTERS!</h1>
        </div>
        <div class="btn-container">
            <button onclick="window.location.href='indexLoginScreen.php'">GET STARTED →</button>
        </div>
    </div>
</body>
</html>
