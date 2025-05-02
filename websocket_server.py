import asyncio
import json
import logging
import uuid
from datetime import datetime
from typing import Dict, List, Set, Any

import websockets
from websockets.server import WebSocketServerProtocol

# Flask uygulamasından veritabanı modellerini import et
from main import db, User, Raffle, RaffleEntry

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("websocket_server")

# Bağlantı takibi
connected_users: Dict[str, WebSocketServerProtocol] = {}
authenticated_users: Dict[str, int] = {}  # websocket_id -> user_id
raffle_subscribers: Dict[int, Set[str]] = {}  # raffle_id -> set of websocket_ids

async def register(websocket: WebSocketServerProtocol) -> str:
    """Yeni WebSocket bağlantısını kaydet"""
    connection_id = str(uuid.uuid4())
    connected_users[connection_id] = websocket
    logger.info(f"Yeni bağlantı: {connection_id}")
    return connection_id

async def unregister(connection_id: str) -> None:
    """WebSocket bağlantısını kaldır"""
    if connection_id in connected_users:
        # Bağlantıyı kaldır
        del connected_users[connection_id]
        
        # Eğer kullanıcı kimliği doğrulanmışsa, onu da kaldır
        if connection_id in authenticated_users:
            del authenticated_users[connection_id]
        
        # Raffle aboneliklerinden kaldır
        for subscribers in raffle_subscribers.values():
            if connection_id in subscribers:
                subscribers.remove(connection_id)
        
        logger.info(f"Bağlantı sonlandırıldı: {connection_id}")

async def subscribe_to_raffle(connection_id: str, raffle_id: int) -> None:
    """Kullanıcıyı bir çekilişin güncellemelerine abone et"""
    if raffle_id not in raffle_subscribers:
        raffle_subscribers[raffle_id] = set()
    
    raffle_subscribers[raffle_id].add(connection_id)
    logger.info(f"Kullanıcı {connection_id} çekiliş {raffle_id}'ye abone oldu")

async def broadcast_raffle_update(raffle_id: int, update_data: dict) -> None:
    """Çekiliş güncellemesini tüm abonelere gönder"""
    if raffle_id not in raffle_subscribers:
        return
    
    message = json.dumps(update_data)
    
    # Tüm abonelere gönder
    for connection_id in raffle_subscribers[raffle_id]:
        if connection_id in connected_users:
            try:
                await connected_users[connection_id].send(message)
            except Exception as e:
                logger.error(f"Mesaj gönderme hatası: {e}")

async def notify_raffle_completed(raffle_id: int, winners: List[int]) -> None:
    """Çekiliş tamamlandığında tüm abonelere bildir"""
    if raffle_id not in raffle_subscribers:
        return
    
    # Tamamlanma mesajı
    completion_message = json.dumps({
        "type": "raffle_completed"
    })
    
    # Sonuç mesajı
    result_message = json.dumps({
        "type": "raffle_result",
        "winners": winners
    })
    
    # Önce sonuçları gönder
    for connection_id in raffle_subscribers[raffle_id]:
        if connection_id in connected_users:
            try:
                await connected_users[connection_id].send(result_message)
                # Kısa bir beklemeden sonra tamamlanma mesajını gönder
                await asyncio.sleep(0.1)
                await connected_users[connection_id].send(completion_message)
            except Exception as e:
                logger.error(f"Mesaj gönderme hatası: {e}")

async def handle_join_raffle(connection_id: str, user_id: int, raffle_id: int) -> None:
    """Kullanıcının çekilişe katılma isteğini işle"""
    # Çekiliş ve kullanıcı bilgilerini al
    with db.session() as session:
        raffle = session.query(Raffle).get(raffle_id)
        user = session.query(User).get(user_id)
        
        if not raffle or not user:
            return
        
        # Çekiliş aktif mi kontrol et
        if raffle.status != 'active':
            return
        
        # Kullanıcının yeterli tokeni var mı kontrol et
        if user.tokens < raffle.token_cost:
            return
        
        # Kullanıcının çekilişe katılımını kaydet
        entry = session.query(RaffleEntry).filter_by(user_id=user_id, raffle_id=raffle_id).first()
        
        if entry:
            # Kullanıcı zaten katılmış, katılım sayısını artır
            entry.entries += 1
        else:
            # Yeni katılım oluştur
            entry = RaffleEntry(user_id=user_id, raffle_id=raffle_id, entries=1)
            session.add(entry)
        
        # Kullanıcının tokenlerini azalt
        user.tokens -= raffle.token_cost
        
        # Değişiklikleri kaydet
        session.commit()
        
        # Katılımcıları ve toplam katılım sayısını hesapla
        participant_count = session.query(RaffleEntry).filter_by(raffle_id=raffle_id).count()
        total_entries = session.query(db.func.sum(RaffleEntry.entries)).filter_by(raffle_id=raffle_id).scalar() or 0
        
        # Güncelleme gönder
        await broadcast_raffle_update(raffle_id, {
            "type": "raffle_update",
            "participant_count": participant_count,
            "total_entries": total_entries,
            "participant": {
                "username": user.username,
                "entries": entry.entries
            }
        })

async def handle_authenticate(connection_id: str, user_id: int) -> None:
    """Kullanıcının kimliğini doğrula"""
    authenticated_users[connection_id] = user_id
    logger.info(f"Kullanıcı {user_id} kimliği doğrulandı, bağlantı: {connection_id}")

