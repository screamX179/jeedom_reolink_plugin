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

if (!isConnect('admin')) {
  throw new Exception('{{401 - Accès non autorisé}}');
}

// Récupérer la configuration
$detectionMode = config::byKey('detection_mode', 'reolink', 'onvif');
$webhookPort = config::byKey('webhookport', 'reolink', '44010');
$reolinkAioApiPort = config::byKey('reolink_aio_api_port', 'reolink', '44011');

// Récupérer les informations de santé selon le mode
$healthInfo = null;
$error = null;

try {
  // Choisir l'endpoint selon le mode de détection
  if ($detectionMode == 'onvif') {
    $apiUrl = 'http://127.0.0.1:' . $webhookPort . '/health';
  } else {
    $apiUrl = 'http://127.0.0.1:' . $reolinkAioApiPort . '/health';
  }
  
  $ch = curl_init($apiUrl);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 5);
  curl_setopt($ch, CURLOPT_HTTPGET, true);
  
  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  
  if ($httpCode === 200 && $response) {
    $healthInfo = json_decode($response, true);
  } else {
    $serviceName = ($detectionMode == 'onvif') ? 'Webhook ONVIF' : 'API Reolink AIO';
    $error = "Impossible de contacter le service $serviceName (HTTP $httpCode)";
  }
} catch (Exception $e) {
  $error = "Erreur lors de la récupération des informations : " . $e->getMessage();
}


// Récupérer l'état du démon
$daemonInfo = reolink::deamon_info();
?>

