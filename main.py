import os
import datetime
import random
import string
from datetime import timedelta

from flask import Flask, render_template, session, redirect, url_for, request, flash, jsonify, send_from_directory
from flask_sqlalchemy import SQLAlchemy
from flask_migrate import Migrate
from werkzeug.security import generate_password_hash, check_password_hash
import email_validator

# Initialize Flask app
app = Flask(__name__)
app.secret_key = os.environ.get("SESSION_SECRET", "dev-secret-key")

# Configure database
db_url = os.environ.get("DATABASE_URL")
# Eğer URL "postgres://" ile başlıyorsa, "postgresql://" ile değiştir
if db_url and db_url.startswith("postgres://"):
    db_url = db_url.replace("postgres://", "postgresql://", 1)

app.config["SQLALCHEMY_DATABASE_URI"] = db_url
app.config["SQLALCHEMY_TRACK_MODIFICATIONS"] = False
app.config["SQLALCHEMY_ENGINE_OPTIONS"] = {
    "pool_pre_ping": True,
    "pool_recycle": 300,
    "connect_args": {
        "sslmode": "require"
    }
}

# Initialize database
db = SQLAlchemy(app)
migrate = Migrate(app, db)

# Define models first (rather than importing)
class User(db.Model):
    __tablename__ = 'users'
    
    id = db.Column(db.Integer, primary_key=True)
    username = db.Column(db.String(64), unique=True, nullable=False)
    email = db.Column(db.String(120), unique=True, nullable=False)
    password_hash = db.Column(db.String(256), nullable=False)
    avatar = db.Column(db.String(255), nullable=True)
    role = db.Column(db.String(20), default='admin')  # Admin yetkisi için default değeri değiştirildi
    tokens = db.Column(db.Integer, default=1000)  # Başlangıç olarak 1000 token
    is_active = db.Column(db.Boolean, default=True)
    created_at = db.Column(db.DateTime, default=datetime.datetime.utcnow)
    last_login = db.Column(db.DateTime, nullable=True)
    # Seviye sistemi
    level = db.Column(db.Integer, default=1)  # Kullanıcı seviyesi
    level_tier = db.Column(db.String(20), default='bronze')  # Seviye kademesi (bronze, silver, gold, diamond)
    experience_points = db.Column(db.Integer, default=0)  # XP puanları
    wallet_address = db.Column(db.String(255), nullable=True)  # Cüzdan adresi
    wallet_type = db.Column(db.String(50), nullable=True)  # Cüzdan tipi
    wallet_verified = db.Column(db.Boolean, default=False)  # Cüzdan doğrulandı mı
    raffle_wins = db.Column(db.Integer, default=0)  # Kazanılan çekiliş sayısı
    total_earned = db.Column(db.Float, default=0.0)  # Toplam kazanılan ödül

class TokenHistory(db.Model):
    __tablename__ = 'token_history'
    
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('users.id'), nullable=False)
    amount = db.Column(db.Integer, nullable=False)
    description = db.Column(db.String(255), nullable=False)
    transaction_type = db.Column(db.String(20), nullable=False)  # 'credit', 'debit'
    created_at = db.Column(db.DateTime, default=datetime.datetime.utcnow)
    
    user = db.relationship('User', backref=db.backref('token_history', lazy=True))

class Promotion(db.Model):
    __tablename__ = 'promotions'
    
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('users.id'), nullable=False)
    code = db.Column(db.String(20), unique=True, nullable=False)
    tokens = db.Column(db.Integer, nullable=False)
    
    # Yeni eklenen alanlar
    max_uses = db.Column(db.Integer, default=None)  # Maksimum kullanım sayısı (None = sınırsız)
    use_count = db.Column(db.Integer, default=0)    # Kaç kez kullanıldığı
    expires_at = db.Column(db.DateTime, default=None)  # Kod geçerlilik süresi (None = süresiz)
    
    is_used = db.Column(db.Boolean, default=False)  # Tek kullanımlık kodlar için
    used_by = db.Column(db.Integer, nullable=True)  # Tek kullanımlık kodlar için kullanıcı ID
    created_at = db.Column(db.DateTime, default=datetime.datetime.utcnow)
    used_at = db.Column(db.DateTime, nullable=True)
    
    user = db.relationship('User', backref=db.backref('promotions', lazy=True), foreign_keys=[user_id])

