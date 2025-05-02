<?php
// Define MOCKER_INCLUDED to allow includes
define('MOCKER_INCLUDED', true);

// Include configuration and required files
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Get user ID from URL
$profileId = intval($_GET['id'] ?? 0);

// If no ID is provided, redirect to home
if ($profileId === 0) {
    header('Location: index.php');
    exit;
}

// Get user data
$db = DB::getInstance();
$query = "SELECT u.id, u.username, u.email, u.avatar, u.tp, u.role, u.created_at, u.last_login
          FROM users u
          WHERE u.id = ? AND u.is_active = 1";
$db->query($query, [$profileId]);
$user = $db->fetch();

// If user doesn't exist, redirect to home
if (!$user) {
    header('Location: index.php');
    exit;
}

// Check if the current user has blocked this profile
$isBlocked = false;
if (Auth::isLoggedIn()) {
    $isBlocked = isUserBlocked(Auth::user()['id'], $profileId);
}

// Get user wallets if viewing own profile or if admin
$wallets = [];
if (Auth::isLoggedIn() && (Auth::user()['id'] === $profileId || Auth::isAdmin())) {
    $query = "SELECT wallet_type, wallet_address FROM user_wallets WHERE user_id = ?";
    $db->query($query, [$profileId]);
    $walletsResult = $db->fetchAll();
    
    foreach ($walletsResult as $wallet) {
        $wallets[$wallet['wallet_type']] = $wallet['wallet_address'];
    }
}

// Get user statistics
$stats = [
    'sent_tp' => 0,
    'received_tp' => 0,
    'raffles_joined' => 0,
    'raffles_won' => 0,
    'predictions_made' => 0,
    'login_days' => 0
];

// Get total TP sent
$query = "SELECT SUM(ABS(amount)) as total FROM tp_transactions 
          WHERE user_id = ? AND source = 'transfer_out'";
$db->query($query, [$profileId]);
$result = $db->fetch();
$stats['sent_tp'] = $result ? intval($result['total']) : 0;

// Get total TP received
$query = "SELECT SUM(amount) as total FROM tp_transactions 
          WHERE user_id = ? AND source = 'transfer_in'";
$db->query($query, [$profileId]);
$result = $db->fetch();
$stats['received_tp'] = $result ? intval($result['total']) : 0;

// Get raffles joined
$query = "SELECT COUNT(*) as count FROM raffle_participants WHERE user_id = ?";
$db->query($query, [$profileId]);
$result = $db->fetch();
$stats['raffles_joined'] = $result ? intval($result['count']) : 0;

// Get raffles won
$query = "SELECT COUNT(*) as count FROM winners WHERE user_id = ? AND source = 'raffle'";
$db->query($query, [$profileId]);
$result = $db->fetch();
$stats['raffles_won'] = $result ? intval($result['count']) : 0;

// Get predictions made
$query = "SELECT COUNT(*) as count FROM prediction_entries WHERE user_id = ?";
$db->query($query, [$profileId]);
$result = $db->fetch();
$stats['predictions_made'] = $result ? intval($result['count']) : 0;

// Get unique login days
$query = "SELECT COUNT(DISTINCT DATE(created_at)) as count FROM user_logins WHERE user_id = ?";
$db->query($query, [$profileId]);
$result = $db->fetch();
$stats['login_days'] = $result ? intval($result['count']) : 0;

// Get user rank
$userRank = getUserRank($user['tp']);

// Get recent activities
$activities = [];
$query = "SELECT a.action, a.details, a.created_at 
          FROM activity_logs a 
          WHERE a.user_id = ? AND a.action IN ('register', 'login', 'raffle_join', 'prediction_entry', 'promo_code_used', 'tp_received')
          ORDER BY a.created_at DESC 
          LIMIT 10";
$db->query($query, [$profileId]);
$activities = $db->fetchAll();

// Check if user is online
$isOnline = isUserOnline($profileId);

// Get user's recent wins
$query = "SELECT w.prize_amount, w.source, w.created_at, r.title as raffle_title 
          FROM winners w
          LEFT JOIN raffles r ON w.raffle_id = r.id
          WHERE w.user_id = ?
          ORDER BY w.created_at DESC
          LIMIT 5";
$db->query($query, [$profileId]);
$recentWins = $db->fetchAll();

// Set page title
$pageTitle = htmlspecialchars($user['username']) . ' - Profil';

