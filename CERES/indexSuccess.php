<!DOCTYPE html>
<html>
<head>
    <title>Success</title>
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
        .success-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            width: 300px; 
            text-align: center;
        }
        .success-icon {
            width: 80px; 
            margin-bottom: 20px;
        }
        button {
            background-color: #007bff; 
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="success-container">
        <h2>SUCCESS</h2>
        <img class="success-icon" src="path/to/success-icon.png" alt="Success Icon">
        <p>Congratulations, your<br>account has been created<br>successfully!</p>
        <button>CONTINUE</button>
    </div>
</body>
</html>