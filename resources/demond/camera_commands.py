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

"""Camera command implementations for Baichuan protocol."""

import logging
import asyncio
import traceback
import sys
import time
from typing import Dict, Optional

import camera_sessions

# --- Motion detection watchdog state ---
# Stores the desired state: cameras that should have motion detection active.
# Key = camera_key (host:channel), Value = full cameras_config entry (dict)
_watched_cameras: Dict[str, dict] = {}
_watchdog_task: Optional[asyncio.Task] = None
WATCHDOG_INTERVAL_SECONDS = 60
WATCHDOG_STALE_THRESHOLD_SECONDS = 300  # 5 min sans event = suspect

# Track last event received per camera (passive health signal)
_last_event_time: Dict[str, float] = {}


def record_camera_event(camera_key: str):
    """Record that a Baichuan push event was received for this camera.
    Called from motion_callback to signal the watchdog that the camera is alive."""
    _last_event_time[camera_key] = time.time()

# Initialize jeedom_com (same as camhook.py)
try:
    from jeedom.jeedom import *
except ImportError:
    print("Error: importing module jeedom.jeedom")
    sys.exit(1)

try:
    f = open('jeedomcreds', 'r')
    _callback = f.readline().rstrip("\n")
    _apikey = f.readline().rstrip("\n")
    f.close()
except:
    logging.warning(f"Unable to read credentials jeedom file for camera_commands")
    _callback = None
    _apikey = None

# Create jeedom connection if credentials are available
if _callback and _apikey:
    jeedom_cnx = jeedom_com(_apikey, _callback)
    logging.debug('jeedom_cnx initialized in camera_commands')
else:
    logging.error('jeedom_cnx not initialized in camera_commands (no credentials)')
    sys.exit(1)


async def _execute_camera_command(camera_name, cameras, command_name, api_call):
    """Generic helper to execute camera commands with standard error handling.
    
    Args:
        camera_name: Name of the camera
        cameras: Dictionary of camera configurations
        command_name: Name of the command for logging
        api_call: Async function that executes the API call
    """
    logging.info('%s on camera %s', command_name, camera_name)
    
    if camera_name not in cameras:
        logging.error('Camera %s not found', camera_name)
        return False
    
    try:
        cam_config = cameras[camera_name]
        session_key = f"{cam_config['host']}:{cam_config.get('port', 9000)}"
        
        camera_api = await camera_sessions.get_camera_session(
            camera_key=session_key,
            host=cam_config['host'],
            username=cam_config['username'],
            password=cam_config['password'],
            port=cam_config.get('port', 9000)
        )
        
        if not camera_api:
            logging.error('Failed to connect to camera %s', camera_name)
            return False
        
        channel = cam_config.get('channel', 0)
        
        await asyncio.wait_for(api_call(camera_api, channel), timeout=10.0)
        logging.info('%s successful on camera %s', command_name, camera_name)
        return True
        
    except asyncio.TimeoutError:
        logging.error('Timeout %s on camera %s', command_name, camera_name)
        return False
    except Exception as e:
        logging.error('Error %s on camera %s: %s', command_name, camera_name, e)
        logging.debug(traceback.format_exc())
        return False


def register_channel_status_monitoring(host):
    """Register callback to log channel status changes (cmd_id 145) for a host."""
    callback_id = f'channel_status_{host.host}'
    
    async def delayed_log():
        logging.debug('Received channel status event for %s, waiting for host data refresh...', host.host)
        await host.get_host_data()  # refresh host data to get updated channel status
        logging.info(
            'Channel status event (cmd_id 145) for %s: %s',
            host.host,
            {ch: {'online': host.camera_online(ch)} for ch in host.channels}
        )
    
    host.baichuan.register_callback(
        callback_id=callback_id,
        callback=lambda: asyncio.create_task(delayed_log()),
        cmd_id=145
    )
    logging.info('Registered channel status monitoring (cmd_id 145) for %s', host.host)


async def active_preset(camera_name, preset_id, cameras):
    """Activate a preset position on the camera."""
    return await _execute_camera_command(
        camera_name, cameras, f'Active preset {preset_id}',
        lambda api, ch: api.set_ptz_command(ch, preset=preset_id)
    )


