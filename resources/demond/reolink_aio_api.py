"""
API FastAPI pour la gestion des équipements Reolink via reolink-aio
Cette API permet de découvrir et gérer les caméras Reolink (autonomes ou connectées à un HomeHub/NVR)
"""

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from typing import List, Optional
import asyncio
import logging
from reolink_aio.api import Host

# Import shared session management
import camera_sessions
import camera_commands

app = FastAPI(
    title="Reolink API (reolink-aio)",
    description="API pour gérer les équipements Reolink via la bibliothèque reolink-aio",
    version="1.0.0"
)

# Modèles de données
class HomeHubCredentials(BaseModel):
    """Informations de connexion au HomeHub"""
    host: str
    username: str
    password: str
    port: int = 80
    use_https: bool = False

class CameraInfo(BaseModel):
    """Informations d'une caméra"""
    channel_id: int
    name: str
    model: str
    online: bool
    uid: Optional[str] = None
    serial: Optional[str] = None

class HomeHubInfo(BaseModel):
    """Informations du HomeHub"""
    model: str
    serial: str
    hardware_version: str
    firmware_version: str
    channel_count: int
    cameras: List[CameraInfo]

class CameraCredentials(BaseModel):
    """Informations de connexion à une caméra pour motion detection"""
    host: str
    username: str
    password: str
    port: int = 9000
    channel: int = 0

class MotionDetectionResponse(BaseModel):
    """Réponse des endpoints de motion detection"""
    success: bool
    message: str
    camera: str

class MotionDetectionStatusResponse(BaseModel):
    """Réponse du statut de détection de mouvement"""
    enabled: bool
    camera: str

def mask_credentials_for_log(credentials: HomeHubCredentials) -> str:
    """
    Masque les informations sensibles pour les logs
    """
    username_masked = credentials.username[:3] + '***' if len(credentials.username) > 3 else '***'
    return f"host={credentials.host}:{credentials.port}, user={username_masked}, https={credentials.use_https}"

async def get_homehub_session(credentials: HomeHubCredentials) -> Host:
    """
    Obtient ou crée une session HomeHub (utilise le cache partagé)
    """
    session_key = f"{credentials.host}:{credentials.port}"
    
    logging.info(f"Demande de session pour {mask_credentials_for_log(credentials)}")
    
    # Utilise le système de cache partagé
    host = await camera_sessions.get_camera_session(
        camera_key=session_key,
        host=credentials.host,
        username=credentials.username,
        password=credentials.password,
        port=credentials.port
    )
    
    if not host:
        raise HTTPException(status_code=500, detail=f"Échec de connexion au HomeHub: {credentials.host}")
    
    return host

@app.post("/reolink/discover", response_model=HomeHubInfo)
async def discover_homehub(credentials: HomeHubCredentials):
    """
    Découvre un HomeHub/NVR et liste toutes ses caméras
    """
    try:
        host = await get_homehub_session(credentials)
        
        # Récupérer les informations du HomeHub
        model = host.model if host.is_nvr else host.camera_model(0)
        serial = host.serial() if host.is_nvr else host.camera_sw_version(0)
        hw_version = host.hardware_version
        fw_version = host.sw_version
        
        # Vérifier que c'est bien un NVR/HomeHub
        if not host.is_nvr:
            raise HTTPException(status_code=400, detail="L'appareil n'est pas un HomeHub/NVR")
        
        # Récupérer la liste des caméras
        cameras = []
        for channel_id in host.channels:
            camera_info = CameraInfo(
                channel_id=channel_id,
                name=host.camera_name(channel_id),
                model=host.camera_model(channel_id),
                online=host.camera_online(channel_id),
                uid=host.camera_uid(channel_id) if hasattr(host, 'camera_uid') else None,
                serial=host.camera_serial(channel_id) if hasattr(host, 'camera_serial') else None
            )
            cameras.append(camera_info)
        
        hub_info = HomeHubInfo(
            model=model,
            serial=serial,
            hardware_version=hw_version,
            firmware_version=fw_version,
            channel_count=len(host.channels),
            cameras=cameras
        )
        
        logging.info(f"HomeHub découvert: {model} avec {len(cameras)} caméras")
        return hub_info
        
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Erreur lors de la découverte du HomeHub: {str(e)}")
        raise HTTPException(status_code=500, detail=f"Erreur: {str(e)}")

@app.post("/reolink/camera/{channel_id}/info")
async def get_camera_info(channel_id: int, credentials: HomeHubCredentials):
    """
    Récupère les informations détaillées d'une caméra spécifique
    """
    try:
        host = await get_homehub_session(credentials)
        
        if channel_id not in host.channels:
            raise HTTPException(status_code=404, detail=f"Canal {channel_id} non trouvé")
        
        # Récupérer les informations de la caméra
        camera_data = {
            "channel_id": channel_id,
            "name": host.camera_name(channel_id),
            "model": host.camera_model(channel_id),
            "online": host.camera_online(channel_id),
            "uid": host.camera_uid(channel_id) if hasattr(host, 'camera_uid') else None,
            "serial": host.camera_serial(channel_id) if hasattr(host, 'camera_serial') else None,
            "resolution": host.resolution(channel_id) if hasattr(host, 'resolution') else None,
            "stream": host.stream(channel_id) if hasattr(host, 'stream') else None,
        }
        
        return camera_data
        
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Erreur lors de la récupération des infos caméra {channel_id}: {str(e)}")
        raise HTTPException(status_code=500, detail=f"Erreur: {str(e)}")

