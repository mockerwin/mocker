<?php
// Define MOCKER_INCLUDED to allow includes
define('MOCKER_INCLUDED', true);

// Include configuration and required files
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';
require_once 'includes/websocket.php';

// Get the current user if logged in
$isLoggedIn = Auth::isLoggedIn();
$currentUser = $isLoggedIn ? Auth::user() : null;

// Get active raffles for banner
$db = DB::getInstance();
$query = "SELECT id, title, tp_prize, max_participants, current_participants, end_date, image_url 
          FROM raffles 
          WHERE status = 'active' 
          ORDER BY end_date ASC 
          LIMIT 3";
$db->query($query);
$featuredRaffles = $db->fetchAll();

// Get recent winners
$query = "SELECT w.id, w.user_id, w.prize_amount, w.source, w.created_at, 
          u.username, u.avatar, r.title as raffle_title
          FROM winners w
          JOIN users u ON w.user_id = u.id
          LEFT JOIN raffles r ON w.raffle_id = r.id
          ORDER BY w.created_at DESC
          LIMIT 10";
$db->query($query);
$recentWinners = $db->fetchAll();

// Get latest activities
$query = "SELECT a.user_id, a.action, a.details, a.created_at, u.username, u.avatar
          FROM activity_logs a
          JOIN users u ON a.user_id = u.id
          WHERE a.action IN ('raffle_win', 'prediction_win', 'promo_code_used', 'register')
          ORDER BY a.created_at DESC
          LIMIT 10";
$db->query($query);
$recentActivities = $db->fetchAll();

// Get top users (by TP)
$query = "SELECT id, username, avatar, tp
          FROM users
          WHERE is_active = TRUE
          ORDER BY tp DESC
          LIMIT 5";
$db->query($query);
$topUsers = $db->fetchAll();

// Get online user count
$query = "SELECT COUNT(DISTINCT user_id) as count 
          FROM user_logins 
          WHERE created_at > NOW() - INTERVAL '5 minutes'";
$db->query($query);
$onlineCount = $db->fetch()['count'] ?? 0;

// Set page title
$pageTitle = 'Anasayfa';

// Include header
include_once 'includes/header.php';
?>

<!-- Hero Banner -->
<div class="hero-banner mb-4">
    <div class="hero-content">
        <h1 class="hero-title">
            <span class="text-gradient">Randy Casino</span><br>
            Eğlence Sizinle Başlar
        </h1>
        <p class="hero-subtitle">
            Çekilişler, promosyonlar ve daha fazlası!
        </p>
        <?php if (!$isLoggedIn): ?>
        <div class="hero-buttons">
            <a href="register.php" class="btn btn-lg btn-success animate-pulse">
                <i class="fas fa-user-plus me-2"></i>Hemen Kayıt Ol
            </a>
            <a href="login.php" class="btn btn-lg btn-outline-light ms-3">
                <i class="fas fa-sign-in-alt me-2"></i>Giriş Yap
            </a>
        </div>
        <?php else: ?>
        <div class="hero-buttons">
            <a href="raffle.php" class="btn btn-lg btn-success">
                <i class="fas fa-gift me-2"></i>Çekilişlere Katıl
            </a>
            <a href="promocode.php" class="btn btn-lg btn-outline-light ms-3">
                <i class="fas fa-ticket-alt me-2"></i>Promo Kod Kullan
            </a>
        </div>
        <?php endif; ?>
    </div>
    <div class="hero-overlay"></div>
</div>