class Raffle(db.Model):
    __tablename__ = 'raffles'
    
    id = db.Column(db.Integer, primary_key=True)
    title = db.Column(db.String(100), nullable=False)
    description = db.Column(db.Text, nullable=True)
    image = db.Column(db.String(255), nullable=True)
    prize = db.Column(db.String(100), nullable=False)
    token_cost = db.Column(db.Integer, nullable=False)
    start_date = db.Column(db.DateTime, nullable=False)
    end_date = db.Column(db.DateTime, nullable=False)
    winner_id = db.Column(db.Integer, nullable=True)
    status = db.Column(db.String(20), default='active')  # 'active', 'completed', 'cancelled'
    created_at = db.Column(db.DateTime, default=datetime.datetime.utcnow)

class RaffleEntry(db.Model):
    __tablename__ = 'raffle_entries'
    
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('users.id'), nullable=False)
    raffle_id = db.Column(db.Integer, db.ForeignKey('raffles.id'), nullable=False)
    entries = db.Column(db.Integer, default=1)
    created_at = db.Column(db.DateTime, default=datetime.datetime.utcnow)

class ChatMessage(db.Model):
    __tablename__ = 'chat_messages'
    
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('users.id'), nullable=False)
    message = db.Column(db.Text, nullable=False)
    created_at = db.Column(db.DateTime, default=datetime.datetime.utcnow)
    
    user = db.relationship('User', backref=db.backref('messages', lazy=True))

class UserToken(db.Model):
    __tablename__ = 'user_tokens'
    
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('users.id'), nullable=False)
    token = db.Column(db.String(255), nullable=False)
    type = db.Column(db.String(20), nullable=False)  # 'session', 'reset', 'activation'
    expires_at = db.Column(db.DateTime, nullable=False)
    created_at = db.Column(db.DateTime, default=datetime.datetime.utcnow)
    
    user = db.relationship('User', backref=db.backref('user_tokens', lazy=True))

# Çekilişler sayfası
@app.route('/raffles')
def raffles():
    # Check if user is logged in
    if 'user_id' in session:
        user = User.query.get(session['user_id'])
    else:
        user = None
    
    # Fetch raffles from database
    active_raffles = Raffle.query.filter_by(status='active').all()
    completed_raffles = Raffle.query.filter_by(status='completed').order_by(Raffle.end_date.desc()).limit(5).all()
    
    return render_template('raffles.html', 
                           user=user, 
                           active_raffles=active_raffles, 
                           completed_raffles=completed_raffles)

# Çekilişe katıl
@app.route('/raffles/<int:raffle_id>/join', methods=['POST'])
def join_raffle(raffle_id):
    # Kullanıcı giriş yapmış mı kontrol et
    if 'user_id' not in session:
        flash('Çekilişe katılmak için giriş yapmalısınız', 'warning')
        return redirect(url_for('login'))
    
    # Kullanıcı ve çekiliş bilgilerini al
    user = User.query.get(session['user_id'])
    raffle = Raffle.query.get_or_404(raffle_id)
    
    # Çekiliş aktif mi?
    if raffle.status != 'active':
        flash('Bu çekiliş artık aktif değil', 'danger')
        return redirect(url_for('raffles'))
    
    # Kullanıcının yeterli tokeni var mı?
    if user.tokens < raffle.token_cost:
        flash(f'Yeterli token yok. Gerekli: {raffle.token_cost}, Mevcut: {user.tokens}', 'danger')
        return redirect(url_for('raffles'))
    
    # Kullanıcının çekilişe katılımını kontrol et
    entry = RaffleEntry.query.filter_by(user_id=user.id, raffle_id=raffle.id).first()
    
    if entry:
        # Katılım sayısını artır
        entry.entries += 1
    else:
        # Yeni katılım oluştur
        entry = RaffleEntry(user_id=user.id, raffle_id=raffle.id, entries=1)
        db.session.add(entry)
    
    # Tokenleri azalt
    user.tokens -= raffle.token_cost
    
    # Token geçmişi oluştur
    token_history = TokenHistory(
        user_id=user.id,
        amount=raffle.token_cost,
        description=f'Çekilişe katılım: {raffle.title}',
        transaction_type='debit'
    )
    
    db.session.add(token_history)
    db.session.commit()
    
    flash('Çekilişe başarıyla katıldınız', 'success')
    
    # Randy çekilişi ise Randy sayfasına yönlendir
    if raffle.is_randy:
        return redirect(url_for('randy_raffle', raffle_id=raffle.id))
    
    return redirect(url_for('raffles'))

