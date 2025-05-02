<?php
// Define MOCKER_INCLUDED to allow includes
define('MOCKER_INCLUDED', true);

// Include configuration and required files
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Redirect if not logged in
if (!Auth::isLoggedIn()) {
    header('Location: ' . SITE_URL . '/login.php?redirect=settings');
    exit;
}

$pageTitle = 'Hesap Ayarları';
$successMessage = null;
$errorMessage = null;

$userId = Auth::user()['id'];
$db = DB::getInstance();

// Default active tab
$activeTab = isset($_GET['tab']) ? cleanInput($_GET['tab']) : 'password';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check CSRF token
    if (!verifyCsrf()) {
        $errorMessage = 'Güvenlik doğrulaması başarısız oldu. Lütfen sayfayı yenileyip tekrar deneyin.';
    } else {
        $action = isset($_POST['action']) ? $_POST['action'] : '';
        
        switch ($action) {
            case 'change_password':
                $currentPassword = $_POST['current_password'] ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';
                
                $activeTab = 'password';
                
                if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                    $errorMessage = 'Tüm alanları doldurun.';
                } elseif ($newPassword !== $confirmPassword) {
                    $errorMessage = 'Yeni şifreler eşleşmiyor.';
                } elseif (strlen($newPassword) < 6) {
                    $errorMessage = 'Şifre en az 6 karakter olmalıdır.';
                } else {
                    if (Auth::updatePassword($userId, $currentPassword, $newPassword)) {
                        $successMessage = 'Şifreniz başarıyla değiştirildi.';
                    } else {
                        $errorMessage = 'Mevcut şifre hatalı.';
                    }
                }
                break;
                
            case 'update_wallet':
                $walletType = $_POST['wallet_type'] ?? '';
                $walletAddress = $_POST['wallet_address'] ?? '';
                
                $activeTab = 'wallets';
                
                if (empty($walletType) || empty($walletAddress)) {
                    $errorMessage = 'Tüm alanları doldurun.';
                } elseif (!in_array($walletType, ['trc20', 'binance', 'gomdom'])) {
                    $errorMessage = 'Geçersiz cüzdan tipi.';
                } elseif (isWalletInUse($walletType, $walletAddress, $userId)) {
                    $errorMessage = 'Bu cüzdan adresi başka bir kullanıcı tarafından kullanılıyor.';
                } else {
                    // Check if wallet exists
                    $query = "SELECT id FROM user_wallets WHERE user_id = ? AND wallet_type = ?";
                    $db->query($query, [$userId, $walletType]);
                    $wallet = $db->fetch();
                    
                    if ($wallet) {
                        // Update existing wallet
                        $query = "UPDATE user_wallets SET wallet_address = ?, updated_at = NOW() WHERE id = ?";
                        if ($db->query($query, [$walletAddress, $wallet['id']])) {
                            $successMessage = 'Cüzdan adresi başarıyla güncellendi.';
                        } else {
                            $errorMessage = 'Cüzdan güncellenirken bir hata oluştu.';
                        }
                    } else {
                        // Create new wallet
                        $query = "INSERT INTO user_wallets (user_id, wallet_type, wallet_address, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())";
                        if ($db->query($query, [$userId, $walletType, $walletAddress])) {
                            $successMessage = 'Cüzdan adresi başarıyla eklendi.';
                        } else {
                            $errorMessage = 'Cüzdan eklenirken bir hata oluştu.';
                        }
                    }
                }
                break;
                
            case 'unblock_user':
                $blockedUserId = (int)($_POST['blocked_user_id'] ?? 0);
                
                $activeTab = 'blocks';
                
                if ($blockedUserId > 0) {
                    if (unblockUser($userId, $blockedUserId)) {
                        $successMessage = 'Kullanıcı engeli kaldırıldı.';
                    } else {
                        $errorMessage = 'Kullanıcı engeli kaldırılırken bir hata oluştu.';
                    }
                } else {
                    $errorMessage = 'Geçersiz kullanıcı ID.';
                }
                break;
                
            case 'update_profile':
                $email = cleanInput($_POST['email'] ?? '');
                $avatar = cleanInput($_POST['avatar'] ?? '');
                
                $activeTab = 'profile';
                
                if (empty($email)) {
                    $errorMessage = 'E-posta adresi gereklidir.';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errorMessage = 'Geçerli bir e-posta adresi girin.';
                } else {
                    // Check if email exists and belongs to another user
                    $query = "SELECT id FROM users WHERE email = ? AND id != ?";
                    $db->query($query, [$email, $userId]);
                    if ($db->fetch()) {
                        $errorMessage = 'Bu e-posta adresi başka bir kullanıcı tarafından kullanılıyor.';
                    } else {
                        // Update user profile
                        $query = "UPDATE users SET email = ?, avatar = ? WHERE id = ?";
                        if ($db->query($query, [$email, $avatar, $userId])) {
                            // Update session data
                            $user = Auth::user();
                            $user['email'] = $email;
                            $user['avatar'] = $avatar;
                            
                            $successMessage = 'Profil bilgileriniz başarıyla güncellendi.';
                        } else {
                            $errorMessage = 'Profil güncellenirken bir hata oluştu.';
                        }
                    }
                }
                break;
                
            case 'notification_settings':
                $emailNotifications = isset($_POST['email_notifications']) ? 1 : 0;
                $raffleNotifications = isset($_POST['raffle_notifications']) ? 1 : 0;
                $winNotifications = isset($_POST['win_notifications']) ? 1 : 0;
                
                $activeTab = 'notifications';
                
                $query = "SELECT id FROM user_settings WHERE user_id = ?";
                $db->query($query, [$userId]);
                $settings = $db->fetch();
                
                if ($settings) {
                    $query = "UPDATE user_settings SET 
                              email_notifications = ?,
                              raffle_notifications = ?,
                              win_notifications = ?,
                              updated_at = NOW()
                              WHERE user_id = ?";
                    if ($db->query($query, [$emailNotifications, $raffleNotifications, $winNotifications, $userId])) {
                        $successMessage = 'Bildirim ayarlarınız başarıyla güncellendi.';
                    } else {
                        $errorMessage = 'Bildirim ayarları güncellenirken bir hata oluştu.';
                    }
                } else {
                    $query = "INSERT INTO user_settings (
                              user_id, email_notifications, raffle_notifications, win_notifications, created_at, updated_at
                              ) VALUES (?, ?, ?, ?, NOW(), NOW())";
                    if ($db->query($query, [$userId, $emailNotifications, $raffleNotifications, $winNotifications])) {
                        $successMessage = 'Bildirim ayarlarınız başarıyla kaydedildi.';
                    } else {
                        $errorMessage = 'Bildirim ayarları kaydedilirken bir hata oluştu.';
                    }
                }
                break;
        }
    }
}

