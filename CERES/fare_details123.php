<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fare Details</title>
    <style>
        body {
            font-family: sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            background: url('Ceres_Bus.JPG') no-repeat center center/cover;
            text-align: center;
            color: white;
            backdrop-filter: blur(5px);
        }
        .container {
            background: linear-gradient(135deg, rgba(0, 0, 50, 0.8), rgba(0, 0, 100, 0.8));
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3);
            width: 90%;
            max-width: 600px;
            text-align: center;
            animation: fadeIn 1.5s;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background: white;
            color: black;
            border-radius: 10px;
            overflow: hidden;
        }
        th, td {
            border: 1px solid #003366;
            padding: 12px;
            text-align: center;
        }
        th {
            background-color: gold;
            color: black;
        }
        tr:hover {
            background-color: #ADD8E6;
            cursor: pointer;
        }
        .buttons {
            text-align: center;
            margin-top: 10px;
        }
        .back-button {
            background: linear-gradient(135deg, rgba(80, 80, 219, 0.8), rgba(89, 69, 122, 0.8));
            color: white;
            padding: 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
            font-size: 16px;
        }
        .back-button:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>

    <div class="container">
        <h2>Fare Details</h2>
        <table>
            <tr>
                <th>Destination</th>
                <th>Distance (km)</th>
                <th>Fare per km</th>
                <th>Total Fare</th>
                <th>Contact</th>
            </tr>
            <tr>
                <td>Passi</td>
                <td>10 km</td>
                <td>₱25</td>
                <td>₱250</td>
                <td>+6393456</td>
            </tr>
            <tr>
                <td>Kalibo</td>
                <td>12 km</td>
                <td>₱25</td>
                <td>₱300</td>
                <td>+6397854</td>
            </tr>
            <tr>
                <td>San Dionisio</td>
                <td>15 km</td>
                <td>₱25</td>
                <td>₱375</td>
                <td>+6390882</td>
            </tr>
            <tr>
                <td>Carles</td>
                <td>20 km</td>
                <td>₱25</td>
                <td>₱500</td>
                <td>+6393420</td>
            </tr>
            <tr>
                <td>Caticlan</td>
                <td>20 km</td>
                <td>₱25</td>
                <td>₱500</td>
                <td>+6393420</td>
            </tr>
        </table>
        <div class="buttons">
            <button class="back-button" onclick="window.history.back()">Back</button>
        </div>
    </div>

</body>
</html>
