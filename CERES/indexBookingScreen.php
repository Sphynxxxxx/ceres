<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking</title>
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
            backdrop-filter: blur(2px);
        }
        .booking-container {
            background: linear-gradient(135deg, rgba(0, 0, 50, 0.8), rgba(0, 0, 100, 0.8));
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
        .header-box {
            background-color: gold;
            padding: 15px;
            border-radius: 8px 8px 0 0;
        }
        .header-box h2 {
            margin: 0;
            font-size: 16px;
            color: black;
        }
        .logo img {
            width: 80px;
            margin: 10px 0;
        }
        .filter-bar {
            display: flex;
            align-items: center;
            background:rgb(0, 2, 3);
            padding: 8px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .filter-bar input {
            flex-grow: 1;
            padding: 10px;
            border: none;
            border-radius: 6px;
            outline: none;
            font-size: 14px;
            background: white;
        }
        .filter-bar button {
            background-color: black;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 10px 15px;
            cursor: pointer;
            margin-left: 10px;
            font-size: 14px;
        }
        .options {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            grid-gap: 10px;
            margin-bottom: 15px;
        }
        .option {
            text-align: center;
            background:rgb(255, 255, 255);
            padding: 10px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            cursor: pointer;
        }
        .option img {
            width: 40px;
            height: 40px;
            margin-bottom: 5px;
        }
        .option p {
            font-size: 14px;
            margin: 0;
        }
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 15px;
        }
        .btn {
            background: linear-gradient(135deg, #0033cc, #0055ff, #FFD700, #FFA500);
            color: white;
            padding: 12px;
            border: 1px solid black;
            border-radius: 25px;
            cursor: pointer;
            width: 100%;
            font-size: 16px;
            font-weight: bold;
            transition: 0.3s;
        }
        .btn:hover {
            background: white;
            color: black;
        }
    </style>
</head>
<body>
    <div class="booking-container">
        <div class="header-box">
            <h2>BUS Ticket Appointment for ISAT-U Ceres Commuters</h2>
        </div>
        <div class="logo">
            <img src="BUS.png" alt="Bus">
        </div>
        <div class="filter-bar">
            <input type="text" id="searchInput" placeholder="Search routes, schedules..." onkeyup="searchOptions()">
            <button onclick="searchOptions()">Search</button>
        </div>
        <div class="options" id="optionsList">
            <div class="option" onclick="redirectTo('Iloilo Bus Routes.php')">
                <img src="https://img.icons8.com/ios-filled/50/000000/bus.png" alt="Route">
                <p style="color: Black;">Route</p>
            </div>
            <div class="option" onclick="redirectTo('BusSchedule.php')">
                <img src="https://img.icons8.com/ios-filled/50/000000/calendar.png" alt="Schedule">
                <p style="color: Black;">Schedule</p>
            </div>
            <div class="option" onclick="redirectTo('Location.php')">
                <img src="https://img.icons8.com/ios-filled/50/000000/map-marker.png" alt="Location">
                <p style="color: Black;">Location</p>
            </div>
            <div class="option" onclick="redirectTo('fare_details123.php')">
                <img src="https://img.icons8.com/ios-filled/50/000000/money.png" alt="Fare">
                <p style="color: Black;">Fare</p>
            </div>
            <div class="option" onclick="redirectTo('indexPayment1.php')">
                <img src="payment.jpg" alt="Payment">
                <p style="color: Black;">Payment</p>
            </div>
            <div class="option" onclick="redirectTo('Contact.php')">
                <img src="https://img.icons8.com/ios-filled/50/000000/phone.png" alt="Contact">
                <p style="color: Black;">Contact</p>
            </div>
        </div>
        <div class="action-buttons">
            <button class="btn" onclick="redirectToBooking()"> BOOK APPOINTMENT</button>
            <button class="btn" onclick="redirectToSeats()"> SEATS AVAILABILITY</button>
        </div>
    </div>
    <script>
        function searchOptions() {
            let input = document.getElementById("searchInput").value.toLowerCase();
            let options = document.querySelectorAll(".option");
            options.forEach(option => {
                let text = option.textContent.toLowerCase();
                option.style.display = text.includes(input) ? "block" : "none";
            });
        }
        function redirectToBooking() {
            window.location.href = "indexBookingFormScreen.php";
        }
        function redirectToSeats() {
            window.location.href = "indexSeatAvailability.php";
        }
        function redirectTo(url) {
            window.location.href = url;
        }
    </script>
</body>
</html>