@app.post("/reolink/camera/{channel_id}/full_info")
async def get_camera_full_info(channel_id: int, credentials: HomeHubCredentials):
    """
    Récupère toutes les informations d'une caméra (équivalent à GetCamNFO)
    Retourne les informations dans un format compatible avec le code PHP existant
    """
    try:
        host = await get_homehub_session(credentials)
        
        if channel_id not in host.channels:
            raise HTTPException(status_code=404, detail=f"Canal {channel_id} non trouvé")
        
        if not host.camera_online(channel_id):
            raise HTTPException(status_code=503, detail=f"La caméra du canal {channel_id} est hors ligne")
        
        # Construire un objet avec toutes les informations nécessaires
        # Format compatible avec ce que GetCamNFO attend
        full_info = {
            "DevInfo": {
                "name": host.camera_name(channel_id),
                "model": host.camera_model(channel_id),
                "channelNum": channel_id,
                "serialNumber": host.serial(channel_id),
                "hardVer": host.camera_hardware_version(channel_id),
                "firmVer": host.camera_sw_version(channel_id),
                "detail": host.camera_model(channel_id),
                "type": "IPC",
                "buildDay": "Unknown",  # Non exposé par reolink_aio
                "cfgVer": "Unknown",  # Non exposé par reolink_aio
            },
            "P2p": {
                "uid": host.camera_uid(channel_id)
            },
            "LocalLink": {
                "activeLink": "LAN" if not host.wifi_connection(channel_id) else "WAN"
            },
            "AiState": {
                # Vérifier si la caméra supporte l'IA
                "aiTrack": host.ai_supported(channel_id) if hasattr(host, 'ai_supported') else False
            },
            "NetPort": {
                "httpPort": 80 if host.use_https is None else (0 if host.use_https else host.port),
                "httpsPort": 443 if host.use_https is None else (host.port if host.use_https else 0),
                "mediaPort": 9000,  # Port Baichuan par défaut
                "onvifPort": host.onvif_port,
                "rtmpPort": host.rtmp_port,
                "rtspPort": host.rtsp_port,
                "httpEnable": 1 if not host.use_https else 0,
                "httpsEnable": 1 if host.use_https else 0,
                "mediaEnable": 1,  # Baichuan toujours activé pour caméras via HomeHub
                "onvifEnable": 1 if host.onvif_enabled else 0,
                "rtmpEnable": 1 if host.rtmp_enabled else 0,
                "rtspEnable": 1 if host.rtsp_enabled else 0
            },
            # Ajout d'informations supplémentaires de reolink-aio
            "capabilities": {
                "ai_supported": host.ai_supported(channel_id) if hasattr(host, 'ai_supported') else False,
                "ptz_supported": host.ptz_supported(channel_id) if hasattr(host, 'ptz_supported') else False,
                "audio_supported": host.audio_alarm_supported(channel_id) if hasattr(host, 'audio_alarm_supported') else False,
                "two_way_audio": host.audio_supported(channel_id) if hasattr(host, 'audio_supported') else False,
            }
        }
        
        logging.info(f"Informations complètes récupérées pour caméra canal {channel_id}")
        logging.info(f"full_info = {full_info}")
        return full_info
        
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Erreur lors de la récupération des infos complètes caméra {channel_id}: {str(e)}")
        raise HTTPException(status_code=500, detail=f"Erreur: {str(e)}")

@app.post("/reolink/test-connection")
async def test_connection(credentials: HomeHubCredentials):
    """
    Teste la connexion à un HomeHub/NVR
    """
    try:
        host = Host(
            credentials.host,
            credentials.username,
            credentials.password,
            port=credentials.port,
            use_https=credentials.use_https
        )
        
        await host.get_host_data()
        
        return {
            "success": True,
            "is_nvr": host.is_nvr,
            "model": host.nvr_model if host.is_nvr else host.camera_model(0),
            "firmware": host.sw_version
        }
        
    except Exception as e:
        logging.error(f"Échec du test de connexion: {str(e)}")
        return {
            "success": False,
            "error": str(e)
        }