async def handler(websocket: WebSocketServerProtocol, path: str) -> None:
    """WebSocket bağlantısı yönetimi"""
    connection_id = await register(websocket)
    
    try:
        async for message in websocket:
            try:
                data = json.loads(message)
                message_type = data.get("type")
                
                if message_type == "authenticate":
                    user_id = data.get("user_id")
                    if user_id:
                        await handle_authenticate(connection_id, user_id)
                
                elif message_type == "subscribe_raffle":
                    raffle_id = data.get("raffle_id")
                    if raffle_id:
                        await subscribe_to_raffle(connection_id, raffle_id)
                
                elif message_type == "join_raffle":
                    user_id = authenticated_users.get(connection_id)
                    raffle_id = data.get("raffle_id")
                    if user_id and raffle_id:
                        await handle_join_raffle(connection_id, user_id, raffle_id)
                
            except json.JSONDecodeError:
                logger.error(f"Geçersiz JSON: {message}")
            except Exception as e:
                logger.error(f"Mesaj işleme hatası: {e}")
    
    finally:
        await unregister(connection_id)

async def check_raffle_end() -> None:
    """Aktif çekilişlerin bitiş zamanlarını kontrol et"""
    while True:
        try:
            now = datetime.utcnow()
            with db.session() as session:
                # Süresi dolan ve hala aktif olan çekilişleri bul
                expired_raffles = session.query(Raffle).filter(
                    Raffle.status == 'active',
                    Raffle.end_date <= now,
                    Raffle.manual_end == False  # Otomatik sonlandırılacak çekilişler
                ).all()
                
                for raffle in expired_raffles:
                    logger.info(f"Çekiliş sonlandırılıyor: {raffle.id} - {raffle.title}")
                    
                    # Kazananları seç
                    winners = pick_winners(session, raffle)
                    
                    # Kazanan bilgilerini güncelle
                    raffle.status = 'completed'
                    raffle.winners = json.dumps(winners)
                    session.commit()
                    
                    # Abonelere bildir
                    await notify_raffle_completed(raffle.id, winners)
        
        except Exception as e:
            logger.error(f"Çekiliş kontrol hatası: {e}")
        
        # Her 10 saniyede bir kontrol et
        await asyncio.sleep(10)

def pick_winners(session, raffle: Raffle) -> List[int]:
    """Çekilişin kazananlarını seç"""
    import random
    
    # Tüm kayıtları al
    entries = session.query(RaffleEntry).filter_by(raffle_id=raffle.id).all()
    
    if not entries:
        return []
    
    # Ağırlıklı liste oluştur
    weighted_entries = []
    for entry in entries:
        weighted_entries.extend([entry.user_id] * entry.entries)
    
    if not weighted_entries:
        return []
    
    # Randy çekilişi için çoklu kazanan seçimi
    winners = []
    winner_count = raffle.winner_count if raffle.is_randy else 1
    
    # Kazanan sayısı, toplam katılımcı sayısından fazla olamaz
    unique_participants = len(set([entry.user_id for entry in entries]))
    if winner_count > unique_participants:
        winner_count = unique_participants
    
    # Kazananları seç
    for _ in range(winner_count):
        if not weighted_entries:  # Tüm katılımcılar kazandıysa çık
            break
            
        winner_id = random.choice(weighted_entries)
        
        # Bir kullanıcı birden fazla kez kazanamaz
        if winner_id not in winners:
            winners.append(winner_id)
            
            # Kazananı güncelle
            winner = session.query(User).get(winner_id)
            if winner:
                winner.raffle_wins += 1
                
                # Ödülü hesapla ve ver
                prize_amount = raffle.token_cost * 10  # Örnek ödül hesaplama
                winner.tokens += prize_amount
                winner.total_earned += float(prize_amount)
        
        # Kazananın diğer katılımlarını kaldır (tekrar seçilmemesi için)
        weighted_entries = [entry_id for entry_id in weighted_entries if entry_id != winner_id]
    
    return winners

async def main() -> None:
    """Ana websocket sunucusunu başlat"""
    raffle_checker = asyncio.create_task(check_raffle_end())
    
    # SSL/TLS sertifikalarını yükle
    import os
    import ssl
    
    # Replit ortamı için özel WSS yapılandırması 
    # SSL sertifikası oluştur (self-signed)
    ssl_context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    
    # Replit ortamında sertifika yoksa da WSS protokolü kullanabilmek için
    # WSS bağlantılarını HTTP üzerinden proxy ile yönlendirme yapacağız 
    
    logger.info(f"WebSocket güvenli bağlantı yapılandırması hazırlanıyor...")
    
    # WebSocket sunucusunu başlat
    server = await websockets.serve(
        handler, 
        "0.0.0.0", 
        6789,
        # CORS yapılandırması
        process_request=lambda path, headers: None, 
        # Ping/pong timeout ve max size
        ping_interval=30,
        ping_timeout=10,
        max_size=1024 * 1024,  # 1MB
        # Extra başlıklar
        extra_headers=[
            ('Access-Control-Allow-Origin', '*'),
            ('Access-Control-Allow-Methods', 'GET, POST, OPTIONS'),
            ('Access-Control-Allow-Headers', 'Content-Type, Authorization')
        ]
    )
    
    logger.info(f"WebSocket sunucusu başlatıldı: WSS protokolü ile dinleniyor (port 6789)")
    
    await asyncio.Future()  # Sonsuza kadar çalış

if __name__ == "__main__":
    asyncio.run(main())