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
from typing import Dict

import camera_sessions

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


# ── Auto-camera registry: cameras to re-enable on session recreation ──────
_auto_cameras: Dict[str, dict] = {}  # camera_key (host:channel) -> cam_config


def register_auto_camera(camera_key: str, cam_config: dict):
    """Register a camera for automatic motion re-enable on session recreation."""
    _auto_cameras[camera_key] = cam_config.copy()
    logging.debug('Auto-camera registered: %s', camera_key)


def unregister_auto_camera(camera_key: str):
    """Unregister a camera from automatic motion re-enable."""
    if _auto_cameras.pop(camera_key, None):
        logging.debug('Auto-camera unregistered: %s', camera_key)


async def _on_session_created(session_key: str):
    """Callback: re-enable motion detection for auto-cameras when session is (re)created."""
    cameras_to_reenable = [
        (ck, cc) for ck, cc in _auto_cameras.items()
        if f"{cc['host']}:{cc.get('port', 9000)}" == session_key
    ]
    if not cameras_to_reenable:
        return

    logging.info('Session %s (re)created: re-enabling motion for %d camera(s)',
                 session_key, len(cameras_to_reenable))

    for camera_key, cam_config in cameras_to_reenable:
        try:
            await enable_motion_detection(camera_key, cam_config)
        except Exception as e:
            logging.error('Re-enable motion failed for %s: %s', camera_key, e)

    # Re-register channel status monitoring once for this session
    first_cam_config = cameras_to_reenable[0][1]
    try:
        await register_channel_status_monitoring(session_key, first_cam_config)
    except Exception as e:
        logging.error('Re-register channel monitoring failed for %s: %s', session_key, e)


# Register session callback at module init
camera_sessions.register_session_created_callback(_on_session_created)


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


async def register_channel_status_monitoring(session_key: str, cam_config: dict):
    """Register callback to log channel status changes (cmd_id 145) for a host session.
    
    Le callback lit directement l'état interne du Host (mis à jour par reolink_aio
    avant le fire du callback) sans faire de refresh/recreation de session.
    
    Args:
        session_key: Session cache key (host:port)
        cam_config: Dict with host, port, username, password
    """
    session_data = camera_sessions.camera_sessions.get(session_key)
    if not session_data:
        logging.warning('Cannot register channel status monitoring: session %s not found', session_key)
        return
    
    host = session_data['host']
    callback_id = f'channel_status_{session_key}'
    
    def _on_channel_status():
        """Callback synchrone déclenché par reolink_aio sur event cmd_id 145.
        
        Le push 145 met à jour _sleep (sleeping/standby) mais PAS _channel_online.
        On lance un get_host_data() sur le Host existant pour rafraîchir _channel_online
        (GetChannelStatus) sans recréer la session (ce qui causerait une boucle infinie).
        """
        async def _refresh_channel_status():
            try:
                logging.debug('Refreshing channel status for %s', session_key)
                await host.get_host_data()
                status = {
                    ch: {'online': host.camera_online(ch), 'sleeping': host.sleeping(ch)}
                    for ch in host.channels
                }
                logging.info('Channel status event (cmd_id 145) for %s: %s', session_key, status)
            except Exception as e:
                logging.error('Error refreshing channel status for %s: %s', session_key, e)
        
        asyncio.get_event_loop().create_task(_refresh_channel_status())
    
    host.baichuan.register_callback(
        callback_id=callback_id,
        callback=_on_channel_status,
        cmd_id=145
    )
    logging.info('Registered channel status monitoring (cmd_id 145) for %s', session_key)


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
        unregister_auto_camera(camera_key)
        
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