# Randy çekilişi sayfası
@app.route('/randy/<int:raffle_id>')
def randy_raffle(raffle_id):
    # Çekiliş bilgilerini al
    raffle = Raffle.query.filter_by(id=raffle_id, is_randy=True).first_or_404()
    
    # Kullanıcı bilgisi
    if 'user_id' in session:
        user = User.query.get(session['user_id'])
        # Kullanıcının bu çekilişe katılımını kontrol et
        user_entry = RaffleEntry.query.filter_by(user_id=user.id, raffle_id=raffle.id).first()
        user_entries = user_entry.entries if user_entry else 0
    else:
        user = None
        user_entries = 0
    
    # Çekiliş katılımcılarını al
    participants_query = db.session.query(
        User.id, User.username, RaffleEntry.entries
    ).join(
        RaffleEntry, User.id == RaffleEntry.user_id
    ).filter(
        RaffleEntry.raffle_id == raffle.id
    ).order_by(
        RaffleEntry.created_at.desc()
    )
    
    participants = [
        {"id": p.id, "username": p.username, "entries": p.entries}
        for p in participants_query.all()
    ]
    
    # Toplam katılımcı sayısı ve katılım sayısı
    participant_count = len(participants)
    total_entries = sum(p["entries"] for p in participants)
    
    # En çok kazanan kullanıcılar
    top_winners = User.query.filter(User.raffle_wins > 0).order_by(User.raffle_wins.desc()).limit(5).all()
    
    # Tamamlanan çekilişler
    completed_raffles = Raffle.query.filter_by(
        status='completed', is_randy=True
    ).order_by(
        Raffle.end_date.desc()
    ).limit(5).all()
    
    # JSON kazanan listesini parse et
    def get_winners(winners_json):
        if not winners_json:
            return []
        
        import json
        winner_ids = json.loads(winners_json)
        return User.query.filter(User.id.in_(winner_ids)).all()
    
    # Kullanıcının kazanan olup olmadığını kontrol et
    def is_winner(user_id, winners_json):
        if not winners_json:
            return False
        
        import json
        winner_ids = json.loads(winners_json)
        return user_id in winner_ids
    
    return render_template('randy.html',
                          raffle=raffle,
                          user=user,
                          user_entries=user_entries,
                          participants=participants,
                          participant_count=participant_count,
                          total_entries=total_entries,
                          top_winners=top_winners,
                          completed_raffles=completed_raffles,
                          get_winners=get_winners,
                          is_winner=is_winner)

# Import admin blueprint
from admin import init_app
init_app(app)

# Create database tables
with app.app_context():
    db.create_all()

# Add context processor to inject datetime into all templates
@app.context_processor
def inject_now():
    return {'now': datetime.datetime.utcnow()}

# API endpoint for getting chat messages
@app.route('/api/chat/messages')
def api_chat_messages():
    limit = request.args.get('limit', 300, type=int)
    
    # For testing, you can use a static JSON file
    # In a real application, fetch from database
    try:
        return send_from_directory('static/api', 'chat_messages.json')
    except:
        # Fallback to database
        messages = []
        if 'user_id' in session:
            user = User.query.get(session['user_id'])
            # Fetch messages from database
            chat_messages = ChatMessage.query.order_by(ChatMessage.created_at.desc()).limit(limit).all()
            
            messages = [
                {
                    "id": msg.id,
                    "user_id": msg.user_id,
                    "username": msg.user.username,
                    "role": msg.user.role,
                    "message": msg.message,
                    "created_at": msg.created_at.isoformat()
                }
                for msg in chat_messages
            ]
            
            return jsonify({
                "success": True,
                "messages": messages,
                "online_count": User.query.filter_by(is_active=True).count()
            })
        else:
            return jsonify({
                "success": False,
                "error": "Kullanıcı oturumu bulunamadı"
            })

# API endpoint for sending chat messages
@app.route('/api/chat/send', methods=['POST'])
def api_send_chat_message():
    # Development/test mod için
    if app.debug:
        # JSON dosyasından test yanıtını al (varsa)
        try:
            return send_from_directory('static/api', 'send_message.json')
        except:
            pass
    
    if 'user_id' not in session:
        return jsonify({
            "success": False,
            "error": "Mesaj göndermek için giriş yapmalısınız"
        })
    
    user = User.query.get(session['user_id'])
    if not user:
        return jsonify({
            "success": False,
            "error": "Kullanıcı bulunamadı"
        })
    
    data = request.json
    message_text = data.get('message', '').strip()
    
    if not message_text:
        return jsonify({
            "success": False,
            "error": "Mesaj boş olamaz"
        })
    
    # Mesajı veritabanına kaydet
    chat_message = ChatMessage(
        user_id=user.id,
        message=message_text
    )
    
    db.session.add(chat_message)
    db.session.commit()
    
    # Websocket üzerinden mesajı diğer kullanıcılara gönder
    # (Bu işlemi websocket sunucu yapacak)
    
    return jsonify({
        "success": True,
        "message_id": chat_message.id,
        "username": user.username,
        "user_id": user.id,
        "user_role": user.role,
        "message": message_text,
        "timestamp": chat_message.created_at.strftime('%H:%M')
    })