// Get user profile data
$query = "SELECT username, email, avatar FROM users WHERE id = ?";
$db->query($query, [$userId]);
$profile = $db->fetch();

// Get user wallets
$query = "SELECT wallet_type, wallet_address FROM user_wallets WHERE user_id = ?";
$db->query($query, [$userId]);
$walletsResult = $db->fetchAll();

$wallets = [];
foreach ($walletsResult as $wallet) {
    $wallets[$wallet['wallet_type']] = $wallet['wallet_address'];
}

// Get blocked users
$blockedUsers = getBlockedUsers($userId);

// Get notification settings
$query = "SELECT email_notifications, raffle_notifications, win_notifications 
          FROM user_settings WHERE user_id = ?";
$db->query($query, [$userId]);
$notificationSettings = $db->fetch();

if (!$notificationSettings) {
    $notificationSettings = [
        'email_notifications' => 1,
        'raffle_notifications' => 1,
        'win_notifications' => 1
    ];
}

// Get recent logins
$query = "SELECT ip_address, user_agent, created_at 
          FROM user_logins 
          WHERE user_id = ? 
          ORDER BY created_at DESC 
          LIMIT 5";
$db->query($query, [$userId]);
$recentLogins = $db->fetchAll();

// Include header
include_once 'includes/header.php';
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <h1 class="page-title mb-4">
                <i class="fas fa-cog me-2"></i>Hesap Ayarları
            </h1>
            
            <div class="card dark-card mb-4">
                <div class="card-body">
                    <?php if ($successMessage): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i><?= $successMessage ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($errorMessage): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i><?= $errorMessage ?>
                        </div>
                    <?php endif; ?>
                    
                    <ul class="nav nav-tabs mb-4" id="settings-tabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?= $activeTab === 'profile' ? 'active' : '' ?>" 
                                    id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile" 
                                    type="button" role="tab">
                                <i class="fas fa-user me-2"></i>Profil
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?= $activeTab === 'password' ? 'active' : '' ?>" 
                                    id="password-tab" data-bs-toggle="tab" data-bs-target="#password" 
                                    type="button" role="tab">
                                <i class="fas fa-key me-2"></i>Şifre
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?= $activeTab === 'wallets' ? 'active' : '' ?>" 
                                    id="wallets-tab" data-bs-toggle="tab" data-bs-target="#wallets" 
                                    type="button" role="tab">
                                <i class="fas fa-wallet me-2"></i>Cüzdanlar
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?= $activeTab === 'notifications' ? 'active' : '' ?>" 
                                    id="notifications-tab" data-bs-toggle="tab" data-bs-target="#notifications" 
                                    type="button" role="tab">
                                <i class="fas fa-bell me-2"></i>Bildirimler
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?= $activeTab === 'blocks' ? 'active' : '' ?>" 
                                    id="blocks-tab" data-bs-toggle="tab" data-bs-target="#blocks" 
                                    type="button" role="tab">
                                <i class="fas fa-ban me-2"></i>Engellenenler
                            </button>
                        </li>
                    </ul>
                    
                    <div class="tab-content" id="settings-content">
                        <!-- Profile Tab -->
                        <div class="tab-pane fade <?= $activeTab === 'profile' ? 'show active' : '' ?>" 
                             id="profile" role="tabpanel">
                            <form action="" method="post" class="needs-validation" novalidate>
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="update_profile">
                                
                                <div class="row mb-4 align-items-center">
                                    <div class="col-md-4 text-center mb-3 mb-md-0">
                                        <div class="profile-image-preview mx-auto">
                                            <?php if (!empty($profile['avatar'])): ?>
                                                <img src="<?= htmlspecialchars($profile['avatar']) ?>" alt="Avatar" class="rounded-circle">
                                            <?php else: ?>
                                                <div class="avatar-placeholder avatar-placeholder-lg">
                                                    <?= strtoupper(substr($profile['username'], 0, 1)) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-8">
                                        <div class="mb-3">
                                            <label for="username" class="form-label">Kullanıcı Adı</label>
                                            <input type="text" class="form-control" id="username" 
                                                   value="<?= htmlspecialchars($profile['username']) ?>" 
                                                   disabled>
                                            <div class="form-text">Kullanıcı adı değiştirilemez.</div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="email" class="form-label">E-posta</label>
                                            <input type="email" class="form-control" id="email" name="email" 
                                                   value="<?= htmlspecialchars($profile['email']) ?>" required>
                                            <div class="invalid-feedback">
                                                Geçerli bir e-posta adresi girin.
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="avatar" class="form-label">Avatar URL (İsteğe Bağlı)</label>
                                            <input type="url" class="form-control" id="avatar" name="avatar" 
                                                   value="<?= htmlspecialchars($profile['avatar']) ?>"
                                                   placeholder="https://example.com/avatar.jpg">
                                            <div class="form-text">Avatar için geçerli bir resim URL'si girin.</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="text-end">
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-save me-2"></i>Profili Güncelle
                                    </button>
                                </div>
                            </form>
                            
                            <hr class="my-4">
                            
                            <h5 class="mb-3">Son Oturumlar</h5>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Tarih</th>
                                            <th>IP Adresi</th>
                                            <th>Tarayıcı</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($recentLogins)): ?>
                                            <tr>
                                                <td colspan="3" class="text-center">Oturum bilgisi bulunamadı.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($recentLogins as $login): ?>
                                                <tr>
                                                    <td><?= formatDate($login['created_at']) ?></td>
                                                    <td><?= htmlspecialchars($login['ip_address']) ?></td>
                                                    <td>
                                                        <?php
                                                        $userAgent = htmlspecialchars($login['user_agent']);
                                                        $browserInfo = '';
                                                        
                                                        if (strpos($userAgent, 'Chrome') !== false) {
                                                            $browserInfo = '<i class="fab fa-chrome me-1"></i> Chrome';
                                                        } elseif (strpos($userAgent, 'Firefox') !== false) {
                                                            $browserInfo = '<i class="fab fa-firefox me-1"></i> Firefox';
                                                        } elseif (strpos($userAgent, 'Safari') !== false) {
                                                            $browserInfo = '<i class="fab fa-safari me-1"></i> Safari';
                                                        } elseif (strpos($userAgent, 'Edge') !== false) {
                                                            $browserInfo = '<i class="fab fa-edge me-1"></i> Edge';
                                                        } elseif (strpos($userAgent, 'Opera') !== false || strpos($userAgent, 'OPR') !== false) {
                                                            $browserInfo = '<i class="fab fa-opera me-1"></i> Opera';
                                                        } else {
                                                            $browserInfo = '<i class="fas fa-globe me-1"></i> Bilinmeyen';
                                                        }
                                                        
                                                        echo $browserInfo;
                                                        ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Password Tab -->
                        <div class="tab-pane fade <?= $activeTab === 'password' ? 'show active' : '' ?>" 
                             id="password" role="tabpanel">
                            <div class="row">
                                <div class="col-lg-8 mx-auto">
                                    <form action="" method="post" class="needs-validation" novalidate>
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="change_password">
                                        
                                        <div class="mb-3">
                                            <label for="current_password" class="form-label">Mevcut Şifre</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="current_password">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                            <div class="invalid-feedback">
                                                Mevcut şifrenizi girin.
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="new_password" class="form-label">Yeni Şifre</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-key"></i></span>
                                                <input type="password" class="form-control" id="new_password" name="new_password" minlength="6" required>
                                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="new_password">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                            <div class="invalid-feedback">
                                                Şifre en az 6 karakter olmalıdır.
                                            </div>
                                            <div class="form-text">En az 6 karakter olmalıdır.</div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="confirm_password" class="form-label">Yeni Şifre (Tekrar)</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-key"></i></span>
                                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="6" required>
                                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="confirm_password">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                            <div class="invalid-feedback">
                                                Şifreler eşleşmiyor.
                                            </div>
                                        </div>
                                        
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle me-2"></i>Güçlü bir şifre için:
                                            <ul class="mb-0 mt-2">
                                                <li>En az 8 karakter kullanın</li>
                                                <li>Büyük ve küçük harfler ekleyin</li>
                                                <li>Rakam ve semboller kullanın</li>
                                                <li>Tahmin edilebilir bilgiler kullanmayın</li>
                                            </ul>
                                        </div>
                                        
                                        <div class="text-center mt-4">
                                            <button type="submit" class="btn btn-success">
                                                <i class="fas fa-save me-2"></i>Şifreyi Değiştir
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Wallets Tab -->
                        <div class="tab-pane fade <?= $activeTab === 'wallets' ? 'show active' : '' ?>" 
                             id="wallets" role="tabpanel">
                            <div class="alert alert-info mb-4">
                                <i class="fas fa-info-circle me-2"></i>
                                Cüzdan adresleri sadece hesabınıza özgüdür. Başka kullanıcı tarafından kullanılan bir adres ekleyemezsiniz.
                                Ödeme işlemlerinde bu adreslere gönderim yapılacaktır.
                            </div>
                            
                            <div class="row">
                                <!-- TRC20 Wallet -->
                                <div class="col-md-4 mb-4">
                                    <div class="card h-100 wallet-card">
                                        <div class="card-header d-flex align-items-center">
                                            <i class="fas fa-coins me-2 wallet-icon"></i>
                                            <h5 class="mb-0">TRC20 Cüzdan</h5>
                                        </div>
                                        <div class="card-body">
                                            <form action="" method="post" class="needs-validation" novalidate>
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="update_wallet">
                                                <input type="hidden" name="wallet_type" value="trc20">
                                                
                                                <div class="mb-3">
                                                    <label for="trc20_address" class="form-label">TRC20 Adres</label>
                                                    <input type="text" class="form-control" id="trc20_address" name="wallet_address" 
                                                        value="<?= htmlspecialchars($wallets['trc20'] ?? '') ?>" required>
                                                    <div class="invalid-feedback">
                                                        Geçerli bir TRC20 adresi girin.
                                                    </div>
                                                    <div class="form-text">USDT TRC20 cüzdan adresinizi girin.</div>
                                                </div>
                                                
                                                <button type="submit" class="btn btn-success w-100">
                                                    <i class="fas fa-save me-2"></i>Kaydet
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Binance Wallet -->
                                <div class="col-md-4 mb-4">
                                    <div class="card h-100 wallet-card">
                                        <div class="card-header d-flex align-items-center">
                                            <i class="fab fa-bitcoin me-2 wallet-icon"></i>
                                            <h5 class="mb-0">Binance Cüzdan</h5>
                                        </div>
                                        <div class="card-body">
                                            <form action="" method="post" class="needs-validation" novalidate>
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="update_wallet">
                                                <input type="hidden" name="wallet_type" value="binance">
                                                
                                                <div class="mb-3">
                                                    <label for="binance_address" class="form-label">Binance Adres</label>
                                                    <input type="text" class="form-control" id="binance_address" name="wallet_address" 
                                                        value="<?= htmlspecialchars($wallets['binance'] ?? '') ?>" required>
                                                    <div class="invalid-feedback">
                                                        Geçerli bir Binance adresi girin.
                                                    </div>
                                                    <div class="form-text">Binance cüzdan adresinizi girin.</div>
                                                </div>
                                                
                                                <button type="submit" class="btn btn-success w-100">
                                                    <i class="fas fa-save me-2"></i>Kaydet
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Gomdom Wallet -->
                                <div class="col-md-4 mb-4">
                                    <div class="card h-100 wallet-card">
                                        <div class="card-header d-flex align-items-center">
                                            <i class="fas fa-money-bill-wave me-2 wallet-icon"></i>
                                            <h5 class="mb-0">Gomdom Cüzdan</h5>
                                        </div>
                                        <div class="card-body">
                                            <form action="" method="post" class="needs-validation" novalidate>
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="update_wallet">
                                                <input type="hidden" name="wallet_type" value="gomdom">
                                                
                                                <div class="mb-3">
                                                    <label for="gomdom_address" class="form-label">Gomdom Adres</label>
                                                    <input type="text" class="form-control" id="gomdom_address" name="wallet_address" 
                                                        value="<?= htmlspecialchars($wallets['gomdom'] ?? '') ?>" required>
                                                    <div class="invalid-feedback">
                                                        Geçerli bir Gomdom adresi girin.
                                                    </div>
                                                    <div class="form-text">Gomdom cüzdan adresinizi girin.</div>
                                                </div>
                                                
                                                <button type="submit" class="btn btn-success w-100">
                                                    <i class="fas fa-save me-2"></i>Kaydet
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Notifications Tab -->
                        <div class="tab-pane fade <?= $activeTab === 'notifications' ? 'show active' : '' ?>" 
                             id="notifications" role="tabpanel">
                            <div class="row">
                                <div class="col-lg-8 mx-auto">
                                    <form action="" method="post">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="notification_settings">
                                        
                                        <div class="notification-settings">
                                            <div class="notification-item">
                                                <div class="notification-info">
                                                    <div class="notification-icon">
                                                        <i class="fas fa-envelope"></i>
                                                    </div>
                                                    <div class="notification-details">
                                                        <h5>E-posta Bildirimleri</h5>
                                                        <p>Önemli bildirimler ve duyurular için e-posta alın</p>
                                                    </div>
                                                </div>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" id="email_notifications" 
                                                           name="email_notifications" <?= $notificationSettings['email_notifications'] ? 'checked' : '' ?>>
                                                </div>
                                            </div>
                                            
                                            <div class="notification-item">
                                                <div class="notification-info">
                                                    <div class="notification-icon">
                                                        <i class="fas fa-gift"></i>
                                                    </div>
                                                    <div class="notification-details">
                                                        <h5>Çekiliş Bildirimleri</h5>
                                                        <p>Yeni çekilişler ve katıldığınız çekilişlerin sonuçları hakkında bilgi alın</p>
                                                    </div>
                                                </div>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" id="raffle_notifications" 
                                                           name="raffle_notifications" <?= $notificationSettings['raffle_notifications'] ? 'checked' : '' ?>>
                                                </div>
                                            </div>
                                            
                                            <div class="notification-item">
                                                <div class="notification-info">
                                                    <div class="notification-icon">
                                                        <i class="fas fa-trophy"></i>
                                                    </div>
                                                    <div class="notification-details">
                                                        <h5>Kazanım Bildirimleri</h5>
                                                        <p>Bir ödül kazandığınızda veya TP aldığınızda bildirim alın</p>
                                                    </div>
                                                </div>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" id="win_notifications" 
                                                           name="win_notifications" <?= $notificationSettings['win_notifications'] ? 'checked' : '' ?>>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="text-center mt-4">
                                            <button type="submit" class="btn btn-success">
                                                <i class="fas fa-save me-2"></i>Ayarları Kaydet
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Blocked Users Tab -->
                        <div class="tab-pane fade <?= $activeTab === 'blocks' ? 'show active' : '' ?>" 
                             id="blocks" role="tabpanel">
                            <?php if (empty($blockedUsers)): ?>
                                <div class="alert alert-info text-center">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Henüz engellediğiniz bir kullanıcı bulunmamaktadır.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Kullanıcı</th>
                                                <th class="text-end">İşlem</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($blockedUsers as $blockedUser): ?>
                                                <tr>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <?php if (!empty($blockedUser['avatar'])): ?>
                                                                <img src="<?= htmlspecialchars($blockedUser['avatar']) ?>" alt="Avatar" class="avatar-small rounded-circle me-2">
                                                            <?php else: ?>
                                                                <div class="avatar-placeholder avatar-placeholder-sm me-2">
                                                                    <?= strtoupper(substr($blockedUser['username'], 0, 1)) ?>
                                                                </div>
                                                            <?php endif; ?>
                                                            <span><?= htmlspecialchars($blockedUser['username']) ?></span>
                                                        </div>
                                                    </td>
                                                    <td class="text-end">
                                                        <form action="" method="post" class="d-inline">
                                                            <?= csrfField() ?>
                                                            <input type="hidden" name="action" value="unblock_user">
                                                            <input type="hidden" name="blocked_user_id" value="<?= $blockedUser['blocked_user_id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-success">
                                                                <i class="fas fa-user-check me-1"></i> Engeli Kaldır
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.page-title {
    color: var(--green-primary);
    font-weight: 700;
    margin-bottom: 1.5rem;
}

