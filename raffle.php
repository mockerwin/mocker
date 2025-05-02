<?php
// Define MOCKER_INCLUDED to allow includes
define('MOCKER_INCLUDED', true);

// Include configuration and required files
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Initialize variables
$raffleId = intval($_GET['id'] ?? 0);
$db = DB::getInstance();
$joinError = '';
$joinSuccess = '';

// Handle specific raffle view or list all
if ($raffleId > 0) {
    // Get raffle details
    $query = "SELECT r.id, r.title, r.description, r.tp_prize, r.max_participants, r.current_participants, 
              r.start_date, r.end_date, r.status, r.image_url, r.created_by
              FROM raffles r
              WHERE r.id = ?";
    $db->query($query, [$raffleId]);
    $raffle = $db->fetch();
    
    if (!$raffle) {
        // Raffle not found, redirect to list
        header('Location: raffle.php');
        exit;
    }
    
    // Get raffle participants
    $query = "SELECT p.id, p.user_id, p.joined_at, u.username, u.avatar
              FROM raffle_participants p
              JOIN users u ON p.user_id = u.id
              WHERE p.raffle_id = ?
              ORDER BY p.joined_at DESC";
    $db->query($query, [$raffleId]);
    $participants = $db->fetchAll();
    
    // Get raffle winners if completed
    $winners = [];
    if ($raffle['status'] === 'completed') {
        $query = "SELECT w.user_id, u.username, u.avatar
                  FROM raffle_winners w
                  JOIN users u ON w.user_id = u.id
                  WHERE w.raffle_id = ?
                  ORDER BY w.created_at";
        $db->query($query, [$raffleId]);
        $winners = $db->fetchAll();
    }
    
    // Check if user has already joined
    $hasJoined = false;
    if (Auth::isLoggedIn()) {
        $userId = Auth::user()['id'];
        foreach ($participants as $participant) {
            if ($participant['user_id'] == $userId) {
                $hasJoined = true;
                break;
            }
        }
    }
    
    // Process join request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join_raffle']) && Auth::isLoggedIn()) {
        // Check CSRF token
        if (!verifyCsrf()) {
            $joinError = 'Güvenlik doğrulaması başarısız oldu. Lütfen sayfayı yenileyip tekrar deneyin.';
        } else {
            $userId = Auth::user()['id'];
            
            // Check if raffle is active
            if ($raffle['status'] !== 'active') {
                $joinError = 'Bu çekiliş artık aktif değil.';
            }
            // Check if already joined
            elseif ($hasJoined) {
                $joinError = 'Bu çekilişe zaten katıldınız.';
            }
            // Check if raffle is full
            elseif ($raffle['current_participants'] >= $raffle['max_participants']) {
                $joinError = 'Bu çekiliş dolmuş durumda.';
            }
            else {
                try {
                    $db->beginTransaction();
                    
                    // Add participant
                    $query = "INSERT INTO raffle_participants (raffle_id, user_id, joined_at)
                              VALUES (?, ?, NOW())";
                    $db->query($query, [$raffleId, $userId]);
                    
                    // Update participant count
                    $query = "UPDATE raffles SET current_participants = current_participants + 1 WHERE id = ?";
                    $db->query($query, [$raffleId]);
                    
                    // Log activity
                    logActivity($userId, 'raffle_join', "Joined raffle: {$raffle['title']}");
                    
                    $db->commit();
                    
                    $joinSuccess = 'Çekilişe başarıyla katıldınız!';
                    $hasJoined = true;
                    $raffle['current_participants']++;
                    
                    // Add user to participants list
                    $newParticipant = [
                        'user_id' => $userId,
                        'username' => Auth::user()['username'],
                        'avatar' => Auth::user()['avatar'],
                        'joined_at' => date('Y-m-d H:i:s')
                    ];
                    array_unshift($participants, $newParticipant);
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $joinError = 'Çekilişe katılırken bir hata oluştu. Lütfen daha sonra tekrar deneyin.';
                }
            }
        }
    }
    
    // Set the page title for single raffle
    $pageTitle = htmlspecialchars($raffle['title']) . ' - Çekiliş';
} else {
    // Get all raffles
    $query = "SELECT r.id, r.title, r.description, r.tp_prize, r.max_participants, r.current_participants, 
              r.start_date, r.end_date, r.status, r.image_url
              FROM raffles r
              ORDER BY 
                CASE r.status 
                    WHEN 'active' THEN 1
                    WHEN 'pending' THEN 2
                    WHEN 'completed' THEN 3
                END,
                r.end_date ASC";
    $db->query($query);
    $raffles = $db->fetchAll();
    
    // Group raffles by status
    $activeRaffles = [];
    $pendingRaffles = [];
    $completedRaffles = [];
    
    foreach ($raffles as $raffle) {
        switch ($raffle['status']) {
            case 'active':
                $activeRaffles[] = $raffle;
                break;
            case 'pending':
                $pendingRaffles[] = $raffle;
                break;
            case 'completed':
                $completedRaffles[] = $raffle;
                break;
        }
    }
    
    // Set the page title for raffle list
    $pageTitle = 'Randy Çekilişler';
}

