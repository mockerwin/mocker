#!/usr/bin/env python
"""
Randy Çekiliş Sistemi WebSocket Sunucusu
-----------------------------------------
Bu script, Randy çekiliş botunu çalıştıran WebSocket sunucusunu başlatır.
Çekiliş katılımları, gerçek zamanlı bildirimler ve sonuçları yönetir.
"""

import asyncio
import logging
import os
import sys

from websocket_server import main as websocket_main

# Logging yapılandırması
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.StreamHandler(sys.stdout)
    ]
)

logger = logging.getLogger('run_websocket')

if __name__ == "__main__":
    logger.info("WebSocket sunucusu başlatılıyor...")
    
    try:
        # WebSocket sunucusunu başlat
        asyncio.run(websocket_main())
    except KeyboardInterrupt:
        logger.info("Sunucu durduruldu.")
    except Exception as e:
        logger.error(f"Sunucu hatası: {e}", exc_info=True)