.profile-image-preview {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    overflow: hidden;
    background-color: var(--dark-accent);
    border: 3px solid var(--border-color);
    box-shadow: var(--shadow-sm);
}

.profile-image-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.nav-tabs {
    border-bottom-color: var(--border-color);
}

.nav-tabs .nav-link {
    color: var(--text-muted);
    border: none;
    padding: 0.75rem 1rem;
    margin-right: 0.5rem;
    border-bottom: 3px solid transparent;
}

.nav-tabs .nav-link:hover {
    color: var(--text-light);
    border-bottom-color: var(--border-color);
}

.nav-tabs .nav-link.active {
    color: var(--green-primary);
    background-color: transparent;
    border-bottom-color: var(--green-primary);
}

.wallet-card {
    transition: transform var(--transition-normal), box-shadow var(--transition-normal);
}

.wallet-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-md);
}

.wallet-icon {
    color: var(--green-primary);
}

.notification-settings {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.notification-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.25rem;
    background-color: var(--dark-accent);
    border-radius: 8px;
    transition: transform var(--transition-normal);
}

.notification-item:hover {
    transform: translateY(-3px);
}

.notification-info {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.notification-icon {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--casino-purple), var(--casino-blue));
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
    flex-shrink: 0;
}

.notification-details h5 {
    font-size: 1.1rem;
    margin-bottom: 0.25rem;
}

