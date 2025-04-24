<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seats Availability</title>
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
        .seats-container {
            background: linear-gradient(135deg, rgba(0, 0, 50, 0.8), rgba(0, 0, 100, 0.8));
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3);
            width: 350px;
            text-align: center;
        }
        .header-box {
            background-color: #FFD700;
            padding: 15px;
            border-radius: 8px 8px 0 0;
        }
        .header-box h2 {
            margin: 0;
            font-size: 18px;
            color: black;
        }
        .availability {
            margin-bottom: 20px;
        }
        .seats {
            display: grid;
            grid-template-columns: repeat(10, 1fr);
            grid-gap: 5px;
            justify-content: center;
        }
        .seat {
            width: 20px;
            height: 20px;
            border-radius: 4px;
            cursor: pointer;
        }
        .available {
            background-color: green;
        }
        .unavailable {
            background-color: red;
            pointer-events: none;
        }
        .back-btn {
            background-color: gray;
            color: white;
            border: none;
            border-radius: 25px;
            padding: 12px;
            width: 100%;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 15px;
            transition: 0.3s;
        }
        .back-btn:hover {
            background-color: darkgray;
        }
    </style>
</head>
<body>
    <div class="seats-container">
        <div class="header-box">
            <h2>Seats Availability</h2>
        </div>
        <div class="availability">
            <p id="seat-count">Loading...</p>
        </div>
        <div class="seats" id="seats-grid"></div>
        <button class="back-btn" onclick="goBack()"> BACK</button>
    </div>
    <script>
        document.addEventListener("DOMContentLoaded", fetchSeats);

        function fetchSeats() {
            fetch("fetch_seats.php")
                .then(response => response.json())
                .then(data => {
                    const seatsGrid = document.getElementById("seats-grid");
                    seatsGrid.innerHTML = "";
                    let availableSeats = 0;

                    data.forEach((status, index) => {
                        const seat = document.createElement("div");
                        seat.classList.add("seat", status);
                        seat.dataset.id = index + 1;

                        if (status === "available") {
                            availableSeats++;
                            seat.addEventListener("click", () => bookSeat(index + 1, seat));
                        }

                        seatsGrid.appendChild(seat);
                    });

                    document.getElementById("seat-count").textContent = `${availableSeats}/40`;
                })
                .catch(error => console.error("Error fetching seats:", error));
        }

        function bookSeat(seatId, seatElement) {
            fetch("book_seat.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded"
                },
                body: `seat_id=${seatId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    seatElement.classList.remove("available");
                    seatElement.classList.add("unavailable");
                    seatElement.removeEventListener("click", () => bookSeat(seatId, seatElement));

                    let seatCountText = document.getElementById("seat-count").textContent;
                    let [availableSeats, totalSeats] = seatCountText.split("/").map(Number);
                    document.getElementById("seat-count").textContent = `${availableSeats - 1}/${totalSeats}`;
                } else {
                    alert("Failed to book the seat.");
                }
            })
            .catch(error => console.error("Error booking seat:", error));
        }

        function goBack() {
            window.history.back();
        }
    </script>
</body>
</html>
