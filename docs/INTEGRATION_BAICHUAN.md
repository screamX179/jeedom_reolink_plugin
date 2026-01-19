# Intégration du mode de détection TCP/Baichuan

## Vue d'ensemble

Cette intégration permet au plugin Reolink de choisir entre deux modes de détection de mouvement :
- **ONVIF** : Mode standard utilisant le protocole ONVIF avec webhook HTTP
- **TCP/Baichuan** : Mode natif Reolink utilisant le protocole Baichuan avec connexion TCP persistante

## Modifications apportées

### 1. Configuration du plugin (`plugin_info/configuration.php`)

Ajout d'un nouveau paramètre de configuration `detection_mode` avec deux options :
- `onvif` : Mode ONVIF (par défaut)
- `baichuan` : Mode TCP/Baichuan

### 2. Modules Python ajoutés

#### `resources/demond/camera_sessions.py`
- Gestion du cache des sessions caméra pour les connexions reolink_aio
- Validation automatique des sessions
- Reconnexion automatique en cas de session expirée
- Partagé entre `reolink_aio_api` et `camera_commands`

#### `resources/demond/camera_commands.py`
- Implémentation des commandes caméra via Baichuan
- `enable_motion_detection()` : Active la détection de mouvement
  - Utilise `baichuan.register_callback()` pour enregistrer les événements
  - Supporte détection de mouvement et détection IA des personnes
  - Envoie les événements via `jeedom_com.send_change_immediate()`
- `disable_motion_detection()` : Désactive la détection
- `active_preset()` : Active un preset de caméra
- `active_siren()` : Active la sirène

#### `resources/demond/reolink_aio_api.py`
- API HTTP FastAPI pour contrôler les caméras Reolink via reolink_aio
- Endpoints pour activer/désactiver la détection de mouvement
- Gestion des sessions caméra partagée avec `camera_commands`
- Démarré automatiquement par le démon sur le port configuré (44011 par défaut)

### 3. Daemon modifié (`resources/demond/reolinkd.py`)

**Imports ajoutés** :
```python
import camera_commands
```

**Nouveaux paramètres** :
- `--detection_mode` : Spécifie le mode de détection (onvif ou baichuan)
- `--reolink_aio_api_port` : Port pour l'API Reolink AIO (par défaut 44011)

**Modifications principales** :

1. **Initialisation** :
   - Configure `camera_commands` avec `set_jeedom_com(jeedom_cnx)`
   - Écrit les credentials Jeedom dans le fichier `jeedomcreds` pour `camhook` et `reolink_aio_api`
   - Démarre l'API Reolink AIO (tous modes)
   - Démarre le webhook uniquement en mode ONVIF
   - Log du mode de détection au démarrage

2. **`start_uvicorn()` et `start_reolink_aio_api()`** :
   - Le webhook (camhook) ne démarre que si mode ONVIF
   - L'API Reolink AIO démarre toujours (tous modes)
   - Chaque service tourne dans un processus séparé

3. **`read_socket()`** :
   - Mode ONVIF : Traite les commandes `sethook` pour la subscription ONVIF via webhook
   - Mode Baichuan : Non utilisé (la détection est gérée via l'API Reolink AIO)

### 4. Classe PHP modifiée (`core/class/reolink.class.php`)

**Fonction `deamon_start()`** :
- Ajout du paramètre `--detection_mode` lors du lancement du démon
- Récupère la valeur depuis `config::byKey('detection_mode', __CLASS__, 'onvif')`
- Ajout du paramètre `--reolink_aio_api_port` pour configurer le port de l'API

**Fonction `callReolinkAioAPI()`** :
- Appelle l'API Reolink AIO sur le port configuré
- Utilise la clé de configuration `reolink_aio_api_port` (par défaut 44011)

## Utilisation

### Configuration

1. Aller dans la page de configuration du plugin Reolink
2. Sélectionner le mode de détection souhaité :
   - **ONVIF** : Pour les caméras compatibles ONVIF standard
   - **TCP/Baichuan** : Pour les caméras Reolink récentes (plus réactif)
3. Sauvegarder la configuration
4. Redémarrer le démon

### Mode ONVIF (par défaut)

- Utilise le webhook HTTP sur le port configuré (44010 par défaut)
- Les caméras envoient des événements XML ONVIF au webhook
- Le plugin renouvelle les subscriptions toutes les 10 minutes

### Mode TCP/Baichuan

- Pas de webhook HTTP nécessaire
- Connexion TCP persistante avec la caméra via reolink_aio
- Callbacks enregistrés pour les événements de mouvement
- Plus réactif car connexion directe

## Structure des données pour Baichuan

Pour utiliser le mode Baichuan, utilisez l'API Reolink AIO via HTTP :

**Activer la détection de mouvement** :
```bash
POST http://127.0.0.1:44011/enable_motion_detection
Content-Type: application/json

{
    "camera_key": "nom_camera",
    "cameras_config": {
        "nom_camera": {
            "host": "192.168.1.100",
            "port": 9000,
            "username": "admin",
            "password": "password",
            "channel": 0,
            "jeedom_motion_cmd_id": "123",
            "jeedom_people_cmd_id": "124",
            "jeedom_vehicle_cmd_id": "125",
            "jeedom_pet_cmd_id": "126"
        }
    }
}
```

**Désactiver la détection** :
```bash
POST http://127.0.0.1:44011/disable_motion_detection
```

**Vérifier l'état** :
```bash
GET http://127.0.0.1:44011/is_motion_detection_enabled?camera_key=nom_camera
```

## Avantages du mode Baichuan

1. **Plus réactif** : Connexion TCP directe sans passer par HTTP
2. **Pas de configuration réseau** : Pas besoin de configurer l'IP de callback
3. **Support natif Reolink** : Utilise le protocole propriétaire Reolink
4. **Détection IA** : Support de la détection des personnes via IA
5. **Cache intelligent** : Réutilise les connexions existantes

## Compatibilité

- **Mode ONVIF** : Compatible avec toutes les caméras supportant ONVIF
- **Mode Baichuan** : Compatible avec les caméras Reolink récentes supportant le protocole Baichuan

## Dépendances

- `reolink-aio` : Bibliothèque Python pour l'API Reolink (déjà installée)
- `fastapi` : Framework web pour l'API HTTP
- `uvicorn` : Serveur ASGI pour FastAPI
- Module `camera_sessions` : Gestion partagée des sessions caméra
- Module `camera_commands` : Implémentation des commandes Baichuan
- Module `reolink_aio_api` : API HTTP pour contrôler les caméras

## Logs

Le mode de détection est affiché dans les logs au démarrage du démon :
```
Detection mode : onvif
Webhook IP : 192.168.1.10
Webhook port : 44010
Reolink AIO API port : 44011
Starting webhook (ONVIF mode)...
Starting Reolink API (reolink-aio)...
```
ou
```
Detection mode : baichuan
Webhook IP : 192.168.1.10
Webhook port : 44010
Reolink AIO API port : 44011
Starting Reolink API (reolink-aio)...
```

## Troubleshooting

### Le mode Baichuan ne fonctionne pas

1. Vérifier que `reolink-aio` est installé : `pip list | grep reolink`
2. Vérifier les logs du démon : `/var/log/reolink_daemon`
3. S'assurer que les caméras supportent le protocole Baichuan
4. Vérifier que les IDs de commandes Jeedom sont bien configurés

### Passage d'un mode à l'autre

1. Modifier le paramètre `detection_mode` dans la configuration
2. Sauvegarder
3. Redémarrer le démon
4. Les subscriptions existantes seront automatiquement nettoyées
