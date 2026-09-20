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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');
    /* L'autoload du coeur ne connaît que la classe qui porte le nom du plugin :
     * les deux autres se chargent par elle, et une action qui commencerait par
     * le détecteur mourrait sur « Class not found ». */
    require_once __DIR__ . '/../class/voletautobe.class.php';

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    /*
     * Récupère un groupe du plugin.
     *
     * eqLogic::byId() charge n'importe quel équipement et le rend dans la
     * classe de SON type : sans ce contrôle, un identifiant étranger ferait
     * agir le plugin sur l'équipement d'un autre.
     */
    $getGroup = function ($_id) {
        $eqLogic = voletautobe::byId($_id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'voletautobe') {
            throw new Exception(__('Groupe introuvable', __FILE__));
        }
        return $eqLogic;
    };

    /*
     * Retrouve la commande d'un rôle sur un volet, pour le bouger depuis le
     * sélecteur.
     *
     * Bouger le volet est la seule façon de savoir lequel s'appelle
     * « Module 3 », donc ces boutons existent — et c'est exactement pour cela
     * qu'ils doivent être tenus court. Une commande désignée par le navigateur
     * est contrôlée avant d'être jouée : elle doit appartenir à l'équipement de
     * la ligne et être une action. Sans ces deux contrôles, le point d'entrée
     * serait un « joue n'importe quelle commande de l'installation » déguisé en
     * bouton d'essai.
     *
     * Rend la commande et ses options : un volet sans bouton « monter » se
     * commande par son curseur, et un curseur a besoin d'une valeur.
     */
    $voletCmd = function ($_eqId, $_order, $_cmdId = '', $_invert = 0) {
        $eqLogic = eqLogic::byId($_eqId);
        if (!is_object($eqLogic)) {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        if ($eqLogic->getEqType_name() == 'voletautobe') {
            throw new Exception(__('Un groupe du plugin ne peut pas être un volet.', __FILE__));
        }

        if ($_cmdId !== '' && $_cmdId !== null) {
            /*
             * Le navigateur a nommé une commande précise : c'est celle que
             * l'utilisateur a choisie dans la liste déroulante du rôle, et
             * l'inversion a déjà joué au moment de ce choix. La rejouer ici
             * ferait descendre un volet à qui l'on demande de monter.
             */
            $cmd = cmd::byId((int) $_cmdId);
            if (!is_object($cmd) || $cmd->getEqLogic_id() != $eqLogic->getId()) {
                throw new Exception(__('Cette commande n\'appartient pas à cet équipement.', __FILE__));
            }
            if ($cmd->getType() != 'action') {
                throw new Exception(__('Ce n\'est pas une commande d\'action.', __FILE__));
            }
            return array('cmd' => $cmd, 'options' => array());
        }

        $cmds = array();
        foreach ($eqLogic->getCmd() as $cmd) {
            $cmds[] = array(
                'id'      => (int) $cmd->getId(),
                'name'    => $cmd->getName(),
                'type'    => $cmd->getType(),
                'subType' => $cmd->getSubType(),
                'generic' => (string) $cmd->getGeneric_type(),
            );
        }
        $volet = voletautobeVolets::classify($eqLogic->getName(), $cmds);
        if ($volet === null) {
            throw new Exception(__('Cet équipement ne sait ni monter ni descendre.', __FILE__));
        }

        if ($_order == 'stop') {
            if ($volet['stop'] === null) {
                throw new Exception(__('Ce volet ne sait pas s\'arrêter.', __FILE__));
            }
            $cmd = cmd::byId($volet['stop']);
            if (!is_object($cmd)) {
                throw new Exception(__('Aucune commande pour cet ordre sur ce volet.', __FILE__));
            }
            return array('cmd' => $cmd, 'options' => array());
        }

        /* 0 fermé, 100 ouvert dans la convention du plugin ; un volet inversé
         * échange ses deux boutons et prend 100 - la consigne. */
        $target = ($_order == 'down') ? 0 : 100;
        $role = ($_order == 'down') ? 'down' : 'up';
        if ($_invert == 1) {
            $role = ($role == 'up') ? 'down' : 'up';
            $target = 100 - $target;
        }

        if ($volet[$role] !== null) {
            $cmd = cmd::byId($volet[$role]);
            if (is_object($cmd)) {
                return array('cmd' => $cmd, 'options' => array());
            }
        }
        if ($volet['slider'] !== null) {
            $cmd = cmd::byId($volet['slider']);
            if (is_object($cmd)) {
                return array('cmd' => $cmd, 'options' => array('slider' => $target));
            }
        }
        throw new Exception(__('Aucune commande pour cet ordre sur ce volet.', __FILE__));
    };

    /* Les volets de l'installation, groupés par pièce. « all » ajoute les
     * équipements dont le plugin ne sait rien dire, pour les installations où
     * un volet est déclaré en module générique. */
    if (init('action') == 'volets') {
        ajax::success(array(
            'groups'      => voletautobeVolets::discover(init('all') == 1),
            'hasLocation' => voletautobe::hasLocation() ? 1 : 0,
        ));
    }

    /* Les sondes de température de l'installation, pour la liste déroulante du
     * groupe et celle de la configuration du plugin. */
    if (init('action') == 'temperatures') {
        ajax::success(array('sensors' => voletautobeVolets::discoverTemperatures()));
    }

    /* Ce qu'un groupe contient et quand il agira : tout ce que la page affiche
     * à l'ouverture d'un équipement. */
    if (init('action') == 'group') {
        $eqLogic = $getGroup(init('id'));
        /* Où est le soleil maintenant : c'est ce qui permet de régler sa fenêtre
         * sans sortir avec une boussole — on regarde par la fenêtre, on voit où
         * tape le soleil, et on lit l'azimut affiché. Les deux valeurs sont
         * nulles quand la position de l'installation n'est pas renseignée. */
        $sun = voletautobe::sunNow();
        /* Les quatre moments dans l'ordre de la journée, et les quatre : un
         * aperçu oublié ici laisse le bloc correspondant muet dans la page,
         * sans erreur ni message — la panne la plus difficile à relier à sa
         * cause, puisque le réglage, lui, est bien enregistré. */
        ajax::success(array(
            'volets'          => $eqLogic->voletList(),
            'morning'         => $eqLogic->previewSlot('morning'),
            'heat'            => $eqLogic->previewSlot('heat'),
            'shade_end'       => $eqLogic->previewSlot('shade_end'),
            'evening'         => $eqLogic->previewSlot('evening'),
            'paused'          => $eqLogic->isPaused() ? 1 : 0,
            'pausedSince'     => $eqLogic->getConfiguration('paused_since', ''),
            'hasLocation'     => voletautobe::hasLocation() ? 1 : 0,
            'temperature'     => $eqLogic->temperature(),
            'temperatureName' => $eqLogic->temperatureName(),
            'azimuth'         => $sun['azimuth'],
            'elevation'       => $sun['elevation'],
        ));
    }

    /*
     * L'aperçu d'un réglage en cours de saisie, avant enregistrement.
     *
     * Le calcul est fait ici et non dans le navigateur : c'est le même code qui
     * répond à la question « quand ? » dans l'interface et qui décide de
     * l'ordre au moment venu. Deux implémentations divergeraient, et c'est
     * l'interface qu'on croirait.
     */
    if (init('action') == 'preview') {
        $slot = json_decode(init('slot'), true);
        $key  = init('key');
        if (!in_array($key, voletautobe::SLOTS)) {
            throw new Exception(__('Moment inconnu :', __FILE__) . ' ' . $key);
        }
        $id = init('id');
        /* Un groupe pas encore enregistré n'a pas d'identifiant : l'aperçu se
         * fait alors sur un groupe vide, ce qui ne change que la graine du
         * tirage aléatoire et la sonde retenue. */
        $eqLogic = is_numeric($id) ? $getGroup($id) : new voletautobe();
        ajax::success($eqLogic->previewSlot($key, $slot));
    }

    /* Suspend ou reprend la programmation du groupe. */
    if (init('action') == 'pause') {
        $eqLogic = $getGroup(init('id'));
        $paused = $eqLogic->pauseSchedule(init('state') == 1);
        ajax::success(array(
            'paused'  => $paused ? 1 : 0,
            'summary' => $paused
                ? __('Programmation suspendue : les volets ne bougeront plus tant qu\'elle n\'est pas reprise.', __FILE__)
                : __('Programmation reprise.', __FILE__),
        ));
    }

    /* Commande tout le groupe, tel que le fera la programmation. L'essai ne
     * regarde pas la température : c'est un essai du câblage, pas de la
     * condition, et un bouton qui ne fait rien parce qu'il fait 14 °C serait
     * incompréhensible. */
    if (init('action') == 'testGroup') {
        unautorizedInDemo();
        $eqLogic = $getGroup(init('id'));
        $result = $eqLogic->applyAction(init('order'), init('position'), false, 'test');
        if (count($result['errors']) > 0) {
            throw new Exception(implode(' ; ', $result['errors']));
        }
        ajax::success(array(
            'sent'    => $result['sent'],
            'summary' => $result['sent'] . ' ' . __('volet(s) commandé(s).', __FILE__),
        ));
    }

    /* Bouge un seul volet, pour le reconnaître dans la pièce. */
    if (init('action') == 'switchVolet') {
        unautorizedInDemo();
        $order = init('order');
        if (!in_array($order, array('up', 'down', 'stop'))) {
            throw new Exception(__('Ordre inconnu :', __FILE__) . ' ' . $order);
        }
        $resolved = $voletCmd(init('eq'), $order, init('cmd'), (init('invert') == 1) ? 1 : 0);
        $resolved['cmd']->execCmd($resolved['options']);
        ajax::success(array('cmd' => $resolved['cmd']->getHumanName()));
    }

    /* Les positions connues, pour les pastilles du sélecteur. */
    if (init('action') == 'states') {
        $states = array();
        $ids = json_decode(init('cmds'), true);
        foreach (is_array($ids) ? $ids : array() as $eq => $cmdId) {
            $states[$eq] = voletautobeVolets::readPosition($cmdId);
        }
        ajax::success($states);
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

} catch (Throwable $e) {
    // Throwable et non Exception : en PHP 8 une Error (méthode inexistante,
    // erreur de type) n'hérite pas d'Exception et donnerait un HTTP 500 muet.
    ajax::error(displayException($e), $e->getCode());
}
