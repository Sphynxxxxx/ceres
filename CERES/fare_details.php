<!DOCTYPE html>
<html>
<head>
    <title>Fare Details</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
        <?php 
        $fare_details = [
            'Passi' => ['distance' => '10km', 'fare_per_km' => 'P25', 'total_fare' => '250', 'contact' => '+639345'],
            'Kalibo' => ['distance' => '12km', 'fare_per_km' => 'P25', 'total_fare' => '300', 'contact' => '+639456'],
            'Caticlan' => ['distance' => '15km', 'fare_per_km' => 'P25', 'total_fare' => '375', 'contact' => '+639567'],
            'San Dionision' => ['distance' => '18km', 'fare_per_km' => 'P25', 'total_fare' => '450', 'contact' => '+639678'],
            'Carles' => ['distance' => '20km', 'fare_per_km' => 'P25', 'total_fare' => '500', 'contact' => '+639789']
        ];
        $destination = $_GET['destination'] ?? 'Unknown';
        $details = $fare_details[$destination] ?? null;
        ?>

        <?php if ($details): ?>
            <table>
                <tr>
                    <th>Destination</th>
                    <th>Distance</th>
                    <th>Fare per km</th>
                    <th>Total Fare</th>
                    <th>Contact</th>
                </tr>
                <tr>
                    <td><?= htmlspecialchars($destination) ?></td>
                    <td><?= $details['distance'] ?></td>
                    <td><?= $details['fare_per_km'] ?></td>
                    <td><?= $details['total_fare'] ?></td>
                    <td><?= $details['contact'] ?></td>
                </tr>
            </table>
        <?php else: ?>
            <p style="text-align:center; color: red;">No fare details found for this destination.</p>
        <?php endif; ?>

        <div class="buttons">
            <button class="back-button" onclick="window.history.back()">Back</button>
        </div>
    </div>
</body>
</html>