async def _get_session_and_channel(camera_key: str, cam_config: dict):
    """Get camera API session and channel from config.
    
    Returns:
        (camera_api, channel) on success, (None, None) on failure.
    """
    session_key = f"{cam_config['host']}:{cam_config.get('port', 9000)}"
    camera_api = await camera_sessions.get_camera_session(
        camera_key=session_key,
        host=cam_config['host'],
        username=cam_config['username'],
        password=cam_config['password'],
        port=cam_config.get('port', 9000)
    )
    if not camera_api:
        logging.error('Failed to connect to camera %s', camera_key)
        return None, None
    return camera_api, cam_config.get('channel', 0)


async def enable_motion_detection(camera_key: str, cam_config: dict):
    """Enable motion detection monitoring via Baichuan events subscription.
    
    Args:
        camera_key: Camera identifier (host:channel)
        cam_config: Dict with host, port, username, password, channel
    """
    logging.info('Enable motion detection on camera %s', camera_key)
    
    try:
        camera_api, channel = await _get_session_and_channel(camera_key, cam_config)
        if camera_api is None:
            return False
        
        camera_ip = cam_config['host']
        callback_id = f'{camera_key}_ch{channel}_motion'
        
        # Create callback for motion events
        def motion_callback():
            logging.debug('Motion event callback id=%s for %s (channel %d)', callback_id, camera_key, channel)
            
            # Signal the watchdog that this camera is alive
            record_camera_event(camera_key)
            
            try:
                # Basic motion detection - send in same format as ONVIF webhook
                motion_value = 1 if camera_api.motion_detected(channel) else 0
                event_data = {
                    'message': 'EvMotion',
                    'ip': camera_ip,
                    'channel': channel,
                    'motionstate': motion_value
                }
                jeedom_cnx.send_change_immediate(event_data)
                logging.debug('Motion event sent for %s channel %d (state=%s)', camera_key, channel, motion_value)
                
                # Visitor detection (doorbell)
                visitor_value = 1 if camera_api.visitor_detected(channel) else 0
                visitor_event = {
                    'message': 'EvVisitor',
                    'ip': camera_ip,
                    'channel': channel,
                    'motionstate': visitor_value
                }
                jeedom_cnx.send_change_immediate(visitor_event)
                logging.debug('Visitor detection event sent: %s', visitor_value)
                
                # AI detections - send as separate ONVIF-style events if supported
                if camera_api.ai_supported(channel):
                    # People detection
                    people_value = 1 if camera_api.ai_detected(channel, 'people') else 0
                    people_event = {
                        'message': 'EvPeopleDetect',
                        'ip': camera_ip,
                        'channel': channel,
                        'motionstate': people_value
                    }
                    jeedom_cnx.send_change_immediate(people_event)
                    logging.debug('People detection event sent: %s', people_value)
                    
                    # Vehicle detection
                    vehicle_value = 1 if camera_api.ai_detected(channel, 'vehicle') else 0
                    vehicle_event = {
                        'message': 'EvVehicleDetect',
                        'ip': camera_ip,
                        'channel': channel,
                        'motionstate': vehicle_value
                    }
                    jeedom_cnx.send_change_immediate(vehicle_event)
                    logging.debug('Vehicle detection event sent: %s', vehicle_value)
                    
                    # Pet (dog/cat) detection
                    pet_value = 1 if camera_api.ai_detected(channel, 'pet') else 0
                    pet_event = {
                        'message': 'EvDogCatDetect',
                        'ip': camera_ip,
                        'channel': channel,
                        'motionstate': pet_value
                    }
                    jeedom_cnx.send_change_immediate(pet_event)
                    logging.debug('Pet detection event sent: %s', pet_value)
                    
            except Exception as e:
                logging.error('Failed to send motion events: %s', e)
                logging.debug(traceback.format_exc())
        
        # Register callback and subscribe to events
        camera_api.baichuan.register_callback(callback_id, motion_callback, 33, channel)
        
        # Debug : vérifier que le callback est bien enregistré
        logging.debug(f'After register_callback: _ext_callback={camera_api.baichuan._ext_callback}')
        
        # Subscribe if not already subscribed
        if not camera_api.baichuan._subscribed:
            await asyncio.wait_for(camera_api.baichuan.subscribe_events(), timeout=10.0)
        
        logging.info('Motion detection enabled on camera %s (channel %d)', camera_key, channel)
        return True
        
    except asyncio.TimeoutError:
        logging.error('Timeout enabling motion detection on camera %s', camera_key)
        return False
    except Exception as e:
        logging.error('Error enabling motion detection on camera %s: %s', camera_key, e)
        logging.debug(traceback.format_exc())
        return False