# Sound effects for chat
@app.route('/sounds/<filename>')
def get_sound(filename):
    return send_from_directory('static/sounds', filename)

# Add context processor to inject user statistics
@app.context_processor
def inject_stats():
    # Burada veritabanından gerçek değerler alınıyor
    try:
        online_users = User.query.filter_by(is_active=True).count()
        total_users = User.query.count()
        offline_users = total_users - online_users
        active_raffles = Raffle.query.filter_by(status='active').count()
        
        return {
            'online_users_count': online_users,
            'total_users_count': total_users,
            'offline_users_count': offline_users
        }
    except Exception as e:
        print(f"Error getting stats: {e}")
        # Fallback değerler (veritabanına erişilemezse)
        return {
            'online_users_count': 0,
            'total_users_count': 0,
            'offline_users_count': 0
        }

# Routes
@app.route('/')
def index():
    # Eğer kullanıcı giriş yapmışsa user değişkenini geçir
    if 'user_id' in session:
        user = User.query.get(session['user_id'])
        return render_template('index.html', user=user)
    # Giriş yapmamışsa user=None geçir
    return render_template('index.html', user=None)

@app.route('/login', methods=['GET', 'POST'])
def login():
    if request.method == 'POST':
        email = request.form.get('email')
        password = request.form.get('password')
        
        # Input validation
        if not email or not password:
            flash('Please fill in all fields', 'danger')
            return render_template('login.html')
        
        # Find user by email
        user = User.query.filter_by(email=email).first()
        
        if user and check_password_hash(user.password_hash, password):
            # Set session
            session['user_id'] = user.id
            session['username'] = user.username
            
            flash('Login successful!', 'success')
            return redirect(url_for('index'))
        else:
            flash('Invalid email or password', 'danger')
    
    return render_template('login.html')

@app.route('/register', methods=['GET', 'POST'])
def register():
    if request.method == 'POST':
        username = request.form.get('username')
        email = request.form.get('email')
        password = request.form.get('password')
        confirm_password = request.form.get('confirm_password')
        
        # Input validation
        if not username or not email or not password or not confirm_password:
            flash('Please fill in all fields', 'danger')
            return render_template('register.html')
        
        if password != confirm_password:
            flash('Passwords do not match', 'danger')
            return render_template('register.html')
        
        # Check if username or email already exists
        existing_user = User.query.filter((User.username == username) | (User.email == email)).first()
        if existing_user:
            flash('Username or email already exists', 'danger')
            return render_template('register.html')
        
        # Create new user
        new_user = User(
            username=username, 
            email=email,
            password_hash=generate_password_hash(password)
        )
        
        db.session.add(new_user)
        db.session.commit()
        
        flash('Registration successful! You can now log in.', 'success')
        return redirect(url_for('login'))
    
    return render_template('register.html')

@app.route('/logout')
def logout():
    # Clear session
    session.clear()
    flash('You have been logged out', 'info')
    return redirect(url_for('login'))

@app.route('/profile')
def profile():
    # Check if user is logged in
    if 'user_id' not in session:
        flash('Profilinizi görüntülemek için giriş yapın', 'warning')
        return redirect(url_for('login'))
    
    # Get user data
    user = User.query.get(session['user_id'])
    if not user:
        session.clear()
        flash('Kullanıcı bulunamadı', 'danger')
        return redirect(url_for('login'))
    
    # Get token history
    token_history = TokenHistory.query.filter_by(user_id=user.id).order_by(TokenHistory.created_at.desc()).limit(20).all()
    
    # Get raffle entries
    raffle_entries = db.session.query(
        RaffleEntry, Raffle
    ).join(
        Raffle, RaffleEntry.raffle_id == Raffle.id
    ).filter(
        RaffleEntry.user_id == user.id
    ).order_by(
        RaffleEntry.created_at.desc()
    ).all()
    
    # Format entries for template
    formatted_entries = []
    for entry, raffle in raffle_entries:
        formatted_entries.append({
            'entries': entry.entries,
            'created_at': entry.created_at,
            'raffle': raffle
        })
    
    # Get raffle wins (for any raffles where user is in the winners list)
    raffle_wins = []
    total_earned = 0.0
    raffle_wins_count = 0
    completed_raffles = Raffle.query.filter_by(status='completed').all()
    
    for raffle in completed_raffles:
        if raffle.winners:
            import json
            winner_ids = json.loads(raffle.winners)
            
            if user.id in winner_ids:
                raffle_wins_count += 1
                # Find token history entry for the win
                token_reward = TokenHistory.query.filter(
                    TokenHistory.user_id == user.id,
                    TokenHistory.description.like(f'%Çekiliş ödülü: {raffle.title}%'),
                    TokenHistory.transaction_type == 'credit'
                ).first()
                
                reward_amount = token_reward.amount if token_reward else 0
                total_earned += reward_amount
                
                raffle_wins.append({
                    'raffle': raffle,
                    'token_reward': reward_amount,
                    'is_claimed': user.wallet_verified
                })
    
    # Helper function to check if user is a winner
    def is_winner(user_id, winners_json):
        if not winners_json:
            return False
        
        import json
        winner_ids = json.loads(winners_json)
        return user_id in winner_ids
    
    # Update user model with counts if necessary
    if hasattr(user, 'raffle_wins') and user.raffle_wins != raffle_wins_count:
        user.raffle_wins = raffle_wins_count
        db.session.commit()
    
    return render_template('profile.html', 
                          user=user,
                          token_history=token_history,
                          raffle_entries=formatted_entries,
                          raffle_wins=raffle_wins,
                          is_winner=is_winner,
                          total_earned=total_earned,
                          now=datetime.datetime.utcnow,
                          timedelta=timedelta)