// Include header
include_once 'includes/header.php';
?>

<?php if ($raffleId > 0): ?>
    <!-- Single Raffle View -->
    <div class="raffle-single">
        <div class="raffle-header">
            <h1 class="raffle-title"><?= htmlspecialchars($raffle['title']) ?></h1>
            
            <div class="raffle-status">
                <?php if ($raffle['status'] === 'active'): ?>
                    <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Aktif</span>
                <?php elseif ($raffle['status'] === 'pending'): ?>
                    <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Beklemede</span>
                <?php elseif ($raffle['status'] === 'completed'): ?>
                    <span class="badge bg-secondary"><i class="fas fa-flag-checkered me-1"></i>Tamamlandı</span>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (!empty($joinError)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i><?= $joinError ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($joinSuccess)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i><?= $joinSuccess ?>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-lg-8">
                <div class="card dark-card mb-4">
                    <div class="card-body">
                        <div class="raffle-details">
                            <div class="raffle-icon">
                                <i class="fas fa-gift fa-3x"></i>
                            </div>
                            
                            <div class="raffle-info">
                                <div class="raffle-prize">
                                    <i class="fas fa-coins me-1"></i>Ödül: <strong><?= formatTP($raffle['tp_prize']) ?> TP</strong>
                                </div>
                                
                                <div class="raffle-participants">
                                    <i class="fas fa-users me-1"></i>Katılımcılar: <strong><?= $raffle['current_participants'] ?> / <?= $raffle['max_participants'] ?></strong>
                                </div>
                                
                                <div class="progress mt-2 mb-2">
                                    <div class="progress-bar bg-success" role="progressbar" 
                                        style="width: <?= ($raffle['current_participants'] / $raffle['max_participants']) * 100 ?>%" 
                                        aria-valuenow="<?= $raffle['current_participants'] ?>" 
                                        aria-valuemin="0" 
                                        aria-valuemax="<?= $raffle['max_participants'] ?>">
                                    </div>
                                </div>
                                
                                <div class="raffle-dates">
                                    <div><i class="fas fa-calendar-plus me-1"></i>Başlangıç: <?= formatDate($raffle['start_date']) ?></div>
                                    <div><i class="fas fa-calendar-check me-1"></i>Bitiş: <?= formatDate($raffle['end_date']) ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="raffle-description mt-4">
                            <h5>Açıklama</h5>
                            <div class="description-content p-3 rounded">
                                <?= nl2br(htmlspecialchars($raffle['description'])) ?>
                            </div>
                        </div>
                        
                        <?php if ($raffle['status'] === 'active'): ?>
                            <div class="raffle-action mt-4">
                                <?php if (Auth::isLoggedIn()): ?>
                                    <?php if ($hasJoined): ?>
                                        <div class="alert alert-success">
                                            <i class="fas fa-check-circle me-2"></i>Bu çekilişe katıldınız.
                                        </div>
                                    <?php elseif ($raffle['current_participants'] < $raffle['max_participants']): ?>
                                        <form method="post">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="join_raffle" value="1">
                                            <button type="submit" class="btn btn-success btn-lg">
                                                <i class="fas fa-ticket-alt me-2"></i>Çekilişe Katıl
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-circle me-2"></i>Bu çekiliş dolmuş durumda.
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>Çekilişe katılmak için <a href="login.php?redirect=raffle.php%3Fid%3D<?= $raffleId ?>" class="alert-link">giriş yapın</a>.
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($raffle['status'] === 'completed' && !empty($winners)): ?>
                            <div class="raffle-winners mt-4">
                                <h5><i class="fas fa-trophy me-2"></i>Kazananlar</h5>
                                <div class="winners-list">
                                    <?php foreach ($winners as $index => $winner): ?>
                                        <div class="winner-item">
                                            <div class="winner-position">#<?= $index + 1 ?></div>
                                            <div class="winner-avatar">
                                                <?php if (!empty($winner['avatar'])): ?>
                                                    <img src="<?= htmlspecialchars($winner['avatar']) ?>" alt="Avatar">
                                                <?php else: ?>
                                                    <div class="avatar-placeholder">
                                                        <?= strtoupper(substr($winner['username'], 0, 1)) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="winner-username">
                                                <a href="profile.php?id=<?= $winner['user_id'] ?>" class="user-link">
                                                    <?= htmlspecialchars($winner['username']) ?>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="card dark-card mb-4">
                    <div class="card-header">
                        <h5 class="card-title"><i class="fas fa-users me-2"></i>Katılımcılar (<?= count($participants) ?>)</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($participants)): ?>
                            <div class="text-center p-4 text-muted">
                                <i class="fas fa-info-circle me-2"></i> Henüz katılımcı yok.
                            </div>
                        <?php else: ?>
                            <div class="participants-list">
                                <?php foreach ($participants as $participant): ?>
                                    <div class="participant-item">
                                        <div class="participant-avatar">
                                            <?php if (!empty($participant['avatar'])): ?>
                                                <img src="<?= htmlspecialchars($participant['avatar']) ?>" alt="Avatar">
                                            <?php else: ?>
                                                <div class="avatar-placeholder">
                                                    <?= strtoupper(substr($participant['username'], 0, 1)) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="participant-info">
                                            <div class="participant-name">
                                                <a href="profile.php?id=<?= $participant['user_id'] ?>" class="user-link">
                                                    <?= htmlspecialchars($participant['username']) ?>
                                                </a>
                                            </div>
                                            <div class="participant-time">
                                                <i class="fas fa-clock me-1"></i> <?= timeAgo($participant['joined_at']) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="card dark-card">
                    <div class="card-header">
                        <h5 class="card-title"><i class="fas fa-info-circle me-2"></i>Çekiliş Bilgileri</h5>
                    </div>
                    <div class="card-body">
                        <ul class="raffle-info-list">
                            <li>
                                <i class="fas fa-users me-2"></i>
                                Her çekilişe sadece bir kez katılabilirsiniz.
                            </li>
                            <li>
                                <i class="fas fa-random me-2"></i>
                                Kazanan tamamen rastgele seçilir.
                            </li>
                            <li>
                                <i class="fas fa-clock me-2"></i>
                                Çekiliş sonuçları bitiş tarihinde açıklanır.
                            </li>
                            <li>
                                <i class="fas fa-coins me-2"></i>
                                Kazanılan TP'ler otomatik olarak hesabınıza eklenir.
                            </li>
                        </ul>
                        
                        <div class="text-center mt-3">
                            <a href="raffle.php" class="btn btn-outline-light">
                                <i class="fas fa-list me-1"></i> Tüm Çekilişler
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- Raffle List View -->
    <div class="raffle-list-header mb-4">
        <h1><i class="fas fa-gift me-2"></i>Çekilişler</h1>
        <p class="lead">Randy Casino'nun çekilişlerine katılın ve TP kazanma şansı yakalayın!</p>
    </div>
    
    <!-- Active Raffles -->
    <?php if (!empty($activeRaffles)): ?>
        <h2 class="section-title"><i class="fas fa-fire me-2"></i>Aktif Çekilişler</h2>
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4 mb-5">
            <?php foreach ($activeRaffles as $raffle): ?>
                <div class="col">
                    <div class="card raffle-card">
                        <div class="raffle-status">
                            <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Aktif</span>
                        </div>
                        <div class="raffle-image">
                            <i class="fas fa-gift"></i>
                        </div>
                        <div class="raffle-details">
                            <h5 class="card-title"><?= htmlspecialchars($raffle['title']) ?></h5>
                            <div class="raffle-prize">
                                <i class="fas fa-coins me-1 text-warning"></i> <?= formatTP($raffle['tp_prize']) ?> TP
                            </div>
                            <div class="raffle-info">
                                <div class="raffle-participants">
                                    <i class="fas fa-users me-1"></i> <?= $raffle['current_participants'] ?>/<?= $raffle['max_participants'] ?>
                                </div>
                                <div class="raffle-time">
                                    <i class="fas fa-clock me-1"></i> <?= timeAgo($raffle['end_date']) ?>
                                </div>
                            </div>
                            <div class="progress mt-2">
                                <div class="progress-bar bg-success" role="progressbar" 
                                     style="width: <?= ($raffle['current_participants'] / $raffle['max_participants']) * 100 ?>%" 
                                     aria-valuenow="<?= $raffle['current_participants'] ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="<?= $raffle['max_participants'] ?>">
                                </div>
                            </div>
                        </div>
                        <div class="raffle-footer">
                            <small class="text-muted">
                                <i class="fas fa-calendar-check me-1"></i> <?= formatDate($raffle['end_date']) ?>
                            </small>
                            <a href="raffle.php?id=<?= $raffle['id'] ?>" class="btn btn-sm btn-success">
                                <i class="fas fa-ticket-alt me-1"></i> Katıl
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-info mb-5">
            <i class="fas fa-info-circle me-2"></i> Şu anda aktif çekiliş bulunmamaktadır. Daha sonra tekrar kontrol edin.
        </div>
    <?php endif; ?>
    
    <!-- Pending Raffles -->
    <?php if (!empty($pendingRaffles)): ?>
        <h2 class="section-title"><i class="fas fa-clock me-2"></i>Yaklaşan Çekilişler</h2>
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4 mb-5">
            <?php foreach ($pendingRaffles as $raffle): ?>
                <div class="col">
                    <div class="card raffle-card">
                        <div class="raffle-status">
                            <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>Yakında</span>
                        </div>
                        <div class="raffle-image">
                            <i class="fas fa-gift"></i>
                        </div>
                        <div class="raffle-details">
                            <h5 class="card-title"><?= htmlspecialchars($raffle['title']) ?></h5>
                            <div class="raffle-prize">
                                <i class="fas fa-coins me-1 text-warning"></i> <?= formatTP($raffle['tp_prize']) ?> TP
                            </div>
                            <div class="raffle-info">
                                <div class="raffle-participants">
                                    <i class="fas fa-users me-1"></i> <?= $raffle['max_participants'] ?> Kişilik
                                </div>
                                <div class="raffle-time">
                                    <i class="fas fa-calendar-plus me-1"></i> <?= formatDate($raffle['start_date']) ?>
                                </div>
                            </div>
                        </div>
                        <div class="raffle-footer">
                            <small class="text-muted">
                                <i class="fas fa-calendar-check me-1"></i> <?= formatDate($raffle['end_date']) ?>
                            </small>
                            <a href="raffle.php?id=<?= $raffle['id'] ?>" class="btn btn-sm btn-outline-light">
                                <i class="fas fa-info-circle me-1"></i> Detaylar
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <!-- Completed Raffles -->
    <?php if (!empty($completedRaffles)): ?>
        <h2 class="section-title"><i class="fas fa-flag-checkered me-2"></i>Tamamlanan Çekilişler</h2>
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
            <?php foreach (array_slice($completedRaffles, 0, 6) as $raffle): ?>
                <div class="col">
                    <div class="card raffle-card completed-raffle">
                        <div class="raffle-status">
                            <span class="badge bg-secondary"><i class="fas fa-flag-checkered me-1"></i>Tamamlandı</span>
                        </div>
                        <div class="raffle-image">
                            <i class="fas fa-gift"></i>
                        </div>
                        <div class="raffle-details">
                            <h5 class="card-title"><?= htmlspecialchars($raffle['title']) ?></h5>
                            <div class="raffle-prize">
                                <i class="fas fa-coins me-1 text-warning"></i> <?= formatTP($raffle['tp_prize']) ?> TP
                            </div>
                            <div class="raffle-info">
                                <div class="raffle-participants">
                                    <i class="fas fa-users me-1"></i> <?= $raffle['current_participants'] ?> Katılımcı
                                </div>
                                <div class="raffle-time">
                                    <i class="fas fa-calendar-times me-1"></i> <?= formatDate($raffle['end_date']) ?>
                                </div>
                            </div>
                        </div>
                        <div class="raffle-footer">
                            <small class="text-muted">
                                <i class="fas fa-trophy me-1"></i> Sonuçlandı
                            </small>
                            <a href="raffle.php?id=<?= $raffle['id'] ?>" class="btn btn-sm btn-outline-light">
                                <i class="fas fa-eye me-1"></i> Sonuçlar
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if (count($completedRaffles) > 6): ?>
            <div class="text-center mt-4">
                <button class="btn btn-outline-light show-more-raffles">
                    <i class="fas fa-plus-circle me-2"></i>Daha Fazla Göster
                </button>
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<style>
/* Single Raffle View Styling */
.raffle-single .raffle-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.raffle-single .raffle-title {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 0;
    color: var(--green-primary);
}

