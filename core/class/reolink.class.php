<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */
/* * ***************************Includes********************************* */
require_once __DIR__  . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../../3rdparty/reolinkapi.class.php';

const CMD_SEND_QTY = 8;

class reolink extends eqLogic {

  const PYTHON_PATH = __DIR__ . '/../../resources/venv/bin/python3';

  /************* Static methods ************/
  public static function getReolinkConnection($id) {
    $camera = reolink::byId($id, 'reolink');
    $adresseIP = $camera->getConfiguration('adresseip');
    $port = $camera->getConfiguration('port');
    $username = $camera->getConfiguration('login');
    $password = $camera->getConfiguration('password');
    $cnxtype = $camera->getConfiguration('cnxtype');

    if (!empty($adresseIP) && !empty($username) && !empty($password)) {
      $cnxinfo = array("adresseIP" => $adresseIP, "port" => $port, "username" => $username, "password" => $password, "cnxtype" => $cnxtype);
      $camcnx = new reolinkAPI($cnxinfo);
      return $camcnx;
    } else {
      log::add('reolink', 'warning', "Information de connexion manquantes : connexion à la caméra impossible");
      return NULL;
    }
  }

  public static function TryConnect($id) {
    $camera = reolink::byId($id, 'reolink');
    
    // Vérifier si c'est une caméra sous HomeHub
    $parentHubId = $camera->getConfiguration('parent_hub_id');
    if (!empty($parentHubId)) {
      log::add('reolink', 'info', 'Test de connexion à la caméra HomeHub via API');
      return reolink::TryConnectHomeHubCamera($id);
    }
    
    // Sinon, utiliser la méthode classique
    $reolinkConn = reolink::getReolinkConnection($id);
    if ($reolinkConn->is_loggedin == true) {
      log::add('reolink', 'info', 'Connection à la caméra réussie');
      return true;
    } else {
      log::add('reolink', 'error', 'Connection à la caméra NOK');
      return false;
    }
  }

  /**
   * Prépare les credentials du HomeHub pour un appel API
   * @return array|false Retourne les credentials ou false en cas d'erreur
   */
  private static function prepareHomeHubCredentials($cameraId) {
    $camera = reolink::byId($cameraId, 'reolink');
    
    $parentHubId = $camera->getConfiguration('parent_hub_id');
    $channelId = $camera->getConfiguration('defined_channel');
    
    if (empty($parentHubId) || $channelId === null) {
      log::add('reolink', 'error', 'Caméra HomeHub mal configurée: parent_hub_id ou defined_channel manquant');
      return false;
    }
    
    // Récupérer les informations du HomeHub parent
    $homeHub = reolink::byId($parentHubId, 'reolink');
    if (!is_object($homeHub)) {
      log::add('reolink', 'error', 'HomeHub parent non trouvé: ID=' . $parentHubId);
      return false;
    }
    
    return array(
      'channel_id' => $channelId,
      'credentials' => array(
        'host' => $homeHub->getConfiguration('adresseip'),
        'username' => $homeHub->getConfiguration('login'),
        'password' => $homeHub->getConfiguration('password'),
        'port' => intval($homeHub->getConfiguration('port', 80)),
        'use_https' => $homeHub->getConfiguration('cnxtype') == 'https'
      )
    );
  }

  /**
   * Prépare les credentials pour un équipement (HomeHub ou caméra)
   * @param int $deviceId ID de l'équipement
   * @return array Credentials de connexion
   */
  private static function getDeviceCredentials($deviceId) {
    $device = reolink::byId($deviceId, 'reolink');
    return array(
      'host' => $device->getConfiguration('adresseip'),
      'username' => $device->getConfiguration('login'),
      'password' => $device->getConfiguration('password'),
      'port' => intval($device->getConfiguration('port', 80)),
      'use_https' => $device->getConfiguration('cnxtype') == 'https'
    );
  }

  /**
   * Vérifie si un équipement est un HomeHub/NVR
   * @param int $deviceId ID de l'équipement
   * @param string $functionName Nom de la fonction appelante (pour les logs)
   * @return bool True si c'est un HomeHub, False sinon
   */
  private static function isHomeHub($deviceId, $functionName = '') {
    $device = reolink::byId($deviceId, 'reolink');
    $isNVR = $device->getConfiguration('isNVR');
    
    if ($isNVR !== 'Oui') {
      if (!empty($functionName)) {
        log::add('reolink', 'warning', $functionName . ' appelé sur un équipement qui n\'est pas un HomeHub');
      }
      return false;
    }
    
    return true;
  }

  /**
   * Masque les informations sensibles dans les credentials pour les logs
   * @param array $credentials Credentials à masquer
   * @return string Version sécurisée pour les logs
   */
  private static function maskCredentialsForLog($credentials) {
    if (!is_array($credentials)) {
      return 'Invalid credentials';
    }
    
    $safe = array(
      'host' => $credentials['host'] ?? 'unknown',
      'port' => $credentials['port'] ?? 'unknown',
      'username' => isset($credentials['username']) ? substr($credentials['username'], 0, 3) . '***' : 'unknown',
      'password' => '***',
      'use_https' => $credentials['use_https'] ?? false
    );
    
    // Ajouter scene_id si présent (pas sensible)
    if (isset($credentials['scene_id'])) {
      $safe['scene_id'] = $credentials['scene_id'];
    }
    
    return json_encode($safe);
  }