@app.post("/reolink/camera/{channel_id}/test-connection")
async def test_camera_connection(channel_id: int, credentials: HomeHubCredentials):
    """
    Teste la connexion à une caméra spécifique d'un HomeHub/NVR
    Essaye de récupérer les informations de la caméra pour vérifier qu'elle répond
    """
    try:
        host = await get_homehub_session(credentials)
        
        if channel_id not in host.channels:
            return {
                "success": False,
                "error": f"Canal {channel_id} non trouvé"
            }
        
        # Forcer un refresh des données pour vérifier la connexion réelle
        try:
            # Refresh les données de l'hôte pour obtenir l'état actuel
            await host.get_host_data()
            
            # Vérifier que la caméra est online
            camera_online = host.camera_online(channel_id)
            if not camera_online:
                logging.debug(f"Caméra canal {channel_id} est hors ligne")
                return {
                    "success": True,
                    "online": False,
                    "camera_name": host.camera_name(channel_id),
                    "camera_model": host.camera_model(channel_id)
                }
            
            logging.debug(f"Caméra canal {channel_id} est en ligne")
            return {
                "success": True,
                "online": True,
                "camera_name": host.camera_name(channel_id),
                "camera_model": host.camera_model(channel_id)
            }
        except Exception as cmd_error:
            # La commande a échoué, la caméra ne répond pas
            logging.warning(f"Caméra canal {channel_id} ne répond pas: {str(cmd_error)}")
            return {
                "success": False,
                "online": False,
                "error": f"La caméra (canal {channel_id}) ne répond pas: {str(cmd_error)}"
            }
        
    except Exception as e:
        logging.error(f"Échec du test de connexion caméra {channel_id}: {str(e)}")
        return {
            "success": False,
            "error": str(e)
        }