.raffle-details {
    display: flex;
    gap: 20px;
}

.raffle-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, var(--casino-purple), var(--casino-blue));
    border-radius: 50%;
    color: white;
    flex-shrink: 0;
}

.raffle-info {
    flex: 1;
}

.raffle-prize, .raffle-participants {
    font-size: 18px;
    margin-bottom: 10px;
}

.raffle-prize strong, .raffle-participants strong {
    color: var(--green-primary);
}

.raffle-dates {
    display: flex;
    gap: 20px;
    color: var(--text-muted);
    font-size: 14px;
    margin-top: 10px;
}

.raffle-description h5 {
    color: var(--green-primary);
    font-weight: 600;
    margin-bottom: 15px;
}

.description-content {
    background-color: var(--dark-accent);
    line-height: 1.6;
}

.winners-list {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-top: 15px;
}

.winner-item {
    display: flex;
    align-items: center;
    background-color: var(--dark-accent);
    padding: 10px;
    border-radius: 6px;
    gap: 10px;
}

.winner-position {
    font-weight: 700;
    color: var(--gold);
    font-size: 18px;
}

.winner-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    overflow: hidden;
}

.winner-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.participants-list {
    max-height: 400px;
    overflow-y: auto;
}

.participant-item {
    display: flex;
    align-items: center;
    padding: 12px 15px;
    border-bottom: 1px solid var(--border-color);
    transition: background-color var(--transition-fast);
}

