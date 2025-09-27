<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/auth.php';

// Rediriger si l'utilisateur est déjà connecté
// if (isset($_SESSION['user_id'])) {
//     header('Location: index.php');
//     exit();
// }

$error = '';
$success = '';
$formData = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'phone' => '',
    'user_type' => 'tenant'
];

// Traitement du formulaire d'inscription
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupération et nettoyage des données
    $formData['first_name'] = trim($_POST['first_name']);
    $formData['last_name'] = trim($_POST['last_name']);
    $formData['email'] = trim($_POST['email']);
    $formData['phone'] = trim($_POST['phone']);
    $formData['user_type'] = $_POST['user_type'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validation des données
    if (empty($formData['first_name']) || empty($formData['last_name']) || 
        empty($formData['email']) || empty($password) || empty($confirm_password)) {
        $error = 'Veuillez remplir tous les champs obligatoires.';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Veuillez entrer une adresse email valide.';
    } elseif ($password !== $confirm_password) {
        $error = 'Les mots de passe ne correspondent pas.';
    } elseif (strlen($password) < 8) {
        $error = 'Le mot de passe doit contenir au moins 8 caractères.';
    } else {
        // Tentative d'inscription
        if (register($formData + ['password' => $password])) {
            $success = 'Votre compte a été créé avec succès!';
            // Réinitialiser les données du formulaire
            $formData = [
                'first_name' => '',
                'last_name' => '',
                'email' => '',
                'phone' => '',
                'user_type' => 'tenant'
            ];
        } else {
            $error = 'Cette adresse email est déjà utilisée.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription - ImmoLink</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary: #64748b;
            --light: #f8fafc;
            --dark: #1e293b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gray: #94a3b8;
            --gray-light: #e2e8f0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f1f5f9;
            color: var(--dark);
            line-height: 1.6;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Header Styles */
        header {
            background-color: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
        }

        .logo {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
        }

        .logo i {
            margin-right: 10px;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 0;
        }

        .auth-container {
            background-color: white;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 500px;
            overflow: hidden;
        }

        .auth-header {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .auth-header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .auth-header p {
            opacity: 0.9;
        }

        .auth-body {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--gray-light);
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .input-with-icon {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        .input-with-icon .form-control {
            padding-left: 45px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .user-type-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 20px;
        }

        .user-type-option {
            padding: 15px;
            border: 2px solid var(--gray-light);
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }

        .user-type-option:hover {
            border-color: var(--primary);
        }

        .user-type-option.selected {
            border-color: var(--primary);
            background-color: rgba(37, 99, 235, 0.1);
        }

        .user-type-option i {
            font-size: 24px;
            margin-bottom: 10px;
            color: var(--primary);
        }

        .user-type-option h3 {
            font-size: 16px;
            margin-bottom: 5px;
        }

        .user-type-option p {
            font-size: 14px;
            color: var(--secondary);
        }

        .btn {
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            width: 100%;
            font-size: 16px;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .btn-google {
            background-color: white;
            color: var(--dark);
            border: 1px solid var(--gray-light);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-google:hover {
            background-color: #f8fafc;
            border-color: var(--gray);
        }

        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 25px 0;
            color: var(--gray);
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid var(--gray-light);
        }

        .divider::before {
            margin-right: 10px;
        }

        .divider::after {
            margin-left: 10px;
        }

        .auth-footer {
            text-align: center;
            margin-top: 20px;
            color: var(--secondary);
        }

        .auth-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        .auth-footer a:hover {
            text-decoration: underline;
        }

        .error-message {
            background-color: #fef2f2;
            color: var(--danger);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid var(--danger);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .success-message {
            background-color: #f0fdf4;
            color: var(--success);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid var(--success);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .error-message i,
        .success-message i {
            font-size: 18px;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--gray);
        }

        .password-strength {
            margin-top: 5px;
            height: 5px;
            background-color: var(--gray-light);
            border-radius: 3px;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s, background-color 0.3s;
        }

        .password-strength-weak .password-strength-bar {
            width: 33%;
            background-color: var(--danger);
        }

        .password-strength-medium .password-strength-bar {
            width: 66%;
            background-color: var(--warning);
        }

        .password-strength-strong .password-strength-bar {
            width: 100%;
            background-color: var(--success);
        }

        .password-requirements {
            margin-top: 5px;
            font-size: 12px;
            color: var(--secondary);
        }

        /* Footer */
        footer {
            background-color: var(--dark);
            color: white;
            padding: 40px 0 20px;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }

        .footer-column h3 {
            font-size: 1.2rem;
            margin-bottom: 15px;
            position: relative;
            display: inline-block;
        }

        .footer-column h3::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 30px;
            height: 2px;
            background-color: var(--primary);
        }

        .footer-column ul {
            list-style: none;
        }

        .footer-column ul li {
            margin-bottom: 8px;
        }

        .footer-column a {
            color: var(--gray);
            text-decoration: none;
            transition: color 0.3s;
        }

        .footer-column a:hover {
            color: white;
        }

        .social-links {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .social-links a {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 35px;
            height: 35px;
            background-color: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transition: all 0.3s;
        }

        .social-links a:hover {
            background-color: var(--primary);
            transform: translateY(-2px);
        }

        .copyright {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: var(--gray);
            font-size: 14px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .auth-container {
                margin: 0 15px;
            }
            
            .auth-header {
                padding: 20px;
            }
            
            .auth-body {
                padding: 20px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .user-type-selector {
                grid-template-columns: 1fr;
            }
            
            .footer-content {
                grid-template-columns: 1fr;
                text-align: center;
            }
            
            .footer-column h3::after {
                left: 50%;
                transform: translateX(-50%);
            }
        }
    </style>
</head>
<body>

    <main class="main-content">
        <div class="container">
            <div class="auth-container">
                <div class="auth-header">
                    <h1>Créer un compte</h1>
                    <p>Rejoignez la communauté ImmoLink</p>
                </div>
                
                <div class="auth-body">
                    <?php if (!empty($error)): ?>
                        <div class="error-message">
                            <i class="fas fa-exclamation-circle"></i>
                            <span><?php echo htmlspecialchars($error); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($success)): ?>
                        <div class="success-message">
                            <i class="fas fa-check-circle"></i>
                            <span><?php echo htmlspecialchars($success); ?></span>
                        </div>
                    <?php endif; ?>

                    <form action="register.php" method="POST" id="registrationForm">
                        <div class="form-group">
                            <label>Je suis :</label>
                            <div class="user-type-selector">
                                <div class="user-type-option <?php echo $formData['user_type'] === 'tenant' ? 'selected' : ''; ?>" data-type="tenant">
                                    <i class="fas fa-user"></i>
                                    <h3>Locataire</h3>
                                    <p>Je cherche un bien</p>
                                </div>
                                <div class="user-type-option <?php echo $formData['user_type'] === 'owner' ? 'selected' : ''; ?>" data-type="owner">
                                    <i class="fas fa-home"></i>
                                    <h3>Propriétaire</h3>
                                    <p>Je propose un bien</p>
                                </div>
                            </div>
                            <input type="hidden" name="user_type" id="userType" value="<?php echo htmlspecialchars($formData['user_type']); ?>">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="first_name">Prénom *</label>
                                <div class="input-with-icon">
                                    <i class="fas fa-user input-icon"></i>
                                    <input 
                                        type="text" 
                                        id="first_name" 
                                        name="first_name" 
                                        class="form-control" 
                                        placeholder="Votre prénom" 
                                        value="<?php echo htmlspecialchars($formData['first_name']); ?>"
                                        required
                                    >
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="last_name">Nom *</label>
                                <div class="input-with-icon">
                                    <i class="fas fa-user input-icon"></i>
                                    <input 
                                        type="text" 
                                        id="last_name" 
                                        name="last_name" 
                                        class="form-control" 
                                        placeholder="Votre nom" 
                                        value="<?php echo htmlspecialchars($formData['last_name']); ?>"
                                        required
                                    >
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="email">Adresse email *</label>
                            <div class="input-with-icon">
                                <i class="fas fa-envelope input-icon"></i>
                                <input 
                                    type="email" 
                                    id="email" 
                                    name="email" 
                                    class="form-control" 
                                    placeholder="votre@email.com" 
                                    value="<?php echo htmlspecialchars($formData['email']); ?>"
                                    required
                                >
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="phone">Numéro de téléphone</label>
                            <div class="input-with-icon">
                                <i class="fas fa-phone input-icon"></i>
                                <input 
                                    type="tel" 
                                    id="phone" 
                                    name="phone" 
                                    class="form-control" 
                                    placeholder="+237 XXX XXX XXX" 
                                    value="<?php echo htmlspecialchars($formData['phone']); ?>"
                                >
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="password">Mot de passe *</label>
                            <div class="input-with-icon">
                                <i class="fas fa-lock input-icon"></i>
                                <input 
                                    type="password" 
                                    id="password" 
                                    name="password" 
                                    class="form-control" 
                                    placeholder="Créez un mot de passe" 
                                    required
                                >
                                <span class="password-toggle" id="passwordToggle">
                                    <i class="far fa-eye"></i>
                                </span>
                            </div>
                            <div class="password-strength" id="passwordStrength">
                                <div class="password-strength-bar"></div>
                            </div>
                            <div class="password-requirements">
                                Le mot de passe doit contenir au moins 8 caractères
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirmer le mot de passe *</label>
                            <div class="input-with-icon">
                                <i class="fas fa-lock input-icon"></i>
                                <input 
                                    type="password" 
                                    id="confirm_password" 
                                    name="confirm_password" 
                                    class="form-control" 
                                    placeholder="Confirmez votre mot de passe" 
                                    required
                                >
                                <span class="password-toggle" id="confirmPasswordToggle">
                                    <i class="far fa-eye"></i>
                                </span>
                            </div>
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-user-plus"></i> Créer mon compte
                            </button>
                        </div>
                    </form>

                    <div class="divider">Ou</div>

                    <div class="form-group">
                        <button class="btn btn-google">
                            <i class="fab fa-google"></i> S'inscrire avec Google
                        </button>
                    </div>

                    <div class="auth-footer">
                        <p>Vous avez déjà un compte ? <a href="login.php">Connectez-vous</a></p>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Sélection du type d'utilisateur
            const userTypeOptions = document.querySelectorAll('.user-type-option');
            const userTypeInput = document.getElementById('userType');
            
            userTypeOptions.forEach(option => {
                option.addEventListener('click', function() {
                    userTypeOptions.forEach(opt => opt.classList.remove('selected'));
                    this.classList.add('selected');
                    userTypeInput.value = this.getAttribute('data-type');
                });
            });

            // Toggle password visibility
            const passwordToggle = document.getElementById('passwordToggle');
            const passwordInput = document.getElementById('password');
            const confirmPasswordToggle = document.getElementById('confirmPasswordToggle');
            const confirmPasswordInput = document.getElementById('confirm_password');
            
            passwordToggle.addEventListener('click', function() {
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    passwordToggle.innerHTML = '<i class="far fa-eye-slash"></i>';
                } else {
                    passwordInput.type = 'password';
                    passwordToggle.innerHTML = '<i class="far fa-eye"></i>';
                }
            });
            
            confirmPasswordToggle.addEventListener('click', function() {
                if (confirmPasswordInput.type === 'password') {
                    confirmPasswordInput.type = 'text';
                    confirmPasswordToggle.innerHTML = '<i class="far fa-eye-slash"></i>';
                } else {
                    confirmPasswordInput.type = 'password';
                    confirmPasswordToggle.innerHTML = '<i class="far fa-eye"></i>';
                }
            });

            // Password strength indicator
            const passwordStrength = document.getElementById('passwordStrength');
            
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;
                
                if (password.length >= 8) strength++;
                if (password.match(/[a-z]+/)) strength++;
                if (password.match(/[A-Z]+/)) strength++;
                if (password.match(/[0-9]+/)) strength++;
                if (password.match(/[!@#$%^&*(),.?":{}|<>]+/)) strength++;
                
                passwordStrength.className = 'password-strength';
                if (strength > 0) {
                    if (strength < 3) {
                        passwordStrength.classList.add('password-strength-weak');
                    } else if (strength < 5) {
                        passwordStrength.classList.add('password-strength-medium');
                    } else {
                        passwordStrength.classList.add('password-strength-strong');
                    }
                }
            });

            // Google sign-up simulation
            const googleButton = document.querySelector('.btn-google');
            googleButton.addEventListener('click', function() {
                alert('Inscription avec Google - Cette fonctionnalité sera bientôt disponible!');
            });

            // Form validation
            const registrationForm = document.getElementById('registrationForm');
            registrationForm.addEventListener('submit', function(e) {
                const password = document.getElementById('password').value;
                const confirmPassword = document.getElementById('confirm_password').value;
                
                if (password !== confirmPassword) {
                    e.preventDefault();
                    alert('Les mots de passe ne correspondent pas.');
                    return false;
                }
                
                if (password.length < 8) {
                    e.preventDefault();
                    alert('Le mot de passe doit contenir au moins 8 caractères.');
                    return false;
                }
            });
        });
    </script>
</body>
</html>