  /**
   * Appel générique à l'API Reolink (reolink-aio)
   */
  private static function callReolinkAioAPI($endpoint, $credentials, $timeout = 30) {
    $apiPort = config::byKey('reolink_aio_api_port', __CLASS__, '44011');
    $apiUrl = 'http://127.0.0.1:' . $apiPort . $endpoint;
    
    log::add('reolink', 'debug', 'Appel API Reolink: ' . $apiUrl . ' avec credentials: ' . self::maskCredentialsForLog($credentials));
    
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($credentials));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode != 200 || !$response) {
      $errorMsg = 'Erreur API Reolink ' . $endpoint . ' (HTTP ' . $httpCode . ')';
      if ($curlError) {
        $errorMsg .= ' - Erreur curl: ' . $curlError;
      }
      if ($response) {
        $errorMsg .= ' - Réponse: ' . substr($response, 0, 200);
      }
      log::add('reolink', 'error', $errorMsg);
      return false;
    }
    
    $result = json_decode($response, true);
    if (!$result) {
      log::add('reolink', 'error', 'Réponse API invalide pour ' . $endpoint . ' (pas un JSON valide)');
      return false;
    }
    
    return $result;
  }

  /**
   * Teste la connexion à une caméra via l'API HomeHub
   */
  private static function TryConnectHomeHubCamera($id) {
    $config = reolink::prepareHomeHubCredentials($id);
    if (!$config) {
      return false;
    }
    
    $endpoint = '/reolink/camera/' . $config['channel_id'] . '/test-connection';
    $result = reolink::callReolinkAioAPI($endpoint, $config['credentials']);
    
    if (!$result) {
      return false;
    }
    
    if ($result['success']) {
      log::add('reolink', 'info', 'Connexion à la caméra ' . $result['camera_name'] . ' (canal ' . $config['channel_id'] . ') réussie');
      return true;
    } else {
      log::add('reolink', 'error', 'Échec connexion caméra: ' . $result['error']);
      return false;
    }
  }

  /**
   * Télécharge l'icône d'une caméra depuis le CDN Reolink
   */
  private static function downloadCameraIcon($cameraId, $modelName) {
    $modelURL = urlencode($modelName);
    $iconurl = "https://cdn.reolink.com/wp-content/assets/app/model-images/$modelURL/light_off.png";
    
    $dir = realpath(dirname(__FILE__) . '/../../desktop');
    
    if (!file_exists($dir . '/img')) {
      mkdir($dir . '/img', 0775, true);
      log::add('reolink', 'debug', 'Création du répertoire visuel caméra = ' . $dir . '/img');
    }
    
    $fileToWrite = $dir . '/img/camera' . $cameraId . '.png';
    
    log::add('reolink', 'debug', 'Enregistrement du visuel de la caméra ' . $modelName . ' depuis serveur Reolink (' . $iconurl . ' => ' . $fileToWrite . ')');
    
    $ch = curl_init($iconurl);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $rawdata = curl_exec($ch);
    
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpcode == 200) {
      log::add('reolink', 'debug', 'HTTP code 200 OK');
      $fp = fopen($fileToWrite, 'w');
      fwrite($fp, $rawdata);
      fclose($fp);
      log::add('reolink', 'debug', 'Ecriture OK');
      return $iconurl;
    } else {
      log::add('reolink', 'error', 'HTTP code ' . $httpcode . ' NOK');
      return false;
    }
  }

  /**
   * Traite et enregistre les informations d'une caméra
   */
  private static function processCameraInfo($cameraId, $devInfo, $p2pUid, $localLink, $aiSupported, $netPort) {
    $camera = reolink::byId($cameraId, 'reolink');
    
    // Traiter DevInfo
    if ($devInfo) {
      foreach ($devInfo as $key => $value) {
        log::add('reolink', 'debug', 'Enregistrement : K=' . $key . ' V=' . $value);
        $camera->setConfiguration($key, $value);
        
        if ($key == "model") {
          $iconurl = reolink::downloadCameraIcon($cameraId, $value);
          if ($iconurl) {
            $camera->setConfiguration("camicon", $iconurl);
          }
        }
      }
    }
    
    // Traiter P2P uid
    if ($p2pUid) {
      log::add('reolink', 'debug', 'Enregistrement : K=uid V=' . $p2pUid);
      $camera->setConfiguration("uid", $p2pUid);
    }
    
    // Traiter LocalLink
    if ($localLink) {
      log::add('reolink', 'debug', 'Enregistrement : K=linkconnection V=' . $localLink);
      $camera->setConfiguration("linkconnection", $localLink);
    }
    
    // Traiter AI support
    $camera->setConfiguration("supportai", $aiSupported ? "Oui" : "Non");
    
    // Traiter NetPort
    if ($netPort) {
      foreach ($netPort as $key => $value) {
        log::add('reolink', 'debug', 'Enregistrement : K=' . $key . ' V=' . $value);
        
        if (strpos($key, 'Enable') !== false) {
          $value = str_replace("0", "(désactiver)", $value);
          $value = str_replace("1", "(actif)", $value);
        }
        
        $camera->setConfiguration($key, $value);
      }
    }
    
    $camera->Save();
    return true;
  }

  /**
   * Récupère les informations d'une caméra via l'API HomeHub
   */
  private static function GetCamNFOFromHomeHub($id) {
    log::add('reolink', 'debug', 'Récupération des informations via API HomeHub');
    
    $config = reolink::prepareHomeHubCredentials($id);
    if (!$config) {
      return false;
    }
    
    $endpoint = '/reolink/camera/' . $config['channel_id'] . '/full_info';
    $fullInfo = reolink::callReolinkAioAPI($endpoint, $config['credentials']);
    
    if (!$fullInfo) {
      return false;
    }
    
    log::add('reolink', 'debug', 'Informations reçues de l\'API: ' . print_r($fullInfo, true));
    
    // Extraire les informations
    $devInfo = isset($fullInfo['DevInfo']) ? $fullInfo['DevInfo'] : null;
    $p2pUid = isset($fullInfo['P2p']['uid']) ? $fullInfo['P2p']['uid'] : null;
    $localLink = isset($fullInfo['LocalLink']['activeLink']) ? $fullInfo['LocalLink']['activeLink'] : null;
    $aiSupported = isset($fullInfo['capabilities']['ai_supported']) ? $fullInfo['capabilities']['ai_supported'] : false;
    $netPort = isset($fullInfo['NetPort']) ? $fullInfo['NetPort'] : null;
    
    // Traiter les informations
    $result = reolink::processCameraInfo($id, $devInfo, $p2pUid, $localLink, $aiSupported, $netPort);
    
    if ($result) {
      log::add('reolink', 'info', 'Informations récupérées avec succès via API HomeHub');
    }
    
    return $result;
  }

  public static function GetCamNFO($id) {
    log::add('reolink', 'debug', 'Obtention des informations de la caméra');
    $camera = reolink::byId($id, 'reolink');

    // Vérifier si c'est une caméra sous HomeHub
    $parentHubId = $camera->getConfiguration('parent_hub_id');
    if (!empty($parentHubId)) {
      log::add('reolink', 'info', 'Caméra sous HomeHub détectée, utilisation de l\'API reolink-aio');
      return reolink::GetCamNFOFromHomeHub($id);
    }

    // Sinon, utiliser la méthode classique par connexion directe
    log::add('reolink', 'debug', 'Caméra autonome, connexion directe');
    
    // Devices Info
    $reolinkConn = reolink::getReolinkConnection($id);
    $deviceInfo = $reolinkConn->SendCMD('[{"cmd":"GetDevInfo"},{"cmd":"GetP2P"},{"cmd":"GetLocalLink"},{"cmd":"GetAiState"},{"cmd":"GetNetPort"}]');

    if (!$deviceInfo) {
      return false;
    }

    // Extraire les informations depuis la réponse
    $devInfo = $deviceInfo[0]['value']["DevInfo"];
    $p2pUid = $deviceInfo[1]['value']["P2p"]['uid'];
    $localLink = $deviceInfo[2]['value']["LocalLink"]['activeLink'];
    $aiSupported = isset($deviceInfo[3]['value']);
    $netPort = $deviceInfo[4]['value']['NetPort'];
    
    // Traiter les informations avec la fonction commune
    reolink::processCameraInfo($id, $devInfo, $p2pUid, $localLink, $aiSupported, $netPort);

    log::add('reolink', 'debug', 'GetDeviceInfo ajout de ' . count($devInfo) . ' items');
    if (count($devInfo) > 1) {
      $camera = reolink::byId($id, 'reolink');
      
      // Détecter si c'est un HomeHub/NVR
      $model = $camera->getConfiguration('model', '');
      $isHomeHub = false;
      
      // Détection basée sur le modèle ou le nombre de canaux
      if (stripos($model, 'RLN') !== false || stripos($model, 'HomeHub') !== false) {
        $isHomeHub = true;
      } else {
        // Vérifier via GetChannelStatus
        $channelStatus = $reolinkConn->SendCMD('[{"cmd":"GetChannelStatus"}]');
        if ($channelStatus && isset($channelStatus[0]['value']['status'])) {
          $channels = $channelStatus[0]['value']['status'];
          if (is_array($channels) && count($channels) > 1) {
            $isHomeHub = true;
          }
        }
      }
      
      if ($isHomeHub) {
        log::add('reolink', 'info', 'HomeHub/NVR détecté : ' . $model);
        $camera->setConfiguration('isNVR', 'Oui');
        $camera->Save();
      } else {
        $camera->setConfiguration('isNVR', 'Non');
        $camera->Save();
      }
      
      return true;
    } else {
      return false;
    }
  }

  /**
   * Récupère les capacités d'une caméra HomeHub via l'API
   */
  private static function GetCamAbilityFromHomeHub($id) {
    log::add('reolink', 'debug', 'Récupération des capacités via API HomeHub');
    
    $config = reolink::prepareHomeHubCredentials($id);
    if (!$config) {
      return false;
    }
    
    $endpoint = '/reolink/camera/' . $config['channel_id'] . '/ability';
    $abilities = reolink::callReolinkAioAPI($endpoint, $config['credentials']);
    
    if (!$abilities) {
      return false;
    }
    
    log::add('reolink', 'debug', 'Capacités reçues de l\'API: ' . print_r($abilities, true));
    log::add('reolink', 'debug', 'GetAbility à récupérer : ' . count($abilities) . ' items');
    
    return $abilities;
  }

  /**
   * Récupère les capacités d'un HomeHub via l'API
   */
  private static function GetHomeHubAbility($id) {
    log::add('reolink', 'debug', 'Récupération des capacités du HomeHub via API');
    
    // Utiliser l'endpoint spécifique au HomeHub (sans channel, utilise None côté Python)
    $endpoint = '/reolink/nvr/ability';
    $credentials = reolink::getDeviceCredentials($id);
    $abilities = reolink::callReolinkAioAPI($endpoint, $credentials);
    
    if (!$abilities) {
      return false;
    }
    
    log::add('reolink', 'debug', 'Capacités HomeHub reçues de l\'API: ' . print_r($abilities, true));
    log::add('reolink', 'debug', 'GetAbility à récupérer : ' . count($abilities) . ' items');
    
    return $abilities;
  }

  public static function GetCamAbility($id) {
    log::add('reolink', 'debug', 'Interrogation de la caméra sur ses capacités hardware/software...');
    $camera = reolink::byId($id, 'reolink');
    
    // Vérifier si c'est une caméra sous HomeHub
    $parentHubId = $camera->getConfiguration('parent_hub_id');
    if (!empty($parentHubId)) {
      log::add('reolink', 'info', 'Caméra sous HomeHub détectée, utilisation de l\'API reolink-aio');
      return reolink::GetCamAbilityFromHomeHub($id);
    }
    
    // Vérifier si c'est un HomeHub lui-même
    $isNVR = $camera->getConfiguration('isNVR');
    if ($isNVR === 'Oui') {
      log::add('reolink', 'info', 'HomeHub/NVR détecté, utilisation de l\'API reolink-aio');
      return reolink::GetHomeHubAbility($id);
    }
    
    // Sinon, utiliser la méthode classique par connexion directe
    log::add('reolink', 'debug', 'Caméra autonome, connexion directe');
    $reolinkConn = reolink::getReolinkConnection($id);

    $username = $camera->getConfiguration('login');
    if (empty($username)) {
      $username = "admin";
    }

    // Devices Ability
    if (is_object($reolinkConn)) {
      $deviceAbility = $reolinkConn->SendCMD('[{"cmd":"GetAbility","param":{"User":{"userName":"' . $username . '"}}}]');

      //log::add('reolink', 'debug', print_r($deviceAbility, true));

      $ab1 = $deviceAbility[0]["value"]["Ability"];
      unset($ab1["abilityChn"]);
      $ab2 = $deviceAbility[0]["value"]["Ability"]["abilityChn"][0];
      $deviceAbility = array_merge($ab1, $ab2);


      log::add('reolink', 'debug', 'GetAbility à récupérer : ' . count($deviceAbility) . ' items');

      if (count($deviceAbility) > 1) {
        return $deviceAbility;
      } else {
        return false;
      }
    } else {
      return false;
    }
  }

  public static function updatePTZpreset($id, $data) {
    $camera = reolink::byId($id, 'reolink');
    $cmd = $camera->getCmd(null, 'SetPtzByPreset');
    $ptzlist = "";
    log::add('reolink', 'debug',  'PTZ à parser = ' . print_r($data['value']['PtzPreset'], true));
    if (is_object($cmd) && is_array($data)) {
      foreach ($data['value']['PtzPreset']  as $key => $value) {
        if ($value['enable'] == 1) {
          log::add('reolink', 'debug',  'Ajout du PTZ preset = ' . $value['id'] . '|' . $value['name']);
          $ptzlist .=  $value['id'] . '|' . $value['name'] . ";";
        }
      }
      $ptzlist = substr($ptzlist, 0, -1);
      $cmd->setConfiguration('listValue', $ptzlist);
      $cmd->save();
      $cmd->getEqLogic()->refreshWidget();
      return true;
    } else {
      return false;
    }
  }

  /**
   * Récupère la liste des scènes depuis le HomeHub et met à jour la commande
   */
  public static function updateScenes($id) {
    // Vérifier que c'est bien un HomeHub
    if (!reolink::isHomeHub($id, 'updateScenes')) {
      return false;
    }
    
    $device = reolink::byId($id, 'reolink');
    $cmd = $device->getCmd(null, 'SetScene');
    if (!is_object($cmd)) {
      log::add('reolink', 'error', 'Commande SetScene non trouvée');
      return false;
    }
    
    // Appeler l'API pour récupérer les scènes
    $endpoint = '/reolink/scenes';
    $credentials = reolink::getDeviceCredentials($id);
    $response = reolink::callReolinkAioAPI($endpoint, $credentials);
    
    if (!$response || !isset($response['scenes'])) {
      log::add('reolink', 'error', 'Échec de la récupération des scènes');
      return false;
    }
    
    $sceneList = "-1|Désactiver les scènes";
    log::add('reolink', 'debug', 'Scènes à parser = ' . print_r($response['scenes'], true));
    
    foreach ($response['scenes'] as $scene_id => $scene_name) {
      // Ne pas inclure la scène -1 (off) dans la liste
      if ($scene_id >= 0) {
        log::add('reolink', 'debug', 'Ajout de la scène = ' . $scene_id . '|' . $scene_name);
        $sceneList .= ";" . $scene_id . '|' . $scene_name;
      }
    }
    
    $cmd->setConfiguration('listValue', $sceneList);
    $cmd->save();
    $device->refreshWidget();
    
    log::add('reolink', 'info', 'Scènes mises à jour avec succès');
    return true;
  }

  /**
   * Active une scène sur le HomeHub
   */
  public static function setScene($id, $scene_id) {
    // Vérifier que c'est bien un HomeHub
    if (!reolink::isHomeHub($id, 'setScene')) {
      return false;
    }
    
    log::add('reolink', 'debug', 'Activation de la scène: ' . $scene_id);
    
    // Préparer le payload avec credentials et scene_id
    $credentials = reolink::getDeviceCredentials($id);
    $payload = array_merge(
      array('scene_id' => intval($scene_id)),
      $credentials
    );
    
    // Appeler l'API pour activer la scène
    $result = reolink::callReolinkAioAPI('/reolink/scene/set', $payload);
    
    if (!$result || !isset($result['success']) || !$result['success']) {
      log::add('reolink', 'error', 'Échec de l\'activation de la scène ' . $scene_id);
      return false;
    }
    
    log::add('reolink', 'info', 'Scène activée : ' . $result['active_scene_name'] . ' (ID: ' . $result['active_scene_id'] . ')');
    return true;
  }

  /**
   * Active la détection de mouvement via Baichuan pour une caméra
   */
  public static function enableMotionDetection($id) {
    $camera = reolink::byId($id, 'reolink');
    if (!is_object($camera)) {
      log::add('reolink', 'error', 'Caméra non trouvée : ID=' . $id);
      return false;
    }
    
    log::add('reolink', 'debug', 'Activation de la détection de mouvement pour la caméra : ' . $camera->getName());
    
    // Préparer les credentials pour l'appel API
    $credentials = array(
      'host' => $camera->getConfiguration('adresseip'),
      'username' => $camera->getConfiguration('login'),
      'password' => $camera->getConfiguration('password'),
      'port' => intval($camera->getConfiguration('port', 9000)),
      'channel' => intval($camera->getConfiguration('defined_channel', 0))
    );
    
    // Appeler l'API pour activer la détection de mouvement
    $result = reolink::callReolinkAioAPI('/reolink/camera/motion/enable', $credentials, 60);
    
    if (!$result || !isset($result['success']) || !$result['success']) {
      $errorMsg = isset($result['detail']) ? $result['detail'] : 'Erreur inconnue';
      log::add('reolink', 'error', 'Échec de l\'activation de la détection de mouvement : ' . $errorMsg);
      throw new Exception(__('Échec de l\'activation de la détection de mouvement', __FILE__));
    }
    
    log::add('reolink', 'info', 'Détection de mouvement activée pour ' . $result['camera']);
    
    // Mettre à jour l'état en interrogeant l'API
    reolink::updateMotionDetectionState($id);
    
    return true;
  }

  /**
   * Désactive la détection de mouvement via Baichuan pour une caméra
   */
  public static function disableMotionDetection($id) {
    $camera = reolink::byId($id, 'reolink');
    if (!is_object($camera)) {
      log::add('reolink', 'error', 'Caméra non trouvée : ID=' . $id);
      return false;
    }
    
    log::add('reolink', 'debug', 'Désactivation de la détection de mouvement pour la caméra : ' . $camera->getName());
    
    // Préparer les credentials pour l'appel API
    $credentials = array(
      'host' => $camera->getConfiguration('adresseip'),
      'username' => $camera->getConfiguration('login'),
      'password' => $camera->getConfiguration('password'),
      'port' => intval($camera->getConfiguration('port', 9000)),
      'channel' => intval($camera->getConfiguration('defined_channel', 0))
    );
    
    // Appeler l'API pour désactiver la détection de mouvement
    $result = reolink::callReolinkAioAPI('/reolink/camera/motion/disable', $credentials, 60);
    
    if (!$result || !isset($result['success']) || !$result['success']) {
      $errorMsg = isset($result['detail']) ? $result['detail'] : 'Erreur inconnue';
      log::add('reolink', 'error', 'Échec de la désactivation de la détection de mouvement : ' . $errorMsg);
      throw new Exception(__('Échec de la désactivation de la détection de mouvement', __FILE__));
    }
    
    log::add('reolink', 'info', 'Détection de mouvement désactivée pour ' . $result['camera']);
    
    // Mettre à jour l'état en interrogeant l'API
    reolink::updateMotionDetectionState($id);
    
    return true;
  }

  /**
   * Met à jour l'état de la détection de mouvement en interrogeant l'API
   */
  public static function updateMotionDetectionState($id) {
    $camera = reolink::byId($id, 'reolink');
    if (!is_object($camera)) {
      log::add('reolink', 'error', 'Caméra non trouvée : ID=' . $id);
      return false;
    }
    
    // Préparer les credentials pour l'appel API
    $credentials = array(
      'host' => $camera->getConfiguration('adresseip'),
      'username' => $camera->getConfiguration('login'),
      'password' => $camera->getConfiguration('password'),
      'port' => intval($camera->getConfiguration('port', 9000)),
      'channel' => intval($camera->getConfiguration('defined_channel', 0))
    );
    
    // Appeler l'API pour récupérer le statut
    $result = reolink::callReolinkAioAPI('/reolink/camera/motion/status', $credentials, 10);
    
    if ($result && isset($result['enabled'])) {
      $enabled = $result['enabled'] ? 1 : 0;
      $camera->checkAndUpdateCmd('motionDetectionState', $enabled);
      log::add('reolink', 'debug', 'État de détection de mouvement mis à jour: ' . $enabled);
      return true;
    } else {
      log::add('reolink', 'warning', 'Impossible de récupérer l\'état de la détection de mouvement');
      return false;
    }
  }

  /**
   * Appelle l'API HomeHub pour découvrir et créer les caméras
   */
  public static function discoverAndCreateCamerasFromAPI($homeHubId) {
    $homeHub = reolink::byId($homeHubId, 'reolink');
    if (!is_object($homeHub)) {
      log::add('reolink', 'error', 'HomeHub non trouvé : ID=' . $homeHubId);
      return false;
    }

    log::add('reolink', 'info', 'Découverte des caméras du HomeHub via API...');

    // Préparer les données de connexion
    $credentials = reolink::getDeviceCredentials($homeHubId);

    // Appeler l'API HomeHub
    $hubInfo = reolink::callReolinkAioAPI('/reolink/discover', $credentials);
    
    if (!$hubInfo || !isset($hubInfo['cameras'])) {
      log::add('reolink', 'error', 'Réponse API invalide pour /reolink/discover');
      return false;
    }

    log::add('reolink', 'info', 'HomeHub découvert : ' . $hubInfo['model'] . ' avec ' . count($hubInfo['cameras']) . ' caméras');

    // Créer un équipement pour chaque caméra
    foreach ($hubInfo['cameras'] as $cameraData) {
      if (!$cameraData['online']) {
        log::add('reolink', 'info', 'Caméra hors ligne ignorée : ' . $cameraData['name']);
        continue;
      }

      reolink::createCameraEquipmentFromHub($homeHubId, $cameraData);
    }

    return true;
  }

  /**
   * Crée un équipement caméra depuis les données de l'API HomeHub
   */
  private static function createCameraEquipmentFromHub($homeHubId, $cameraData) {
    $homeHub = reolink::byId($homeHubId, 'reolink');
    $channelId = $cameraData['channel_id'];
    
    // Vérifier si l'équipement existe déjà
    $logicalId = 'homehub_' . $homeHubId . '_ch' . $channelId;
    $existingCameras = eqLogic::byLogicalId($logicalId, 'reolink');
    if (is_object($existingCameras)) {
      log::add('reolink', 'info', 'Équipement déjà existant pour ' . $cameraData['name'] . ' (canal ' . $channelId . ')');
      return;
    }

    // Vérifier aussi par parent_hub_id et channel
    $allCameras = eqLogic::byType('reolink');
    foreach ($allCameras as $cam) {
      if ($cam->getConfiguration('parent_hub_id') == $homeHubId && 
          $cam->getConfiguration('defined_channel') == $channelId) {
        log::add('reolink', 'info', 'Équipement déjà existant pour ' . $cameraData['name'] . ' (ID: ' . $cam->getId() . ')');
        return;
      }
    }

    log::add('reolink', 'info', 'Création de l\'équipement pour la caméra : ' . $cameraData['name'] . ' (canal ' . $channelId . ')');

    // Créer le nouvel équipement
    $camera = new reolink();
    $camera->setName($cameraData['name']);
    $camera->setLogicalId($logicalId);
    $camera->setEqType_name('reolink');
    $camera->setIsEnable(1);
    $camera->setIsVisible(1);
    
    // Copier les informations de connexion du HomeHub
    $camera->setConfiguration('adresseip', $homeHub->getConfiguration('adresseip'));
    $camera->setConfiguration('port', $homeHub->getConfiguration('port'));
    $camera->setConfiguration('login', $homeHub->getConfiguration('login'));
    $camera->setConfiguration('password', $homeHub->getConfiguration('password'));
    $camera->setConfiguration('cnxtype', $homeHub->getConfiguration('cnxtype'));
    $camera->setConfiguration('port_onvif', $homeHub->getConfiguration('port_onvif', '8000'));
    
    // Informations spécifiques à la caméra
    $camera->setConfiguration('defined_channel', $channelId);
    $camera->setConfiguration('parent_hub_id', $homeHubId);
    $camera->setConfiguration('parent_hub_name', $homeHub->getName());
    $camera->setConfiguration('model', $cameraData['model']);
    $camera->setConfiguration('isNVR', 'Non');
    
    if (isset($cameraData['uid']) && !empty($cameraData['uid'])) {
      $camera->setConfiguration('uid', $cameraData['uid']);
    }
    if (isset($cameraData['serial']) && !empty($cameraData['serial'])) {
      $camera->setConfiguration('serial', $cameraData['serial']);
    }
    
    // Sauvegarder
    $camera->save();
    
    log::add('reolink', 'info', 'Équipement créé avec succès (ID: ' . $camera->getId() . ')');
    log::add('reolink', 'info', 'Cliquez sur "Récupérer les informations" pour obtenir toutes les données de la caméra');
    
    return $camera->getId();
  }

  public static function refreshNFO($id) {
    $camcmd = reolink::byId($id, 'reolink');
    
    // Vérifier si c'est un HomeHub/NVR ou une caméra sous HomeHub
    $parentHubId = $camcmd->getConfiguration('parent_hub_id');
    $isHomeHub = $camcmd->getConfiguration('is_homehub', false);
    
    // Si HomeHub/NVR, récupérer les données via l'API Reolink AIO
    if (!empty($parentHubId) || $isHomeHub) {
      log::add('reolink', 'debug', 'Rafraichissement via API Reolink AIO...');
      
      if ($isHomeHub) {
        // C'est un HomeHub/NVR - pas encore implémenté
        log::add('reolink', 'info', 'Rafraichissement HomeHub/NVR - non implémenté pour le moment');
        return true;
      }
      
      // C'est une caméra sous HomeHub
      $config = reolink::prepareHomeHubCredentials($id);
      if (!$config) {
        log::add('reolink', 'error', 'Impossible de préparer les credentials pour la caméra');
        return false;
      }
      
      // Récupérer les commandes via l'API
      $endpoint = '/reolink/camera/' . $config['channel_id'] . '/refresh_info';
      $cmd_results = reolink::callReolinkAioAPI($endpoint, $config['credentials'], 'POST');
      
      if (!$cmd_results || !is_array($cmd_results)) {
        log::add('reolink', 'error', 'Impossible de récupérer les informations de configuration de la caméra');
        return false;
      }
      
      // Mapper les noms de commandes string en constantes reolinkAPI
      foreach ($cmd_results as &$json_data) {
        if (isset($json_data['cmd']) && is_string($json_data['cmd'])) {
          $const_name = 'reolinkAPI::CAM_' . strtoupper(preg_replace('/([a-z])([A-Z])/', '$1_$2', $json_data['cmd']));
          if (defined($const_name)) {
            $json_data['cmd'] = constant($const_name);
          }
        }
      }
      unset($json_data);
      
      // Créer un tableau de résultats au format attendu par le reste du code
      $cmd_block = [$cmd_results];
      
    } else {
      // Méthode classique pour les caméras autonomes
      $camcnx = reolink::getReolinkConnection($id);
      $cmdget = NULL;

      if ($camcnx->is_loggedin == false) {
        exit();
      }

      log::add('reolink', 'debug', 'Rafraichissement des informations de la caméra...');

      $channel = $camcmd->getConfiguration('defined_channel', 0);

      // Prepare request with INFO needed
      $cmdarr = [];
      foreach (reolinkCmd::byEqLogicId($id) as $cmd) {
        $payload = $cmd->getConfiguration('payload');
        if ($cmd->getType() == "info" && $payload != NULL) {
          $payload = str_replace('#CHANNEL#', $channel, $payload);
          $payload = str_replace('\\', '', $payload);

          if (!in_array($payload, $cmdarr)) {
            $cmdarr[] = $payload;
          }
          $cmd_block = array_chunk($cmdarr, config::byKey('cmdblock', __CLASS__, CMD_SEND_QTY));
        }
      }
    }

    // Traitement des commandes (commun à toutes les sources)
    foreach ($cmd_block as $key => &$value) {
      // Si provient de l'API AIO, $value est déjà le tableau de résultats
      // Sinon, il faut envoyer la commande via reolinkAPI
      if (!empty($parentHubId) || $isHomeHub) {
        $res = $value; // Déjà les résultats de l'API
      } else {
        $cmdget = "";
        foreach ($value as &$value2) {
          $cmdget .= $value2 . ",";
        }
        $cmdget = substr($cmdget, 0, -1);
        log::add('reolink', 'debug', 'Envoi ' . ($key + 1) . '/' . count($cmd_block) . ' payload multiple GetSetting = ' . $cmdget);
        $res = $camcnx->SendCMD("[$cmdget]");
      }

      foreach ($res as &$json_data) {
        log::add('reolink', 'debug', 'Lecture info > ' . preg_replace('/\s+/', '', print_r($json_data, true)));

        switch ($json_data['cmd']) {
          case reolinkAPI::CAM_GET_REC:
            $camcmd->checkAndUpdateCmd('SetRecordState', $json_data['value']['Rec']['schedule']['enable']);
            $camcmd->checkAndUpdateCmd('SetPreRecordState', $json_data['value']['Rec']['preRec']);
            $camcmd->checkAndUpdateCmd('SetOverwriteState', $json_data['value']['Rec']['overwrite']);
            $camcmd->checkAndUpdateCmd('SetPostRecordState', $json_data['value']['Rec']['postRec']);
            break;

          case reolinkAPI::CAM_GET_RECV20:
            $camcmd->checkAndUpdateCmd('SetRecordStateV20', $json_data['value']['Rec']['enable']);
            $camcmd->checkAndUpdateCmd('SetPreRecordStateV20', $json_data['value']['Rec']['preRec']);
            $camcmd->checkAndUpdateCmd('SetOverwriteStateV20', $json_data['value']['Rec']['overwrite']);
            $camcmd->checkAndUpdateCmd('SetPostRecordStateV20', $json_data['value']['Rec']['postRec']);
            break;

          case reolinkAPI::CAM_GET_HDDINFO:
            if ($json_data['value']['HddInfo'][0]['format'] == 1 && $json_data['value']['HddInfo'][0]['mount'] == 1) {
              $camcmd->checkAndUpdateCmd('driveAvailable', 1);
            } else {
              $camcmd->checkAndUpdateCmd('driveAvailable', 0);
            }
            if (is_numeric($json_data['value']['HddInfo'][0]['size']) && is_numeric($json_data['value']['HddInfo'][0]['capacity'])) {
              $percoccupancy = round(($json_data['value']['HddInfo'][0]['size'] * 100) / $json_data['value']['HddInfo'][0]['capacity'], 0, PHP_ROUND_HALF_DOWN);
              $camcmd->checkAndUpdateCmd('driveSpaceAvailable', $percoccupancy);
            }
            if ($json_data['value']['HddInfo'][0]['storageType'] == 1) {
              $camcmd->checkAndUpdateCmd('driveType', "HDD");
            } elseif ($json_data['value']['HddInfo'][0]['storageType'] == 2) {
              $camcmd->checkAndUpdateCmd('driveType', "Sdcard");
            }
            break;

          case reolinkAPI::CAM_GET_OSD:
            $camcmd->checkAndUpdateCmd('SetWatermarkState', $json_data['value']['Osd']['watermark']);
            $camcmd->checkAndUpdateCmd('SetOsdTimeState', $json_data['value']['Osd']['osdTime']['enable']);
            $camcmd->checkAndUpdateCmd('SetOsdChannelState', $json_data['value']['Osd']['osdChannel']['enable']);
            $camcmd->checkAndUpdateCmd('SetPosOsdTimeState', $json_data['value']['Osd']['osdTime']['pos']);
            $camcmd->checkAndUpdateCmd('SetPosOsdChannelState', $json_data['value']['Osd']['osdChannel']['pos']);
            break;

          case reolinkAPI::CAM_GET_FTP:
            $camcmd->checkAndUpdateCmd('SetFTPState', $json_data['value']['Ftp']['schedule']['enable']);
            break;

          case reolinkAPI::CAM_GET_FTPV20:
            $camcmd->checkAndUpdateCmd('SetFTPStateV20', $json_data['value']['Ftp']['enable']);
            break;

          case reolinkAPI::CAM_GET_PUSH:
            $camcmd->checkAndUpdateCmd('SetPushState', $json_data['value']['Push']['schedule']['enable']);
            break;

          case reolinkAPI::CAM_GET_PUSHV20:
            $camcmd->checkAndUpdateCmd('SetPushStateV20', $json_data['value']['Push']['enable']);
            break;

          case reolinkAPI::CAM_GET_PUSHCFG:
            $camcmd->checkAndUpdateCmd('SetPushCfgState', $json_data['value']['PushCfg']['pushInterval']);
            break;

          case reolinkAPI::CAM_GET_EMAIL:
            $camcmd->checkAndUpdateCmd('SetEmailState', $json_data['value']['Email']['schedule']['enable']);
            break;

          case reolinkAPI::CAM_GET_EMAILV20:
            $camcmd->checkAndUpdateCmd('SetEmailStateV20', $json_data['value']['Email']['enable']);
            break;

          case reolinkAPI::CAM_GET_ENC:
            $camcmd->checkAndUpdateCmd('SetMicrophoneState', $json_data['value']['audio']);
            $camcmd->checkAndUpdateCmd('SetResolutionst1State', $json_data['value']['Enc']['mainStream']['size']);
            $camcmd->checkAndUpdateCmd('SetFPSst1State', $json_data['value']['Enc']['mainStream']['size']);
            $camcmd->checkAndUpdateCmd('SetBitratest1State', $json_data['value']['Enc']['mainStream']['bitRate']);
            $camcmd->checkAndUpdateCmd('SetResolutionst2State', $json_data['value']['Enc']['subStream']['size']);
            $camcmd->checkAndUpdateCmd('SetFPSst2State', $json_data['value']['Enc']['subStream']['size']);
            $camcmd->checkAndUpdateCmd('SetBitratest2State', $json_data['value']['Enc']['subStream']['size']);
            break;

          case reolinkAPI::CAM_GET_ISP:
            $camcmd->checkAndUpdateCmd('SetRotationState', $json_data['value']['Isp']['rotation']);
            $camcmd->checkAndUpdateCmd('SetMirroringState', $json_data['value']['Isp']['mirroring']);
            $camcmd->checkAndUpdateCmd('SetAntiFlickerState', $json_data['value']['Isp']['antiFlicker']);
            $camcmd->checkAndUpdateCmd('SetBackLightState', $json_data['value']['Isp']['backLight']);
            $camcmd->checkAndUpdateCmd('SetBlcState', $json_data['value']['Isp']['blc']);
            $camcmd->checkAndUpdateCmd('SetBlueGainState', $json_data['value']['Isp']['blueGain']); // ???
            $camcmd->checkAndUpdateCmd('SetDayNightState', $json_data['value']['Isp']['dayNight']);
            $camcmd->checkAndUpdateCmd('SetDrcState', $json_data['value']['Isp']['drc']);
            $camcmd->checkAndUpdateCmd('SetNr3dState', $json_data['value']['Isp']['nr3d']);
            $camcmd->checkAndUpdateCmd('SetRedGainState', $json_data['value']['Isp']['redGain']); // ???
            $camcmd->checkAndUpdateCmd('SetWhiteBalanceState', $json_data['value']['Isp']['whiteBalance']); // ???
            $camcmd->checkAndUpdateCmd('SetExposureState', $json_data['value']['Isp']['exposure']); // ???
            break;

          case reolinkAPI::CAM_GET_IRLIGHTS:
            $camcmd->checkAndUpdateCmd('SetIrLightsState', $json_data['value']['IrLights']['state']);
            break;

          case reolinkAPI::CAM_GET_IMAGE:
            $camcmd->checkAndUpdateCmd('SetBrightState', $json_data['value']['Image']['bright']);
            $camcmd->checkAndUpdateCmd('SetContrastState', $json_data['value']['Image']['contrast']);
            $camcmd->checkAndUpdateCmd('SetSaturationState', $json_data['value']['Image']['saturation']);
            $camcmd->checkAndUpdateCmd('SetHueState', $json_data['value']['Image']['hue']);
            $camcmd->checkAndUpdateCmd('SetSharpenState', $json_data['value']['Image']['sharpen']);
            break;

          case reolinkAPI::CAM_GET_WHITELED:
            $camcmd->checkAndUpdateCmd('SetWhitLedState', $json_data['value']['WhiteLed']['state']);
            $camcmd->checkAndUpdateCmd('SetWhiteLedModeState', $json_data['value']['WhiteLed']['mode']);
            $camcmd->checkAndUpdateCmd('SetWhitLedLuxState', $json_data['value']['WhiteLed']['bright']);
            break;

          case reolinkAPI::CAM_GET_PTZPRESET:
            break;

          case reolinkAPI::CAM_GET_PTZGUARD:
            $camcmd->checkAndUpdateCmd('CheckIsExistsPtzGuardPoint', $json_data['value']['PtzGuard']['bexistPos']);
            $camcmd->checkAndUpdateCmd('SetAutoReturnPtzGuardPointState', $json_data['value']['PtzGuard']['benable']);
            $camcmd->checkAndUpdateCmd('SetIntervalAutoReturnPtzGuardPointState', $json_data['value']['PtzGuard']['timeout']);
            break;

          case reolinkAPI::CAM_PTZCHECK:
            break;

          case reolinkAPI::CAM_GET_MDSTATE:
            // Updated by daemon
            break;

          case reolinkAPI::CAM_GET_PTZCHECKSTATE:
            switch ((int) $json_data['value']['PtzCheckState']) {
              case 0:
                $camcmd->checkAndUpdateCmd('SetPtzCheckState', 'REQUISE');
                break;
              case 1:
                $camcmd->checkAndUpdateCmd('SetPtzCheckState', 'EN COURS');
                break;
              case 2:
                $camcmd->checkAndUpdateCmd('SetPtzCheckState', 'TERMINEE');
                break;
            }
            log::add('reolink', 'debug', 'Statut Calibration : ' . $json_data['value']['PtzCheckState']);
            break;

          case reolinkAPI::CAM_GET_ALARM:
            // Not supported for now
            break;

          case reolinkAPI::CAM_GET_AUDIOALARM:
            $camcmd->checkAndUpdateCmd('SetAudioAlarmState', $json_data['value']['Audio']['schedule']['enable']);
            break;

          case reolinkAPI::CAM_GET_AUDIOALARMV20:
            $camcmd->checkAndUpdateCmd('SetAudioAlarmStateV20', $json_data['value']['Audio']['enable']);
            break;

          case reolinkAPI::CAM_AUDIOALARMPLAY:
            break;

          case reolinkAPI::CAM_GET_AUDIOCFG:
            $camcmd->checkAndUpdateCmd('SetSirenVolumeState', $json_data['value']['AudioCfg']['volume']);
            break;

          case reolinkAPI::CAM_GET_POWERLED:
            $camcmd->checkAndUpdateCmd('SetPowerLedState', $json_data['value']['PowerLed']['state']);
            break;

          case reolinkAPI::CAM_GET_ABILITY:
            $ab1 = $json_data['value']['Ability'];
            unset($ab1['abilityChn']);
            $ab2 = $json_data['value']['Ability']['abilityChn']['0'];
            // $camcnx->ability_settings = array_merge($ab1, $ab2); FIXME: ability_settings does not exist and is not used anywhere
            break;

          case reolinkAPI::CAM_GET_AUTOFOCUS:
            $camcmd->checkAndUpdateCmd('SetAutoFocusState', $json_data['value']['AutoFocus']['disable']);
            break;

          case reolinkAPI::CAM_GET_MASK:
            $camcmd->checkAndUpdateCmd('SetMaskState', $json_data['value']['Mask']['enable']);
            break;

          case reolinkAPI::CAM_GET_AUTOMAINT:
            $camcmd->checkAndUpdateCmd('SetAutoMaintState', $json_data['value']['AutoMaint']['enable']);
            break;

          case reolinkAPI::CAM_GET_UPNP:
            $camcmd->checkAndUpdateCmd('SetUpnpState', $json_data['value']['Upnp']['enable']);
            break;

          case reolinkAPI::CAM_GET_P2P:
            $camcmd->checkAndUpdateCmd('SetUidP2pState', $json_data['value']['P2p']['enable']);
            break;

          case reolinkAPI::CAM_GET_ZOOMFOCUS:
            $camcmd->checkAndUpdateCmd('SetZoomState', $json_data['value']['ZoomFocus']['zoom']['pos']);
            $camcmd->checkAndUpdateCmd('SetFocusState', $json_data['value']['ZoomFocus']['focus']['pos']);
            break;

          case reolinkAPI::CAM_GET_PERFORMANCE:
            $camcmd->checkAndUpdateCmd('SetCpuUsedState', $json_data['value']['Performance']['cpuUsed']);
            $camcmd->checkAndUpdateCmd('SetNetThroughputState', $json_data['value']['Performance']['netThroughput']);
            $camcmd->checkAndUpdateCmd('SetCodecRateState', $json_data['value']['Performance']['codecRate']);
            break;

          case reolinkAPI::CAM_GET_AICFG:
            $camcmd->checkAndUpdateCmd('SetaiTrackState', $json_data['value']['aiTrack']);
            break;

          case reolinkAPI::CAM_GET_MDALARM:
            $revert_value = reolinkCmd::byEqLogicIdAndLogicalId($id, 'SetMdDefaultSensitivityState')->getConfiguration('revertvalue', 0);
            $mdsensdef = $revert_value - $json_data['value']['MdAlarm']['newSens']['sensDef'];
            $camcmd->checkAndUpdateCmd('SetMdDefaultSensitivityState', $mdsensdef);
            break;

          case reolinkAPI::CAM_GET_AIALARM:
            switch ($json_data['value']['AiAlarm']['ai_type']) {
              case "people":
                $s1 = 'SetSdSensitivityPeopleState';
                $s2 = 'SetAlarmDelayPeopleState';
                break;
              case "vehicle":
                $s1 = 'SetSdSensitivityVehicleState';
                $s2 = 'SetAlarmDelayVehicleState';
                break;
              case "dog_cat":
                $s1 = 'SetSdSensitivityDogCatState';
                $s2 = 'SetAlarmDelayDogCatState';
                break;
            }
            $camcmd->checkAndUpdateCmd($s1, $json_data['value']['AiAlarm']['sensitivity']);
            $camcmd->checkAndUpdateCmd($s2, $json_data['value']['AiAlarm']['stay_time']);
            log::add('reolink', 'debug', 'ai_type check : ' . $json_data['value']['AiAlarm']['ai_type']);
            break;

          default:
            log::add('reolink', 'error', 'Switch command not found : ' . print_r($json_data, true));
            $res = false;
        }
      }
    } #END foreach
    
    // Mise à jour de l'état de la détection de mouvement (mode Baichuan)
    $detection_mode = config::byKey('detection_mode', __CLASS__, 'onvif');
    if ($detection_mode == 'baichuan') {
      reolink::updateMotionDetectionState($id);
    }
  } #End refreshNFO


  /*
   * Permet de définir les possibilités de personnalisation du widget (en cas d'utilisation de la fonction 'toHtml' par exemple)
   * Tableau multidimensionnel - exemple: array('custom' => true, 'custom::layout' => false)
	public static $_widgetPossibility = array();
   */

  /*     * ***********************Methode static*************************** */

  public static function cron() {
    $eqLogics = eqLogic::byType('reolink', true);
    /** @var reolink */
    foreach ($eqLogics as $camera) {
      $autorefresh = $camera->getConfiguration('autorefresh', '*/15 * * * *');
      if ($autorefresh != '') {
        try {
          $c = new Cron\CronExpression(checkAndFixCron($autorefresh), new Cron\FieldFactory);
          if ($c->isDue()) {
            log::add('reolink', 'debug', '#### CRON refresh ' . $camera->getHumanName());
            $camera->refreshNFO($camera->getId());
          }
          #Reset detection info if daemon is down
          $deamon_info = self::deamon_info();
          if ($deamon_info['state'] != 'ok') {
            $camera->checkAndUpdateCmd('MdState', 0);
          }
        } catch (Exception $exc) {
          log::add('reolink', 'error', __('Expression cron non valide pour ', __FILE__) . $camera->getHumanName() . ' : ' . $autorefresh);
        }
      }
    }
  }


  // Fonction exécutée automatiquement toutes les 10 minutes par Jeedom
  public static function cron10() {
    // Refresh motion detection subscription
    $detection_mode = config::byKey('detection_mode', __CLASS__, 'onvif');
    
    if ($detection_mode == 'onvif') {
      $eqLogics = eqLogic::byType('reolink', true);
      // Mode ONVIF: Envoie les infos pour chaque caméra
      foreach ($eqLogics as $camera) {
        $camera_contact_point = $camera->getConfiguration('adresseip');
        if (filter_var($camera_contact_point, FILTER_VALIDATE_IP)) {
          $camera_ip = $camera_contact_point;
        } else {
          $camera_ip = gethostbyname($camera_contact_point);
        }

        $port_onvif = $camera->getConfiguration('port_onvif');
        if ($port_onvif == "") {
          $port_onvif = "8000";
        }
        
        // Sending info to Daemon
        $params['action'] = 'sethook';
        $params['cam_ip'] = $camera_ip;
        $params['cam_onvif_port'] = $port_onvif;
        $params['cam_user'] = $camera->getConfiguration('login');
        $params['cam_pwd'] = $camera->getConfiguration('password');

        log::add('reolink', 'debug', 'CRON mise à jour souscription ONVIF events Cam=' . $camera->getConfiguration('adresseip'));
        reolink::sendToDaemon($params);
      }
    }
  }

  public static function deamon_info() {
    $return = array();
    $return['log'] = __CLASS__;
    $return['state'] = 'nok';
    $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
    if (file_exists($pid_file)) {
      if (@posix_getsid(trim(file_get_contents($pid_file)))) {
        $return['state'] = 'ok';
      } else {
        shell_exec(system::getCmdSudo() . 'rm -rf ' . $pid_file . ' 2>&1 > /dev/null');
      }
    }
    $return['launchable'] = 'ok';
    $list_camera = eqLogic::byType('reolink');

    if (count($list_camera) == 0) {
      $return['launchable'] = 'nok';
      $return['launchable_message'] = __('Aucune caméra n\'est configuré', __FILE__);
    }
    return $return;
  }

  public static function deamon_start() {
    self::deamon_stop();
    $deamon_info = self::deamon_info();
    if ($deamon_info['launchable'] != 'ok') {
      throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
    }


    $webhook_ip = "";
    $callbackip_cfg = config::byKey('ipwebhook', __CLASS__, '0');
    if ($callbackip_cfg == 1) {
      // Internal
      $webhook_ip = network::getNetworkAccess('internal', 'ip');
    } elseif ($callbackip_cfg == 2) {
      // External
      $webhook_ip = network::getNetworkAccess('external', 'ip');
    } elseif ($callbackip_cfg == 3) {
      // Personnalised
      $webhook_ip = config::byKey('webhookdefinedip', __CLASS__, '');
    }

    $path = realpath(dirname(__FILE__) . '/../../resources/demond');
    $cmd = self::PYTHON_PATH . " {$path}/reolinkd.py";
    $cmd .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel(__CLASS__));
    $cmd .= ' --socketport ' . config::byKey('socketport', __CLASS__, '44009');
    $cmd .= ' --callback ' . network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/reolink/core/php/jeeReolink.php';
    $cmd .= ' --apikey ' . jeedom::getApiKey(__CLASS__);
    $cmd .= ' --pid ' . jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
    if ($webhook_ip != "") {
      $cmd .= ' --webhook_ip ' . $webhook_ip;
    }
    $cmd .= ' --webhook_port ' . config::byKey('webhookport', __CLASS__, '44010');
    $cmd .= ' --reolink_aio_api_port ' . config::byKey('reolink_aio_api_port', __CLASS__, '44011');
    $cmd .= ' --detection_mode ' . config::byKey('detection_mode', __CLASS__, 'onvif');
    $cmd .= ' --reolink_aio_log_level ' . config::byKey('reolink_aio_log_level', __CLASS__, 'warning');
    log::add(__CLASS__, 'info', 'Lancement démon');
    $result = exec($cmd . ' >> ' . log::getPathToLog('reolink_daemon') . ' 2>&1 &');
    $i = 0;
    while ($i < 20) {
      $deamon_info = self::deamon_info();
      if ($deamon_info['state'] == 'ok') {
        break;
      }
      sleep(1);
      $i++;
    }
    if ($i >= 30) {
      log::add(__CLASS__, 'error', __('Impossible de lancer le démon, vérifiez le log', __FILE__), 'unableStartDeamon');
      return false;
    }
    message::removeAll(__CLASS__, 'unableStartDeamon');
    return true;
  }

  public static function deamon_stop() {
    $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
    if (file_exists($pid_file)) {
      $pid = intval(trim(file_get_contents($pid_file)));
      system::kill($pid);
    }
    system::kill('reolinkd.py');
    sleep(1);
  }

  public static function sendToDaemon($params) {
    $deamon_info = self::deamon_info();
    if ($deamon_info['state'] != 'ok') {
      log::add('reolink', 'error', 'Envoi des infos de webhook impossible le daemon n\'est pas démarré');
      return False;
    }
    $params['apikey'] = jeedom::getApiKey(__CLASS__);
    $payLoad = json_encode($params);
    $socket = socket_create(AF_INET, SOCK_STREAM, 0);
    socket_connect($socket, '127.0.0.1', config::byKey('socketport', __CLASS__, '44009'));
    socket_write($socket, $payLoad, strlen($payLoad));
    socket_close($socket);
  }

  public static function dependancy_install() {
    log::remove(__CLASS__ . '_update');
    return array('script' => __DIR__ . '/../../resources/install_#stype#.sh', 'log' => log::getPathToLog(__CLASS__ . '_update'));
  }

  public static function dependancy_info() {
    $return = array();
    $return['log'] = log::getPathToLog(__CLASS__ . '_update');
    $return['progress_file'] = jeedom::getTmpFolder(__CLASS__) . '/dependance';
    $return['state'] = 'ok';
    if (file_exists(jeedom::getTmpFolder(__CLASS__) . '/dependance')) {
      $return['state'] = 'in_progress';
    } elseif (!file_exists(self::PYTHON_PATH)) {
      $return['state'] = 'nok';
    } elseif (exec(self::PYTHON_PATH . ' -m pip list | grep -Ewc "aiosignal|aiohttp|uvicorn|fastapi|urllib3|requests|charset-normalizer"') < 7) {
      $return['state'] = 'nok';
    }
    return $return;
  }

  // Fonction exécutée automatiquement avant la mise à jour de l'équipement
  public function preUpdate() {
    if ($this->getConfiguration('adresseip') == NULL) {
      throw new Exception(__('L\'IP ou le nom d\'hôte est obligatoire', __FILE__));
    }
    if ($this->getConfiguration('login') == NULL) {
      throw new Exception(__('Le champ login est obligatoire', __FILE__));
    }
    if ($this->getConfiguration('password') == NULL) {
      throw new Exception(__('Le mot de passe ne peut pas être vide', __FILE__));
    }
    // Champs OK
  }

  public function loadCmdFromConf($id) {
    $devAbilityReturn = reolink::GetCamAbility($id);
    $camera = reolink::byId($id, 'reolink');
    if ($camera->GetConfiguration("supportai") == "Non") {
      $camisIA = 0;
    } else {
      $camisIA = 1;
    }

    if (!$devAbilityReturn) {
      log::add('reolink', 'debug', 'Erreur lors de l\'obtention des capacités hardware/software de la caméra');
      return false;
    }

    log::add('reolink', 'debug', 'Chargement des commandes depuis le fichiers de config : ' . dirname(__FILE__) . '/../config/reolinkapicmd.json');
    $content = file_get_contents(dirname(__FILE__) . '/../config/reolinkapicmd.json');


    if (!is_json($content)) {
      log::add('reolink', 'error', 'Format du fichier de configuration n\'est pas du JSON valide !');
      return false;
    }
    $device = json_decode($content, true);
    if (!is_array($device) || !isset($device['commands'])) {
      log::add('reolink', 'error', 'Pas de configuration valide trouvé dans le fichier');
      return false;
    }
    log::add('reolink', 'info', 'Nombre de commandes dans le fichier de configuration : ' . count($device['commands']));
    $cmd_order = 0;

    foreach ($device['commands'] as $command) {
      // Check cam ability
      $cmd = null;
      foreach ($this->getCmd() as $liste_cmd) {
        if ((isset($command['logicalId']) && $liste_cmd->getLogicalId() == $command['logicalId'])
          || (isset($command['name']) && $liste_cmd->getName() == $command['name'])
        ) {
          $cmd = $liste_cmd;
          break;
        }
      }

      if ($cmd == null || !is_object($cmd)) {
        // Check cam ability
        $ability = false;
        $abilityfound = false;
        // Global Ability
        foreach ($devAbilityReturn as $abilityName => $abilityParam) {

          if ($command['abilityneed'] == "none") {
            $ability = true;
            break;
          }

          if ($command['abilityneed'] == $abilityName) {
            $abilityfound = true;
            if ($abilityParam['permit'] != 0) {
              if (isset($command['iastate'])) {
                if ($command['iastate'] == $camisIA) {
                  // Function available for this model ADD
                  log::add('reolink', 'info', '=> (IA) Capacité hardware/software OK pour : ' . $command['name']);
                  $ability = true;
                } else {
                  // Function IA NOT available for this model DO NOT ADD
                  log::add('reolink', 'debug', '=> (IA) Ignorer, capacité hardware/software NOK pour : ' . $command['name']);
                  break;
                }
              } else {
                // Function available for this model ADD
                log::add('reolink', 'info', '=> Capacité hardware/software OK pour : ' . $command['name']);
                $ability = true;
              }
              break;
            } else {
              // Function NOT available for this model DO NOT ADD
              log::add('reolink', 'debug', '=> Ignorer, capacité hardware/software NOK pour : ' . $command['name']);
              break;
            }
            break;
          }
        }

        if (!$abilityfound && !$ability) {
          log::add('reolink', 'info', 'Aucun match de capacité ' . $command['abilityneed'] . ' pour la CMD : ' . $command['name']);
        }

        if ($ability) {
          log::add('reolink', 'info', '-> Ajout de : ' . $command['name']);
          $cmd = new reolinkCmd();
          $cmd->setOrder($cmd_order);
          $cmd->setEqLogic_id($this->getId());
          utils::a2o($cmd, $command);
          $cmd->save();
          if ($cmd->getConfiguration('valueFrom') != "") {
            $valueLink = $cmd->getConfiguration('valueFrom');
            $camera = reolink::byId($id, 'reolink');
            $cmdlogic = reolinkCmd::byEqLogicIdAndLogicalId($camera->getId(), $valueLink);
            if (is_object($cmdlogic)) {
              $cmd->setValue($cmdlogic->getId());
              $cmd->save();
              log::add('reolink', 'debug', '--> Valeur lier depuis : ' . $valueLink . " (" . $cmdlogic->getId() . ")");
            } else {
              log::add('reolink', 'warning', 'X--> Liaison impossible objet introuvable : ' . $valueLink);
            }
          }
          $cmd_order++;
        }
      } else {
        log::add('reolink', 'debug', 'Commande déjà présente : ' . $command['name']);
      }
    }
    return $cmd_order;
  }

  /*
     * Non obligatoire : permet de modifier l'affichage du widget (également utilisable par les commandes)
      public function toHtml($_version = 'dashboard') {

      }
     */

  /*
     * Non obligatoire : permet de déclencher une action après modification de variable de configuration
    public static function postConfig_<Variable>() {
    }
     */

  /*
     * Non obligatoire : permet de déclencher une action avant modification de variable de configuration
    public static function preConfig_<Variable>() {
    }
     */

  /*     * **********************Getteur Setteur*************************** */
}

