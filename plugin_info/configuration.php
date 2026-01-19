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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect()) {
  include_file('desktop', '404', 'php');
  die();
}
?>
<form class="form-horizontal">
  <fieldset>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Methode d'authentification GET}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Methode par laquelle le plugin va envoyer les commandes API (ne pas modifier ce paramètre sauf si vous savez ce que vous faites)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <select class="configKey form-control" data-l1key="authmethod">
            <option value="0">Token</option>
            <option value="1">Login & Pass</option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Taille des block commandes}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Taille des blocks de commandes envoyés en simultanée à la caméra (+ grand, + rapide, mais plus de risque de plantage, + petit, + lent, mais plus stable)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <select class="configKey form-control" data-l1key="cmdblock">
            <option value="2">2</option>
            <option value="4">4</option>
            <option value="8">8</option>
            <option value="16">16</option>
            <option value="24">24</option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Mode de détection de mouvement}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Protocole utilisé pour la détection de mouvement : ONVIF (standard) ou TCP/Baichuan (natif Reolink, peut être plus réactif)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <select class="configKey form-control" data-l1key="detection_mode">
            <option value="onvif">ONVIF</option>
            <option value="baichuan">TCP/Baichuan</option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{IP de callback du webhook}}
        <sup><i class="fas fa-question-circle tooltips" title="{{IP vers laquelle les caméras vont renvoyer les évènement détections de mouvement. (IP permettant d'accéder au webhook du daemon du plugin) (mode ONVIF)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <select class="configKey form-control" data-l1key="ipwebhook">
            <option value="0">Auto-détection</option>
            <option value="1">Interne</option>
            <option value="2">Externe</option>
            <option value="3">Personnalisée</option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{IP personnalisée}}
        <sup><i class="fas fa-question-circle tooltips" title="{{IP utilisée pour le rappel du daemon, lorsque vous sélectionner l'option : 'Personnalisée' (mode ONVIF)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="webhookdefinedip" />
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Port du webhook}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Port du webhook du daemon utilisé par les caméras lors de la détection des mouvements (mode ONVIF)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="webhookport" value="44010" />
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Port de l'API Reolink AIO}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Port utilisé par l'API interne Reolink AIO pour communiquer avec les caméras (mode TCP/Baichuan)}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="reolink_aio_api_port" value="44011" />
      </div>
    </div>
  </fieldset>
</form>
