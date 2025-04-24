<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Page</title>
    <style>
        body {
            font-family: sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-color: #f0f0f0;
        }

        .payment-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            width: 350px;
        }

        h2 {
            text-align: center;
            margin-bottom: 20px;
        }

        .input-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
        }

        input[type="text"],
        input[type="number"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }

        button {
            background-color: #4CAF50;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
        }

        button:hover {
            background-color: #45a049;
        }

        .promo-code {
            margin-top: 20px;
        }

        .promo-code input[type="text"] {
            width: calc(100% - 80px); /* Adjust width for button */
            display: inline-block;
        }

        .promo-code button {
            width: auto;
            padding: 10px;
            margin-left: 10px;
            background-color: #007bff;
        }

        .promo-code button:hover {
            background-color: #0069d9;
        }

        .total {
            margin-top: 20px;
            font-size: 1.2em;
            font-weight: bold;
            text-align: right;
        }

        .paypal-button, .card-button {
            margin-top: 10px;
            width: 100%;
            padding: 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-align: center;
        }

        .paypal-button {
            background-color: #FFC439; 
            color: #000;
        }

        .card-button {
            background-color: #4285F4; 
            color: white;
        }

        .paypal-button:hover {
            background-color: #e6b32a;
        }

        .card-button:hover {
            background-color: #357ae8;
        }

        .deposit-info {
            margin-top: 10px;
            text-align: right;
            font-size: 0.9em;
            color: #777;
        }
    </style>
</head>
<body>

    <div class="payment-container">
        <h2>Payment</h2>

        <div class="input-group">
            <label for="invoice">I require an invoice</label>
            <input type="checkbox" id="invoice" name="invoice">
        </div>

        <button class="paypal-button">PayPal</button>
        <button class="card-button">Pay with Card</button>

        <div class="promo-code">
            <input type="text" id="promo-code" placeholder="ADD PROMOCODE">
            <button onclick="applyPromo()">Apply</button> 
            <div id="promo-message"></div>  </div>

        <div class="total">
            Total to Pay: <span id="total-amount">$50</span>
        </div>

        <div class="deposit-info">
            Booking Price: $50<br>
            Deposit Available: $30
        </div>

        <button onclick="payNow()">Pay Now</button>
    </div>

    <script>
        let bookingPrice = 50;
        let depositAvailable = 30;
        let totalAmount = bookingPrice;

        function applyPromo() {
            const promoCodeInput = document.getElementById("promo-code");
            const promoMessage = document.getElementById("promo-message");
            const enteredCode = promoCodeInput.value;

            if (enteredCode === "Fall2024") {
                totalAmount = 15;
                document.getElementById("total-amount").textContent = "$" + totalAmount;
                promoMessage.textContent = "Promocode Applied";
                promoMessage.style.color = "green";
            } else {
                totalAmount = bookingPrice; 
                document.getElementById("total-amount").textContent = "$" + totalAmount;
                promoMessage.textContent = "Invalid Promocode";
                promoMessage.style.color = "red";
            }
        }

        function payNow() {
            alert("Payment processed! (This is a simulation)");
        }
    </script>

</body>
</html>