class reolinkCmd extends cmd {
  /*     * *************************Attributs****************************** */

  /*
      public static $_widgetPossibility = array();
    */

  /*     * ***********************Methode static*************************** */


  /*     * *********************Methode d'instance************************* */

  /*
     * Non obligatoire permet de demander de ne pas supprimer les commandes même si elles ne sont pas dans la nouvelle configuration de l'équipement envoyé en JS
      public function dontRemoveCmd() {
      return true;
      }
     */

  // Exécution d'une commande
  public function execute($_options = array()) {
    log::add('reolink', 'debug', 'Action demandé : ' . $this->getLogicalId());
    $EqId = $this->getEqLogic_id();

    $channel = $this->getEqLogic()->getConfiguration('defined_channel'); // Cherche dans l'équipement parent
    log::add('reolink', 'debug', 'Channel : ' . $channel);
    if ($channel == NULL) {
      $channel = 0;
    }

    switch ($this->getLogicalId()) {
      case 'refresh':
        reolink::refreshNFO($EqId);
        break;
      case 'GetPtzPreset':
        $camcnx = reolink::getReolinkConnection($EqId);
        $data = $camcnx->SendCMD('[{"cmd":"GetPtzPreset","action":1,"param":{"channel":' . $channel . '}}]');
        reolink::updatePTZpreset($EqId, $data[0]);
        break;
      case 'GetScenes':
        reolink::updateScenes($EqId);
        break;
      case 'SetScene':
        reolink::setScene($EqId, $_options['select']);
        break;
      case 'SetSpeed':
        $this->setConfiguration('speedvalue', $_options['slider']);
        break;
      case 'enableMotionDetection':
        reolink::enableMotionDetection($EqId);
        break;
      case 'disableMotionDetection':
        reolink::disableMotionDetection($EqId);
        break;
      default:
        $camcnx = reolink::getReolinkConnection($EqId);
        // Speed NFO
        $cmd = reolinkCmd::byEqLogicIdAndLogicalId($EqId, "SetSpeed");
        if (is_object($cmd)) {
          $speed = $cmd->getConfiguration('speedvalue');
        } else {
          $speed = 32;
        }


        $actionAPI = $this->getConfiguration('actionapi');
        $linkedvalue = $this->getConfiguration('valueFrom');
        $revert_value = intval($this->getConfiguration('revertvalue'));

        if ($actionAPI != NULL) {
          $payload = str_replace('\\', '', $this->getConfiguration('payload'));
          $payload = str_replace('#OPTSELECTEDINT#', intval($_options['select']), $payload);
          $payload = str_replace('#OPTSELECTEDSTR#', '"' . $_options['select'] . '"', $payload);
          $payload = str_replace('#OPTSLIDER#', intval($_options['slider']), $payload);
          $payload = str_replace('#OPTR_SLIDER#', abs($revert_value - intval($_options['slider'])), $payload);
          $payload = str_replace('#CHANNEL#', $channel, $payload);
          $payload = str_replace('#SPEED#', $speed, $payload);
          $payload = '[{"cmd":"' . $actionAPI . '","param":' . $payload . '}]';

          log::add('reolink', 'debug', 'Payload avec paramètre utilisateur demandé = ' . $payload);

          $camresp = $camcnx->SendCMD($payload);
          // Check return and update CMD State
          if ($camresp[0]["value"]["rspCode"] == 200) {
            log::add('reolink', 'debug', 'OK > Action réalisé avec succès sur la caméra');

            if (!empty($linkedvalue)) {
              $camcmd = reolink::byId($EqId, 'reolink');
              $cmd = $camcmd->getCmd(null, $linkedvalue);
              if (is_object($cmd)) {
                if (isset($_options['select'])) {
                  $updtval = $_options['select'];
                } elseif (isset($_options['slider'])) {
                  $updtval = $_options['slider'];
                } else {
                  $updtval = 0;
                  log::add('reolink', 'error', 'Impossible de trouver la valeur à inserer');
                }
                $camcmd->checkAndUpdateCmd($linkedvalue, $updtval);
                $camcmd->save();
                log::add('reolink', 'debug', 'Mise à jour de l\'info liée : ' . $linkedvalue . " Valeur=" . $updtval);
              }
            }
          } else {
            throw new Exception(__('Echec d\'execution de la commande (consultez le log pour plus de détails)', __FILE__));
          }
        }
    }
  }

  /*     * **********************Getteur Setteur*************************** */
}
