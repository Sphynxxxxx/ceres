<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment</title>
    <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrious/4.0.2/qrious.min.js"></script>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
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
        .qr-container {
            display: none;
            margin-top: 15px;
        }
        .form-group {
            margin-top: 15px;
            text-align: left;
        }
        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .form-group select, .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: white;
            font-size: 16px;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
        }
        button {
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
        button:hover {
            background-color: #FFD700;
            color: black;
        }
        .message {
            margin-top: 15px;
            font-size: 18px;
            font-weight: bold;
            color: red;
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Make a Payment</h2>
        
        <button id="gcashBtn">Pay with GCash</button>
        <button id="qrBtn">Pay with QR Code</button>
        
        <div class="qr-container" id="qrContainer">
            <canvas id="qrCode"></canvas>
            <p>Scan the QR Code to Pay</p>
        </div>

        <div class="form-group">
            <label for="payment_method">Choose Payment Method:</label>
            <select name="payment_method" id="payment_method">
                <option value="" selected disabled>Select a Payment Method</option>
                <option value="PayPal">PayPal</option>
                <option value="Visa">Visa</option>
                <option value="Mastercard">Mastercard</option>
                <option value="Metro Bank">Metro Bank</option>
            </select>
        </div>

        <form id="paymentForm" action="process_payment.php" method="POST">
            <div class="form-group">
                <input type="text" id="promoCode" name="promo_code" placeholder="ADD PROMOCODE">
                <button type="button" id="applyPromo">Apply</button>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" id="require-invoice" name="invoice" value="1">
                <label for="require-invoice">I require an invoice</label>
            </div>

            <div class="form-group">
                <label>Please fill in the billing info</label>
                <input type="text" name="company" placeholder="Company Name">
                <input type="text" name="post_code" placeholder="Post Code">
            </div>
            <button type="submit" id="payNow">Pay Now</button>
        </form>

        <p id="paymentMessage" class="message">Payment Unsuccessful</p>
    </div>

    <script>
        document.getElementById("gcashBtn").addEventListener("click", function() {
            window.location.href = "https://www.gcash.com/"; 
        });

        document.getElementById("qrBtn").addEventListener("click", function() {
            var qrContainer = document.getElementById("qrContainer");
            var qrCodeCanvas = document.getElementById("qrCode");
            
            var qr = new QRious({
                element: qrCodeCanvas,
                value: "https://www.gcash.com/payment-link",
                size: 200,
            });
            
            qrContainer.style.display = "block";
        });

        document.getElementById("applyPromo").addEventListener("click", function() {
            var promoCode = document.getElementById("promoCode").value;
            if (promoCode.trim() === "") {
                alert("Please enter a promo code.");
            } else {
                alert("Promo code applied: " + promoCode);
            }
        });

        document.getElementById("paymentForm").addEventListener("submit", function(event) {
            event.preventDefault();
            var paymentMethod = document.getElementById("payment_method").value;
            if (!paymentMethod) {
                document.getElementById("paymentMessage").style.display = "block";
                return;
            }
            this.submit();
        });
    </script>
</body>
</html>