@app.route('/update-wallet', methods=['POST'])
def update_wallet():
    # Check if user is logged in
    if 'user_id' not in session:
        flash('İşlem için giriş yapın', 'warning')
        return redirect(url_for('login'))
    
    # Get user data
    user = User.query.get(session['user_id'])
    if not user:
        session.clear()
        flash('Kullanıcı bulunamadı', 'danger')
        return redirect(url_for('login'))
    
    # Update wallet information
    wallet_type = request.form.get('wallet_type')
    wallet_address = request.form.get('wallet_address')
    
    if not wallet_type or not wallet_address:
        flash('Lütfen tüm alanları doldurun', 'danger')
        return redirect(url_for('profile'))
    
    # Update user's wallet information
    user.wallet_type = wallet_type
    user.wallet_address = wallet_address
    
    # If it's a first-time wallet setup, mark as unverified
    if not user.wallet_verified:
        user.wallet_verified = False
    
    db.session.commit()
    
    flash('Cüzdan bilgileriniz güncellendi. Doğrulama için bekleyin.', 'success')
    return redirect(url_for('profile'))

@app.route('/promo-kod', methods=['GET', 'POST'])
def promo_kod():
    import datetime
    # Check if user is logged in
    if 'user_id' not in session:
        flash('Promo kod sayfasını görüntülemek için giriş yapın', 'warning')
        return redirect(url_for('login'))
    
    # Get user data
    user = User.query.get(session['user_id'])
    if not user:
        session.clear()
        flash('Kullanıcı bulunamadı', 'danger')
        return redirect(url_for('login'))
    
    if request.method == 'POST':
        action = request.form.get('action')
        
        if action == 'create':
            # Promo kod oluşturma işlemi
            token_amount = request.form.get('token_amount', type=int)
            
            # Token miktarı kontrolü
            if not token_amount or token_amount < 10:
                flash('En az 10 coin paylaşmalısınız', 'danger')
                return redirect(url_for('promo_kod'))
            
            if token_amount > user.tokens:
                flash('Yeterli coin\'e sahip değilsiniz', 'danger')
                return redirect(url_for('promo_kod'))
            
            # Benzersiz kod oluştur (6 karakterli alfanümerik)
            import random
            import string
            import datetime
            
            code = ''.join(random.choices(string.ascii_uppercase + string.digits, k=6))
            
            # Yeni promo kodu oluştur
            new_promo = Promotion(
                user_id=user.id,
                code=code,
                tokens=token_amount,
                created_at=datetime.datetime.utcnow()
            )
            
            # Kullanıcı tokenlerini azalt
            user.tokens -= token_amount
            
            # Token geçmişi oluştur
            token_history = TokenHistory(
                user_id=user.id,
                amount=token_amount,
                description=f'Promo kod oluşturuldu ({code})',
                transaction_type='debit'
            )
            
            # Veritabanına kaydet
            db.session.add(new_promo)
            db.session.add(token_history)
            db.session.commit()
            
            flash(f'Promo kod başarıyla oluşturuldu: {code}', 'success')
            return redirect(url_for('promo_kod'))
            
        elif action == 'use':
            # Promo kod kullanma işlemi
            promo_code = request.form.get('promo_code', '').strip().upper()
            
            if not promo_code:
                flash('Geçerli bir promo kod girin', 'danger')
                return redirect(url_for('promo_kod'))
            
            # Promo kodu kontrol et - Artık çoklu kullanım destekli
            promo = Promotion.query.filter_by(code=promo_code).first()
            
            if not promo:
                flash('Geçersiz promo kod', 'danger')
                return redirect(url_for('promo_kod'))
                
            # Çoklu kullanımlı mı yoksa tek kullanımlı mı kontrol et
            if promo.max_uses is None:
                # Eski sistem - tek kullanımlık kod
                if promo.is_used:
                    flash('Bu promo kod zaten kullanılmış', 'danger')
                    return redirect(url_for('promo_kod'))
            else:
                # Yeni sistem - çoklu kullanımlı kod
                # Kod kullanım limiti kontrolü
                if promo.max_uses > 0 and promo.use_count >= promo.max_uses:
                    flash('Bu promo kod kullanım limitine ulaşmış', 'danger')
                    return redirect(url_for('promo_kod'))
                
                # Bu kullanıcı daha önce kullandı mı kontrol et
                used_promo = Promotion.query.filter_by(code=promo_code, used_by=user.id).first()
                if used_promo:
                    flash('Bu promo kodu daha önce kullandınız', 'danger')
                    return redirect(url_for('promo_kod'))
            
            # Süre kontrolü
            if promo.expires_at and datetime.datetime.utcnow() > promo.expires_at:
                flash('Bu promo kod süresi dolmuş', 'danger')
                return redirect(url_for('promo_kod'))
            
            # Kendine ait promo kodları kullanamaz
            if promo.user_id == user.id:
                flash('Kendi promo kodunuzu kullanamazsınız', 'danger')
                return redirect(url_for('promo_kod'))
            
            # Promo kodu güncelle
            if promo.max_uses is None:
                # Eski sistem - tek kullanımlık
                promo.is_used = True
                promo.used_by = user.id
                promo.used_at = datetime.datetime.utcnow()
            else:
                # Yeni sistem - çoklu kullanımlı
                promo.use_count += 1
                
                # Kullanım kaydı için yeni bir promosyon girişi
                usage_record = Promotion(
                    user_id=promo.user_id,
                    code=promo.code,
                    tokens=promo.tokens,
                    is_used=True,
                    used_by=user.id,
                    used_at=datetime.datetime.utcnow()
                )
                db.session.add(usage_record)
            
            # Kullanıcıya tokenleri ekle
            user.tokens += promo.tokens
            
            # Token geçmişi oluştur
            token_history = TokenHistory(
                user_id=user.id,
                amount=promo.tokens,
                description=f'Promo kod kullanıldı ({promo_code})',
                transaction_type='credit'
            )
            
            # Veritabanına kaydet
            db.session.add(token_history)
            db.session.commit()
            
            flash(f'Promo kod başarıyla kullanıldı: +{promo.tokens} coin', 'success')
            return redirect(url_for('promo_kod'))
    
    # Şimdiki zamanı al
    now = datetime.datetime.utcnow()
    
    # Kullanıcının promo kodlarını getir
    user_promos = Promotion.query.filter_by(user_id=user.id).order_by(Promotion.created_at.desc()).all()
    
    # Kullanıcının kullandığı promo kodları
    used_promos = Promotion.query.filter_by(used_by=user.id).order_by(Promotion.used_at.desc()).all()
    
    return render_template('promo_kod.html', user=user, user_promos=user_promos, used_promos=used_promos, now=now)

