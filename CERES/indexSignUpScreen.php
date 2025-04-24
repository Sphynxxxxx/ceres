<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign_Up</title>
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
        .login-container, .signup-container {
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
        input[type="text"],
        input[type="password"],
        input[type="email"],
        input[type="tel"] {
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
    <div class="signup-container">
        <div class="header-box">
            <h2>SIGN UP</h2>
        </div>
        <div class="logo">
            <img src="Logo_ISAT.png" alt="ISAT-U Logo">
        </div>
        <input type="text" id="name" placeholder="NAME:">
        <input type="text" id="lastName" placeholder="LAST NAME:">
        <input type="email" id="email" placeholder="EMAIL:">
        <input type="tel" id="contact" placeholder="CONTACT NO:">
        <button onclick="signUp()">SIGN UP</button>
    </div>

    <script>
function signUp() {
    let name = document.getElementById("name").value.trim();
    let lastName = document.getElementById("lastName").value.trim();
    let email = document.getElementById("email").value.trim();
    let contact = document.getElementById("contact").value.trim();

    if (name === "" || lastName === "" || email === "" || contact === "") {
        alert("Please fill in all fields.");
        return;
    }

    let formData = new FormData();
    formData.append("name", name);
    formData.append("lastName", lastName);
    formData.append("email", email);
    formData.append("contact", contact);

    fetch("signupUser.php", {
        method: "POST",
        body: formData
    })
    .then(response => response.text())
    .then(data => {
        if (data === "success") {
            alert("Sign-up successful!");
            document.getElementById("name").value = "";
            document.getElementById("lastName").value = "";
            document.getElementById("email").value = "";
            document.getElementById("contact").value = "";
        } else {
            alert("Error: " + data);
        }
    })
    .catch(error => console.error("Error:", error));
}
</script>

</body>
</html>
