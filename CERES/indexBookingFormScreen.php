<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Form</title>

    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

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
        .booking-form-container {
            background: linear-gradient(135deg, rgba(0, 0, 50, 0.8), rgba(0, 0, 100, 0.8));
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3);
            width: 350px;
            text-align: center;
            animation: fadeIn 1.5s;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
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
        .input-group {
            margin-bottom: 15px;
            text-align: left;
        }
        label {
            display: block;
            margin-bottom: 5px;
        }
        select,
        input[type="text"],
        input[type="datetime-local"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .button-container {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
        }
        button {
            flex: 1;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
        }
        .book-btn {
            background: linear-gradient(135deg, #0055ff, #FFA500);
            color: white;
            margin-right: 5px;
        }
        .back-btn {
            background-color: gray;
            color: white;
            margin-left: 5px;
        }
        .book-btn:hover {
            background: #007bff;
            color: black;
        }
        .back-btn:hover {
            background-color: darkgray;
        }

        .select2-container--default .select2-selection--single {
            background-color: #333; 
            color: white; 
            border: 1px solid #FFA500; 
            border-radius: 5px;
            padding: 6px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: white; 
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow b {
            border-color: white transparent transparent transparent;
        }
        .select2-dropdown {
            background-color: #222; 
            border: 1px solid #FFA500; 
            color: white; 
        }
        .select2-results__option {
            background-color: #222; 
            color: white;
            padding: 8px;
        }
        .select2-results__option:hover {
            background-color: #FFA500 !important; 
            color: black !important;
        }
    </style>
</head>
<body>
    <div class="booking-form-container">
        <div class="header-box">
            <h2>Bus Ticket Appointment</h2>
        </div>
        <form action="book_ticket.php" method="POST">
    <div class="input-group">
        <label for="passenger-name">NAME OF PASSENGER</label>
        <input type="text" name="first_name" placeholder="First Name" required>
        <input type="text" name="last_name" placeholder="Last Name" required>
    </div>
    <div class="input-group">
        <label for="num-people">NUMBER OF PEOPLE GOING</label>
        <select name="num_people" class="select2">
            <?php for ($i = 1; $i <= 10; $i++) { ?>
                <option value="<?= $i ?>"><?= $i ?></option>
            <?php } ?>
        </select>
    </div>
    <div class="input-group">
        <label for="num-seats">NUMBER OF SEATS</label>
        <select name="num_seats" class="select2">
            <?php for ($i = 1; $i <= 10; $i++) { ?>
                <option value="<?= $i ?>"><?= $i ?></option>
            <?php } ?>
        </select>
    </div>
    <div class="input-group">
        <label for="bus-time">BUS TIME AND DATE</label>
        <input type="datetime-local" name="bus_time" required>
    </div>
    <div class="input-group">
        <label for="bus-location">BUS LOCATION</label>
        <input type="text" name="bus_location" required>
    </div>
    <div class="input-group">
        <label for="destination">DESTINATION</label>
        <input type="text" name="destination" required>
    </div>
    <div class="button-container">
        <button type="submit" class="book-btn">BOOK NOW</button>
    </div>
</form>
    </div>

    <script>
        function goBack() {
            window.history.back();
        }

        $(document).ready(function() {
            $('.select2').select2({
                minimumResultsForSearch: -1
            });
        });
    </script>

</body>
</html>
