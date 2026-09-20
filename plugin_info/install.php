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

require_once __DIR__ . '/../../../core/php/core.inc.php';
/* La désinstallation passe ici alors que le plugin peut déjà être désactivé :
 * l'autoload ne chargerait alors plus sa classe. */
require_once __DIR__ . '/../core/class/voletautobe.class.php';

function voletautobe_install() {
    /*
     * Le plugin ne sert à rien sans la position de l'installation : les heures
     * de lever et de coucher du soleil seraient celles du point zéro, au large
     * du golfe de Guinée, sans qu'aucune erreur ne soit levée. Le dire à
     * l'installation évite de chercher longtemps pourquoi les volets se ferment
     * à 18 h 15 toute l'année.
     *
     * Le contrôle vit dans la classe : le cron quotidien le refait, et retire
     * l'avertissement le jour où la position est renseignée.
     */
    voletautobe::checkLocation();
}

function voletautobe_update() {
    voletautobe_install();

    /*
     * Les groupes déjà créés n'ont pas les commandes ajoutées depuis. Sans ce
     * passage, « Position », « Température retenue » ou « Prochaine fin de
     * protection » n'existeraient que sur les groupes créés après la mise à
     * jour, et l'utilisateur croirait la nouveauté absente. createCommands() ne
     * touche ni au nom ni à la visibilité de ce qui existe déjà : ce que
     * l'utilisateur a réglé lui reste.
     *
     * Un équipement à la fois, sous son propre try : une mise à jour qui meurt
     * sur un groupe — un moment illisible, un enregistrement refusé — ne doit
     * pas priver les autres de la leur. Sinon le premier groupe en défaut
     * laisse toute l'installation à moitié migrée, et l'erreur suivante se
     * produira ailleurs, des jours plus tard.
     */
    foreach (eqLogic::byType('voletautobe') as $eqLogic) {
        try {
            voletautobe_migrateFacade($eqLogic);
            $eqLogic->createCommands();
        } catch (Throwable $e) {
            log::add('voletautobe', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
}

/*
 * Fait passer un groupe au modèle où la façade appartient au groupe.
 *
 * Jusqu'ici, l'orientation était écrite dans chaque moment — une fenêtre
 * d'azimut par moment, et un mode de déclenchement « azimut » avec son propre
 * angle. Elle est désormais unique, dans la configuration de l'équipement, et
 * les moments s'y réfèrent. Sans cette reprise, les réglages existants
 * retomberaient en silence sur la façade par défaut, sud-est à nord-ouest : une
 * maison orientée au nord-est verrait sa protection solaire se déclencher à
 * contretemps, et rien n'indiquerait que le réglage patiemment trouvé a été
 * perdu à une mise à jour.
 *
 * Le moment est relu ici dans sa forme BRUTE, telle qu'elle est en base :
 * cleanSlot() ne connaît plus le mode « azimut » ni le champ du même nom, et
 * les aurait déjà effacés. C'est aussi pourquoi cette migration passe avant
 * tout enregistrement.
 */
function voletautobe_migrateFacade($_eqLogic) {
    /*
     * Déjà migré : la façade est posée, et c'est elle qu'on garde.
     *
     * Le contrôle rend la migration rejouable, et elle le sera —
     * voletautobe_update() passe à chaque mise à jour du plugin. Repartir des
     * valeurs par défaut ici rendrait sa façade par défaut à un groupe déjà
     * réglé, à la mise à jour suivante, sans que rien ne le dise.
     */
    $defined = ($_eqLogic->getConfiguration('facade_from', '') !== '');
    $facade = $defined ? $_eqLogic->facade() : array(
        'from'      => voletautobe::DEFAULT_FACADE_FROM,
        'to'        => voletautobe::DEFAULT_FACADE_TO,
        'elevation' => voletautobe::DEFAULT_FACADE_ELEVATION,
    );

    /*
     * 1. La façade se prend dans le premier moment qui portait une fenêtre.
     *
     * C'est là que l'utilisateur a écrit l'orientation de sa maison, et c'est
     * la seule valeur qu'il ait choisie lui-même : elle prime sur tout défaut.
     * En pratique il n'y en a qu'une — la protection solaire — mais rien ne
     * l'imposait, et le premier moment de la journée qui en porte une gagne.
     */
    if (!$defined) {
        foreach (voletautobe::SLOTS as $key) {
            $slot = $_eqLogic->getConfiguration($key);
            if (!is_array($slot) || !isset($slot['sun_mode'])
                || $slot['sun_mode'] != voletautobeSun::SUN_WINDOW) {
                continue;
            }
            foreach (array('from' => 'sun_from', 'to' => 'sun_to', 'elevation' => 'sun_elevation') as $to => $from) {
                if (isset($slot[$from]) && $slot[$from] !== '' && $slot[$from] !== null) {
                    $facade[$to] = $slot[$from];
                }
            }
            $defined = true;
            break;
        }
    }

    /*
     * 2. Un moment resté en mode azimut devient un moment de façade.
     *
     * « azimuth » est écrit en toutes lettres et non en constante : le mode
     * n'existe plus dans voletautobeSun, remplacé par les deux modes de façade,
     * et c'est bien la valeur en base qu'on reconnaît ici. Son angle devient
     * l'azimut d'arrivée du soleil sur la façade, faute de quoi le moment
     * partirait désormais à un tout autre moment de la journée.
     */
    $slots = array();
    foreach (voletautobe::SLOTS as $key) {
        $slot = $_eqLogic->getConfiguration($key);
        if (!is_array($slot)) {
            continue;
        }
        if (isset($slot['mode']) && $slot['mode'] == 'azimuth') {
            if (!$defined && isset($slot['azimuth']) && $slot['azimuth'] !== '' && $slot['azimuth'] !== null) {
                $facade['from'] = $slot['azimuth'];
                $defined = true;
            }
            $slot['mode'] = voletautobeSun::MODE_FACADE_IN;
        }
        /*
         * 3. Les clés devenues inutiles, retirées du moment.
         *
         * Les laisser ne casserait rien — cleanSlot() les ignore — mais elles
         * resteraient à traîner en base, et le jour où l'on ouvrirait la
         * configuration d'un groupe pour comprendre un comportement, on lirait
         * une orientation par moment qui ne sert plus à rien. slotConfig()
         * remettra sun_from, sun_to et sun_elevation depuis la façade du
         * groupe, et eux seuls font foi.
         */
        foreach (array('azimuth', 'sun_from', 'sun_to', 'sun_elevation') as $obsolete) {
            unset($slot[$obsolete]);
        }
        $slots[$key] = $slot;
    }

    foreach ($slots as $key => $slot) {
        $_eqLogic->setConfiguration($key, $slot);
    }
    $_eqLogic->setConfiguration('facade_from', $facade['from']);
    $_eqLogic->setConfiguration('facade_to', $facade['to']);
    $_eqLogic->setConfiguration('facade_elevation', $facade['elevation']);

    /*
     * L'enregistrement fait le reste : preSave() normalise la façade puis
     * repasse chaque moment par slotConfig(), ce qui crée au passage le
     * quatrième — « Fin de protection », désactivé — sur les groupes qui ne
     * l'ont jamais connu.
     */
    $_eqLogic->save();
}

function voletautobe_remove() {
    /*
     * Les marques d'exécution du jour survivraient à la désinstallation : elles
     * empêcheraient un moment de repartir le jour d'une réinstallation, et rien
     * ne le montrerait. Les équipements, eux, sont nettoyés un par un par
     * voletautobe::preRemove().
     */
    foreach (eqLogic::byType('voletautobe') as $eqLogic) {
        foreach (voletautobe::SLOTS as $key) {
            try {
                cache::delete('voletautobe::done::' . $eqLogic->getId() . '::' . $key);
                /* La marque de mouvement va avec l'autre, et pour la même
                 * raison : oubliée ici, elle ferait croire à une réinstallation
                 * que la protection solaire a déjà fermé les volets du jour, et
                 * la fin de protection les rouvrirait sans qu'ils aient bougé. */
                cache::delete('voletautobe::moved::' . $eqLogic->getId() . '::' . $key);
            } catch (Throwable $e) {
                log::add('voletautobe', 'debug', __('Nettoyage du cache impossible :', __FILE__) . ' ' . $e->getMessage());
            }
        }
    }
    message::removeAll('voletautobe');
}