<div class="panel panel-primary">
  <div class="panel-heading">
    <h3 class="panel-title"><i class="fas fa-heartbeat"></i> {{Santé du plugin Reolink}}</h3>
  </div>
  <div class="panel-body">
    
    <!-- Configuration -->
    <div class="alert alert-info">
      <strong><i class="fas fa-cog"></i> {{Configuration}}</strong>
      <ul>
        <li><strong>{{Mode de détection}} :</strong> <?php echo strtoupper($detectionMode); ?></li>
        <li><strong>{{Port webhook (ONVIF)}} :</strong> <?php echo $webhookPort; ?></li>
        <li><strong>{{Port API Reolink AIO}} :</strong> <?php echo $reolinkAioApiPort; ?></li>
      </ul>
    </div>

    <!-- État du démon -->
    <div class="alert <?php echo ($daemonInfo['state'] == 'ok') ? 'alert-success' : 'alert-danger'; ?>">
      <strong><i class="fas fa-server"></i> {{État du démon}}</strong>
      <ul>
        <li><strong>{{Statut}} :</strong> 
          <?php 
          if ($daemonInfo['state'] == 'ok') {
            echo '<span class="label label-success">{{Démarré}}</span>';
          } else {
            echo '<span class="label label-danger">{{Arrêté}}</span>';
          }
          ?>
        </li>
        <li><strong>{{Démarrage automatique}} :</strong> 
          <?php echo ($daemonInfo['auto'] == 1) ? '<i class="fas fa-check text-success"></i> {{Oui}}' : '<i class="fas fa-times text-danger"></i> {{Non}}'; ?>
        </li>
        <?php if (isset($daemonInfo['launchable_message']) && $daemonInfo['launchable_message'] != ''): ?>
        <li><strong>{{Message}} :</strong> <?php echo $daemonInfo['launchable_message']; ?></li>
        <?php endif; ?>
      </ul>
    </div>

    <!-- Informations API/Webhook -->
    <?php if ($error): ?>
      <div class="alert alert-warning">
        <strong><i class="fas fa-exclamation-triangle"></i> 
          <?php echo ($detectionMode == 'onvif') ? '{{Webhook ONVIF}}' : '{{API Reolink AIO}}'; ?>
        </strong>
        <p><?php echo $error; ?></p>
      </div>
    <?php elseif ($healthInfo): ?>
      <?php if ($detectionMode == 'onvif'): ?>
        <!-- Mode ONVIF : Informations du webhook -->
        <div class="alert alert-success">
          <strong><i class="fas fa-check-circle"></i> {{Webhook ONVIF}}</strong>
          <ul>
            <li><strong>{{Statut}} :</strong> <span class="label label-success"><?php echo strtoupper($healthInfo['status']); ?></span></li>
            <li><strong>{{Mode}} :</strong> <?php echo strtoupper($healthInfo['mode']); ?></li>
            <li><strong>{{Événements enregistrés}} :</strong> <?php echo $healthInfo['registered_events']; ?></li>
            <?php if ($healthInfo['registered_events'] > 0): ?>
            <li><strong>{{Types d'événements}} :</strong> 
              <?php 
              foreach ($healthInfo['event_types'] as $eventType) {
                echo '<span class="label label-info">' . $eventType . '</span> ';
              }
              ?>
            </li>
            <?php endif; ?>
          </ul>
        </div>
      <?php else: ?>
        <!-- Mode Baichuan : Informations de l'API -->
        <div class="alert alert-success">
          <strong><i class="fas fa-check-circle"></i> {{API Reolink AIO}}</strong>
          <ul>
            <li><strong>{{Statut}} :</strong> <span class="label label-success"><?php echo strtoupper($healthInfo['status']); ?></span></li>
            <li><strong>{{Sessions actives}} :</strong> <?php echo $healthInfo['active_sessions']; ?></li>
            <li><strong>{{TTL des sessions}} :</strong> <?php echo $healthInfo['session_ttl_minutes']; ?> minutes</li>
          </ul>
        </div>
      <?php endif; ?>

      <!-- Caméras avec détection Baichuan active -->
      <?php if ($detectionMode == 'baichuan' && isset($healthInfo['active_baichuan_cameras']) && count($healthInfo['active_baichuan_cameras']) > 0): ?>
        <div class="panel panel-info">
          <div class="panel-heading">
            <h4 class="panel-title">
              <i class="fas fa-video"></i> {{Caméras avec détection Baichuan active}}
              <span class="badge"><?php echo count($healthInfo['active_baichuan_cameras']); ?></span>
            </h4>
          </div>
          <div class="panel-body">
            <table class="table table-condensed table-striped">
              <thead>
                <tr>
                  <th>{{Caméra (Session)}}</th>
                  <th>{{Channels}}</th>
                  <th>{{Callbacks}}</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($healthInfo['active_baichuan_cameras'] as $camera): ?>
                  <tr>
                    <td><span class="label label-info"><?php echo $camera['session_key']; ?></span></td>
                    <td>
                      <?php 
                      foreach ($camera['channels'] as $channel) {
                        echo '<span class="label label-primary">Channel ' . $channel . '</span> ';
                      }
                      ?>
                    </td>
                    <td><span class="badge badge-success"><?php echo $camera['callback_count']; ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php elseif ($detectionMode == 'baichuan'): ?>
        <div class="alert alert-warning">
          <i class="fas fa-info-circle"></i> {{Aucune caméra avec détection Baichuan active}}
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- Liste des équipements -->
    <?php 
    // Préparer une map des channels actifs pour Baichuan (IP:channel)
    $activeBaichuanChannels = [];
    if ($detectionMode == 'baichuan' && isset($healthInfo['active_baichuan_cameras'])) {
      foreach ($healthInfo['active_baichuan_cameras'] as $camera) {
        // Extraire l'IP depuis session_key (format: "ip:port")
        $parts = explode(':', $camera['session_key']);
        if (count($parts) >= 1) {
          $camera_ip = $parts[0];
          
          // Ajouter chaque channel actif
          if (isset($camera['channels']) && is_array($camera['channels'])) {
            foreach ($camera['channels'] as $channel) {
              $activeBaichuanChannels[$camera_ip . ':' . $channel] = true;
            }
          }
        }
      }
    }
    
    // Préparer une map des caméras ONVIF actives
    $activeOnvifMap = [];
    if ($detectionMode == 'onvif' && isset($healthInfo['active_cameras'])) {
      foreach ($healthInfo['active_cameras'] as $ip) {
        $activeOnvifMap[$ip] = true;
      }
    }
    ?>
    <div class="panel panel-default">
      <div class="panel-heading">
        <h4 class="panel-title">
          <i class="fas fa-camera"></i> {{Équipements Reolink}}
          <span class="badge"><?php echo count(reolink::byType('reolink')); ?></span>
        </h4>
      </div>
      <div class="panel-body">
        <table class="table table-condensed table-striped">
          <thead>
            <tr>
              <th>{{Nom}}</th>
              <th>{{IP}}</th>
              <th>{{Channel}}</th>
              <th>{{Actif}}</th>
              <th>{{Visible}}</th>
              <th>{{Accessible}}</th>
              <?php if ($detectionMode == 'onvif'): ?>
              <th>{{Connexion ONVIF}}</th>
              <?php elseif ($detectionMode == 'baichuan'): ?>
              <th>{{Détection Baichuan}}</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach (reolink::byType('reolink') as $eqLogic): 
              // Récupérer l'adresse et la résoudre en IP
              $camera_contact_point = $eqLogic->getConfiguration('adresseip');
              $camera_ip = filter_var($camera_contact_point, FILTER_VALIDATE_IP) 
                ? $camera_contact_point 
                : gethostbyname($camera_contact_point);
              
              $channel_id = $eqLogic->getConfiguration('defined_channel', 0);
              $parent_hub_id = $eqLogic->getConfiguration('parent_hub_id');
              $is_active = false;
              
              // Déterminer l'IP à utiliser (hub parent ou caméra elle-même)
              $target_ip = $camera_ip;
              if (!empty($parent_hub_id)) {
                $parent_hub = eqLogic::byId($parent_hub_id);
                if ($parent_hub) {
                  $hub_contact_point = $parent_hub->getConfiguration('adresseip');
                  $target_ip = filter_var($hub_contact_point, FILTER_VALIDATE_IP) 
                    ? $hub_contact_point 
                    : gethostbyname($hub_contact_point);
                }
              }
              
              // Vérifier le statut
              if ($detectionMode == 'baichuan') {
                $channel_key = $target_ip . ':' . $channel_id;
                $is_active = isset($activeBaichuanChannels[$channel_key]);
              } else {
                $is_active = isset($activeOnvifMap[$target_ip]);
              }
            ?>
              <tr data-eqlogic-id="<?php echo $eqLogic->getId(); ?>">
                <td>
                  <a href="index.php?v=d&p=reolink&m=reolink&id=<?php echo $eqLogic->getId(); ?>">
                    <?php echo $eqLogic->getHumanName(); ?>
                  </a>
                </td>
                <td><span class="label label-info"><?php echo $camera_contact_point; ?></span></td>
                <td>
                  <?php 
                  $isNVR = $eqLogic->getConfiguration('isNVR');
                  if ($isNVR === 'Oui'): 
                  ?>
                    <span class="label label-info">Hub</span>
                  <?php else: ?>
                    <span class="label label-info"><?php echo $channel_id; ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($eqLogic->getIsEnable()): ?>
                    <i class="fas fa-check text-success"></i>
                  <?php else: ?>
                    <i class="fas fa-times text-danger"></i>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($eqLogic->getIsVisible()): ?>
                    <i class="fas fa-check text-success"></i>
                  <?php else: ?>
                    <i class="fas fa-times text-muted"></i>
                  <?php endif; ?>
                </td>
                <td class="connection-status">
                  <span class="label label-default">
                    <i class="fas fa-spinner fa-spin"></i> {{Test en cours...}}
                  </span>
                </td>
                <?php if ($detectionMode == 'onvif'): ?>
                <td>
                  <?php if ($isNVR === 'Oui'): ?>
                    <span class="label label-default">N/A</span>
                  <?php elseif ($is_active): ?>
                    <span class="label label-success"><i class="fas fa-check"></i> {{Connectée}}</span>
                  <?php else: ?>
                    <span class="label label-default"><i class="fas fa-times"></i> {{Inactive}}</span>
                  <?php endif; ?>
                </td>
                <?php elseif ($detectionMode == 'baichuan'): ?>
                <td>
                  <?php if ($isNVR === 'Oui'): ?>
                    <span class="label label-default">N/A</span>
                  <?php elseif ($is_active): ?>
                    <span class="label label-success"><i class="fas fa-check"></i> {{Active}}</span>
                  <?php else: ?>
                    <span class="label label-default"><i class="fas fa-times"></i> {{Inactive}}</span>
                  <?php endif; ?>
                </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<script>
$(function() {
  // Tester la connexion de chaque équipement en asynchrone
  $('tr[data-eqlogic-id]').each(function() {
    var $row = $(this);
    var eqLogicId = $row.data('eqlogic-id');
    var $statusCell = $row.find('.connection-status');
    var isNVR = $row.find('td:eq(2)').text().trim() === 'Hub';
    
    // Appel AJAX pour tester la connexion
    $.ajax({
      type: 'POST',
      url: 'plugins/reolink/core/ajax/reolink.ajax.php',
      data: {
        action: 'TestConnection',
        id: eqLogicId
      },
      dataType: 'json',
      timeout: 60000, // Timeout de 60 secondes
      global: false, // Ne pas afficher le loader Jeedom global
      success: function(data) {
        if (data.state == 'ok' && data.result.connected) {
          // Équipement accessible
          var label = isNVR ? '{{En ligne}}' : '{{Accessible}}';
          $statusCell.html('<span class="label label-success"><i class="fas fa-check"></i> ' + label + '</span>');
        } else {
          // Équipement inaccessible
          var label = isNVR ? '{{Hors ligne}}' : '{{Inaccessible}}';
          $statusCell.html('<span class="label label-danger"><i class="fas fa-times"></i> ' + label + '</span>');
        }
      },
      error: function(xhr, status, error) {
        // Erreur ou timeout
        var label = isNVR ? '{{Hors ligne}}' : '{{Inaccessible}}';
        $statusCell.html('<span class="label label-danger"><i class="fas fa-times"></i> ' + label + '</span>');
      }
    });
  });
});
</script>
