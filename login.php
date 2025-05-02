<?php
// Define MOCKER_INCLUDED to allow includes
define('MOCKER_INCLUDED', true);

// Include configuration and required files
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

$pageTitle = 'Giriş Yap';
$errorMessage = null;
$successMessage = null;

// Redirect if already logged in
if (Auth::isLoggedIn()) {
    header('Location: ' . SITE_URL);
    exit;
}

// Get redirect URL if any
$redirect = isset($_GET['redirect']) ? cleanInput($_GET['redirect']) : '';
$redirectUrl = '';

if (!empty($redirect)) {
    // Validate redirect to prevent open redirect vulnerability
    if (preg_match('/^[a-z0-9_\-]+\.php$/', $redirect)) {
        $redirectUrl = $redirect;
    }
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check CSRF token
    if (!verifyCsrf()) {
        $errorMessage = 'Güvenlik doğrulaması başarısız oldu. Lütfen sayfayı yenileyip tekrar deneyin.';
    } else {
        $email = cleanInput($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);
        
        // Validate input
        if (empty($email) || empty($password)) {
            $errorMessage = 'Lütfen e-posta ve şifre girin.';
        } else {
            // Attempt login
            $result = Auth::login($email, $password, $remember);
            
            if ($result['success']) {
                // Redirect after successful login
                $targetUrl = !empty($redirectUrl) ? $redirectUrl : SITE_URL;
                header('Location: ' . $targetUrl);
                exit;
            } else {
                $errorMessage = $result['message'];
            }
        }
    }
}

// Check for logged out message
if (isset($_GET['logged_out']) && $_GET['logged_out'] == 1) {
    $successMessage = 'Başarıyla çıkış yaptınız.';
}

// Include header
include_once 'includes/header.php';
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card login-card">
                <div class="card-header text-center">
                    <h4 class="mb-0"><i class="fas fa-sign-in-alt me-2"></i>Giriş Yap</h4>
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
                    
                    <form action="" method="post" id="loginForm" class="animate-float">
                        <?= csrfField() ?>
                        
                        <?php if (!empty($redirectUrl)): ?>
                            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectUrl) ?>">
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label for="email" class="form-label">E-posta</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                <input type="email" class="form-control" id="email" name="email" value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>" required autocomplete="email">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">Şifre</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="remember" name="remember">
                            <label class="form-check-label" for="remember">Beni hatırla</label>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-success animate-pulse">
                                <i class="fas fa-sign-in-alt me-2"></i>Giriş Yap
                            </button>
                        </div>
                    </form>
                    
                    <div class="mt-4 text-center">
                        <p>Hesabınız yok mu? <a href="register.php">Kayıt Ol</a></p>
                    </div>
                </div>
            </div>
            
            <div class="login-features mt-4">
                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-gift"></i>
                    </div>
                    <div class="feature-text">
                        <h5>Çekilişler</h5>
                        <p>Katıl ve kazanma şansı yakala</p>
                    </div>
                </div>
                
                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-ticket-alt"></i>
                    </div>
                    <div class="feature-text">
                        <h5>Promo Kodlar</h5>
                        <p>Bonus TP kazanma fırsatı</p>
                    </div>
                </div>
                
                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-comments"></i>
                    </div>
                    <div class="feature-text">
                        <h5>Canlı Sohbet</h5>
                        <p>Diğer kullanıcılarla sohbet et</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.login-card {
    background-color: var(--dark-accent);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    overflow: hidden;
    margin-top: 2rem;
    margin-bottom: 2rem;
    box-shadow: var(--shadow-md);
}

.login-card .card-header {
    background-color: var(--dark-lighter);
    color: var(--green-primary);
    padding: 1.5rem;
    border-bottom: 1px solid var(--border-color);
}

.login-card .card-body {
    padding: 2rem;
}

.input-group-text {
    background-color: var(--dark-lighter);
    color: var(--text-light);
    border-color: var(--border-color);
}

.login-card .form-control {
    background-color: var(--dark-bg);
    border-color: var(--border-color);
    color: var(--text-light);
}

.login-card .form-control:focus {
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

.login-features {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.feature-item {
    background-color: var(--dark-accent);
    border-radius: 8px;
    padding: 1rem;
    display: flex;
    align-items: center;
    box-shadow: var(--shadow-sm);
    transition: transform var(--transition-normal);
}

.feature-item:hover {
    transform: translateY(-3px);
}

.feature-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--casino-purple), var(--casino-blue));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
    margin-right: 1rem;
    flex-shrink: 0;
}

.feature-text h5 {
    font-size: 1.1rem;
    margin-bottom: 0.25rem;
    color: var(--green-primary);
}

.feature-text p {
    font-size: 0.9rem;
    margin-bottom: 0;
    color: var(--text-muted);
}
</style>

<?php
// Include footer
include_once 'includes/footer.php';
?>