@app.route('/daily-reward', methods=['GET', 'POST'])
def daily_reward():
    import datetime
    from datetime import timedelta
    
    if 'user_id' not in session:
        return redirect(url_for('login'))
    
    user = User.query.get(session['user_id'])
    now = datetime.datetime.utcnow()
    today = now.date()
    
    # Günlük ödül değerleri
    rewards = {
        1: 50,   # 1. gün: 50 token
        2: 100,  # 2. gün: 100 token
        3: 150,  # 3. gün: 150 token
        4: 200,  # 4. gün: 200 token
        5: 250,  # 5. gün: 250 token
        6: 300,  # 6. gün: 300 token
        7: 350   # 7. gün: 350 token
    }
    
    # Geçici olarak günlük ödül sistemi için örnek değerler kullanıyoruz
    current_day = 1
    current_streak = 0
    progress_percentage = (current_day / 7) * 100
    can_claim = True
    already_claimed = False
    bonus_claimed = False
    next_reward_time = (now + timedelta(days=1)).strftime('%Y-%m-%d %H:%M:%S')
    leaderboard = []
    
    return render_template(
        'daily_reward.html',
        user=user,
        current_day=current_day,
        current_streak=current_streak,
        progress_percentage=progress_percentage,
        can_claim=can_claim,
        already_claimed=already_claimed,
        bonus_claimed=bonus_claimed,
        next_reward_time=next_reward_time,
        leaderboard=leaderboard,
        rewards=rewards
    )
    
    # Günlük ödül miktarları
    day_rewards = {
        1: 50,    # Gün 1: 50 coin
        2: 75,    # Gün 2: 75 coin
        3: 100,   # Gün 3: 100 coin
        4: 150,   # Gün 4: 150 coin
        5: 200,   # Gün 5: 200 coin
        6: 250,   # Gün 6: 250 coin
        7: 500    # Gün 7: Bonus
    }
    
    # Aktif seriyi kontrol et
    active_series = DailyRewardSeries.query.filter_by(
        user_id=user.id, 
        is_completed=False
    ).order_by(DailyRewardSeries.start_date.desc()).first()
    
    # Ödül geçmişi
    reward_history = DailyReward.query.filter_by(user_id=user.id).order_by(DailyReward.claimed_at.desc()).limit(10).all()
    
    # Liderler tablosu - son 7 günde en çok ödül alan kullanıcılar
    seven_days_ago = now - timedelta(days=7)
    leaderboard_query = db.session.query(
        User.username,
        db.func.count(DailyReward.id).label('days')
    ).join(
        DailyReward, User.id == DailyReward.user_id
    ).filter(
        DailyReward.claimed_at >= seven_days_ago
    ).group_by(
        User.id
    ).order_by(
        db.desc('days')
    ).limit(20).all()
    
    leaderboard = [{'username': username, 'days': days} for username, days in leaderboard_query]
    
    # Varsayılan değerler
    current_day = 0
    can_claim = False
    claimed_today = False
    next_claim_time = None
    
    if active_series:
        # Bugün hangi gündeyiz
        day_count = DailyReward.query.filter(
            DailyReward.user_id == user.id,
            DailyReward.claimed_at >= active_series.start_date
        ).count()
        
        current_day = day_count + 1
        if current_day > 7:
            current_day = 7
        
        # Bugün zaten ödül alınmış mı
        last_reward = DailyReward.query.filter_by(
            user_id=user.id
        ).order_by(DailyReward.claimed_at.desc()).first()
        
        if last_reward and last_reward.claimed_at.date() == today:
            claimed_today = True
            
            # Sonraki ödül saati
            tomorrow = datetime.datetime.combine(today + timedelta(days=1), datetime.time.min)
            next_claim_time = (tomorrow - now).total_seconds()
        else:
            # Serinin devam edip etmediğini kontrol et
            if last_reward:
                days_passed = (today - last_reward.claimed_at.date()).days
                if days_passed > 1:
                    # Seri bozuldu, yeni seri başlatılmalı
                    active_series = None
                    current_day = 0
                else:
                    can_claim = True
            else:
                can_claim = True
    
    # POST istekleri
    if request.method == 'POST':
        action = request.form.get('action')
        
        if action == 'start':
            # Yeni seri başlat
            if active_series:
                flash('Zaten aktif bir ödül seriniz var', 'warning')
                return redirect(url_for('daily_reward'))
            
            new_series = DailyRewardSeries(
                user_id=user.id,
                start_date=now
            )
            
            db.session.add(new_series)
            db.session.commit()
            
            active_series = new_series
            current_day = 1
            can_claim = True
            
            flash('Günlük ödül seriniz başlatıldı! İlk ödülünüzü alabilirsiniz.', 'success')
            return redirect(url_for('daily_reward'))
            
        elif action == 'claim':
            # Günlük ödül al
            if not active_series:
                flash('Aktif bir ödül seriniz yok', 'danger')
                return redirect(url_for('daily_reward'))
                
            if not can_claim:
                if claimed_today:
                    flash('Bugünkü ödülünüzü zaten aldınız', 'warning')
                else:
                    flash('Şu anda ödül alamazsınız', 'danger')
                return redirect(url_for('daily_reward'))
            
            # Ödül miktarını belirle
            tokens = day_rewards.get(current_day, 50)
            
            # Ödülü kaydet
            reward = DailyReward(
                user_id=user.id,
                day=current_day,
                tokens=tokens,
                claimed_at=now
            )
            
            # Kullanıcı tokenlerini güncelle
            user.tokens += tokens
            
            # Token geçmişi
            token_history = TokenHistory(
                user_id=user.id,
                amount=tokens,
                description=f'Günlük ödül (Gün {current_day})',
                transaction_type='credit'
            )
            
            db.session.add(reward)
            db.session.add(token_history)
            
            # 7. günse seriyi tamamla
            if current_day == 7:
                active_series.is_completed = True
                active_series.completion_date = now
            
            db.session.commit()
            
            flash(f'Tebrikler! Günlük ödülünüz: {tokens} coin', 'success')
            return redirect(url_for('daily_reward'))
            
        elif action == 'claim_bonus':
            # Bonus ödülü al
            if not active_series or not active_series.is_completed or active_series.bonus_claimed:
                flash('Bonus ödül alamazsınız', 'danger')
                return redirect(url_for('daily_reward'))
            
            # Bonus ödül - sabit 500 coin
            bonus_tokens = 500
            
            # Kullanıcı tokenlerini güncelle
            user.tokens += bonus_tokens
            
            # Token geçmişi
            token_history = TokenHistory(
                user_id=user.id,
                amount=bonus_tokens,
                description='7 günlük seri tamamlama bonusu',
                transaction_type='credit'
            )
            
            # Bonusu aldı olarak işaretle
            active_series.bonus_claimed = True
            
            db.session.add(token_history)
            db.session.commit()
            
            flash(f'Tebrikler! 7 günlük seri bonusunuz: {bonus_tokens} coin', 'success')
            return redirect(url_for('daily_reward'))
    
    return render_template(
        'daily_reward.html',
        user=user,
        current_day=current_day,
        day_rewards=day_rewards,
        can_claim=can_claim,
        claimed_today=claimed_today,
        next_claim_time=next_claim_time,
        reward_history=reward_history,
        leaderboard=leaderboard,
        series=active_series
    )

