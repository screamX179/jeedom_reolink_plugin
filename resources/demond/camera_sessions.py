# This file is part of Jeedom.
#
# Jeedom is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# Jeedom is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Jeedom. If not, see <http://www.gnu.org/licenses/>.

"""Shared camera session management for both reolink_aio_api and camera_commands."""

import logging
import asyncio
from datetime import datetime, timedelta
from collections import OrderedDict
from reolink_aio.api import Host

# Global cache for camera sessions (LRU with max size)
camera_sessions = OrderedDict()
MAX_CACHE_SIZE = 20
SESSION_TTL_MINUTES = 30

# Locks per camera to prevent concurrent connection attempts
_connection_locks = {}
_locks_mutex = asyncio.Lock()

async def get_camera_session(camera_key, host, username, password, port=9000):
    """Get or create camera session (with caching and validation).
    
    Args:
        camera_key: Unique key for caching (e.g., "192.168.1.100:9000")
        host: Camera IP or hostname
        username: Camera username
        password: Camera password
        port: Camera port (default 9000)
        
    Returns:
        Host API object or None if connection fails
    """
    # Get or create lock for this camera
    async with _locks_mutex:
        if camera_key not in _connection_locks:
            _connection_locks[camera_key] = asyncio.Lock()
        camera_lock = _connection_locks[camera_key]
    
    # Use camera-specific lock to prevent concurrent connections
    async with camera_lock:
        # Log cache contents for debugging
        logging.debug('Camera sessions cache: %s', list(camera_sessions.keys()))
        logging.debug('Current camera_key: %s', camera_key)

        # Re-check cache inside lock (another coroutine might have created the session)
        if camera_key in camera_sessions:
            logging.debug('Checking cached session for %s', camera_key)
            session_data = camera_sessions[camera_key]
            
            logging.debug('Using cached session for %s (host: %s, last_used: %s)', 
                         camera_key, session_data['host'].host, session_data['last_used'])
            # Update last_used and move to end (most recently used)
            session_data['last_used'] = datetime.now()
            camera_sessions.move_to_end(camera_key)
            return session_data['host']
        
        try:
            logging.debug('Creating new session for %s', camera_key)
            api = Host(host, username, password, port=port)
            await asyncio.wait_for(api.get_host_data(), timeout=20.0)
            
            # Cache the session with LRU eviction
            camera_sessions[camera_key] = {
                'host': api,
                'last_used': datetime.now()
            }
            
            # Evict oldest if cache is full
            if len(camera_sessions) > MAX_CACHE_SIZE:
                # Remove least recently used (first item)
                oldest_key, oldest_data = camera_sessions.popitem(last=False)
                logging.info('Cache full, evicting least recently used camera: %s', oldest_key)
                try:
                    await oldest_data['host'].logout()
                except:
                    pass
            
            logging.info('Camera %s session established and cached', camera_key)
            return api
            
        except asyncio.TimeoutError:
            logging.error('Timeout connecting to camera %s', camera_key)
            return None
        except Exception as e:
            logging.error('Failed to connect to camera %s: %s', camera_key, repr(e))
            return None

async def cleanup_all_sessions():
    """Clean up all cached camera sessions."""
    if camera_sessions:
        logging.debug("Closing %d cached camera sessions", len(camera_sessions))
        for camera_key, session_data in list(camera_sessions.items()):
            try:
                logging.debug("Logging out from camera %s", camera_key)
                await session_data['host'].logout()
            except Exception as e:
                logging.warning('Error logging out from camera %s: %s', camera_key, e)
        camera_sessions.clear()
