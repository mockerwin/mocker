<?php
// Define MOCKER_INCLUDED to allow includes
define('MOCKER_INCLUDED', true);

// Include configuration and required files
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

$pageTitle = 'Kayıt Ol';
$errorMessage = null;
$successMessage = null;

// Redirect if already logged in
if (Auth::isLoggedIn()) {
    header('Location: ' . SITE_URL);
    exit;
}

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check CSRF token
    if (!verifyCsrf()) {
        $errorMessage = 'Güvenlik doğrulaması başarısız oldu. Lütfen sayfayı yenileyip tekrar deneyin.';
    } else {
        $username = cleanInput($_POST['username'] ?? '');
        $email = cleanInput($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $terms = isset($_POST['terms']);
        
        // Validate input
        if (empty($username) || empty($email) || empty($password) || empty($confirmPassword)) {
            $errorMessage = 'Lütfen tüm alanları doldurun.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'Geçerli bir e-posta adresi girin.';
        } elseif (strlen($username) < 3 || strlen($username) > 20) {
            $errorMessage = 'Kullanıcı adı 3-20 karakter arasında olmalıdır.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $errorMessage = 'Kullanıcı adı sadece harf, rakam ve alt çizgi içerebilir.';
        } elseif (strlen($password) < 6) {
            $errorMessage = 'Şifre en az 6 karakter olmalıdır.';
        } elseif ($password !== $confirmPassword) {
            $errorMessage = 'Şifreler eşleşmiyor.';
        } elseif (!$terms) {
            $errorMessage = 'Kayıt olmak için kullanım koşullarını kabul etmelisiniz.';
        } else {
            // Register user
            $result = Auth::register($username, $email, $password);
            
            if ($result['success']) {
                // Auto-login is handled in Auth::register()
                // Redirect to home page with welcome message
                header('Location: ' . SITE_URL . '?welcome=1');
                exit;
            } else {
                $errorMessage = $result['message'];
            }
        }
    }
}

// Include header
include_once 'includes/header.php';
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card register-card">
                <div class="card-header text-center">
                    <h4 class="mb-0"><i class="fas fa-user-plus me-2"></i>Kayıt Ol</h4>
                </div>
                <div class="card-body">
                    <?php if ($errorMessage): ?>
                        <div class="alert alert-danger" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i><?= $errorMessage ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($successMessage): ?>
                        <div class="alert alert-success" role="alert">
                            <i class="fas fa-check-circle me-2"></i><?= $successMessage ?>
                        </div>
                    <?php endif; ?>
                    
                    <form action="" method="post" id="registerForm" class="needs-validation animate-float" novalidate>
                        <?= csrfField() ?>
                        
                        <div class="mb-3">
                            <label for="username" class="form-label">Kullanıcı Adı</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-user"></i></span>
                                <input type="text" class="form-control" id="username" name="username" value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>" required autocomplete="username" pattern="^[a-zA-Z0-9_]{3,20}$">
                                <div class="invalid-feedback">
                                    Kullanıcı adı 3-20 karakter arasında olmalı ve sadece harf, rakam ve alt çizgi içermelidir.
                                </div>
                            </div>
                            <small class="form-text text-muted">3-20 karakter, sadece harf, rakam ve alt çizgi kullanabilirsiniz.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="email" class="form-label">E-posta</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                <input type="email" class="form-control" id="email" name="email" value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>" required autocomplete="email">
                                <div class="invalid-feedback">
                                    Geçerli bir e-posta adresi girin.
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">Şifre</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                <input type="password" class="form-control" id="password" name="password" required autocomplete="new-password" minlength="6">
                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="password">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <div class="invalid-feedback">
                                    Şifre en az 6 karakter olmalıdır.
                                </div>
                            </div>
                            <small class="form-text text-muted">En az 6 karakter olmalıdır.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Şifre (Tekrar)</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="confirm_password">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <div class="invalid-feedback">
                                    Şifreler eşleşmiyor.
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="terms" name="terms" required>
                            <label class="form-check-label" for="terms">
                                <a href="terms.php" target="_blank">Kullanım Koşulları</a>'nı kabul ediyorum
                            </label>
                            <div class="invalid-feedback">
                                Devam etmek için kullanım koşullarını kabul etmelisiniz.
                            </div>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-success animate-pulse">
                                <i class="fas fa-user-plus me-2"></i>Kayıt Ol
                            </button>
                        </div>
                    </form>
                    
                    <div class="mt-4 text-center">
                        <p>Zaten hesabınız var mı? <a href="login.php">Giriş Yap</a></p>
                    </div>
                </div>
            </div>
            
            <div class="register-bonus mt-4">
                <div class="bonus-icon">
                    <i class="fas fa-gift"></i>
                </div>
                <div class="bonus-content">
                    <h5>Hoş Geldin Bonusu</h5>
                    <p>Kayıt olun ve hemen <strong><?= formatTP(DEFAULT_TP) ?> TP</strong> kazanın!</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.register-card {
    background-color: var(--dark-accent);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    overflow: hidden;
    margin-top: 2rem;
    margin-bottom: 2rem;
    box-shadow: var(--shadow-md);
}

.register-card .card-header {
    background-color: var(--dark-lighter);
    color: var(--green-primary);
    padding: 1.5rem;
    border-bottom: 1px solid var(--border-color);
}

.register-card .card-body {
    padding: 2rem;
}

.input-group-text {
    background-color: var(--dark-lighter);
    color: var(--text-light);
    border-color: var(--border-color);
}

.register-card .form-control {
    background-color: var(--dark-bg);
    border-color: var(--border-color);
    color: var(--text-light);
}

.register-card .form-control:focus {
    box-shadow: 0 0 0 0.25rem rgba(29, 185, 84, 0.25);
    border-color: var(--green-primary);
}

.toggle-password {
    border-color: var(--border-color);
    color: var(--text-muted);
    background-color: var(--dark-lighter);
}

.toggle-password:hover {
    background-color: var(--dark-accent);
    color: var(--text-light);
}

.register-bonus {
    background: linear-gradient(135deg, var(--casino-purple), var(--casino-blue));
    border-radius: 8px;
    padding: 1.5rem;
    display: flex;
    align-items: center;
    box-shadow: var(--shadow-md);
    color: white;
    position: relative;
    overflow: hidden;
    animation: glow 3s infinite alternate;
}

.bonus-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background-color: rgba(255, 255, 255, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    margin-right: 1.5rem;
    flex-shrink: 0;
    position: relative;
    z-index: 2;
}

.bonus-content {
    position: relative;
    z-index: 2;
}

.bonus-content h5 {
    font-size: 1.4rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.bonus-content p {
    margin-bottom: 0;
    opacity: 0.9;
}

.register-bonus::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 100%;
    height: 100%;
    background: url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMDAgMjAwIj48Y2lyY2xlIGN4PSIxMDAiIGN5PSIxMDAiIHI9IjgwIiBmaWxsPSJub25lIiBzdHJva2U9IiNmZmYiIHN0cm9rZS13aWR0aD0iMiIgc3Ryb2tlLW9wYWNpdHk9IjAuMSIgc3Ryb2tlLWRhc2hhcnJheT0iMTAgMTAiLz48L3N2Zz4=');
    background-position: top right;
    background-repeat: no-repeat;
    opacity: 0.2;
    z-index: 1;
}

@keyframes glow {
    0% {
        box-shadow: 0 0 5px rgba(94, 43, 151, 0.5);
    }
    100% {
        box-shadow: 0 0 20px rgba(94, 43, 151, 0.8);
    }
}
</style>

<?php
// Include footer
include_once 'includes/footer.php';
?>
