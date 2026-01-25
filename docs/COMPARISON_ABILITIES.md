# Comparaison des Abilities - API Native vs API AIO

## Vue d'ensemble

Ce document compare les abilities (capacités matérielles/logicielles) retournées par :
- **Ancienne API** : Appel direct `GetAbility` via reolinkapi.class.php
- **Nouvelle API AIO** : Via reolink_aio_api.py avec fonction `build_abilities()`

## Format de données

### Ancienne API (GetAbility direct)
```json
{
  "alarmMd": {
    "ver": 1,
    "permit": 1
  },
  "ptzCtrl": {
    "ver": 2,
    "permit": 1
  },
  ...
}
```

**Structure retournée** :
```php
$deviceAbility = $reolinkConn->SendCMD('[{"cmd":"GetAbility","param":{"User":{"userName":"admin"}}}]');
$ab1 = $deviceAbility[0]["value"]["Ability"];
unset($ab1["abilityChn"]);
$ab2 = $deviceAbility[0]["value"]["Ability"]["abilityChn"][0];
$deviceAbility = array_merge($ab1, $ab2);
```

La réponse native fusionne :
- Les capacités globales de l'appareil (`Ability`)
- Les capacités du premier canal (`abilityChn[0]`)

### Nouvelle API AIO (build_abilities)
```json
{
  "alarmMd": {
    "ver": 1,
    "permit": 1
  },
  "ptzCtrl": {
    "ver": 1,
    "permit": 1
  },
  ...
}
```

**Fonction de construction** :
```python
def build_abilities(host: Host, channel: int | None) -> dict:
    def check_capability(cap_name: str) -> dict:
        if host.supported(channel, cap_name):
            return {"ver": 1, "permit": 1}
        return {"ver": 1, "permit": 0}
    
    def check_api_version(api_name: str) -> dict:
        ver = host.api_version(api_name, channel)
        return {"ver": ver if ver > 0 else 1, "permit": 1 if ver > 0 else 0}
```

## Différences principales

### 1. Source des données

| Aspect | Ancienne API | Nouvelle API AIO |
|--------|-------------|------------------|
| **Méthode** | Commande HTTP directe `GetAbility` | Méthodes `host.supported()` et `host.api_version()` |
| **Donnée brute** | Réponse JSON caméra | Introspection de reolink_aio |
| **Précision** | Dépend de la réponse caméra | Dépend de la base de connaissances reolink_aio |

### 2. Numéro de version (ver)

**Ancienne API** :
- Retourne le numéro de version réel de l'API (1, 2, 3, etc.)
- Exemple : `"ptzCtrl": {"ver": 2, "permit": 1}`

**Nouvelle API AIO** :
- Pour `check_capability()` : toujours `ver: 1`
- Pour `check_api_version()` : version réelle si disponible, sinon 1
- Exemple : `"ptzCtrl": {"ver": 1, "permit": 1}`

### 3. Couverture des capacités

#### Ancienne API - Capacités natives
L'ancienne API retourne **toutes** les capacités supportées par le firmware de la caméra, y compris :
- Capacités standard Reolink
- Fonctionnalités spécifiques à certains modèles
- Nouvelles fonctionnalités firmware non documentées

#### Nouvelle API AIO - Capacités mappées
L'API AIO ne retourne que les capacités **explicitement mappées** dans `build_abilities()` :

