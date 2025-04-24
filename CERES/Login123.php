<!DOCTYPE html>
<html>
<head>
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
            background-color: #f0f0f0;
        }
        .login-container {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            width: 300px;
            text-align: center;
        }
        input {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            background-color: #007bff;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
        }
        button:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Login</h2>
        <input type="email" id="loginEmail" placeholder="EMAIL:">
        <input type="password" id="loginPassword" placeholder="PASSWORD:">
        <button onclick="login()">LOGIN</button>
    </div>

    <script>
        function login() {
            let email = document.getElementById("loginEmail").value.trim();
            let password = document.getElementById("loginPassword").value.trim();

            if (email === "" || password === "") {
                alert("Please fill in all fields.");
                return;
            }

            let formData = new FormData();
            formData.append("email", email);
            formData.append("password", password);

            fetch("login.php", {
                method: "POST",
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = "indexWelcome with Booking Button Screen.php";
                } else {
                    alert("Invalid email or password.");
                }
            })
            .catch(error => console.error("Error:", error));
        }
    </script>
</body>
</html>