def build_abilities(host: Host, channel: int | None) -> dict:
    """
    Construit l'objet abilities basé sur les capacités réelles de reolink-aio
    
    Args:
        host: Instance Host de reolink-aio
        channel: ID du canal (int pour caméra, None pour HomeHub)
    
    Returns:
        dict: Dictionnaire des capacités au format {ability_name: {ver: X, permit: Y}}
    """
    def check_capability(cap_name: str) -> dict:
        """Retourne {ver: 1, permit: 1} si supporté, sinon permit: 0"""
        if host.supported(channel, cap_name):
            return {"ver": 1, "permit": 1}
        return {"ver": 1, "permit": 0}
    
    def check_api_version(api_name: str) -> dict:
        """Vérifie la version API et retourne permit basé sur la version"""
        ver = host.api_version(api_name, channel)
        return {"ver": ver if ver > 0 else 1, "permit": 1 if ver > 0 else 0}
    
    abilities = {
        # === CAPACITÉS DE BASE ===
        "none": {"ver": 1, "permit": 1},
        
        # === SYSTÈME ===
        "reboot": check_api_version("reboot"),
        "performance": check_capability("performance"),
        "autoMaint": check_api_version("autoMaint"),
        
        # === RÉSEAU ===
        "netPort": check_api_version("netPort"),  # GetNetPort/SetNetPort
        "localLink": check_api_version("localLink"),  # GetLocalLink
        "upnp": check_api_version("upnp"),
        "p2p": check_api_version("p2p"),
        "wifi": check_api_version("wifi"),  # Signal/SSID
        "rtsp": check_api_version("rtsp"),  # RTSP URL
        "onvif": check_api_version("onvif"),  # ONVIF
        
        # === ALARMES ET DÉTECTIONS ===
        "alarmMd": check_capability("motion_detection"),  # GetMdAlarm/SetMdAlarm
        "alarmAudio": check_capability("audio_alarm"),  # Audio alarm
        "supportAudioAlarm": check_api_version("supportAudioAlarm"),
        
        # === DÉTECTION IA AVANCÉE ===
        "aiTrack": check_capability("auto_track"),  # AI tracking
        "supportAiSensitivity": check_capability("ai_sensitivity"),
        "supportAiStayTime": check_capability("ai_delay"),
        "cryDetection": check_api_version("cryDetection"),  # Cry detection
        "yoloAI": check_api_version("yoloAI"),  # YOLO AI settings
        "smartAI": check_api_version("smartAI"),  # Smart AI (crossline, intrusion, etc.)
        
        # === NOTIFICATIONS ===
        "push": check_capability("push"),
        "supportPushInterval": check_capability("push_config"),
        "email": check_capability("email"),
        
        # === STOCKAGE ET ENREGISTREMENT ===
        "sdCard": check_capability("recording"),
        "rec": check_capability("recording"),  # GetRec/SetRecV20
        "preRecord": check_api_version("preRecord"),  # Pre-recording
        "search": check_api_version("search"),  # VOD search
        "snap": check_api_version("snap"),  # Snapshot
        
        # === FTP ===
        "ftp": check_capability("ftp"),
        
        # === LED ET ÉCLAIRAGE ===
        "ledControl": check_api_version("ledControl"),
        "powerLed": check_api_version("powerLed"),  # Status LED
        "irLights": check_api_version("irLights"),  # IR LED brightness
        "supportFLswitch": check_capability("floodLight"),  # Floodlight/Spotlight
        "supportFLBrightness": check_api_version("supportFLBrightness"),
        
        # === ENCODAGE ===
        "enc": check_capability("encoding"),  # GetEnc/SetEnc
        
        # === IMAGE ET ISP ===
        "image": check_api_version("image"),  # GetImage/SetImage
        "isp": check_api_version("isp"),  # GetIsp/SetIsp
        "ispMirror": check_api_version("ispMirror"),
        "ispFlip": check_api_version("ispFlip"),
        "ispBackLight": check_capability("backLight"),
        "ispExposureMode": check_api_version("ispExposureMode"),
        "ispAntiFlick": check_api_version("ispAntiFlick"),
        "isp3Dnr": check_api_version("isp3Dnr"),
        "ispHue": check_api_version("ispHue"),
        "ispSharpen": check_api_version("ispSharpen"),
        "white_balance": check_api_version("white_balance"),
        
        # === OSD ===
        "osd": check_api_version("osd"),
        "waterMark": check_api_version("waterMark"),
        
        # === PRIVACY ===
        "mask": check_capability("privacy_mask"),  # GetMask/SetMask
        "privacyMode": check_api_version("privacyMode"),  # Privacy mode (sleep)
        
        # === PTZ ===
        "ptzPreset": check_capability("ptz_presets"),
        "ptzCtrl": check_capability("ptz"),
        "ptzPatrol": check_capability("ptz_patrol"),
        "ptzPosition": check_api_version("ptzPosition"),  # GetPtzCurPos
        "supportPtzCheck": check_api_version("supportPtzCheck"),
        
        # === FOCUS ===
        "disableAutoFocus": check_capability("auto_focus"),  # GetAutoFocus/SetAutoFocus
        
        # === AUDIO ===
        "audioCfg": check_api_version("audioCfg"),  # GetAudioCfg/SetAudioCfg
        "audioNoise": check_api_version("audioNoise"),  # Audio noise reduction
        "audioAlarmPlay": check_api_version("audioAlarmPlay"),  # AudioAlarmPlay
        
        # === DOORBELL/CHIME ===
        "dingDong": check_api_version("dingDong"),  # GetDingDongList
        "dingDongCfg": check_api_version("dingDongCfg"),  # GetDingDongCfg/SetDingDongCfg
        "dingDongSilent": check_api_version("dingDongSilent"),  # Silent mode
        "dingDongCtrl": check_api_version("dingDongCtrl"),  # Hardwired chime control
        "quickReply": check_api_version("quickReply"),  # QuickReplyPlay
        
        # === PIR SENSOR ===
        "pirInfo": check_api_version("pirInfo"),  # GetPirInfo/SetPirInfo
        
        # === IO ===
        "ioInput": check_api_version("IOInputPortNum"),  # IO inputs
        "ioOutput": check_api_version("IOOutputPortNum"),  # IO outputs
        
        # === RÈGLES DE SURVEILLANCE ===
        "surveillanceRules": check_api_version("surveillanceRules"),  # Surveillance rules (IFTTT)
        
        # === DAY/NIGHT ===
        "dayNight": check_api_version("dayNight"),  # Day/night state
        
        # ============================================================
        # === CAPACITÉS SUPPLÉMENTAIRES VIA HTTP API (non-Baichuan) ===
        # ============================================================
        
        # === CONFIGURATION SYSTÈME (HTTP uniquement) ===
        "getTime": {"ver": 1, "permit": 1},  # GetTime - disponible via HTTP
        "setTime": {"ver": 1, "permit": 1},  # SetTime - disponible via HTTP
        "getNtp": check_api_version("getNtp"),  # GetNtp
        "setNtp": check_api_version("setNtp"),  # SetNtp
        "syncNtp": {"ver": 1, "permit": 1},  # Sync NTP - HTTP uniquement
        
        # === HDD INFO (HTTP uniquement) ===
        "hddInfo": check_api_version("GetHddInfo"),  # GetHddInfo
        
        # === WEBHOOKS (HTTP uniquement) ===
        "webhook": {"ver": 1, "permit": 1},  # Webhook add/remove/test/disable
        
        # === ONVIF SUBSCRIPTION (HTTP uniquement) ===
        "onvifSubscription": {"ver": 1, "permit": 1},  # ONVIF events subscription
        
        # === FIRMWARE (HTTP uniquement) ===
        "checkFirmware": {"ver": 1, "permit": 1},  # check_new_firmware
        "updateFirmware": {"ver": 1, "permit": 1},  # update_firmware
        "uploadFirmware": {"ver": 1, "permit": 1},  # upload_firmware
        
        # === STREAMING (HTTP uniquement) ===
        "rtspStream": {"ver": 1, "permit": 1},  # get_rtsp_stream_source
        "rtmpStream": {"ver": 1, "permit": 1},  # get_rtmp_stream_source
        "flvStream": {"ver": 1, "permit": 1},  # get_flv_stream_source
        
        # === VOD (HTTP uniquement) ===
        "vodRequest": check_api_version("search"),  # request_vod_files
        "vodDownload": {"ver": 1, "permit": 1},  # download_vod
        
        # === PTZ AVANCÉ (HTTP uniquement) ===
        "ptzGuard": check_api_version("ptzGuard"),  # GetPtzGuard/SetPtzGuard
        "ptzCalibrate": check_api_version("supportPtzCheck"),  # ptz_callibrate
        "autoTrackSettings": check_capability("auto_track"),  # set_auto_tracking
        "autoTrackLimit": check_capability("auto_track"),  # set_auto_track_limit
        
        # === ZOOM/FOCUS (HTTP uniquement) ===
        "zoomFocus": check_api_version("supportZoomFocus"),  # get_zoom/set_zoom/get_focus/set_focus
        
        # === SPOTLIGHT SCHEDULE (HTTP uniquement) ===
        "spotlightSchedule": check_api_version("supportFLSchedule"),  # set_spotlight_lighting_schedule
        
        # === STATE LIGHT (HTTP uniquement) ===
        "stateLight": {"ver": 1, "permit": 1},  # set_state_light - état indicateur NVR
        
        # === MOTION/AI STATE POLLING (HTTP uniquement) ===
        "motionState": check_capability("motion_detection"),  # get_motion_state/get_all_motion_states
        "aiState": {"ver": 1, "permit": 1},  # get_ai_state/get_ai_state_all_ch
        
        # === USERS (HTTP uniquement) ===
        "getUser": check_api_version("GetUser"),  # GetUser - liste utilisateurs
        
        # === BATTERY INFO (Baichuan push) ===
        "battery": check_capability("battery"),  # battery info (via Baichuan push)
    }
    
    # === SCÈNES (HomeHub uniquement) ===
    if channel is None:
        abilities["scene"] = {"ver": 1, "permit": 1 if host.baichuan.supported(None, "scenes") else 0}
        abilities["sceneManagement"] = check_api_version("sceneModeCfg")  # get_scene/set_scene/get_scene_info
        
        # Host-level uniquement
        abilities["stateLight"] = {"ver": 1, "permit": 1}  # State light indicator
        abilities["chimeList"] = {"ver": 1, "permit": 1 if len(host.chime_list) > 0 else 0}  # Chime management
    else:
        abilities["scene"] = {"ver": 1, "permit": 0}
    
    return abilities

