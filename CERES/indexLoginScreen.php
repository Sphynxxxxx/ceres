<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
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
        .login-container {
            background: linear-gradient(135deg, rgba(0, 0, 50, 0.8), rgba(26, 26, 252, 0.8));
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
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 10px;
            margin-bottom: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .password-container {
            position: relative;
            width: 100%;
        }
        .password-container input {
            width: 100%;
            padding-right: 40px;
        }
        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: black;
        }
        .error {
            color: red;
            font-size: 12px;
            display: none;
            margin-bottom: 10px;
        }
        a {
            text-decoration: none;
            font-size: 14px;
            color: #007bff;
        }
        button {
            background-color: #007bff;
            color: white;
            padding: 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
            font-size: 16px;
        }
        button:hover {
            background-color: #0056b3;
        }
        .signup-link {
            margin-top: 10px;
            font-size: 14px;
        }
        .social-login {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-top: 20px;
        }
        .social-login button {
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: white;
            color: #333;
            border: 1px solid #ccc;
            padding: 10px;
            width: 100%;
            font-size: 14px;
            border-radius: 4px;
            margin-bottom: 10px;
            cursor: pointer;
            font-weight: bold;
        }
        .social-login button img {
            width: 24px;
            height: 24px;
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="header-box">
            <h2>BUS TICKET APPOINTMENT<br>for ISAT-U CERES COMMUTERS</h2>
        </div>
        <div class="logo">
            <img src="Logo_ISAT.png" alt="ISAT-U Logo">
        </div>
        <form id="loginForm">
            <input type="text" id="username" placeholder="Username">
            <span class="error" id="userError">Username is required</span>

            <div class="password-container">
                <input type="password" id="password" placeholder="Password">
                <span class="toggle-password" onclick="togglePassword()">👁</span>
            </div>
            <span class="error" id="passError">Password is required</span>

            <a href="#">Forget Password?</a> <br><br>
            <button type="submit">Login</button>
        </form>

        <div class="signup-link">
            Don't have an account? <a href="indexSignUpScreen.php">SIGN UP</a>
        </div>

        <div class="social-login">
            <p>Or</p>
            <a href="https://www.facebook.com/login" target="_blank">
                <button>
                    <img src="https://upload.wikimedia.org/wikipedia/commons/5/51/Facebook_f_logo_%282019%29.svg" alt="Facebook">
                    Continue with Facebook
                </button>
            </a>
            <a href="https://accounts.google.com/signin" target="_blank">
                <button>
                    <img src="https://upload.wikimedia.org/wikipedia/commons/0/09/Google-logo.png" alt="Google">
                    Continue with Google
                </button>
            </a>
            <a href="https://www.instagram.com/accounts/login/" target="_blank">
                <button>
                    <img src="https://upload.wikimedia.org/wikipedia/commons/a/a5/Instagram_icon.png" alt="Instagram">
                    Continue with Instagram
                </button>
            </a>
        </div>
    </div>

    <script>
        function togglePassword() {
            var pass = document.getElementById("password");
            pass.type = (pass.type === "password") ? "text" : "password";
        }

        document.getElementById("loginForm").addEventListener("submit", function(event) {
            event.preventDefault();
            let username = document.getElementById("username").value.trim();
            let password = document.getElementById("password").value.trim();
            let valid = true;

            document.getElementById("userError").style.display = username === "" ? "block" : "none";
            document.getElementById("passError").style.display = password === "" ? "block" : "none";

            if (username && password) {
                alert("Login Successful!");
                window.location.href = "indexBookingScreen.php";
            }
        });
    </script>
</body>
</html>