<!-- Stats Banner -->
<div class="stats-banner mb-4">
    <div class="stats-item">
        <div class="stats-icon">
            <i class="fas fa-users"></i>
        </div>
        <div class="stats-value"><?= $onlineCount ?></div>
        <div class="stats-label">Çevrimiçi</div>
    </div>
    <div class="stats-item">
        <div class="stats-icon">
            <i class="fas fa-gift"></i>
        </div>
        <div class="stats-value">
            <?php
            $db->query("SELECT COUNT(*) as count FROM raffles WHERE status = 'active'");
            echo $db->fetch()['count'] ?? 0;
            ?>
        </div>
        <div class="stats-label">Aktif Çekiliş</div>
    </div>
    <div class="stats-item">
        <div class="stats-icon">
            <i class="fas fa-trophy"></i>
        </div>
        <div class="stats-value">
            <?php
            $db->query("SELECT COUNT(*) as count FROM winners WHERE created_at > NOW() - INTERVAL '24 hours'");
            echo $db->fetch()['count'] ?? 0;
            ?>
        </div>
        <div class="stats-label">Bugünkü Kazanan</div>
    </div>
    <div class="stats-item">
        <div class="stats-icon">
            <i class="fas fa-coins"></i>
        </div>
        <div class="stats-value">
            <?php
            $db->query("SELECT SUM(tp_prize) as total FROM raffles WHERE status = 'active'");
            echo formatTP($db->fetch()['total'] ?? 0);
            ?>
        </div>
        <div class="stats-label">Toplam TP</div>
    </div>
</div>

