<?php

try {
    require_once dirname(__FILE__) . "/../../../../core/php/core.inc.php";

    if (!jeedom::apiAccess(init('apikey'), 'reolink')) {
        echo __('Vous n\'etes pas autorisé à effectuer cette action', __FILE__);
        die();
    }
    if (init('test') != '') {
        echo 'OK';
        die();
    }
    $result = json_decode(file_get_contents("php://input"), true);
    if (!is_array($result)) {
        die();
    }

    if (isset($result['message']) && $result['message'] == 'baichuan_events') {
        // ── Mode Baichuan : événements batch (un seul appel avec tous les états) ──
        $plugin = plugin::byId('reolink');
        $eqLogics = eqLogic::byType($plugin->getId());
        $events = $result['events'];
        $eventChannel = isset($result['channel']) ? intval($result['channel']) : null;

        foreach ($eqLogics as $eqLogic) {
            if ($eqLogic->getConfiguration('isNVR') === 'Oui') {
              continue;
            }

            $camera_contact_point = $eqLogic->getConfiguration('adresseip');
            $camera_channel = intval($eqLogic->getConfiguration('defined_channel', 0));

            if (filter_var($camera_contact_point, FILTER_VALIDATE_IP)) {
              $camera_ip = $camera_contact_point;
            } else {
              $camera_ip = gethostbyname($camera_contact_point);
            }

            $ip_match = ($camera_ip == $result['ip']);
            $channel_match = ($eventChannel === null) || ($camera_channel == $eventChannel);

            if ($ip_match && $channel_match) {
              // Mettre à jour chaque commande d'événement
              $mdState = 0;
              foreach ($events as $evName => $evState) {
                $eqLogic->checkAndUpdateCmd($evName, $evState);
                if ($evState == 1) {
                  $mdState = 1;
                }
              }
              // MdState = 1 si au moins un événement est actif
              $eqLogic->checkAndUpdateCmd('MdState', $mdState);

              log::add('reolink', 'debug', 'Cam IP='.$result['ip'].' Ch='.$camera_channel.' Baichuan batch: ' . json_encode($events) . ' => MdState=' . $mdState);
            }
        }

    } elseif (isset($result['message']) && (($result['message'] == 'motion') || (strpos($result['message'], 'Ev') !== false))) {
        // ── Mode ONVIF : événements individuels ──
        $plugin = plugin::byId('reolink');
        $eqLogics = eqLogic::byType($plugin->getId());

        foreach ($eqLogics as $eqLogic) {
            if ($eqLogic->getConfiguration('isNVR') === 'Oui') {
              continue;
            }

            $camera_contact_point = $eqLogic->getConfiguration('adresseip');
            $camera_AI = $eqLogic->getConfiguration('supportai');
            $camera_channel = intval($eqLogic->getConfiguration('defined_channel', 0));

            if (filter_var($camera_contact_point, FILTER_VALIDATE_IP)) {
              $camera_ip = $camera_contact_point;
            } else {
              $camera_ip = gethostbyname($camera_contact_point);
            }

            $ip_match = ($camera_ip == $result['ip']);
            $channel_match = !isset($result['channel']) || ($camera_channel == intval($result['channel']));

            if ($ip_match && $channel_match) {
              if ($result['message'] == 'motion') {
                log::add('reolink', 'debug', 'Cam IP='.$result['ip'].' Ch='.$camera_channel.' ONVIF motion event, état='.$result['motionstate']);
                $eqLogic->checkAndUpdateCmd('MdState', $result['motionstate']);
              }

              // Commandes ONVIF génériques
              $eqLogic->checkAndUpdateCmd('EvLastOnvifName', $result['message']);
              $eqLogic->checkAndUpdateCmd('EvLastOnvifState', $result['motionstate']);
              $eqLogic->checkAndUpdateCmd('EvLastOnvifFull', $result['message'] . '-' . $result['motionstate']);

              if (strpos($result['message'], 'Ev') !== false) {
                log::add('reolink', 'debug', 'Cam IP='.$result['ip'].' Ch='.$camera_channel.' ONVIF Ev event: ' . $result['message'] . '='.$result['motionstate']);
                $eqLogic->checkAndUpdateCmd($result['message'], $result['motionstate']);
              }

              // ONVIF : interroger l'API pour les états AI
              if ($camera_AI == 'Oui') {
                  $camcnx = reolink::getReolinkConnection($eqLogic->getId());
                  $channel = $eqLogic->getConfiguration('channelNum') - 1;
                  $res = $camcnx->SendCMD('[{"cmd":"GetAiState","action":0,"param":{"channel":'.$channel.'}}]');
                  if (isset($res[0]['value'])) {
                    $eqLogic->checkAndUpdateCmd('EvPeopleDetect', $res[0]['value']['people']['alarm_state']);
                    $eqLogic->checkAndUpdateCmd('EvVehicleDetect', $res[0]['value']['vehicle']['alarm_state']);
                    $eqLogic->checkAndUpdateCmd('EvDogCatDetect', $res[0]['value']['dog_cat']['alarm_state']);
                    log::add('reolink', 'debug', 'Cam IP='.$result['ip'].' Ch='.$camera_channel.' AI (ONVIF): People=' . $res[0]['value']['people']['alarm_state'] . ' Vehicle=' . $res[0]['value']['vehicle']['alarm_state'] . ' Pet=' . $res[0]['value']['dog_cat']['alarm_state']);
                  }
              }
            }
        }

    } elseif (isset($result['message']) && $result['message'] == 'channel_status') {
        // ── Mode Baichuan : événement channel status (cmd_id 145) ──
        $plugin = plugin::byId('reolink');
        $eqLogics = eqLogic::byType($plugin->getId());
        $eventChannel = isset($result['channel']) ? intval($result['channel']) : null;

        foreach ($eqLogics as $eqLogic) {
            if ($eqLogic->getConfiguration('isNVR') === 'Oui') {
              continue;
            }

            $camera_contact_point = $eqLogic->getConfiguration('adresseip');
            $camera_channel = intval($eqLogic->getConfiguration('defined_channel', 0));

            if (filter_var($camera_contact_point, FILTER_VALIDATE_IP)) {
              $camera_ip = $camera_contact_point;
            } else {
              $camera_ip = gethostbyname($camera_contact_point);
            }

            $ip_match = ($camera_ip == $result['ip']);
            $channel_match = ($eventChannel === null) || ($camera_channel == $eventChannel);

            if ($ip_match && $channel_match) {
              $eqLogic->checkAndUpdateCmd('CameraConnected', $result['online']);
              log::add('reolink', 'debug', 'Cam IP='.$result['ip'].' Ch='.$camera_channel.' channel_status: online=' . $result['online']);
            }
        }

    } elseif (isset($result['message']) && $result['message'] == 'subscription') {
        if ($result['state'] == 0) {
          $title = 'Plugin Reolink';
          $message = 'Notification de détection de mouvement indisponible sur la caméra : ' . $result['ip'] . ' ( Détails : ' . $result['details'] . ')';
          message::add($title, $message);
        }
    } else {
        log::add('reolink', 'error', 'unknown message received from daemon');
    }
} catch (Exception $e) {
    log::add('reolink', 'error', displayException($e));
}
?>