@app.post("/reolink/camera/{channel_id}/ability")
async def get_camera_ability(channel_id: int, credentials: HomeHubCredentials):
    """
    Récupère les capacités (abilities) d'une caméra spécifique
    Retourne un format compatible avec GetAbility de l'API Reolink
    """
    try:
        host = await get_homehub_session(credentials)
        
        if channel_id not in host.channels:
            raise HTTPException(status_code=404, detail=f"Canal {channel_id} non trouvé")
        
        if not host.camera_online(channel_id):
            raise HTTPException(status_code=503, detail=f"La caméra du canal {channel_id} est hors ligne")
        
        abilities = build_abilities(host, channel_id)
        
        logging.info(f"Capacités récupérées pour caméra canal {channel_id}")
        logging.debug(f"Abilities details: {abilities}")
        return abilities
        
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Échec de la récupération des capacités pour caméra {channel_id}: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/reolink/nvr/ability")
async def get_homehub_ability(credentials: HomeHubCredentials):
    """
    Récupère les capacités (abilities) du HomeHub/NVR lui-même (pas d'une caméra spécifique)
    Utilise channel=None pour interroger le HomeHub en tant que NVR
    Retourne un format compatible avec GetAbility de l'API Reolink
    """
    try:
        host = await get_homehub_session(credentials)
        
        abilities = build_abilities(host, None)
        
        logging.info(f"Capacités récupérées pour HomeHub")
        logging.debug(f"Abilities details: {abilities}")
        return abilities
        
    except Exception as e:
        logging.error(f"Erreur lors de la récupération des capacités du HomeHub: {str(e)}")
        raise HTTPException(status_code=500, detail=f"Erreur: {str(e)}")

@app.delete("/reolink/session")
async def close_session(credentials: HomeHubCredentials):
    """
    Ferme une session Reolink manuellement
    """
    session_key = f"{credentials.host}:{credentials.port}"
    
    if session_key in camera_sessions.camera_sessions:
        session_data = camera_sessions.camera_sessions[session_key]
        await session_data['host'].logout()
        del camera_sessions.camera_sessions[session_key]
        logging.info(f"Session fermée manuellement: {session_key}")
        return {"message": "Session fermée"}
    
    return {"message": "Aucune session active"}

@app.post("/reolink/scenes")
async def get_scenes(credentials: HomeHubCredentials):
    """
    Récupère la liste des scènes disponibles sur le HomeHub/NVR
    Utilise host.baichuan._scenes
    """
    try:
        host = await get_homehub_session(credentials)
        
        if not hasattr(host, 'baichuan') or host.baichuan is None:
            raise HTTPException(status_code=503, detail="Baichuan API non disponible")
        
        # Récupérer les scènes depuis baichuan._scenes
        scenes = host.baichuan._scenes
        
        # Format de retour : dict avec scene_id: scene_name
        logging.info(f"Scènes récupérées: {scenes}")
        return {
            "scenes": scenes,
            "active_scene_id": host.baichuan.active_scene_id,
            "active_scene_name": host.baichuan.active_scene
        }
        
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Erreur lors de la récupération des scènes: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

