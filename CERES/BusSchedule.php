<!DOCTYPE html>
<html>
<head>
    <title>Bus Schedule</title>
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
    <script>
        function rowClicked(destination) {
            window.location.href = `fare_details.php?destination=${destination}`;
        }
    </script>
</head>
<body>
    <div class="container">
        <h2>Bus Schedule</h2>
        <table>
            <tr>
                <th>Bus</th>
                <th>Departure</th>
                <th>Origin</th>
                <th>Destination</th>
                <th>Distance</th>
            </tr>
            <?php 
            $bus_schedule = [
                ['bus' => '123', 'departure' => '6:00am', 'origin' => 'Term 1', 'destination' => 'Passi', 'distance' => '10km'],
                ['bus' => '231', 'departure' => '8:00am', 'origin' => 'Term 2', 'destination' => 'Kalibo', 'distance' => '12km'],
                ['bus' => '239', 'departure' => '10:00am', 'origin' => 'Term 2', 'destination' => 'Caticlan', 'distance' => '15km'],
                ['bus' => '245', 'departure' => '12:00pm', 'origin' => 'Term 1', 'destination' => 'San Dionision', 'distance' => '18km'],
                ['bus' => '256', 'departure' => '3:00pm', 'origin' => 'Term 3', 'destination' => 'Carles', 'distance' => '20km']
            ];
            foreach ($bus_schedule as $bus) { 
                echo "<tr onclick=\"rowClicked('{$bus['destination']}')\">"
                    . "<td>{$bus['bus']}</td>"
                    . "<td>{$bus['departure']}</td>"
                    . "<td>{$bus['origin']}</td>"
                    . "<td>{$bus['destination']}</td>"
                    . "<td>{$bus['distance']}</td>"
                    . "</tr>";
            }
            ?>
        </table>
        <div class="buttons">
            <button class="back-button" onclick="window.history.back()">Back</button>
        </div>
    </div>
</body>
</html>