async def disable_motion_detection(camera_key: str, cam_config: dict):
    """Disable motion detection monitoring by unregistering callback.
    
    Args:
        camera_key: Camera identifier (host:channel)
        cam_config: Dict with host, port, username, password, channel
    """
    logging.info('Disable motion detection on camera %s', camera_key)
    
    try:
        camera_api, channel = await _get_session_and_channel(camera_key, cam_config)
        if camera_api is None:
            return False
        
        # Unregister callback
        callback_id = f'{camera_key}_ch{channel}_motion'
        camera_api.baichuan.unregister_callback(callback_id)
        
        logging.debug('After unregister_callback: _ext_callback=%s', camera_api.baichuan._ext_callback)
        
        logging.info('Motion detection disabled on camera %s (channel %d)', camera_key, channel)
        return True
        
    except Exception as e:
        logging.error('Error disabling motion detection on camera %s: %s', camera_key, e)
        logging.debug(traceback.format_exc())
        return False


async def is_motion_detection_enabled(camera_key: str, cam_config: dict):
    """Check if motion detection is enabled (subscribed) for a camera.
    
    Returns True if the camera is subscribed to Baichuan events AND has a callback
    registered for cmd 33 (motion events) for the specific channel.
    
    Args:
        camera_key: Camera identifier (host:channel)
        cam_config: Dict with host, port, username, password, channel
    """
    logging.debug('Check motion detection status for camera %s', camera_key)
    
    try:
        camera_api, channel = await _get_session_and_channel(camera_key, cam_config)
        if camera_api is None:
            return False
        
        # Check if subscribed to Baichuan events
        if not hasattr(camera_api, 'baichuan'):
            logging.debug('Camera %s: no baichuan API available', camera_key)
            return False
        
        is_subscribed = camera_api.baichuan._subscribed
        
        # The callbacks structure in reolink-aio is: _ext_callback[cmd_id][channel][callback_id] = callback
        has_callback = bool(camera_api.baichuan._ext_callback.get(33, {}).get(channel, {}))
        
        is_enabled = is_subscribed and has_callback
        
        logging.debug('Camera %s (channel %d): subscribed=%s, has_callback=%s, enabled=%s', 
                     camera_key, channel, is_subscribed, has_callback, is_enabled)
        return is_enabled
        
    except Exception as e:
        logging.error('Error checking motion detection status for camera %s: %s', camera_key, e)
        logging.debug(traceback.format_exc())
        return False


def get_active_cameras():
    """Get list of cameras with active Baichuan motion detection.
    
    Returns a list of camera session keys that have active motion callbacks registered
    and are subscribed to events.
    """
    active_cameras = []
    
    try:
        # Parcourir toutes les sessions actives
        for session_key, session_data in camera_sessions.camera_sessions.items():
            camera_api = session_data.get('host')
            
            if not camera_api or not hasattr(camera_api, 'baichuan'):
                continue
            
            # Vérifier si la session est subscribed ET a des callbacks actifs pour motion (cmd 33)
            if camera_api.baichuan._subscribed and camera_api.baichuan._ext_callback.get(33):
                # Lister les channels avec des callbacks de motion
                channels_with_callbacks = list(camera_api.baichuan._ext_callback.get(33, {}).keys())
                if channels_with_callbacks:
                    active_cameras.append({
                        'session_key': session_key,
                        'channels': channels_with_callbacks,
                        'callback_count': sum(len(callbacks) for callbacks in camera_api.baichuan._ext_callback.get(33, {}).values())
                    })
        
        logging.debug(f'Active Baichuan cameras: {len(active_cameras)} - {active_cameras}')
        return active_cameras
        
    except Exception as e:
        logging.error(f'Error getting active cameras: {e}')
        logging.debug(traceback.format_exc())
        return []


async def active_siren(camera_name, duration, cameras):
    """Activate the siren on the camera."""
    cmd_name = f'Active siren (duration: {duration})'
    if duration:
        return await _execute_camera_command(
            camera_name, cameras, cmd_name,
            lambda api, ch: api.set_siren(ch, True, duration=int(duration))
        )
    else:
        return await _execute_camera_command(
            camera_name, cameras, cmd_name,
            lambda api, ch: api.set_siren(ch, True)
        )


