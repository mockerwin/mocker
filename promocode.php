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
    header('Location: ' . SITE_URL . '/login.php?redirect=promocode');
    exit;
}

$pageTitle = 'Promo Kod';
$successMessage = null;
$errorMessage = null;

$userId = Auth::user()['id'];
$db = DB::getInstance();

// Handle promo code form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_code'])) {
    // Check CSRF token
    if (!verifyCsrf()) {
        $errorMessage = 'Güvenlik doğrulaması başarısız oldu. Lütfen sayfayı yenileyip tekrar deneyin.';
    } else {
        $code = strtoupper(cleanInput($_POST['code'] ?? ''));
        
        if (empty($code)) {
            $errorMessage = 'Lütfen bir promo kod girin.';
        } else {
            $result = applyPromoCode($code, $userId);
            
            if ($result) {
                $successMessage = 'Tebrikler! ' . formatTP($result['amount']) . ' TP kazandınız.';
                
                // Update user TP in session
                $query = "SELECT tp FROM users WHERE id = ?";
                $db->query($query, [$userId]);
                $updatedTp = $db->fetch()['tp'];
                Auth::user()['tp'] = $updatedTp;
            } else {
                $errorMessage = 'Geçersiz veya kullanılmış promo kod.';
            }
        }
    }
}

// Get user's promo code history
$query = "SELECT p.code, p.tp_amount, pcu.created_at 
          FROM promo_code_uses pcu 
          JOIN promo_codes p ON pcu.promo_code_id = p.id 
          WHERE pcu.user_id = ? 
          ORDER BY pcu.created_at DESC
          LIMIT 20";
$db->query($query, [$userId]);
$promoHistory = $db->fetchAll();

// Include header
include_once 'includes/header.php';
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <h1 class="page-title mb-4">
                <i class="fas fa-ticket-alt me-2"></i>Promo Kod
            </h1>

            <div class="card dark-card mb-4">
                <div class="card-body">
                    <?php if ($successMessage): ?>
                        <div class="alert alert-success animate-pulse">
                            <i class="fas fa-check-circle me-2"></i><?= $successMessage ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($errorMessage): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle me-2"></i><?= $errorMessage ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-lg-6 mb-4 mb-lg-0">
                            <div class="promo-card">
                                <div class="promo-icon">
                                    <i class="fas fa-ticket-alt"></i>
                                </div>
                                <h5>Promo Kod Kullan</h5>
                                <form action="" method="post" class="needs-validation" novalidate>
                                    <?= csrfField() ?>
                                    <div class="mb-3 mt-3">
                                        <label for="code" class="form-label">Kodunuzu Girin</label>
                                        <input type="text" class="form-control" id="code" name="code" placeholder="Örn: WELCOME500" required autocomplete="off">
                                        <div class="invalid-feedback">
                                            Lütfen bir promo kod girin.
                                        </div>
                                    </div>
                                    <button type="submit" name="redeem_code" class="btn btn-success w-100">
                                        <i class="fas fa-check-circle me-2"></i>Kodu Kullan
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <div class="col-lg-6">
                            <div class="promo-info">
                                <h5><i class="fas fa-info-circle me-2"></i>Kod Bilgileri</h5>
                                <div class="alert alert-info">
                                    <p>Promo kodlar size ekstra TP kazandırır. Bu kodları şu kanallardan edinebilirsiniz:</p>
                                    <ul class="mb-0">
                                        <li>Sosyal medya hesaplarımız</li>
                                        <li>Canlı yayınlar</li>
                                        <li>Özel etkinlikler</li>
                                        <li>Ortaklık kampanyaları</li>
                                    </ul>
                                </div>
                                
                                <div class="promo-rules">
                                    <h6><i class="fas fa-list me-2"></i>Promo Kod Kuralları:</h6>
                                    <ul class="list-unstyled">
                                        <li><i class="fas fa-check me-2 text-success"></i>Her kod sadece bir kez kullanılabilir.</li>
                                        <li><i class="fas fa-check me-2 text-success"></i>Kodlar büyük/küçük harfe duyarlı değildir.</li>
                                        <li><i class="fas fa-check me-2 text-success"></i>Bazı kodlar sınırlı sürelidir.</li>
                                        <li><i class="fas fa-check me-2 text-success"></i>Bazı kodlar sınırlı sayıda kullanılabilir.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if (!empty($promoHistory)): ?>
            <div class="card dark-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-history me-2"></i>Kod Geçmişi
                    </h5>
                    <span class="badge bg-primary"><?= count($promoHistory) ?> Kayıt</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Kod</th>
                                    <th>Miktar</th>
                                    <th>Tarih</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($promoHistory as $promo): ?>
                                    <tr>
                                        <td><code class="promo-code"><?= htmlspecialchars($promo['code']) ?></code></td>
                                        <td><span class="text-success fw-bold"><?= formatTP($promo['tp_amount']) ?> TP</span></td>
                                        <td><?= formatDate($promo['created_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.page-title {
    color: var(--green-primary);
    font-weight: 700;
    margin-bottom: 1.5rem;
}

.promo-card {
    background-color: var(--dark-accent);
    padding: 2rem;
    border-radius: 8px;
    text-align: center;
    height: 100%;
    box-shadow: var(--shadow-sm);
    transition: transform var(--transition-normal), box-shadow var(--transition-normal);
}

.promo-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-md);
}

.promo-icon {
    font-size: 3rem;
    color: var(--green-primary);
    margin-bottom: 1rem;
}

.promo-card h5 {
    color: var(--green-primary);
    font-weight: 600;
    margin-bottom: 1.5rem;
}

.promo-info {
    height: 100%;
    display: flex;
    flex-direction: column;
}

.promo-info h5 {
    color: var(--green-primary);
    font-weight: 600;
    margin-bottom: 1rem;
}

.promo-rules {
    background-color: var(--dark-accent);
    padding: 1.5rem;
    border-radius: 8px;
    margin-top: 1rem;
    box-shadow: var(--shadow-sm);
    transition: transform var(--transition-normal);
}

.promo-rules:hover {
    transform: translateY(-3px);
}

.promo-rules h6 {
    color: var(--text-light);
    font-weight: 600;
    margin-bottom: 1rem;
}

.promo-rules li {
    margin-bottom: 0.5rem;
}

.promo-code {
    background-color: var(--dark-bg);
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-family: 'Courier New', monospace;
    font-weight: bold;
    letter-spacing: 1px;
}

.table {
    margin-bottom: 0;
}

.table th {
    background-color: var(--dark-accent);
    color: var(--text-light);
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.85rem;
    letter-spacing: 1px;
}

.table td {
    vertical-align: middle;
}

@media (max-width: 767.98px) {
    .promo-card, .promo-rules {
        padding: 1.5rem;
    }
    
    .page-title {
        font-size: 1.75rem;
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
});
</script>

<?php
// Include footer
include_once 'includes/footer.php';
?>