<div class="row">
    <!-- Main Content Column -->
    <div class="col-lg-8">
        <!-- Featured Raffles Section -->
        <?php if (!empty($featuredRaffles)): ?>
        <div class="card dark-card mb-4">
            <div class="card-header">
                <h5 class="card-title"><i class="fas fa-gift me-2"></i>Popüler Çekilişler</h5>
            </div>
            <div class="card-body p-0">
                <div class="featured-raffles">
                    <?php foreach ($featuredRaffles as $raffle): ?>
                        <div class="featured-raffle">
                            <div class="raffle-image">
                                <i class="fas fa-gift"></i>
                            </div>
                            <div class="raffle-content">
                                <h5 class="raffle-title"><?= htmlspecialchars($raffle['title']) ?></h5>
                                <div class="raffle-prize">
                                    <i class="fas fa-coins me-1 text-warning"></i> <?= formatTP($raffle['tp_prize']) ?> TP
                                </div>
                                <div class="raffle-progress">
                                    <div class="progress">
                                        <div class="progress-bar bg-success" role="progressbar" 
                                             style="width: <?= ($raffle['current_participants'] / $raffle['max_participants']) * 100 ?>%" 
                                             aria-valuenow="<?= $raffle['current_participants'] ?>" 
                                             aria-valuemin="0" 
                                             aria-valuemax="<?= $raffle['max_participants'] ?>">
                                        </div>
                                    </div>
                                    <div class="raffle-participants">
                                        <?= $raffle['current_participants'] ?>/<?= $raffle['max_participants'] ?> katılımcı
                                    </div>
                                </div>
                                <div class="raffle-footer">
                                    <div class="raffle-ends">
                                        <i class="fas fa-clock me-1"></i> Bitiş: <?= formatDate($raffle['end_date']) ?>
                                    </div>
                                    <a href="raffle.php?id=<?= $raffle['id'] ?>" class="btn btn-sm btn-success">
                                        <i class="fas fa-arrow-right me-1"></i> Katıl
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="text-center p-3">
                    <a href="raffle.php" class="btn btn-outline-light">
                        <i class="fas fa-gift me-2"></i>Tüm Çekilişleri Gör
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Recent Activities -->
        <div class="card dark-card mb-4">
            <div class="card-header">
                <h5 class="card-title"><i class="fas fa-bolt me-2"></i>Son Aktiviteler</h5>
            </div>
            <div class="card-body p-0">
                <div class="activity-list">
                    <?php if (empty($recentActivities)): ?>
                        <div class="text-center p-4 text-muted">Henüz aktivite yok</div>
                    <?php else: ?>
                        <?php foreach ($recentActivities as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-avatar">
                                    <?php if (!empty($activity['avatar'])): ?>
                                        <img src="<?= htmlspecialchars($activity['avatar']) ?>" alt="Avatar">
                                    <?php else: ?>
                                        <div class="avatar-placeholder">
                                            <?= strtoupper(substr($activity['username'], 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-message">
                                        <a href="profile.php?id=<?= $activity['user_id'] ?>" class="user-link">
                                            <?= htmlspecialchars($activity['username']) ?>
                                        </a>
                                        <?php
                                        switch ($activity['action']) {
                                            case 'raffle_win':
                                                echo ' bir çekilişte kazandı!';
                                                break;
                                            case 'prediction_win':
                                                echo ' bir tahminde kazandı!';
                                                break;
                                            case 'promo_code_used':
                                                echo ' promosyon kodu kullandı!';
                                                break;
                                            case 'register':
                                                echo ' aramıza katıldı! Hoş geldin!';
                                                break;
                                            default:
                                                echo ' ' . htmlspecialchars($activity['details']);
                                        }
                                        ?>
                                    </div>
                                    <div class="activity-time"><?= timeAgo($activity['created_at']) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Sidebar Column -->
    <div class="col-lg-4">
        <!-- Recent Winners -->
        <div class="card dark-card mb-4">
            <div class="card-header">
                <h5 class="card-title"><i class="fas fa-trophy me-2"></i>Son Kazananlar</h5>
            </div>
            <div class="card-body p-0">
                <div class="winners-list">
                    <?php if (empty($recentWinners)): ?>
                        <div class="text-center p-4 text-muted">Henüz kazanan yok</div>
                    <?php else: ?>
                        <?php foreach ($recentWinners as $winner): ?>
                            <div class="winner-item">
                                <div class="winner-avatar">
                                    <?php if (!empty($winner['avatar'])): ?>
                                        <img src="<?= htmlspecialchars($winner['avatar']) ?>" alt="Avatar">
                                    <?php else: ?>
                                        <div class="avatar-placeholder">
                                            <?= strtoupper(substr($winner['username'], 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="winner-content">
                                    <div class="winner-name">
                                        <a href="profile.php?id=<?= $winner['user_id'] ?>" class="user-link">
                                            <?= htmlspecialchars($winner['username']) ?>
                                        </a>
                                    </div>
                                    <div class="winner-prize">
                                        <i class="fas fa-coins me-1 text-warning"></i> <?= formatTP($winner['prize_amount']) ?> TP
                                    </div>
                                    <div class="winner-source">
                                        <?php if ($winner['source'] == 'raffle'): ?>
                                            <i class="fas fa-gift me-1"></i> <?= htmlspecialchars($winner['raffle_title'] ?? 'Çekiliş') ?>
                                        <?php else: ?>
                                            <i class="fas fa-chart-line me-1"></i> Tahmin
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="winner-time">
                                    <?= timeAgo($winner['created_at']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Top Users -->
        <div class="card dark-card mb-4">
            <div class="card-header">
                <h5 class="card-title"><i class="fas fa-crown me-2"></i>En Zengin Kullanıcılar</h5>
            </div>
            <div class="card-body p-0">
                <div class="top-users-list">
                    <?php if (empty($topUsers)): ?>
                        <div class="text-center p-4 text-muted">Henüz kullanıcı yok</div>
                    <?php else: ?>
                        <?php foreach ($topUsers as $index => $user): ?>
                            <div class="top-user-item">
                                <div class="top-user-rank">#<?= $index + 1 ?></div>
                                <div class="top-user-avatar">
                                    <?php if (!empty($user['avatar'])): ?>
                                        <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="Avatar">
                                    <?php else: ?>
                                        <div class="avatar-placeholder">
                                            <?= strtoupper(substr($user['username'], 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="top-user-info">
                                    <div class="top-user-name">
                                        <a href="profile.php?id=<?= $user['id'] ?>" class="user-link">
                                            <?= htmlspecialchars($user['username']) ?>
                                        </a>
                                    </div>
                                    <div class="top-user-tp">
                                        <i class="fas fa-coins me-1 text-warning"></i> <?= formatTP($user['tp']) ?> TP
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Promo Banner -->
        <?php if (!$isLoggedIn): ?>
        <div class="promo-banner">
            <div class="promo-content">
                <h4>Hemen Kayıt Ol</h4>
                <p>Randy Casino'ya üye ol, <strong>100 TP</strong> bonusla başla!</p>
                <a href="register.php" class="btn btn-success">
                    <i class="fas fa-user-plus me-2"></i>Kayıt Ol
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="promo-banner">
            <div class="promo-content">
                <h4>Promosyon Kodu Kullan</h4>
                <p>Promo kodlarla daha fazla TP kazanma şansı!</p>
                <a href="promocode.php" class="btn btn-success">
                    <i class="fas fa-ticket-alt me-2"></i>Promo Kod
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* Hero Banner */
.hero-banner {
    background: linear-gradient(135deg, var(--casino-purple), var(--casino-blue));
    border-radius: 10px;
    position: relative;
    overflow: hidden;
    padding: 60px 30px;
    color: white;
    text-align: center;
}

.hero-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA4MDAgODAiPjxwYXRoIGQ9Ik04MDAgODBWMEgwdjgwYzE2MCAwIDE4MC02MCAxOTAtNzAgNjAtNjAgOTAtMTAgMzQwIDIwIDEzMCAyMCAyMTAgMjAgMjcwLTMwWiIgZmlsbD0iI2ZmZiIgZmlsbC1vcGFjaXR5PSIuMSIvPjwvc3ZnPg==');
    background-position: bottom center;
    background-repeat: no-repeat;
    background-size: 100% auto;
    opacity: 0.2;
}

.hero-content {
    position: relative;
    z-index: 2;
}

.hero-title {
    font-size: 2.8rem;
    font-weight: 800;
    margin-bottom: 1rem;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

.hero-subtitle {
    font-size: 1.2rem;
    margin-bottom: 2rem;
    opacity: 0.9;
}

.text-gradient {
    background: linear-gradient(90deg, #fff, #ffd700);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.hero-buttons {
    margin-top: 2rem;
    display: flex;
    justify-content: center;
    gap: 1rem;
}

/* Stats Banner */
.stats-banner {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    background-color: var(--dark-lighter);
    border-radius: 10px;
    padding: 20px;
    box-shadow: var(--shadow-sm);
}

.stats-item {
    flex: 1;
    min-width: 120px;
    text-align: center;
    padding: 15px 10px;
}

.stats-icon {
    font-size: 24px;
    color: var(--green-primary);
    margin-bottom: 10px;
}

.stats-value {
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 5px;
}

.stats-label {
    font-size: 14px;
    color: var(--text-muted);
}

/* Featured Raffles */
.featured-raffles {
    display: flex;
    flex-direction: column;
}

.featured-raffle {
    display: flex;
    border-bottom: 1px solid var(--border-color);
    transition: background-color var(--transition-fast);
}

.featured-raffle:last-child {
    border-bottom: none;
}

.featured-raffle:hover {
    background-color: var(--dark-accent);
}

.raffle-image {
    width: 80px;
    height: 80px;
    background: linear-gradient(45deg, var(--casino-purple), var(--casino-blue));
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 2rem;
    flex-shrink: 0;
}

.raffle-content {
    flex: 1;
    padding: 15px;
    display: flex;
    flex-direction: column;
}

.raffle-title {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 5px;
}

.raffle-prize {
    font-weight: 700;
    color: var(--green-primary);
    margin-bottom: 10px;
}

.raffle-progress {
    margin-top: auto;
}

.raffle-participants {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 5px;
    text-align: right;
}

.raffle-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 10px;
}

.raffle-ends {
    font-size: 12px;
    color: var(--text-muted);
}

/* Activity List */
.activity-list {
    display: flex;
    flex-direction: column;
}

.activity-item {
    display: flex;
    padding: 15px;
    border-bottom: 1px solid var(--border-color);
    transition: background-color var(--transition-fast);
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-item:hover {
    background-color: var(--dark-accent);
}

.activity-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    overflow: hidden;
    margin-right: 15px;
    flex-shrink: 0;
}

.activity-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.activity-content {
    flex: 1;
}

.activity-message {
    margin-bottom: 5px;
}

.activity-time {
    font-size: 12px;
    color: var(--text-muted);
}

.user-link {
    font-weight: 600;
    color: var(--green-primary);
    text-decoration: none;
}

.user-link:hover {
    text-decoration: underline;
    color: var(--green-light);
}

/* Winners List */
.winners-list {
    display: flex;
    flex-direction: column;
}

.winner-item {
    display: flex;
    align-items: center;
    padding: 15px;
    border-bottom: 1px solid var(--border-color);
    transition: background-color var(--transition-fast);
}

.winner-item:last-child {
    border-bottom: none;
}

.winner-item:hover {
    background-color: var(--dark-accent);
}

.winner-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    overflow: hidden;
    margin-right: 15px;
    flex-shrink: 0;
}

.winner-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.winner-content {
    flex: 1;
}

.winner-name {
    font-weight: 600;
    margin-bottom: 3px;
}

.winner-prize {
    font-weight: 700;
    color: var(--green-primary);
    font-size: 14px;
}

.winner-source {
    font-size: 12px;
    color: var(--text-muted);
}

.winner-time {
    font-size: 12px;
    color: var(--text-muted);
    text-align: right;
    flex-shrink: 0;
    padding-left: 10px;
}

/* Top Users List */
.top-users-list {
    display: flex;
    flex-direction: column;
}

.top-user-item {
    display: flex;
    align-items: center;
    padding: 15px;
    border-bottom: 1px solid var(--border-color);
    transition: background-color var(--transition-fast);
}

.top-user-item:last-child {
    border-bottom: none;
}

.top-user-item:hover {
    background-color: var(--dark-accent);
}

.top-user-rank {
    font-weight: 700;
    font-size: 18px;
    width: 40px;
    text-align: center;
    color: var(--gold);
}

.top-user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    overflow: hidden;
    margin-right: 15px;
    flex-shrink: 0;
}

.top-user-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.top-user-info {
    flex: 1;
}

.top-user-name {
    font-weight: 600;
    margin-bottom: 3px;
}

.top-user-tp {
    font-size: 14px;
    color: var(--green-primary);
}

/* Promo Banner */
.promo-banner {
    background: linear-gradient(135deg, var(--casino-purple), var(--casino-blue));
    border-radius: 10px;
    overflow: hidden;
    padding: 20px;
    box-shadow: var(--shadow-md);
    animation: glow 3s infinite alternate;
}

.promo-content {
    text-align: center;
    color: white;
}

.promo-content h4 {
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 10px;
}

.promo-content p {
    margin-bottom: 20px;
    opacity: 0.9;
}

@keyframes glow {
    0% {
        box-shadow: 0 0 5px rgba(94, 43, 151, 0.5);
    }
    100% {
        box-shadow: 0 0 20px rgba(94, 43, 151, 0.8);
    }
}

/* Media queries */
@media (max-width: 767.98px) {
    .hero-title {
        font-size: 2rem;
    }
    
    .hero-subtitle {
        font-size: 1rem;
    }
    
    .stats-banner {
        flex-wrap: wrap;
    }
    
    .stats-item {
        flex: 1 0 50%;
        min-width: 50%;
    }
    
    .hero-buttons {
        flex-direction: column;
        align-items: center;
    }
    
    .hero-buttons .btn {
        width: 100%;
        margin-bottom: 10px;
    }
    
    .hero-buttons .ms-3 {
        margin-left: 0 !important;
    }
}
</style>

<?php
// Include footer
include_once 'includes/footer.php';
?>