.participant-item:last-child {
    border-bottom: none;
}

.participant-item:hover {
    background-color: var(--dark-accent);
}

.participant-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    overflow: hidden;
    margin-right: 12px;
}

.participant-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.participant-info {
    flex: 1;
}

.participant-name {
    font-weight: 600;
    margin-bottom: 2px;
}

.participant-time {
    font-size: 12px;
    color: var(--text-muted);
}

.raffle-info-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.raffle-info-list li {
    margin-bottom: 12px;
    color: var(--text-muted);
    display: flex;
    align-items: flex-start;
}

.raffle-info-list li i {
    color: var(--green-primary);
    margin-top: 3px;
    flex-shrink: 0;
}

/* Raffle List View Styling */
.raffle-list-header {
    text-align: center;
}

.raffle-list-header h1 {
    color: var(--green-primary);
    font-weight: 700;
    margin-bottom: 15px;
}

.section-title {
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 20px;
    color: var(--text-light);
    border-left: 4px solid var(--green-primary);
    padding-left: 15px;
}

.raffle-card {
    height: 100%;
    transition: transform var(--transition-normal), box-shadow var(--transition-normal);
    position: relative;
    overflow: hidden;
}

.raffle-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-md);
}

.raffle-card .raffle-status {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 2;
}

.raffle-card .raffle-image {
    height: 150px;
    background: linear-gradient(45deg, var(--casino-purple), var(--casino-blue));
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 3rem;
}