@app.route('/market', methods=['GET', 'POST'])
def market():
    # Check if user is logged in
    if 'user_id' not in session:
        flash('Market sayfasını görüntülemek için giriş yapın', 'warning')
        return redirect(url_for('login'))
    
    # Get user data
    user = User.query.get(session['user_id'])
    if not user:
        session.clear()
        flash('Kullanıcı bulunamadı', 'danger')
        return redirect(url_for('login'))
    
    # Market ürünleri (gerçek uygulama için veritabanından gelmelidir)
    market_items = [
        {
            'id': 1,
            'name': '10$ Steam Kodu',
            'description': 'Steam kodunu hemen kullanabilirsiniz',
            'price': 15000,
            'image': 'https://via.placeholder.com/300/2c2c2c/0d8a52?text=Steam+10$'
        },
        {
            'id': 2,
            'name': '20$ Steam Kodu',
            'description': 'Steam kodunu hemen kullanabilirsiniz',
            'price': 30000,
            'image': 'https://via.placeholder.com/300/2c2c2c/0d8a52?text=Steam+20$'
        },
        {
            'id': 3,
            'name': '10$ Netflix Hediye Kartı',
            'description': 'Netflix hesabınıza para yükleyin',
            'price': 15000,
            'image': 'https://via.placeholder.com/300/2c2c2c/0d8a52?text=Netflix+10$'
        },
        {
            'id': 4,
            'name': '25$ Netflix Hediye Kartı',
            'description': 'Netflix hesabınıza para yükleyin',
            'price': 37500,
            'image': 'https://via.placeholder.com/300/2c2c2c/0d8a52?text=Netflix+25$'
        },
        {
            'id': 5,
            'name': '10$ Amazon Hediye Kartı',
            'description': 'Amazon.com hediye kartı',
            'price': 15000,
            'image': 'https://via.placeholder.com/300/2c2c2c/0d8a52?text=Amazon+10$'
        },
        {
            'id': 6,
            'name': 'Özel Profil Rozeti',
            'description': 'Profilinizde gösterilecek özel rozet',
            'price': 5000,
            'image': 'https://via.placeholder.com/300/2c2c2c/0d8a52?text=Özel+Rozet'
        }
    ]
    
    # İşlem kaydı (satın alım geçmişi - örnek veri)
    purchase_history = []
    
    if request.method == 'POST':
        item_id = request.form.get('item_id', type=int)
        
        # Ürünü bul
        selected_item = None
        for item in market_items:
            if item['id'] == item_id:
                selected_item = item
                break
        
        if not selected_item:
            flash('Geçersiz ürün seçimi', 'danger')
            return redirect(url_for('market'))
        
        # Yeterli token'a sahip mi kontrol et
        if user.tokens < selected_item['price']:
            flash('Bu ürünü satın almak için yeterli coin\'e sahip değilsiniz', 'danger')
            return redirect(url_for('market'))
        
        # Token'ları düş ve satın alma işlemini kaydet
        import datetime
        
        user.tokens -= selected_item['price']
        
        # Token geçmişine ekle
        token_history = TokenHistory(
            user_id=user.id,
            amount=selected_item['price'],
            description=f"Market: {selected_item['name']} satın alındı",
            transaction_type='debit',
            created_at=datetime.datetime.utcnow()
        )
        
        # Veritabanına kaydet
        db.session.add(token_history)
        db.session.commit()
        
        flash(f"{selected_item['name']} başarıyla satın alındı! Hesap bilgileriniz size iletilecektir.", 'success')
        return redirect(url_for('market'))
    
    return render_template('market.html', user=user, market_items=market_items, purchase_history=purchase_history)

# Run the app
if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=True)