.notification-details p {
    color: var(--text-muted);
    margin-bottom: 0;
    font-size: 0.9rem;
}

.form-check-input {
    width: 3rem;
    height: 1.5rem;
}

.form-check-input:checked {
    background-color: var(--green-primary);
    border-color: var(--green-primary);
}

.toggle-password {
    cursor: pointer;
}

@media (max-width: 767.98px) {
    .notification-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }
    
    .form-check-input {
        width: 2.5rem;
        height: 1.25rem;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Form validation
    const forms = document.querySelectorAll('.needs-validation');
    
    Array.prototype.slice.call(forms).forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            
            form.classList.add('was-validated');
        }, false);
    });
    
    // Password match validation
    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('confirm_password');
    
    if (newPassword && confirmPassword) {
        confirmPassword.addEventListener('input', function() {
            if (newPassword.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity('Şifreler eşleşmiyor.');
            } else {
                confirmPassword.setCustomValidity('');
            }
        });
        
        newPassword.addEventListener('input', function() {
            if (newPassword.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity('Şifreler eşleşmiyor.');
            } else {
                confirmPassword.setCustomValidity('');
            }
        });
    }
    
    // Password toggle
    const toggleBtns = document.querySelectorAll('.toggle-password');
    
    toggleBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const target = document.getElementById(targetId);
            const icon = this.querySelector('i');
            
            if (target.type === 'password') {
                target.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                target.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
    });
    
    // Keep the selected tab active after form submission
    const tab = window.location.hash;
    if (tab) {
        const tabElement = document.querySelector(`a[href="${tab}"]`);
        if (tabElement) {
            tabElement.tab('show');
        }
    }
    
    // Update URL hash when tab changes
    const tabLinks = document.querySelectorAll('[data-bs-toggle="tab"]');
    tabLinks.forEach(tabLink => {
        tabLink.addEventListener('shown.bs.tab', function (e) {
            const id = e.target.getAttribute('data-bs-target').substr(1);
            window.location.hash = id;
        });
    });
});
</script>

<?php
// Include footer
include_once 'includes/footer.php';
?>
