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

# Locks per camera to prevent concurrent connection attempts
_connection_locks = {}
_locks_mutex = asyncio.Lock()

async def _get_camera_lock(camera_key):
    """Get or create the lock for a specific camera key."""
    async with _locks_mutex:
        if camera_key not in _connection_locks:
            _connection_locks[camera_key] = asyncio.Lock()
        return _connection_locks[camera_key]

async def _create_and_cache_session(camera_key, host, username, password, port=9000):
    """Create a Host session, fetch data, and cache it with LRU eviction."""
    api = Host(host, username, password, port=port)
    await asyncio.wait_for(api.get_host_data(), timeout=20.0)

    camera_sessions[camera_key] = {
        'host': api,
        'last_used': datetime.now()
    }
    camera_sessions.move_to_end(camera_key)

    if len(camera_sessions) > MAX_CACHE_SIZE:
        oldest_key, oldest_data = camera_sessions.popitem(last=False)
        logging.info('Cache full, evicting least recently used camera: %s', oldest_key)
        try:
            await oldest_data['host'].logout()
        except Exception:
            pass

    return api

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
    camera_lock = await _get_camera_lock(camera_key)
    
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
            api = await _create_and_cache_session(camera_key, host, username, password, port)
            
            logging.info('Camera %s session established and cached', camera_key)
            return api
            
        except asyncio.TimeoutError:
            logging.error('Timeout connecting to camera %s', camera_key)
            return None
        except Exception as e:
            logging.error('Failed to connect to camera %s: %s', camera_key, repr(e))
            return None

async def remove_camera_session(camera_key):
    """Remove a cached camera session and logout cleanly.

    Args:
        camera_key: Unique key used in cache (e.g., "192.168.1.100:9000")

    Returns:
        True if a session was removed, False if no session existed.
    """
    # Use the same per-camera lock as get_camera_session to avoid races
    camera_lock = await _get_camera_lock(camera_key)

    async with camera_lock:
        session_data = camera_sessions.pop(camera_key, None)
        if not session_data:
            return False

        try:
            await session_data['host'].logout()
        except Exception as e:
            logging.warning('Error logging out from camera %s: %s', camera_key, e)

        # Keep per-camera lock persistent to preserve synchronization semantics
        # across remove/recreate cycles.
        logging.info('Camera session removed: %s', camera_key)
        return True

async def recreate_camera_session(camera_key, host, username, password, port=9000):
    """Recreate a camera session atomically under the camera lock.

    Args:
        camera_key: Unique key used in cache (e.g., "192.168.1.100:9000")
        host: Camera IP or hostname
        username: Camera username
        password: Camera password
        port: Camera port (default 9000)

    Returns:
        New Host API object on success.
        Existing Host object if recreation fails but one was already cached.
        None if recreation fails and no cached session exists.
    """
    camera_lock = await _get_camera_lock(camera_key)

    async with camera_lock:
        previous_session = camera_sessions.get(camera_key)
        previous_host = previous_session['host'] if previous_session else None

        try:
            logging.debug('Recreating session for %s', camera_key)
            api = await _create_and_cache_session(camera_key, host, username, password, port)

            if previous_host and previous_host is not api:
                try:
                    await previous_host.logout()
                except Exception as e:
                    logging.warning('Error logging out from previous camera %s session: %s', camera_key, e)

            logging.info('Camera %s session recreated and cached', camera_key)
            return api

        except asyncio.TimeoutError:
            logging.error('Timeout recreating session for camera %s', camera_key)
            return previous_host
        except Exception as e:
            logging.error('Failed to recreate session for camera %s: %s', camera_key, repr(e))
            return previous_host

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