class SetSceneRequest(BaseModel):
    """Requête pour activer une scène"""
    host: str
    username: str
    password: str
    port: int = 80
    use_https: bool = False
    scene_id: Optional[int] = None
    scene_name: Optional[str] = None

@app.post("/reolink/scene/set")
async def set_scene(request: SetSceneRequest):
    """
    Active une scène spécifique sur le HomeHub/NVR
    Peut être appelé avec scene_id ou scene_name
    Utilise host.baichuan.set_scene()
    """
    try:
        # Créer les credentials depuis la requête
        credentials = HomeHubCredentials(
            host=request.host,
            username=request.username,
            password=request.password,
            port=request.port,
            use_https=request.use_https
        )
        
        host = await get_homehub_session(credentials)
        
        if not hasattr(host, 'baichuan') or host.baichuan is None:
            raise HTTPException(status_code=503, detail="Baichuan API non disponible")
        
        if request.scene_id is None and request.scene_name is None:
            raise HTTPException(status_code=400, detail="scene_id ou scene_name requis")
        
        # Appeler set_scene de baichuan
        await host.baichuan.set_scene(scene_id=request.scene_id, scene_name=request.scene_name)
        
        # Récupérer l'état actuel
        current_scene_id = host.baichuan.active_scene_id
        current_scene_name = host.baichuan.active_scene
        
        logging.info(f"Scène activée: {current_scene_name} (ID: {current_scene_id})")
        return {
            "success": True,
            "active_scene_id": current_scene_id,
            "active_scene_name": current_scene_name
        }
        
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Erreur lors de l'activation de la scène: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/reolink/camera/motion/enable", response_model=MotionDetectionResponse)
async def enable_camera_motion_detection(credentials: CameraCredentials):
    """
    Active la détection de mouvement via Baichuan pour une caméra
    """
    try:
        camera_key = f"{credentials.host}:{credentials.port}"
        logging.info(f"Activation de la détection de mouvement pour {camera_key}")
        
        # Préparer la configuration de la caméra
        cameras_config = {
            camera_key: {
                'host': credentials.host,
                'port': credentials.port,
                'username': credentials.username,
                'password': credentials.password,
                'channel': credentials.channel
            }
        }
        
        # Activer la détection de mouvement
        success = await camera_commands.enable_motion_detection(camera_key, cameras_config)
        
        if success:
            return MotionDetectionResponse(
                success=True,
                message="Détection de mouvement activée avec succès",
                camera=camera_key
            )
        else:
            raise HTTPException(
                status_code=500,
                detail="Échec de l'activation de la détection de mouvement"
            )
            
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Erreur lors de l'activation de la détection: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/reolink/camera/motion/disable", response_model=MotionDetectionResponse)
async def disable_camera_motion_detection(credentials: CameraCredentials):
    """
    Désactive la détection de mouvement via Baichuan pour une caméra
    """
    try:
        camera_key = f"{credentials.host}:{credentials.port}"
        logging.info(f"Désactivation de la détection de mouvement pour {camera_key}")
        
        # Préparer la configuration de la caméra
        cameras_config = {
            camera_key: {
                'host': credentials.host,
                'port': credentials.port,
                'username': credentials.username,
                'password': credentials.password,
                'channel': credentials.channel
            }
        }
        
        # Désactiver la détection de mouvement
        success = await camera_commands.disable_motion_detection(camera_key, cameras_config)
        
        if success:
            return MotionDetectionResponse(
                success=True,
                message="Détection de mouvement désactivée avec succès",
                camera=camera_key
            )
        else:
            raise HTTPException(
                status_code=500,
                detail="Échec de la désactivation de la détection de mouvement"
            )
            
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Erreur lors de la désactivation de la détection: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/reolink/camera/motion/status", response_model=MotionDetectionStatusResponse)
async def get_camera_motion_status(credentials: CameraCredentials):
    """
    Vérifie si la détection de mouvement est activée pour une caméra
    """
    try:
        camera_key = f"{credentials.host}:{credentials.port}"
        logging.debug(f"Vérification du statut de détection pour {camera_key}")
        
        # Préparer la configuration de la caméra
        cameras_config = {
            camera_key: {
                'host': credentials.host,
                'port': credentials.port,
                'username': credentials.username,
                'password': credentials.password,
                'channel': credentials.channel
            }
        }
        
        # Vérifier si la détection est activée
        enabled = await camera_commands.is_motion_detection_enabled(camera_key, cameras_config)
        
        logging.info(f"Statut de détection pour {camera_key}: {'activé' if enabled else 'désactivé'}")
        
        return MotionDetectionStatusResponse(
            enabled=enabled,
            camera=camera_key
        )
            
    except Exception as e:
        logging.error(f"Erreur lors de la vérification du statut: {str(e)}")
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/reolink/camera/{channel_id}/refresh_info")
async def refresh_camera_info(channel_id: int, credentials: HomeHubCredentials):
    """
    Récupère toutes les informations de configuration d'une caméra
    (équivalent à refreshNFO de l'ancienne API)
    Retourne un format compatible avec refreshNFO PHP (array de commandes avec 'cmd' et 'value')
    """
    try:
        host = await get_homehub_session(credentials)
        
        if channel_id not in host.channels:
            raise HTTPException(status_code=404, detail=f"Canal {channel_id} non trouvé")
        
        if not host.camera_online(channel_id):
            raise HTTPException(status_code=503, detail=f"La caméra du canal {channel_id} est hors ligne")
        
        # Format compatible avec refreshNFO : array de commandes
        commands = []
        
        # Recording settings - format CAM_GET_RECV20
        if host.supported(channel_id, "recording"):
            commands.append({
                'cmd': 'GetRecV20',
                'value': {
                    'Rec': {
                        'enable': host.recording_enabled(channel_id),
                        'preRec': 5,
                        'overwrite': 1,
                        'postRec': 30
                    }
                }
            })
        
        # HDD Info - format CAM_GET_HDDINFO
        if host.hdd_info:
            commands.append({
                'cmd': 'GetHddInfo',
                'value': {
                    'HddInfo': [{
                        'format': 1 if host.hdd_info else 0,
                        'mount': 1 if host.hdd_info else 0,
                        'size': 0,
                        'capacity': 100,
                        'storageType': 2  # 1=HDD, 2=SDcard
                    }]
                }
            })
        
        # FTP settings V20 - format CAM_GET_FTPV20
        if host.supported(channel_id, "ftp"):
            commands.append({
                'cmd': 'GetFtpV20',
                'value': {
                    'Ftp': {
                        'enable': host.ftp_enabled(channel_id) if hasattr(host, 'ftp_enabled') else 0
                    }
                }
            })
        
        # Email settings V20 - format CAM_GET_EMAILV20
        if host.supported(channel_id, "email"):
            commands.append({
                'cmd': 'GetEmailV20',
                'value': {
                    'Email': {
                        'enable': host.email_enabled(channel_id) if hasattr(host, 'email_enabled') else 0
                    }
                }
            })
        
        # Push settings V20 - format CAM_GET_PUSHV20
        if host.supported(channel_id, "push"):
            commands.append({
                'cmd': 'GetPushV20',
                'value': {
                    'Push': {
                        'enable': host.push_enabled(channel_id) if hasattr(host, 'push_enabled') else 0
                    }
                }
            })
        
        # Encoding settings - format CAM_GET_ENC
        if host.supported(channel_id, "encoding"):
            commands.append({
                'cmd': 'GetEnc',
                'value': {
                    'Enc': {
                        'audio': host.audio_enabled(channel_id) if hasattr(host, 'audio_enabled') else 0,
                        'mainStream': {
                            'size': '2560*1920',
                            'frameRate': 15,
                            'bitRate': 8192
                        },
                        'subStream': {
                            'size': '640*480',
                            'frameRate': 7,
                            'bitRate': 160
                        }
                    }
                }
            })
        
        # Image settings - format CAM_GET_IMAGE
        commands.append({
            'cmd': 'GetImage',
            'value': {
                'Image': {
                    'bright': host.brightness(channel_id) if hasattr(host, 'brightness') else 50,
                    'contrast': host.contrast(channel_id) if hasattr(host, 'contrast') else 50,
                    'saturation': host.saturation(channel_id) if hasattr(host, 'saturation') else 50,
                    'hue': host.hue(channel_id) if hasattr(host, 'hue') else 50,
                    'sharpen': host.sharpness(channel_id) if hasattr(host, 'sharpness') else 50
                }
            }
        })
        
        # ISP settings - format CAM_GET_ISP
        commands.append({
            'cmd': 'GetIsp',
            'value': {
                'Isp': {
                    'dayNight': host.daynight_state(channel_id) if hasattr(host, 'daynight_state') else 'Auto'
                }
            }
        })
        
        # IR Lights - format CAM_GET_IRLIGHT
        if host.supported(channel_id, "ir_lights"):
            commands.append({
                'cmd': 'GetIrLights',
                'value': {
                    'IrLights': {
                        'state': host.ir_enabled(channel_id) if hasattr(host, 'ir_enabled') else 'Auto'
                    }
                }
            })
        
        # White LED / Floodlight - format CAM_GET_WHITELED
        if host.supported(channel_id, "floodLight"):
            commands.append({
                'cmd': 'GetWhiteLed',
                'value': {
                    'WhiteLed': {
                        'state': host.whiteled_enabled(channel_id) if hasattr(host, 'whiteled_enabled') else 0,
                        'mode': host.whiteled_mode(channel_id) if hasattr(host, 'whiteled_mode') else 0,
                        'bright': host.whiteled_brightness(channel_id) if hasattr(host, 'whiteled_brightness') else 100
                    }
                }
            })
        
        # PTZ Preset - format CAM_GET_PTZPRESET
        if host.supported(channel_id, "ptz_presets"):
            commands.append({
                'cmd': 'GetPtzPreset',
                'value': {
                    'PtzPreset': []  # Liste vide = disponible
                }
            })
        
        # PTZ Guard - format CAM_GET_PTZGUARD
        if host.supported(channel_id, "ptz_guard"):
            commands.append({
                'cmd': 'GetPtzGuard',
                'value': {
                    'PtzGuard': {
                        'bexistPos': host.ptz_guard_enabled(channel_id) if hasattr(host, 'ptz_guard_enabled') else 0,
                        'benable': 0,
                        'timeout': 60
                    }
                }
            })
        
        # PTZ Check - format CAM_GET_PTZCURPOS
        if host.supported(channel_id, "ptz_check"):
            commands.append({
                'cmd': 'GetPtzCurPos',
                'value': {
                    'state': 2  # 0=requis, 1=en cours, 2=terminé
                }
            })
        
        # Audio Alarm - format CAM_GET_AUDIOALARM
        if host.supported(channel_id, "audio_alarm"):
            commands.append({
                'cmd': 'GetAudioAlarm',
                'value': {
                    'Audio': {
                        'enable': host.audio_alarm_enabled(channel_id) if hasattr(host, 'audio_alarm_enabled') else 0
                    }
                }
            })
            commands.append({
                'cmd': 'GetAudioAlarmV20',
                'value': {
                    'AudioCfg': {
                        'volume': 50
                    }
                }
            })
        
        # Power LED - format CAM_GET_POWERLED
        if host.supported(channel_id, "ledControl"):
            commands.append({
                'cmd': 'GetPowerLed',
                'value': {
                    'PowerLed': {
                        'state': host.status_led_enabled(channel_id) if hasattr(host, 'status_led_enabled') else 1
                    }
                }
            })
        
        # Auto Focus - format CAM_GET_AUTOFOCUS
        if host.supported(channel_id, "auto_focus"):
            commands.append({
                'cmd': 'GetAutoFocus',
                'value': {
                    'AutoFocus': {
                        'disable': 0 if (hasattr(host, 'autofocus_enabled') and host.autofocus_enabled(channel_id)) else 1
                    }
                }
            })
        
        # Zoom/Focus - format CAM_GET_ZOOMFOCUS
        if host.supported(channel_id, "zoom_focus"):
            commands.append({
                'cmd': 'GetZoomFocus',
                'value': {
                    'ZoomFocus': {
                        'zoom': {'pos': host.zoom_position(channel_id) if hasattr(host, 'zoom_position') else 0},
                        'focus': {'pos': host.focus_position(channel_id) if hasattr(host, 'focus_position') else 0}
                    }
                }
            })
        
        # AI Track - format CAM_GET_ABILITY_AI
        if host.ai_supported(channel_id):
            commands.append({
                'cmd': 'GetAiCfg',
                'value': {
                    'aiTrack': host.auto_track_enabled(channel_id) if hasattr(host, 'auto_track_enabled') else 0
                }
            })
            
            # AI Alarm sensitivity - format CAM_GET_MDALARM
            commands.append({
                'cmd': 'GetMdAlarm',
                'value': {
                    'MdAlarm': {
                        'newSens': {
                            'sensDef': 50
                        }
                    }
                }
            })
            
            # AI Alarm per type - format CAM_GET_AIALARM
            for ai_type in ['people', 'vehicle', 'dog_cat']:
                commands.append({
                    'cmd': 'GetAiAlarm',
                    'value': {
                        'AiAlarm': {
                            'ai_type': ai_type,
                            'sensitivity': 50,
                            'stay_time': 0
                        }
                    }
                })
        
        logging.info(f"Informations de configuration récupérées pour caméra canal {channel_id} ({len(commands)} commandes)")
        return commands
        
    except HTTPException:
        raise
    except Exception as e:
        logging.error(f"Erreur lors de la récupération des infos de configuration caméra {channel_id}: {str(e)}")
        raise HTTPException(status_code=500, detail=f"Erreur: {str(e)}")

@app.get("/health")
async def health_check():
    """
    Vérification de santé de l'API
    """
    # Nettoyer les sessions expirées
    await camera_sessions.cleanup_expired_sessions()
    
    # Récupérer les caméras avec détection Baichuan active
    active_baichuan = camera_commands.get_active_cameras()
    
    return {
        "status": "healthy",
        "active_sessions": len(camera_sessions.camera_sessions),
        "session_ttl_minutes": camera_sessions.SESSION_TTL_MINUTES,
        "active_baichuan_cameras": active_baichuan
    }

if __name__ == "__main__":
    import uvicorn
    logging.basicConfig(level=logging.INFO)
    uvicorn.run(app, host="127.0.0.1", port=44011)
