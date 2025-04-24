<?php

$bus_routes = [
    ['bus' => 'ICB101', 'departure' => '6:00 AM', 'origin' => 'Iloilo City', 'destination' => 'Passi', 'distance' => 5, 'fare' => 'P20', 'lat' => 10.7202, 'lng' => 122.5621],
    ['bus' => 'ICB102', 'departure' => '7:30 AM', 'origin' => 'Iloilo City', 'destination' => 'Kalibo', 'distance' => 8, 'fare' => 'P30', 'lat' => 10.7150, 'lng' => 122.5516],
    ['bus' => 'ICB103', 'departure' => '9:00 AM', 'origin' => 'Iloilo City', 'destination' => 'Caticlan', 'distance' => 12, 'fare' => 'P40', 'lat' => 10.7667, 'lng' => 122.5453],
    ['bus' => 'ICB104', 'departure' => '10:15 AM', 'origin' => 'Iloilo City', 'destination' => 'San Dionisio', 'distance' => 15, 'fare' => 'P50', 'lat' => 10.7893, 'lng' => 122.6086],
    ['bus' => 'ICB105', 'departure' => '12:00 PM', 'origin' => 'Iloilo City', 'destination' => 'Carles', 'distance' => 20, 'fare' => 'P60', 'lat' => 10.8242, 'lng' => 122.5372]
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bus Location</title>
    <script async defer src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_MAPS_API_KEY&callback=initMap"></script>
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
        #map {
            height: 400px;
            width: 100%;
            margin-top: 20px;
            border-radius: 10px;
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
            background-color: #FFD700;
            color: black;
        }
    </style>
    <script>
        function initMap() {
            var map = new google.maps.Map(document.getElementById('map'), {
                zoom: 12,
                center: { lat: 10.7202, lng: 122.5621 }
            });
            var routes = <?php echo json_encode($bus_routes); ?>;
            routes.forEach(function(route) {
                var marker = new google.maps.Marker({
                    position: { lat: parseFloat(route.lat), lng: parseFloat(route.lng) },
                    map: map,
                    title: route.destination
                });
            });
        }
        function rowClicked(bus, departure, origin, destination, distance, fare) {
            window.location.href = `fare_details.php?bus=${bus}&departure=${encodeURIComponent(departure)}&origin=${encodeURIComponent(origin)}&destination=${encodeURIComponent(destination)}&distance=${distance}&fare=${fare}`;
        }
    </script>
</head>
<body>
    <div class="container">
        <h2>Bus Location</h2>
        <table>
            <tr>
                <th>Bus No.</th>
                <th>Departure</th>
                <th>Origin</th>
                <th>Destination</th>
                <th>Distance (km)</th>
                <th>Fare</th>
            </tr>
            <?php foreach ($bus_routes as $route) { ?>
                <tr onclick="rowClicked('<?= $route['bus'] ?>', '<?= $route['departure'] ?>', '<?= $route['origin'] ?>', '<?= $route['destination'] ?>', <?= $route['distance'] ?>, '<?= $route['fare'] ?>')">
                    <td><?= htmlspecialchars($route['bus']) ?></td>
                    <td><?= htmlspecialchars($route['departure']) ?></td>
                    <td><?= htmlspecialchars($route['origin']) ?></td>
                    <td><?= htmlspecialchars($route['destination']) ?></td>
                    <td><?= htmlspecialchars($route['distance']) ?></td>
                    <td><?= htmlspecialchars($route['fare']) ?></td>
                </tr>
            <?php } ?>
        </table>
        <div id="map"></div>
        <button class="back-button" onclick="window.history.back()">Back</button>
    </div>
</body>
</html>