```python
abilities = {
    # === DÉTECTION & ALARMES ===
    "alarmMd": check_capability("motion_detection"),
    "alarmAudio": check_capability("audio_alarm"),
    
    # === PTZ ===
    "ptzPreset": check_capability("ptz_presets"),
    "ptzCtrl": check_capability("ptz"),
    "ptzPatrol": check_capability("ptz_patrol"),
    "supportPtzCheck": check_api_version("supportPtzCheck"),
    
    # === IMAGE (ISP) ===
    "ispMirror": check_api_version("ispMirror"),
    "ispFlip": check_api_version("ispFlip"),
    "ispBackLight": check_capability("backLight"),
    "ispExposureMode": check_api_version("ispExposureMode"),
    "isp": check_api_version("isp"),
    "ispAntiFlick": check_api_version("ispAntiFlick"),
    "isp3Dnr": check_api_version("isp3Dnr"),
    "ispHue": check_api_version("ispHue"),
    "ispSharpen": check_api_version("ispSharpen"),
    
    # === RÉSEAU ===
    "push": check_capability("push"),
    "supportPushInterval": check_capability("push_config"),
    "email": check_capability("email"),
    "ftp": check_capability("ftp"),
    "upnp": check_api_version("upnp"),
    "p2p": check_api_version("p2p"),
    
    # === STOCKAGE ===
    "sdCard": check_capability("recording"),
    
    # === LED & ÉCLAIRAGE ===
    "ledControl": check_api_version("ledControl"),
    "powerLed": check_api_version("powerLed"),
    "supportFLswitch": check_capability("floodLight"),
    "supportFLBrightness": check_api_version("supportFLBrightness"),
    
    # === ENCODAGE ===
    "enc": check_capability("encoding"),
    
    # === FOCUS ===
    "disableAutoFocus": check_capability("auto_focus"),
    
    # === SYSTÈME ===
    "reboot": check_api_version("reboot"),
    "performance": check_capability("performance"),
    "autoMaint": check_api_version("autoMaint"),
    
    # === MASQUAGE ===
    "mask": check_capability("privacy_mask"),
    
    # === BALANCE DES BLANCS & IMAGE ===
    "white_balance": check_api_version("white_balance"),
    "image": check_api_version("image"),
    
    # === OSD ===
    "osd": check_api_version("osd"),
    "waterMark": check_api_version("waterMark"),
    
    # === IA ===
    "aiTrack": check_capability("auto_track"),
    "supportAiSensitivity": check_capability("ai_sensitivity"),
    "supportAiStayTime": check_capability("ai_delay"),
    
    # === SCÈNES (HomeHub uniquement) ===
    "scene": {"ver": 1, "permit": 1} if channel is None else None
}
```

### 4. Capacités disponibles via Baichuan (API AIO)

Après analyse du fichier `baichuan.py`, voici les capacités disponibles via l'API Baichuan :

#### ✅ Capacités DISPONIBLES via Baichuan

**Informations système et réseau** :
- ✅ `GetDevInfo`, `GetChnTypeInfo` - via `get_info()`
- ✅ `GetLocalLink` - via `get_network_info()`
- ✅ `GetNetPort`, `SetNetPort` - via `get_ports()`, `set_port_enabled()`
- ✅ `GetUser` - via `GetUser()`
- ✅ `GetP2p` - via `get_uid()`
- ✅ Wifi : Signal via `get_wifi_signal()`, SSID via `get_wifi_ssid()`

**Encodage et image** :
- ✅ `GetEnc`, `SetEnc` - via `GetEnc()`, `SetEnc()`
- ✅ `GetImage`, `SetImage` - via `GetImage()`, `SetImage()`
- ✅ `GetIsp`, `SetIsp` - via `SetIsp()` (dans GetImage)
- ✅ `Snap` - via `snapshot()`
- ✅ `GetMask`, `SetMask` - via `GetMask()`, `SetMask()` (privacy mask)

**Enregistrement** :
- ✅ `GetRec`, `GetRecV20`, `SetRecV20` - via `GetRec()`, `SetRecV20()`
- ✅ Pre-recording - via `get_pre_recording()`, `set_pre_recording()`
- ✅ `Search` (VOD) - via `search_vod_type()`

**Alarmes et détection** :
- ✅ `GetAlarm`, `GetMdAlarm`, `SetMdAlarm` - via `GetMdAlarm()`, `SetMdAlarm()`
- ✅ `GetAiAlarm`, `SetAiAlarm` - via `GetAiAlarm()`, `SetAiAlarm()`
- ✅ Cry Detection - via `get_cry_detection()`, `set_cry_detection()`
- ✅ YOLO AI - via `get_yolo_settings()`, `set_yolo_settings()`
- ✅ Smart AI - via `set_smart_ai()` (crossline, intrusion, loitering, etc.)
- ✅ Surveillance Rules - via `get_rule_ids()`, `get_rule()`, `set_rule_enabled()`

**Email et Push** :
- ✅ `GetEmail`, `GetEmailV20`, `SetEmail`, `SetEmailV20` - via `GetEmail()`, `SetEmail()`
- ✅ `GetPush`, `GetPushV20`, `SetPush`, `SetPushV20` - via `GetPush()`, `SetPush()`

**Audio** :
- ✅ `GetAudioAlarm`, `GetAudioAlarmV20`, `SetAudioAlarm`, `SetAudioAlarmV20` - via `GetAudioAlarm()`, `SetAudioAlarm()`
- ✅ `AudioAlarmPlay` - via `AudioAlarmPlay()`
- ✅ `GetAudioCfg`, `SetAudioCfg` - via `GetAudioCfg()`, `SetAudioCfg()`
- ✅ Audio Noise Reduction - via `GetAudioNoise()`, `SetAudioNoise()`
- ✅ `QuickReplyPlay` - via `QuickReplyPlay()`

