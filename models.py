from main import db
from datetime import datetime, timedelta

class User(db.Model):
    __tablename__ = 'users'
    
    id = db.Column(db.Integer, primary_key=True)
    username = db.Column(db.String(64), unique=True, nullable=False)
    email = db.Column(db.String(120), unique=True, nullable=False)
    password_hash = db.Column(db.String(256), nullable=False)
    avatar = db.Column(db.String(255), nullable=True)
    role = db.Column(db.String(20), default='user')
    tokens = db.Column(db.Integer, default=0)
    is_active = db.Column(db.Boolean, default=True)
    wallet_address = db.Column(db.String(255), nullable=True)  # Cüzdan adresi
    wallet_type = db.Column(db.String(50), nullable=True)  # Cüzdan tipi (Binance, Gomdom...)
    wallet_verified = db.Column(db.Boolean, default=False)  # Cüzdan doğrulandı mı?
    raffle_wins = db.Column(db.Integer, default=0)  # Kazanılan çekiliş sayısı
    total_earned = db.Column(db.Float, default=0.0)  # Toplam kazanılan ödül
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    last_login = db.Column(db.DateTime, nullable=True)
    # 2FA alanları
    two_factor_enabled = db.Column(db.Boolean, default=False)
    two_factor_secret = db.Column(db.String(32), nullable=True)
    # Seviye sistemi
    experience_points = db.Column(db.Integer, default=0)  # XP puanları
    level = db.Column(db.Integer, default=1)  # Kullanıcı seviyesi
    level_tier = db.Column(db.String(20), default='bronze')  # Seviye kademesi (bronze, silver, gold, diamond)
    
    # Relationships
    tokens_history = db.relationship('TokenHistory', backref='user', lazy=True)
    promotions = db.relationship('Promotion', backref='user', lazy=True)
    raffle_entries = db.relationship('RaffleEntry', backref='user', lazy=True)
    
    def __repr__(self):
        return f'<User {self.username}>'

class TokenHistory(db.Model):
    __tablename__ = 'token_history'
    
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('users.id'), nullable=False)
    amount = db.Column(db.Integer, nullable=False)
    description = db.Column(db.String(255), nullable=False)
    transaction_type = db.Column(db.String(20), nullable=False)  # 'credit', 'debit'
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    
    def __repr__(self):
        return f'<TokenHistory {self.transaction_type} {self.amount}>'

class Promotion(db.Model):
    __tablename__ = 'promotions'
    
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('users.id'), nullable=False)
    code = db.Column(db.String(20), nullable=False)  # Removed unique constraint for multi-use codes
    tokens = db.Column(db.Integer, nullable=False)
    is_used = db.Column(db.Boolean, default=False)
    used_by = db.Column(db.Integer, nullable=True)
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    used_at = db.Column(db.DateTime, nullable=True)
    
    # Yeni alanlar
    max_uses = db.Column(db.Integer, default=None)  # Maximum use count (None = single-use)
    use_count = db.Column(db.Integer, default=0)    # Current use count
    expires_at = db.Column(db.DateTime, default=None)  # Expiration time (None = never expires)
    
    def __repr__(self):
        return f'<Promotion {self.code}>'

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
    is_randy = db.Column(db.Boolean, default=False)  # Randy çekilişi mi?
    winner_count = db.Column(db.Integer, default=1)  # Kazanan sayısı
    manual_end = db.Column(db.Boolean, default=False)  # Manuel sonlandırma
    winners = db.Column(db.Text, nullable=True)  # JSON olarak kazanan IDs
    status = db.Column(db.String(20), default='active')  # 'active', 'completed', 'cancelled'
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    
    # Relationships
    entries = db.relationship('RaffleEntry', backref='raffle', lazy=True)
    
    def __repr__(self):
        return f'<Raffle {self.title}>'

class RaffleEntry(db.Model):
    __tablename__ = 'raffle_entries'
    
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('users.id'), nullable=False)
    raffle_id = db.Column(db.Integer, db.ForeignKey('raffles.id'), nullable=False)
    entries = db.Column(db.Integer, default=1)
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    
    def __repr__(self):
        return f'<RaffleEntry User:{self.user_id} Raffle:{self.raffle_id}>'

class ChatMessage(db.Model):
    __tablename__ = 'chat_messages'
    
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('users.id'), nullable=False)
    message = db.Column(db.Text, nullable=False)
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    
    # Relationship
    user = db.relationship('User', backref='messages')
    
    def __repr__(self):
        return f'<ChatMessage User:{self.user_id}>'

class UserToken(db.Model):
    __tablename__ = 'user_tokens'
    
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('users.id'), nullable=False)
    token = db.Column(db.String(255), nullable=False)
    type = db.Column(db.String(20), nullable=False)  # 'session', 'reset', 'activation'
    expires_at = db.Column(db.DateTime, nullable=False)
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    
    def __repr__(self):
        return f'<UserToken User:{self.user_id} Type:{self.type}>'

class DailyReward(db.Model):
    __tablename__ = 'daily_rewards'
    
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('users.id'), nullable=False)
    day = db.Column(db.Integer, nullable=False)  # 1-7
    tokens = db.Column(db.Integer, nullable=False)
    claimed_at = db.Column(db.DateTime, default=datetime.utcnow)
    
    # Relationship
    user = db.relationship('User', backref='daily_rewards')
    
    def __repr__(self):
        return f'<DailyReward User:{self.user_id} Day:{self.day}>'

class DailyRewardSeries(db.Model):
    __tablename__ = 'daily_reward_series'
    
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('users.id'), nullable=False)
    start_date = db.Column(db.DateTime, nullable=False, default=datetime.utcnow)
    completion_date = db.Column(db.DateTime, nullable=True)
    is_completed = db.Column(db.Boolean, default=False)
    bonus_claimed = db.Column(db.Boolean, default=False)
    
    # Relationship
    user = db.relationship('User', backref='daily_reward_series')
    
    def __repr__(self):
        return f'<DailyRewardSeries User:{self.user_id} Completed:{self.is_completed}>'

class ActivityLog(db.Model):
    __tablename__ = 'activity_logs'
    
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('users.id'), nullable=False)
    action = db.Column(db.String(50), nullable=False)
    description = db.Column(db.Text, nullable=True)
    ip_address = db.Column(db.String(45), nullable=True)
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    
    def __repr__(self):
        return f'<ActivityLog User:{self.user_id} Action:{self.action}>'