// Include header
include_once 'includes/header.php';
?>

<div class="profile-header">
    <div class="profile-cover"></div>
    <div class="profile-avatar-container">
        <div class="profile-avatar">
            <?php if (!empty($user['avatar'])): ?>
                <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="Avatar" class="rounded-circle">
            <?php else: ?>
                <div class="avatar-placeholder avatar-placeholder-lg">
                    <?= strtoupper(substr($user['username'], 0, 1)) ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($isOnline): ?>
            <div class="online-status">
                <i class="fas fa-circle"></i> Çevrimiçi
            </div>
        <?php endif; ?>
    </div>
    
    <div class="profile-info">
        <h1 class="profile-username">
            <?= htmlspecialchars($user['username']) ?>
            <?php if ($user['role'] == ROLE_ADMIN): ?>
                <span class="badge bg-danger ms-2"><i class="fas fa-shield-alt me-1"></i>Admin</span>
            <?php elseif ($user['role'] == ROLE_MOD): ?>
                <span class="badge bg-warning text-dark ms-2"><i class="fas fa-hammer me-1"></i>Moderatör</span>
            <?php endif; ?>
        </h1>
        
        <div class="profile-rank">
            <span class="badge rank-badge rank-<?= $userRank['key'] ?>"><?= $userRank['name'] ?></span>
            <span class="profile-tp ms-3"><i class="fas fa-coins me-1"></i><?= formatTP($user['tp']) ?> TP</span>
        </div>
        
        <div class="profile-meta">
            <div class="profile-join-date">
                <i class="fas fa-calendar-alt me-1"></i> Katılım: <?= formatDate($user['created_at'], 'd.m.Y') ?>
            </div>
            <?php if (!empty($user['last_login'])): ?>
                <div class="profile-last-login ms-3">
                    <i class="fas fa-clock me-1"></i> Son Giriş: <?= timeAgo($user['last_login']) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (Auth::isLoggedIn() && Auth::user()['id'] !== $profileId): ?>
        <div class="profile-actions">
            <button type="button" class="btn btn-success send-tp-btn" data-user-id="<?= $user['id'] ?>" data-username="<?= htmlspecialchars($user['username']) ?>">
                <i class="fas fa-coins me-1"></i> TP Gönder
            </button>
            
            <?php if ($isBlocked): ?>
                <button type="button" class="btn btn-outline-light ms-2 unblock-user-btn" data-user-id="<?= $user['id'] ?>">
                    <i class="fas fa-user-check me-1"></i> Engeli Kaldır
                </button>
            <?php else: ?>
                <button type="button" class="btn btn-outline-light ms-2 block-user-btn" data-user-id="<?= $user['id'] ?>">
                    <i class="fas fa-ban me-1"></i> Engelle
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="row mt-4">
    <div class="col-lg-4">
        <!-- User Statistics Card -->
        <div class="card dark-card mb-4">
            <div class="card-header">
                <h5 class="card-title"><i class="fas fa-chart-bar me-2"></i>İstatistikler</h5>
            </div>
            <div class="card-body">
                <ul class="stat-list">
                    <li class="stat-item">
                        <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                        <div class="stat-info">
                            <span class="stat-label">Toplam Giriş Günü</span>
                            <span class="stat-value"><?= $stats['login_days'] ?></span>
                        </div>
                    </li>
                    <li class="stat-item">
                        <div class="stat-icon"><i class="fas fa-share-alt"></i></div>
                        <div class="stat-info">
                            <span class="stat-label">Gönderilen TP</span>
                            <span class="stat-value"><?= formatTP($stats['sent_tp']) ?></span>
                        </div>
                    </li>
                    <li class="stat-item">
                        <div class="stat-icon"><i class="fas fa-download"></i></div>
                        <div class="stat-info">
                            <span class="stat-label">Alınan TP</span>
                            <span class="stat-value"><?= formatTP($stats['received_tp']) ?></span>
                        </div>
                    </li>
                    <li class="stat-item">
                        <div class="stat-icon"><i class="fas fa-ticket-alt"></i></div>
                        <div class="stat-info">
                            <span class="stat-label">Katıldığı Çekilişler</span>
                            <span class="stat-value"><?= $stats['raffles_joined'] ?></span>
                        </div>
                    </li>
                    <li class="stat-item">
                        <div class="stat-icon"><i class="fas fa-trophy"></i></div>
                        <div class="stat-info">
                            <span class="stat-label">Kazandığı Çekilişler</span>
                            <span class="stat-value"><?= $stats['raffles_won'] ?></span>
                        </div>
                    </li>
                    <li class="stat-item">
                        <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                        <div class="stat-info">
                            <span class="stat-label">Yaptığı Tahminler</span>
                            <span class="stat-value"><?= $stats['predictions_made'] ?></span>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
        
        <!-- Wallet Information (Only visible to self or admin) -->
        <?php if (Auth::isLoggedIn() && (Auth::user()['id'] === $profileId || Auth::isAdmin())): ?>
            <div class="card dark-card mb-4">
                <div class="card-header">
                    <h5 class="card-title"><i class="fas fa-wallet me-2"></i>Cüzdan Bilgileri</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($wallets)): ?>
                        <div class="text-center p-3">
                            <i class="fas fa-info-circle me-2"></i> Henüz cüzdan bilgisi eklenmemiş.
                            <div class="mt-2">
                                <a href="settings.php" class="btn btn-sm btn-success">
                                    <i class="fas fa-plus-circle me-1"></i> Cüzdan Ekle
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <ul class="wallet-list">
                            <?php if (isset($wallets['trc20'])): ?>
                                <li class="wallet-item">
                                    <div class="wallet-icon"><i class="fas fa-coins"></i></div>
                                    <div class="wallet-info">
                                        <span class="wallet-label">TRC20 (USDT)</span>
                                        <span class="wallet-address"><?= htmlspecialchars($wallets['trc20']) ?></span>
                                    </div>
                                </li>
                            <?php endif; ?>
                            
                            <?php if (isset($wallets['binance'])): ?>
                                <li class="wallet-item">
                                    <div class="wallet-icon"><i class="fab fa-bitcoin"></i></div>
                                    <div class="wallet-info">
                                        <span class="wallet-label">Binance</span>
                                        <span class="wallet-address"><?= htmlspecialchars($wallets['binance']) ?></span>
                                    </div>
                                </li>
                            <?php endif; ?>
                            
                            <?php if (isset($wallets['gomdom'])): ?>
                                <li class="wallet-item">
                                    <div class="wallet-icon"><i class="fas fa-money-bill-wave"></i></div>
                                    <div class="wallet-info">
                                        <span class="wallet-label">Gomdom</span>
                                        <span class="wallet-address"><?= htmlspecialchars($wallets['gomdom']) ?></span>
                                    </div>
                                </li>
                            <?php endif; ?>
                        </ul>
                        
                        <div class="text-center mt-3">
                            <a href="settings.php" class="btn btn-sm btn-outline-light">
                                <i class="fas fa-edit me-1"></i> Düzenle
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="col-lg-8">
        <!-- Recent Wins -->
        <?php if (!empty($recentWins)): ?>
            <div class="card dark-card mb-4">
                <div class="card-header">
                    <h5 class="card-title"><i class="fas fa-trophy me-2"></i>Son Kazanımlar</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Tarih</th>
                                    <th>Kaynak</th>
                                    <th>Miktar</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentWins as $win): ?>
                                    <tr>
                                        <td><?= formatDate($win['created_at']) ?></td>
                                        <td>
                                            <?php if ($win['source'] == 'raffle'): ?>
                                                <i class="fas fa-gift me-1 text-success"></i> <?= htmlspecialchars($win['raffle_title'] ?? 'Çekiliş') ?>
                                            <?php else: ?>
                                                <i class="fas fa-chart-line me-1 text-primary"></i> Tahmin
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-bold text-success"><?= formatTP($win['prize_amount']) ?> TP</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Recent Activities -->
        <div class="card dark-card mb-4">
            <div class="card-header">
                <h5 class="card-title"><i class="fas fa-history me-2"></i>Son Aktiviteler</h5>
            </div>
            <div class="card-body p-0">
                <div class="activity-timeline">
                    <?php if (empty($activities)): ?>
                        <div class="text-center p-4 text-muted">
                            <i class="fas fa-info-circle me-2"></i> Henüz aktivite yok.
                        </div>
                    <?php else: ?>
                        <?php foreach ($activities as $activity): ?>
                            <div class="timeline-item">
                                <div class="timeline-marker">
                                    <?php
                                    $iconClass = 'fas fa-circle';
                                    $markerClass = '';
                                    
                                    switch ($activity['action']) {
                                        case 'register':
                                            $iconClass = 'fas fa-user-plus';
                                            $markerClass = 'marker-success';
                                            break;
                                        case 'login':
                                            $iconClass = 'fas fa-sign-in-alt';
                                            $markerClass = 'marker-info';
                                            break;
                                        case 'raffle_join':
                                            $iconClass = 'fas fa-ticket-alt';
                                            $markerClass = 'marker-primary';
                                            break;
                                        case 'prediction_entry':
                                            $iconClass = 'fas fa-chart-line';
                                            $markerClass = 'marker-warning';
                                            break;
                                        case 'promo_code_used':
                                            $iconClass = 'fas fa-gift';
                                            $markerClass = 'marker-success';
                                            break;
                                        case 'tp_received':
                                            $iconClass = 'fas fa-coins';
                                            $markerClass = 'marker-success';
                                            break;
                                    }
                                    ?>
                                    <div class="timeline-marker-icon <?= $markerClass ?>">
                                        <i class="<?= $iconClass ?>"></i>
                                    </div>
                                </div>
                                <div class="timeline-content">
                                    <div class="timeline-date"><?= formatDate($activity['created_at']) ?></div>
                                    <div class="timeline-title">
                                        <?php
                                        switch ($activity['action']) {
                                            case 'register':
                                                echo 'Hesap Oluşturuldu';
                                                break;
                                            case 'login':
                                                echo 'Giriş Yapıldı';
                                                break;
                                            case 'raffle_join':
                                                echo 'Çekilişe Katıldı';
                                                break;
                                            case 'prediction_entry':
                                                echo 'Tahmin Yapıldı';
                                                break;
                                            case 'promo_code_used':
                                                echo 'Promo Kod Kullanıldı';
                                                break;
                                            case 'tp_received':
                                                echo 'TP Alındı';
                                                break;
                                            default:
                                                echo htmlspecialchars($activity['action']);
                                        }
                                        ?>
                                    </div>
                                    <?php if (!empty($activity['details'])): ?>
                                        <div class="timeline-details"><?= htmlspecialchars($activity['details']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Profile additional styling */
.profile-header {
    background: linear-gradient(to bottom, var(--dark-accent), var(--dark-lighter));
    border: 1px solid var(--border-color);
    box-shadow: var(--shadow-md);
}

.profile-cover {
    background: linear-gradient(135deg, var(--casino-purple), var(--casino-blue));
    opacity: 0.6;
    height: 150px;
}

.table {
    color: var(--text-light);
    margin-bottom: 0;
}

.table thead {
    background-color: var(--dark-accent);
    border-bottom: 1px solid var(--border-color);
}

.table tbody tr {
    transition: background-color var(--transition-fast);
}

.table tbody tr:hover {
    background-color: var(--dark-accent);
}

/* Timeline styling */
.activity-timeline {
    position: relative;
    padding: 1.5rem;
}

.timeline-item {
    position: relative;
    padding-left: 30px;
    margin-bottom: 1.5rem;
}

.timeline-item:last-child {
    margin-bottom: 0;
}

.timeline-item:not(:last-child)::after {
    content: '';
    position: absolute;
    left: 10px;
    top: 24px;
    bottom: -24px;
    width: 2px;
    background-color: var(--border-color);
}

.timeline-marker {
    position: absolute;
    left: 0;
    top: 3px;
    width: 20px;
    height: 20px;
    z-index: 1;
}

.timeline-marker-icon {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background-color: var(--dark-accent);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    color: white;
}

.marker-success {
    background-color: var(--green-primary);
}

.marker-info {
    background-color: var(--casino-blue);
}

.marker-primary {
    background-color: var(--casino-purple);
}

.marker-warning {
    background-color: #ffc107;
}

.timeline-content {
    background-color: var(--dark-accent);
    padding: 1rem;
    border-radius: 6px;
}

.timeline-date {
    font-size: 12px;
    color: var(--text-muted);
    margin-bottom: 4px;
}

.timeline-title {
    font-weight: 600;
    margin-bottom: 4px;
}

.timeline-details {
    font-size: 14px;
    color: var(--text-muted);
}
</style>

<?php
// Include footer
include_once 'includes/footer.php';
?>