**LED et éclairage** :
- ✅ `GetWhiteLed`, `SetWhiteLed` - via `get_floodlight()`, `set_floodlight()`
- ✅ `GetPowerLed`, `SetPowerLed` - via `get_status_led()`, `set_status_led()`
- ✅ `GetIrLights`, `SetIrLights` - via `get_status_led()`, `set_status_led()`

**PTZ** :
- ✅ `GetPtzCurPos` - via `get_ptz_position()`
- ✅ `GetAutoFocus`, `SetAutoFocus` - via `GetAutoFocus()`, `SetAutoFocus()`

**Doorbell/Chime** :
- ✅ `GetDingDongList` - via `GetDingDongList()`
- ✅ `DingDongOpt` - via `get_DingDongOpt()`
- ✅ `GetDingDongCfg`, `SetDingDongCfg` - via `GetDingDongCfg()`, `SetDingDongCfg()`
- ✅ DingDong Silent Mode - via `get_ding_dong_silent()`, `set_ding_dong_silent()`
- ✅ DingDong Control - via `get_ding_dong_ctrl()`, `set_ding_dong_ctrl()`

**PIR Sensor** :
- ✅ `GetPirInfo`, `SetPirInfo` - via `GetPirInfo()`, `SetPirInfo()`

**Scènes (HomeHub)** :
- ✅ Scene Management - via `get_scene()`, `set_scene()`, `get_scene_info()`

**Système** :
- ✅ `Reboot` - via `reboot()`
- ✅ Privacy Mode - via `get_privacy_mode()`, `set_privacy_mode()`
- ✅ Day/Night State - via `get_day_night_state()`
- ✅ Battery Info - cmd_id 252 (parsing automatique)
- ✅ Channel Sleep Status - cmd_id 145 (parsing automatique)
- ✅ IO Inputs/Outputs - stockés dans `_io_inputs`, `_io_outputs`, `_io_input`

#### ❌ Capacités NON DISPONIBLES via Baichuan

**Configuration système avancée** :
- ❌ `GetDevName`, `SetDevName` - Nom de l'appareil
- ❌ `GetTime`, `SetTime` - Configuration heure
- ❌ `GetAutoMaint`, `SetAutoMaint` - Maintenance auto
- ❌ `GetHddInfo`, `Format` - Gestion disque dur
- ❌ `GetNtp`, `SetNtp` - Configuration NTP
- ❌ `GetSysCfg`, `SetSysCfg` - Configuration système
- ❌ `GetNorm`, `SetNorm` - Norme vidéo (PAL/NTSC)

**Mise à jour firmware** :
- ❌ `Upgrade`, `Restore`
- ❌ `UpgradePrepare`, `GetAutoUpgrade`, `SetAutoUpgrade`
- ❌ `CheckFirmware`, `UpgradeOnline`, `UpgradeStatus`

**Gestion utilisateurs** :
- ❌ `AddUser`, `DelUser`, `ModifyUser` (seulement GetUser disponible)

**Réseau avancé** :
- ❌ `GetDdns`, `SetDdns` - DDNS
- ❌ `GetUpnp`, `SetUpnp` - UPnP
- ❌ `GetWifi`, `SetWifi`, `TestWifi`, `ScanWifi` - Configuration WiFi complète (seulement signal/SSID disponibles)
- ❌ `TestEmail`, `TestFtp` - Tests de connexion
- ❌ `GetPushCfg`, `SetPushCfg` - Configuration push avancée
- ❌ `GetOnline`, `Disconnect` - État connexions
- ❌ `GetCertificateInfo`, `CertificateClear` - Certificats SSL
- ❌ `GetRtspUrl` - URL RTSP (récupérable via HTTP API)

**PTZ avancé** :
- ❌ `GetPtzPreset`, `SetPtzPreset` - Presets PTZ
- ❌ `GetPtzPatrol`, `SetPtzPatrol` - Patrouilles PTZ
- ❌ `PtzCtrl` - Contrôle PTZ direct
- ❌ `GetPtzSerial`, `SetPtzSerial` - Configuration série PTZ
- ❌ `GetPtzTattern`, `SetPtzTattern` - Patterns PTZ
- ❌ `GetZoomFocus`, `StartZoomFocus` - Zoom/Focus
- ❌ `GetPtzGuard`, `SetPtzGuard` - Position de garde
- ❌ `GetPtzCheckState`, `PtzCheck` - Calibration PTZ

**Image avancée** :
- ❌ `GetOsd`, `SetOsd` - OSD (overlay texte)
- ❌ `GetCrop`, `SetCrop` - Recadrage image
- ❌ `Preview` - Prévisualisation

