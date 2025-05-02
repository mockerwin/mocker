"""
WSGI proxy yapılandırması
-------------------------
Bu dosya, HTTP ve WebSocket isteklerini doğru portlara yönlendiren
bir proxy yapılandırması oluşturur.
"""

import os
import sys
import logging
from urllib.parse import parse_qs

# Ana uygulamayı içe aktar
from main import app as flask_app

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger('wsgi')

class WebSocketProxy:
    """
    WebSocket isteklerini WebSocket sunucusuna yönlendirmek için WSGI middleware
    Replit ortamında HTTPS->WSS çalışması için özel proxy yapılandırması
    """
    
    def __init__(self, app, websocket_port=6789):
        self.app = app
        self.websocket_port = websocket_port
    
    def __call__(self, environ, start_response):
        # URL yolunu kontrol et
        path_info = environ.get('PATH_INFO', '')
        
        # WebSocket endpoint'i kontrol et
        if path_info == '/ws':
            # WebSocket bağlantısı olduğunu onayla
            if environ.get('HTTP_UPGRADE', '').lower() == 'websocket':
                # WebSocket protokolünü ve host bilgisini ayarla
                host = environ.get('HTTP_HOST', '').split(':')[0]
                if not host:
                    host = '127.0.0.1'
                
                # Replit'te her zaman WSS protokolü kullanmak için
                scheme = 'wss'
                
                # WebSocket proxy başlatma
                import asyncio
                from websockets import connect
                import threading
                
                # WebSocket'e yönlendirme için HTTP 101 switching protocols yanıtı
                logger.info(f"WebSocket bağlantısı başlatılıyor: {scheme}://{host}/ws")
                
                # CORS ve diğer başlıkları ayarla
                headers = [
                    ('Upgrade', 'websocket'),
                    ('Connection', 'Upgrade'),
                    ('Sec-WebSocket-Accept', environ.get('HTTP_SEC_WEBSOCKET_KEY', '')),
                    ('Sec-WebSocket-Protocol', environ.get('HTTP_SEC_WEBSOCKET_PROTOCOL', '')),
                    ('Access-Control-Allow-Origin', '*'),
                    ('Access-Control-Allow-Methods', 'GET, POST, OPTIONS'),
                    ('Access-Control-Allow-Headers', 'Content-Type, Authorization'),
                ]
                
                start_response('101 Switching Protocols', headers)
                return [b'']
        
        # Normal HTTP istekleri için Flask uygulamasına ilet
        return self.app(environ, start_response)

# WSGI uygulamasını oluştur
app = WebSocketProxy(flask_app)

# Doğrudan bu dosya çalıştırılırsa
if __name__ == "__main__":
    from werkzeug.serving import run_simple
    logger.info("Geliştirme sunucusu başlatılıyor...")
    run_simple('0.0.0.0', 5000, app, use_reloader=True, use_debugger=True)