.raffle-card .raffle-details {
    padding: 16px;
    display: block;
}

.raffle-card .card-title {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 10px;
    color: var(--text-light);
    height: 2.5rem;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}

.raffle-card .raffle-prize {
    font-weight: 700;
    font-size: 16px;
    margin-bottom: 10px;
    color: var(--green-primary);
}

.raffle-card .raffle-info {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    font-size: 14px;
    color: var(--text-muted);
}

.raffle-card .raffle-footer {
    background-color: var(--dark-accent);
    padding: 12px 16px;
    border-top: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.completed-raffle {
    opacity: 0.8;
}

.completed-raffle .raffle-image {
    filter: grayscale(30%);
}

@media (max-width: 767.98px) {
    .raffle-details {
        flex-direction: column;
        gap: 20px;
    }
    
    .raffle-icon {
        margin: 0 auto;
    }
    
    .raffle-dates {
        flex-direction: column;
        gap: 5px;
    }
    
    .raffle-single .raffle-header {
        flex-direction: column;
        text-align: center;
    }
    
    .raffle-status {
        margin-top: 10px;
    }
    
    .raffle-action form {
        display: flex;
        justify-content: center;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Show more raffles functionality
    const showMoreBtn = document.querySelector('.show-more-raffles');
    if (showMoreBtn) {
        showMoreBtn.addEventListener('click', function() {
            // In a real implementation, this would load more raffles via AJAX
            this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Yükleniyor...';
            setTimeout(() => {
                this.innerHTML = '<i class="fas fa-check me-2"></i>Tüm çekilişler yüklendi';
                this.disabled = true;
            }, 1000);
        });
    }
});
</script>

<?php
// Include footer
include_once 'includes/footer.php';
?>