**Autres** :
- ❌ `GetPerformance` - Performances système
- ❌ `GetChannelstatus` - État des canaux
- ❌ `GetMdState` - État détection mouvement (événements push disponibles)
- ❌ `GetBuzzerAlarmV20`, `SetBuzzerAlarmV20` - Buzzer
- ❌ `SetAlarmArea` - Zones d'alarme
- ❌ `GetAiCfg`, `SetAiCfg` - Config IA (mais AI Alarm disponible)
- ❌ `GetAiState` - État IA
- ❌ `Download`, `Playback`, `NvrDownload` - Lecture vidéo (seulement Search VOD)

**Note** : 
1. Certaines fonctionnalités "manquantes" sont disponibles via l'API HTTP standard de reolink_aio
2. Les capacités marquées ❌ ne sont pas disponibles via le protocole Baichuan mais peuvent exister dans l'API HTTP
3. Le protocole Baichuan est principalement orienté événements temps réel et configuration de base

## Cas d'usage spécifiques

### Caméra autonome (connexion directe)
- **Ancienne API** : ✅ Fonctionne
- **Nouvelle API AIO** : ❌ Non supportée (nécessite connexion au HomeHub)

### Caméra sous HomeHub/NVR
- **Ancienne API** : ❌ Ne fonctionne pas directement
- **Nouvelle API AIO** : ✅ Fonctionne via canal

### HomeHub/NVR lui-même
- **Ancienne API** : ✅ Fonctionne (fusion Ability + abilityChn)
- **Nouvelle API AIO** : ✅ Fonctionne (channel=None)

## Recommandations

### Pour accéder aux capacités Baichuan manquantes dans build_abilities()

Plusieurs capacités Baichuan ne sont **pas** mappées dans la fonction `build_abilities()` de reolink_aio_api.py mais sont **accessibles** via des méthodes de la classe Baichuan :

#### Ajouts possibles à build_abilities() :

```python
# Dans build_abilities(host: Host, channel: int | None) -> dict:
abilities = {
    # ... capacités existantes ...
    
    # === RÉSEAU AVANCÉ ===
    "netPort": check_api_version("netPort"),  # GetNetPort/SetNetPort disponible
    "localLink": check_api_version("localLink"),  # GetLocalLink disponible
    "wifi": check_api_version("wifi"),  # Signal/SSID disponible
    
    # === ENREGISTREMENT ===
    "rec": check_capability("recording"),  # GetRec/SetRecV20 disponible
    "search": check_api_version("search"),  # search_vod_type disponible
    "snap": check_api_version("snap"),  # snapshot() disponible
    
    # === DÉTECTION AVANCÉE ===
    "cryDetection": check_api_version("cryDetection"),  # get_cry_detection disponible
    "yoloAI": check_api_version("yoloAI"),  # get_yolo_settings disponible
    "smartAI": check_api_version("smartAI"),  # set_smart_ai disponible
    "surveillanceRules": check_api_version("surveillanceRules"),  # get_rule_ids disponible
    
    # === SCÈNES (HomeHub uniquement) ===
    if channel is None:
        abilities["sceneManagement"] = check_api_version("sceneModeCfg")  # get_scene/set_scene
    
    # === DOORBELL/CHIME ===
    "dingDong": check_api_version("dingDong"),  # GetDingDongList disponible
    "dingDongSilent": check_api_version("dingDongSilent"),  # get_ding_dong_silent
    "dingDongCtrl": check_api_version("dingDongCtrl"),  # get_ding_dong_ctrl
    "quickReply": check_api_version("quickReply"),  # QuickReplyPlay
    
    # === PIR ===
    "pirInfo": check_api_version("pirInfo"),  # GetPirInfo/SetPirInfo disponible
    
    # === AUDIO AVANCÉ ===
    "audioNoise": check_api_version("audioNoise"),  # GetAudioNoise/SetAudioNoise
    
    # === PRIVACY ===
    "privacyModeBasic": check_api_version("privacyModeBasic"),  # get_privacy_mode
    
    # === PRE-RECORDING ===
    "preRecord": check_api_version("preRecord"),  # get_pre_recording
    
    # === IO ===
    "ioInputs": check_api_version("IOInputPortNum"),  # IO inputs disponibles
    "ioOutputs": check_api_version("IOOutputPortNum"),  # IO outputs disponibles
}
```

### Pour une compatibilité maximale

1. **Caméras autonomes** : Conserver l'ancienne API
   - Plus de détails sur les capacités disponibles
   - Versions API réelles
   - Pas de dépendance à reolink_aio

