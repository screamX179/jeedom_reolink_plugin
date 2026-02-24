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

async def get_camera_session(camera_key, host, username, password, port=9000, refresh=False):
    """Get or create a camera session, with optional host-data refresh and atomic recreation.

    Args:
        camera_key: Unique key for caching (e.g., "192.168.1.100:9000")
        host:       Camera IP or hostname
        username:   Camera username
        password:   Camera password
        port:       Camera port (default 9000)
        refresh:    If True, call get_host_data() on the cached session and recreate it
                    atomically when new devices are detected. Concurrent callers that
                    arrive while a recreation is already in progress will reuse the
                    already-recreated session instead of triggering redundant reconnections.

    Returns:
        Host API object, or None if the connection fails.
    """
    camera_lock = await _get_camera_lock(camera_key)

    # ── Step 1: get or create session (fast, under lock) ──────────────────────
    async with camera_lock:
        logging.debug('Camera sessions cache: %s', list(camera_sessions.keys()))
        logging.debug('Current camera_key: %s', camera_key)

        if camera_key in camera_sessions:
            session_data = camera_sessions[camera_key]
            logging.debug('Using cached session for %s (host: %s, last_used: %s)',
                          camera_key, session_data['host'].host, session_data['last_used'])
            session_data['last_used'] = datetime.now()
            camera_sessions.move_to_end(camera_key)
            api = session_data['host']
        else:
            try:
                logging.debug('Creating new session for %s', camera_key)
                api = await _create_and_cache_session(camera_key, host, username, password, port)
                logging.info('Camera %s session established and cached', camera_key)
            except asyncio.TimeoutError:
                logging.error('Timeout connecting to camera %s', camera_key)
                return None
            except Exception as e:
                logging.error('Failed to connect to camera %s: %s', camera_key, repr(e))
                return None

        # Capture timestamp now so concurrent refresh callers can detect a later recreation
        observed_at = camera_sessions[camera_key]['last_used']

    if not refresh:
        return api

    # ── Step 2: refresh host data (slow, outside lock) ────────────────────────
    await api.get_host_data()

    if not api.new_devices:
        return api

    # ── Step 3: new devices detected – recreate session atomically ────────────
    logging.info('New devices discovered for %s, recreating session', camera_key)
    async with camera_lock:
        current = camera_sessions.get(camera_key)

        # Another coroutine already recreated while we were in get_host_data()
        if current and current['last_used'] > observed_at:
            logging.debug('Session for %s already recreated by another coroutine, reusing', camera_key)
            return current['host']

        previous_host = current['host'] if current else None
        try:
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
