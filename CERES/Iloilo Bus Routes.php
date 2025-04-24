<!DOCTYPE html>
<html>
<head>
    <title>Bus Routes</title>
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
        .back-button {
            background: linear-gradient(135deg, rgba(80, 80, 219, 0.8), rgba(89, 69, 122, 0.8));
            color: white;
            padding: 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
            font-size: 16px;
            margin-top: 10px;
        }
        .back-button:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Bus Routes</h2>
        <table>
            <tr>
                <th>Bus No.</th>
                <th>Departure</th>
                <th>Origin</th>
                <th>Destination</th>
                <th>Distance (km)</th>
                <th>Fare</th>
                <th>Route Map</th>
            </tr>
            <?php
            $bus_routes = [
                ['bus' => 'ICB101', 'departure' => '6:00 AM', 'origin' => 'Iloilo City', 'destination' => 'Passi', 'distance' => 5, 'fare' => 'P20', 'map' => 'https://www.google.com/maps/dir/Iloilo+City/Passi/'],
                ['bus' => 'ICB102', 'departure' => '7:30 AM', 'origin' => 'Iloilo City', 'destination' => 'Kalibo', 'distance' => 8, 'fare' => 'P30', 'map' => 'https://www.google.com/maps/dir/Iloilo+City/Kalibo/'],
                ['bus' => 'ICB103', 'departure' => '9:00 AM', 'origin' => 'Iloilo City', 'destination' => 'Caticlan', 'distance' => 12, 'fare' => 'P40', 'map' => 'https://www.google.com/maps/dir/Iloilo+City/Caticlan/'],
                ['bus' => 'ICB104', 'departure' => '10:15 AM', 'origin' => 'Iloilo City', 'destination' => 'San Dionisio', 'distance' => 15, 'fare' => 'P50', 'map' => 'https://www.google.com/maps/dir/Iloilo+City/San+Dionisio/'],
                ['bus' => 'ICB105', 'departure' => '12:00 PM', 'origin' => 'Iloilo City', 'destination' => 'Carles', 'distance' => 20, 'fare' => 'P60', 'map' => 'https://www.google.com/maps/dir/Iloilo+City/Carles/']
            ];
            foreach ($bus_routes as $route) {
                echo "<tr>
                        <td>" . htmlspecialchars($route['bus']) . "</td>
                        <td>" . htmlspecialchars($route['departure']) . "</td>
                        <td>" . htmlspecialchars($route['origin']) . "</td>
                        <td>" . htmlspecialchars($route['destination']) . "</td>
                        <td>" . htmlspecialchars($route['distance']) . "</td>
                        <td>" . htmlspecialchars($route['fare']) . "</td>
                        <td><a href='" . htmlspecialchars($route['map']) . "' target='_blank'>View Map</a></td>
                      </tr>";
            }
            ?>
        </table>
        <button class="back-button" onclick="goBack()">Back</button>
    </div>

    <script>
        function goBack() {
            window.history.back();
        }
    </script>
</body>
</html>