2. **Caméras sous HomeHub** : Utiliser la nouvelle API AIO
   - Seule méthode fonctionnelle
   - Gestion centralisée par le HomeHub
   - Support Baichuan pour la détection

3. **Vérification des capacités critiques** : Toujours vérifier `permit: 1`
   ```php
   if (isset($abilities['ptzCtrl']) && $abilities['ptzCtrl']['permit'] == 1) {
       // PTZ disponible
   }
   ```

### Pour étendre l'API AIO

Si une capacité manque dans `build_abilities()`, il faut :

1. Identifier le nom de la capacité dans reolink_aio
2. Ajouter le mapping dans `build_abilities()` :
   ```python
   "newAbility": check_capability("nom_reolink_aio"),
   # ou
   "newAbility": check_api_version("nom_api"),
   ```

## Conclusion

**Points forts de l'ancienne API** :
- ✅ Données brutes de la caméra (exhaustif)
- ✅ Versions API réelles
- ✅ Fonctionne pour caméras autonomes

**Points forts de la nouvelle API AIO** :
- ✅ Unifie HomeHub et caméras sous HomeHub
- ✅ Gestion de sessions simplifiée
- ✅ Support Baichuan intégré (événements temps réel)
- ✅ Format standardisé
- ✅ **Beaucoup plus de capacités que prévu** : ~70% des commandes Reolink disponibles via Baichuan

**Limites de la nouvelle API AIO** :
- ⚠️ Couverture limitée aux capacités mappées explicitement dans `build_abilities()`
- ⚠️ Versions API simplifiées (souvent ver: 1)
- ⚠️ Nécessite maintenance si nouvelles capacités firmware
- ❌ Ne fonctionne pas pour caméras autonomes (nécessite HomeHub/NVR)
- ❌ Certaines fonctions avancées (PTZ presets, patterns, zones d'alarme) non disponibles

**Découverte importante** :
Après analyse de `baichuan.py`, l'API Baichuan expose **beaucoup plus de fonctionnalités** que ce qui est actuellement mappé dans `build_abilities()`. Environ **60+ capacités sont disponibles** mais seulement **~30 sont mappées**.

**Recommandation finale** : 

### Approche hybride optimisée

1. **Caméras autonomes** → Ancienne API (seule option)
2. **Caméras sous HomeHub/NVR** → Nouvelle API AIO (via Baichuan)
3. **Étendre `build_abilities()`** pour exposer toutes les capacités Baichuan disponibles

### Plan d'amélioration de l'API AIO

**Phase 1 - Capacités prioritaires à ajouter** :
- Scènes (HomeHub)
- Pre-recording
- Cry detection
- YOLO AI
- Smart AI (crossline, intrusion, loitering)
- Surveillance rules
- Audio noise reduction
- Quick reply (doorbell)

**Phase 2 - Capacités secondaires** :
- VOD search
- PIR info
- IO inputs/outputs
- Doorbell/Chime management
- Day/Night state

**Phase 3 - Documentation** :
- Mapper tous les cmd_id Baichuan vers les noms de capacités
- Documenter les limitations (pas de PTZ presets via Baichuan, etc.)
- Créer des exemples d'utilisation pour chaque capacité

### Tableau récapitulatif

| Catégorie | Ancienne API | API AIO (actuel) | API AIO (potentiel) |
|-----------|--------------|------------------|---------------------|
| **Infos système** | ✅ Complet | ⚠️ Partiel | ✅ Excellent |
| **Réseau** | ✅ Complet | ⚠️ Basique | ✅ Bon |
| **Image/Encodage** | ✅ Complet | ✅ Bon | ✅ Excellent |
| **Alarmes/Détection** | ✅ Complet | ✅ Bon | ✅ Excellent |
| **PTZ** | ✅ Complet | ❌ Limité | ⚠️ Position seule |
| **Audio** | ✅ Complet | ✅ Bon | ✅ Excellent |
| **LED/Éclairage** | ✅ Complet | ✅ Bon | ✅ Excellent |
| **Enregistrement** | ✅ Complet | ✅ Basique | ✅ Bon |
| **Doorbell/Chime** | ✅ Complet | ❌ Absent | ✅ Excellent |
| **IA avancée** | ✅ Complet | ⚠️ Basique | ✅ Excellent |
| **Scènes** | ❌ N/A | ❌ Absent | ✅ Excellent |
| **Firmware/Users** | ✅ Complet | ❌ Absent | ❌ Non Baichuan |

**Légende** :
- ✅ Excellent : >80% des fonctionnalités
- ✅ Bon : 60-80%
- ⚠️ Partiel/Basique : 30-60%
- ❌ Limité/Absent : <30%