# =====================================================================
# Motion detection watchdog
# =====================================================================

async def _watchdog_loop():
    """Passive watchdog: only actively checks cameras that appear stale (no event received recently)."""
    logging.info('Motion detection watchdog started (interval=%ds, stale_threshold=%ds, cameras=%d)',
                 WATCHDOG_INTERVAL_SECONDS, WATCHDOG_STALE_THRESHOLD_SECONDS, len(_watched_cameras))
    while True:
        await asyncio.sleep(WATCHDOG_INTERVAL_SECONDS)
        if not _watched_cameras:
            continue
        try:
            for camera_key, cam_config in list(_watched_cameras.items()):
                now = time.time()
                last_event = _last_event_time.get(camera_key)

                # If we received an event recently, the detection is working — skip
                if last_event and (now - last_event) < WATCHDOG_STALE_THRESHOLD_SECONDS:
                    logging.debug('Watchdog: %s OK (last event %ds ago)', camera_key, int(now - last_event))
                    continue

                # Stale or never received an event — do an active check
                stale_info = f"{int(now - last_event)}s ago" if last_event else "never"
                logging.info('Watchdog: %s stale (last event: %s), checking...', camera_key, stale_info)

                try:
                    enabled = await is_motion_detection_enabled(camera_key, cam_config)
                    if not enabled:
                        logging.warning('Watchdog: motion detection lost on %s – re-enabling', camera_key)
                        success = await enable_motion_detection(camera_key, cam_config)
                        if success:
                            logging.info('Watchdog: motion detection re-enabled on %s', camera_key)
                            record_camera_event(camera_key)  # reset timer
                        else:
                            logging.error('Watchdog: failed to re-enable motion detection on %s', camera_key)
                    else:
                        logging.debug('Watchdog: %s detection still active (stale but OK)', camera_key)
                        record_camera_event(camera_key)  # reset timer
                except Exception as e:
                    logging.error('Watchdog: error checking/re-enabling %s: %s', camera_key, e)
                    logging.debug(traceback.format_exc())
        except Exception as e:
            logging.error('Watchdog loop error: %s', e)
            logging.debug(traceback.format_exc())


def start_motion_watchdog(cameras_configs: Dict[str, dict]):
    """Start (or update) the motion detection watchdog with the given cameras.

    Args:
        cameras_configs: dict keyed by camera_key with cam config dicts
                         (host, port, username, password, channel)
    """
    global _watchdog_task

    # Merge new cameras into the watched set (additive)
    _watched_cameras.update(cameras_configs)
    logging.info('Watchdog: now watching %d cameras', len(_watched_cameras))

    # Start the background task if not already running
    if _watchdog_task is None or _watchdog_task.done():
        _watchdog_task = asyncio.get_event_loop().create_task(_watchdog_loop())
        logging.info('Watchdog background task started')


def stop_motion_watchdog():
    """Stop the watchdog and clear the watched cameras list."""
    global _watchdog_task

    _watched_cameras.clear()
    _last_event_time.clear()
    if _watchdog_task is not None and not _watchdog_task.done():
        _watchdog_task.cancel()
        logging.info('Watchdog background task cancelled')
    _watchdog_task = None


def remove_watched_camera(camera_key: str):
    """Remove a single camera from the watchdog.

    If no cameras remain, the watchdog task keeps running but does nothing
    until new cameras are added.
    """
    removed = _watched_cameras.pop(camera_key, None)
    _last_event_time.pop(camera_key, None)
    if removed:
        logging.info('Watchdog: stopped watching %s (%d remaining)', camera_key, len(_watched_cameras))
    return removed is not None


def is_watchdog_running() -> bool:
    """Check if the watchdog background task is running."""
    return _watchdog_task is not None and not _watchdog_task.done()


def get_watchdog_status() -> dict:
    """Return watchdog status info for health/diagnostics."""
    return {
        'running': is_watchdog_running(),
        'watched_cameras': len(_watched_cameras),
        'interval_seconds': WATCHDOG_INTERVAL_SECONDS,
        'stale_threshold_seconds': WATCHDOG_STALE_THRESHOLD_SECONDS,
    }
