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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/voletautobeSun.class.php';
require_once __DIR__ . '/voletautobeVolets.class.php';

/*
 * Un équipement = un groupe de volets et quatre moments : le matin, la
 * protection solaire, la fin de protection, le soir.
 *
 * Ce découpage est le plugin tout entier. On aurait pu faire un équipement par
 * ordre — « ouverture du salon », « fermeture du salon » — mais il faudrait
 * alors choisir quatre fois les mêmes volets, et les quatre quarts d'une même
 * intention pourraient diverger sans qu'on le voie. Un groupe, quatre moments :
 * ce que l'utilisateur ouvre le matin est exactement ce qu'il ferme le soir,
 * par construction.
 *
 * Un groupe rassemble les volets d'une pièce ou d'une façade, et c'est pour
 * cela que l'orientation appartient au groupe et non au moment : le soleil
 * arrive sur la façade et la quitte aux mêmes azimuts pour la protection
 * solaire, pour sa fin et pour toute condition posée. Écrite une fois dans la
 * configuration de l'équipement, elle ne peut plus se contredire d'un moment à
 * l'autre.
 *
 * La nouveauté par rapport au plugin des lampes tient en une phrase : un volet
 * ne se commande pas seulement à l'heure, il se commande aussi à la
 * température. Ouvrir à 7 h par −3 °C fait perdre pour trois heures de lumière
 * grise la chaleur qu'un volet fermé gardait ; fermer aux trois quarts à 13 h
 * n'a de sens qu'un jour à 30 °C, pas un jour d'avril à 14 °C. D'où la sonde,
 * et d'où la condition attachée à chaque moment.
 *
 * Aucun démon, aucune dépendance, aucun appel réseau : le cron du coeur passe
 * chaque minute, l'heure du soleil se calcule en PHP, et un ordre est une
 * commande d'action jouée sur le volet de quelqu'un d'autre.
 */
class voletautobe extends eqLogic {

    /*
     * Les quatre moments, dans l'ordre de la journée.
     *
     * Les noms sont ceux de la configuration, l'interface les appelle « Le
     * matin », « Protection solaire », « Fin de protection » et « Le soir ».
     * L'ordre de cette liste est celui de tout ce qui s'affiche — les blocs de
     * la page, les aperçus, la réponse AJAX — et une journée se lit du matin au
     * soir : un moment rangé au mauvais endroit se cherche.
     */
    const SLOTS = array('morning', 'heat', 'shade_end', 'evening');

    /*
     * La façade du groupe, quand elle n'a jamais été réglée.
     *
     * Sud-est à nord-ouest en passant par le sud : la moitié du ciel où le
     * soleil chauffe vraiment sous nos latitudes. Et 15° de hauteur — plus bas,
     * le soleil rase, il passe derrière les maisons d'en face et ne justifie
     * plus de fermer quoi que ce soit.
     */
    const DEFAULT_FACADE_FROM      = 135.0;
    const DEFAULT_FACADE_TO        = 315.0;
    const DEFAULT_FACADE_ELEVATION = 15.0;

    /* Retard au-delà duquel un ordre manqué n'est plus joué. Une box redémarrée
     * à 3 h du matin ne doit pas rattraper la fermeture de 21 h. Réglable dans
     * la configuration du plugin. */
    const DEFAULT_GRACE_MINUTES = 15;

    /* Combien de temps on se souvient d'avoir joué un moment. Deux jours
     * suffisent : la marque ne sert qu'à ne pas rejouer le même jour. */
    const DONE_MEMORY = 259200;

    /*
     * L'attente entre deux ordres d'un même groupe, en millisecondes.
     *
     * Huit volets commandés dans la même milliseconde, ce sont huit trames
     * radio qui se chevauchent : en 433 MHz une ou deux se perdent, un volet ne
     * bouge pas, et aucune erreur n'est levée puisque la commande a bien été
     * jouée. C'est la panne la plus difficile à voir de tout le plugin, et elle
     * se corrige en laissant respirer la passerelle. Réglable dans la
     * configuration du plugin, 0 pour envoyer tout d'un coup.
     */
    const DEFAULT_ORDER_DELAY = 400;
    const ORDER_DELAY_MAX     = 5000;

    /* Et son garde-fou : l'attente totale d'un groupe, en secondes. Cent volets
     * à 500 ms bloqueraient le cron du coeur cinquante secondes, au-delà de sa
     * propre limite d'exécution — un réglage de confort ne doit pas pouvoir
     * arrêter la programmation de toute la maison. */
    const ORDER_SPREAD_MAX = 30;

    /* Jours en toutes lettres : IntlDateFormatter n'est pas garanti présent sur
     * toutes les installations Jeedom. */
    public static $_days = array(1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche');

    /* ==================================================================== CRON */

    /*
     * Chaque minute, et c'est nécessaire : un utilisateur qui écrit 07:07 attend
     * 7 h 07, pas 7 h 10. Le coût est nul — pour un équipement sans moment
     * actif, le passage se réduit à quelques comparaisons d'entiers.
     */
    public static function cron() {
        $now = time();
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            try {
                $eqLogic->runSchedule($now);
            } catch (Throwable $e) {
                // Un groupe en échec ne doit pas priver les autres de leur matin.
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /*
     * Une fois par jour : l'avertissement de position, posé ou retiré.
     *
     * Le message du centre de messages ne s'efface pas tout seul. Sans ce
     * passage, celui qui renseigne enfin sa latitude garderait l'avertissement
     * sous les yeux indéfiniment, et finirait par le fermer à la main — ce qui
     * le ferait disparaître même le jour où il redeviendrait vrai.
     *
     * Une fois par jour et non chaque minute : la position d'une maison ne
     * bouge pas, et le contrôle coûte une requête.
     */
    public static function cronDaily() {
        self::checkLocation();
    }

    public static function checkLocation() {
        if (self::hasLocation()) {
            message::removeAll(__CLASS__, 'voletautobe::location');
            return;
        }
        message::add(__CLASS__,
            __('Renseignez la position de votre installation pour que les heures de lever et de coucher du soleil soient justes.', __FILE__),
            '', 'voletautobe::location');
    }

    /* =============================================================== POSITION */

    /*
     * La position de l'installation, celle des réglages généraux de Jeedom.
     *
     * Ces trois méthodes s'appellent location() et non position() : dans un
     * plugin de volets, « position » désigne déjà la hauteur d'un tablier, de 0
     * à 100. Deux sens pour un même mot dans le même fichier, et c'est la
     * latitude d'une maison qu'on finit par envoyer à un curseur.
     *
     * C'est la même position que celle dont le coeur se sert pour #sunrise# et
     * #sunset# dans les scénarios : les heures affichées par le plugin et
     * celles des scénarios de l'utilisateur ne peuvent donc pas diverger.
     */
    public static function location() {
        return array(
            'latitude'  => (float) config::byKey('info::latitude'),
            'longitude' => (float) config::byKey('info::longitude'),
        );
    }

    /* Une position vide donne les heures de soleil du golfe de Guinée, sans
     * qu'aucune erreur ne soit levée : les volets se fermeraient tous les jours
     * à la même heure et personne ne comprendrait pourquoi. */
    public static function hasLocation() {
        $location = self::location();
        return ($location['latitude'] != 0 || $location['longitude'] != 0);
    }

    public static function graceSeconds() {
        return max(1, (int) config::byKey('grace_minutes', __CLASS__, self::DEFAULT_GRACE_MINUTES)) * 60;
    }

    /* Le délai entre deux ordres, borné. Zéro est une valeur légitime — une
     * installation entièrement filaire n'a rien à gagner à attendre — et c'est
     * pourquoi la borne basse est 0 et non 1, contrairement au rattrapage. */
    public static function orderDelay() {
        return max(0, min(self::ORDER_DELAY_MAX,
            (int) config::byKey('order_delay', __CLASS__, self::DEFAULT_ORDER_DELAY)));
    }

    /* ===================================================== CYCLE DE VIE eqLogic */

    public function preSave() {
        /*
         * La façade d'abord, les moments ensuite, et l'ordre n'est pas
         * indifférent : slotConfig() recopie la façade dans chaque moment, et
         * la lirait encore sous sa forme brute — « 135 » entre guillemets, ou
         * la chaîne vide d'un champ effacé — si elle n'était pas déjà
         * normalisée ici.
         */
        $facade = $this->facade();
        $this->setConfiguration('facade_from', $facade['from']);
        $this->setConfiguration('facade_to', $facade['to']);
        $this->setConfiguration('facade_elevation', $facade['elevation']);

        /*
         * Les quatre moments arrivent du formulaire tels que le JS les a
         * ramassés : c'est ici, et pas à l'exécution, qu'on leur impose une
         * forme. Une case à cocher absente vaut « 0 » et non « clé manquante »,
         * un décalage vaut un entier et non « 30 » entre guillemets, et une
         * consigne de température tapée « 5,5 » vaut 5.5 et non 5.
         */
        foreach (self::SLOTS as $key) {
            $this->setConfiguration($key, $this->slotConfig($key));
        }
        $this->setConfiguration('volets', self::cleanVolets($this->getConfiguration('volets')));

        /* La tuile porte une ligne de texte — « Fermer demain 21:14 » — que
         * 230 px coupent en deux. L'utilisateur reste libre de la
         * redimensionner. */
        if ($this->getDisplay('width') == '') {
            $this->setDisplay('width', '280px');
        }

        /*
         * Aucune exception ici, même sans volet et sans moment : le coeur crée
         * l'équipement avec son seul nom, et toute validation rendrait le
         * bouton « Ajouter » définitivement inopérant. Un groupe incomplet se
         * signale dans l'interface, pas en refusant d'exister.
         */
    }

    /*
     * Impose sa forme à la liste des volets.
     *
     * Un volet est enregistré par l'identifiant de son équipement et ceux de
     * ses commandes : un renommage du volet ou de la pièce ne casse donc rien,
     * et le nom conservé ici n'est qu'un souvenir d'affichage, rafraîchi à
     * chaque ouverture de la page.
     */
    public static function cleanVolets($_volets) {
        $clean = array();
        if (!is_array($_volets)) {
            return $clean;
        }
        $seen = array();
        foreach ($_volets as $volet) {
            if (!is_array($volet) || !isset($volet['eq'])) {
                continue;
            }
            $eq = (int) $volet['eq'];
            /* Deux fois le même volet dans un groupe, c'est deux ordres
             * identiques envoyés coup sur coup : un moteur qui reçoit « monter »
             * pendant qu'il monte s'arrête, sur certains protocoles. */
            if ($eq <= 0 || isset($seen[$eq])) {
                continue;
            }
            $seen[$eq] = true;
            $entry = array(
                'eq'     => $eq,
                'name'   => isset($volet['name']) ? (string) $volet['name'] : '',
                'object' => isset($volet['object']) ? (string) $volet['object'] : '',
            );
            foreach (array('up', 'down', 'stop', 'slider', 'state') as $role) {
                $entry[$role] = (isset($volet[$role]) && $volet[$role] !== '' && $volet[$role] !== null)
                    ? (int) $volet[$role] : null;
            }
            /* 0 % veut dire fermé et 100 % ouvert dans tout le plugin. Tous les
             * plugins de volets ne suivent pas cette convention, et il n'existe
             * aucun moyen de deviner laquelle est en usage : la case revient
             * donc à l'utilisateur, volet par volet. */
            $entry['invert'] = (isset($volet['invert']) && $volet['invert'] == 1) ? 1 : 0;
            $clean[] = $entry;
        }
        return $clean;
    }

    public function postSave() {
        $this->createCommands();
        try {
            $this->refreshInfo(time());
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
        }
    }

    public function preRemove() {
        foreach (self::SLOTS as $key) {
            cache::delete($this->doneKey($key));
            /* Les deux marques sont nettoyées ensemble : une marque de
             * mouvement oubliée survivrait à l'équipement, et le groupe recréé
             * sous le même identifiant croirait sa protection solaire déjà
             * jouée le jour même. */
            cache::delete($this->movedKey($key));
        }
        message::removeAll(__CLASS__, $this->failureKey());
    }

    /* ============================================================== COMMANDES */

    /*
     * Dix-neuf commandes, dont quatre visibles.
     *
     * Le reste sert aux scénarios et à l'historique, et reste masqué : une
     * tuile de tableau de bord qui empile dix-neuf widgets ne se lit plus.
     * L'utilisateur réaffiche ce qu'il veut, c'est une décision qui lui
     * appartient — mais elle ne doit pas lui être imposée à l'installation.
     *
     * « Position » est masquée alors qu'elle est utile : un curseur sur une
     * tuile s'attrape du doigt en passant, et un volet qui descend à moitié
     * sans qu'on l'ait voulu est plus déroutant qu'une commande à réafficher.
     */
    public function createCommands() {
        $order = 0;
        $definitions = array(
            array('logicalId' => 'next', 'name' => __('Prochain changement', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 1, 'icon' => 'fas fa-clock'),
            array('logicalId' => 'up', 'name' => __('Ouvrir', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => 'FLAP_UP',
                  'visible' => 1, 'icon' => 'fas fa-arrow-up'),
            array('logicalId' => 'down', 'name' => __('Fermer', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => 'FLAP_DOWN',
                  'visible' => 1, 'icon' => 'fas fa-arrow-down'),
            array('logicalId' => 'stop', 'name' => __('Stop', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => 'FLAP_STOP',
                  'visible' => 1, 'icon' => 'fas fa-stop'),
            array('logicalId' => 'position', 'name' => __('Position', __FILE__),
                  'type' => 'action', 'subType' => 'slider', 'generic' => 'FLAP_SLIDER',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'state', 'name' => __('État', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'generic' => 'FLAP_STATE',
                  'visible' => 0, 'historized' => 1, 'icon' => ''),
            array('logicalId' => 'temperature', 'name' => __('Température retenue', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'generic' => 'TEMPERATURE',
                  'visible' => 0, 'historized' => 1, 'icon' => ''),
            array('logicalId' => 'last', 'name' => __('Dernier changement', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'active', 'name' => __('Programmation active', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'generic' => '',
                  'visible' => 0, 'historized' => 1, 'icon' => ''),
            array('logicalId' => 'pause', 'name' => __('Suspendre', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'resume', 'name' => __('Reprendre', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'nextMorning', 'name' => __('Prochain matin', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'nextHeat', 'name' => __('Prochaine protection', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'nextShadeEnd', 'name' => __('Prochaine fin de protection', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'nextEvening', 'name' => __('Prochain soir', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'sunrise', 'name' => __('Lever du soleil', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'sunset', 'name' => __('Coucher du soleil', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            /* La position du soleil n'est pas historisée : c'est un calcul
             * déterministe, et l'archiver reviendrait à stocker une table
             * d'éphémérides que l'on sait refaire à la demande. */
            array('logicalId' => 'azimuth', 'name' => __('Azimut du soleil', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'elevation', 'name' => __('Hauteur du soleil', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
        );

        foreach ($definitions as $definition) {
            $cmd = $this->getCmd(null, $definition['logicalId']);
            $isNew = !is_object($cmd);
            if ($isNew) {
                $cmd = new voletautobeCmd();
                $cmd->setLogicalId($definition['logicalId']);
                $cmd->setEqLogic_id($this->getId());
                /* Aucun de ces noms ne contient ' & # [ ] % / " * :
                 * cmd::setName() les retire en silence, et une commande
                 * renommée dans le dos de l'utilisateur casse l'unicité
                 * (eqLogic_id, name) au deuxième enregistrement. */
                $cmd->setName($definition['name']);
                $cmd->setIsVisible($definition['visible']);
                $cmd->setIsHistorized(isset($definition['historized']) ? $definition['historized'] : 0);
                if ($definition['icon'] != '') {
                    $cmd->setDisplay('icon', '<i class="' . $definition['icon'] . '"></i>');
                }
                if ($definition['logicalId'] == 'position') {
                    /* Un curseur sans bornes se règle de 0 à l'infini : le coeur
                     * les pose à la création, pas après. */
                    $cmd->setConfiguration('minValue', 0);
                    $cmd->setConfiguration('maxValue', 100);
                }
            }
            /*
             * Le type et le type générique sont reposés à chaque enregistrement,
             * le nom et la visibilité non : ces deux-là appartiennent à
             * l'utilisateur dès qu'il y a touché, les premiers déterminent le
             * fonctionnement et une commande mal typée ne s'exécute plus.
             */
            $cmd->setType($definition['type']);
            $cmd->setSubType($definition['subType']);
            $cmd->setGeneric_type($definition['generic']);
            $cmd->setOrder($order++);
            $cmd->save();
        }

        /* Les trois ordres agissent sur la position affichée : sans ce lien, la
         * tuile garde l'ancienne hauteur jusqu'au prochain rafraîchissement, et
         * le curseur revient à sa valeur d'avant sous le doigt. */
        $state = $this->getCmd(null, 'state');
        foreach (array('up', 'down', 'position') as $logicalId) {
            $cmd = $this->getCmd(null, $logicalId);
            if (is_object($cmd) && is_object($state) && $cmd->getValue() != $state->getId()) {
                $cmd->setValue($state->getId());
                $cmd->save();
            }
        }
    }

    /* ============================================================== SUSPENSION */

    /*
     * Suspendre plutôt que désactiver.
     *
     * Partir quinze jours, ou faire dormir quelqu'un dans le salon, n'a rien à
     * voir avec désactiver l'équipement : celui-ci sort alors du tableau de
     * bord, ses boutons ne répondent plus et ses commandes disparaissent des
     * scénarios. Un groupe suspendu, lui, reste entier — on peut toujours
     * ouvrir à la main — mais ses quatre moments se taisent.
     *
     * L'état est enregistré en base et non en cache : un cache vidé rendrait la
     * programmation au matin suivant, et les volets s'ouvriraient sur une
     * chambre où quelqu'un dort sans que personne comprenne pourquoi.
     */
    public function isPaused() {
        return ($this->getConfiguration('paused', 0) == 1);
    }

    public function pauseSchedule($_paused) {
        $paused = $_paused ? 1 : 0;
        if ($this->isPaused() == ($paused == 1)) {
            /* Rien à faire : réenregistrer pour rien ferait repasser toute la
             * configuration dans preSave et écrirait un événement de plus. */
            return $this->isPaused();
        }
        $this->setConfiguration('paused', $paused);
        $this->setConfiguration('paused_since', ($paused == 1) ? date('Y-m-d H:i:s') : '');
        $this->save();

        log::add(__CLASS__, 'info', $this->getHumanName() . ' : '
               . ($paused == 1 ? __('programmation suspendue', __FILE__) : __('programmation reprise', __FILE__)));
        $this->refreshInfo();
        return ($paused == 1);
    }

    /* ============================================================ PROGRAMMATION */

    /* L'action naturelle d'un moment : le matin on ouvre, le soir on ferme, la
     * protection solaire descend à une hauteur choisie et sa fin rouvre. C'est
     * seulement le défaut — rien n'empêche de fermer le matin une chambre
     * exposée à l'est. */
    public static function slotAction($_key) {
        switch ($_key) {
            case 'morning':   return voletautobeSun::ACTION_UP;
            case 'shade_end': return voletautobeSun::ACTION_UP;
            case 'heat':      return voletautobeSun::ACTION_POSITION;
        }
        return voletautobeSun::ACTION_DOWN;
    }

    /*
     * Le réglage d'un moment à la création d'un groupe.
     *
     * Un plugin qui se crée entièrement vide donne quatre moments désactivés à
     * 07:00, et l'utilisateur doit tout régler avant de comprendre ce que le
     * plugin sait faire. Ces valeurs-là racontent l'usage : le matin au lever
     * du soleil mais pas avant 7 h (en juin le soleil se lève à 5 h 30, et
     * personne ne veut ses volets ouverts à 5 h 30), le soir au coucher mais
     * pas avant 18 h (en décembre il se couche à 16 h 40, fermer à 16 h 40 est
     * trop tôt), et la paire protection solaire / fin de protection calée sur
     * la façade du groupe, désactivée, réglée sur « seulement si ≥ 26 °C » pour
     * qu'il ne reste qu'à cocher la case.
     */
    public static function defaultSlot($_key) {
        $slot = voletautobeSun::emptySlot(self::slotAction($_key));
        switch ($_key) {
            case 'morning':
                $slot['enable'] = 1;
                $slot['mode'] = voletautobeSun::MODE_SUNRISE;
                $slot['not_before'] = '07:00';
                break;
            case 'evening':
                $slot['enable'] = 1;
                $slot['mode'] = voletautobeSun::MODE_SUNSET;
                $slot['not_before'] = '18:00';
                break;
            case 'heat':
                /*
                 * Quand le soleil arrive sur la façade, et non à 13 h : c'est
                 * tout l'objet de la protection solaire. Le 13 h qui convient
                 * en juin laisse le soleil taper une heure de trop en août et
                 * ne correspond à rien en octobre, alors que l'azimut d'arrivée
                 * sur la façade est le même toute l'année.
                 */
                $slot['mode'] = voletautobeSun::MODE_FACADE_IN;
                $slot['position'] = 30;
                $slot['temp_mode'] = voletautobeSun::TEMP_MIN;
                $slot['temp_value'] = 26.0;
                /*
                 * Aucune condition de soleil, alors même que ce moment est
                 * celui qui parle le plus du soleil : son déclencheur est déjà
                 * la façade, et un moment déclenché par la façade ne tombe que
                 * lorsque le soleil y est, direction et hauteur comprises. La
                 * cocher d'office livrerait un réglage qui ne fait rien, et
                 * l'utilisateur passerait du temps à se demander lequel des
                 * deux commande vraiment — c'est exactement la question qu'il
                 * ne doit plus avoir à se poser.
                 */
                $slot['sun_mode'] = voletautobeSun::SUN_NONE;
                break;
            case 'shade_end':
                $slot['mode'] = voletautobeSun::MODE_FACADE_OUT;
                /* L'heure fixe ne sert que si l'utilisateur quitte le mode
                 * façade, mais elle doit alors être plausible : emptySlot()
                 * donne 07:00 pour une action d'ouverture, ce qui rouvrirait au
                 * petit matin un moment qui existe pour rouvrir l'après-midi.
                 * C'est aussi la valeur que pose le formulaire, et deux défauts
                 * qui divergent finissent toujours par se voir. */
                $slot['time'] = '17:00';
                /*
                 * Aucune condition de soleil ici, et c'est un piège à éviter
                 * plutôt qu'un oubli : au moment où le soleil quitte la façade,
                 * il n'y est par définition plus. Poser la fenêtre en condition
                 * ferait donc échouer le contrôle à chaque fois et sauterait la
                 * réouverture tous les jours — les volets resteraient à 30 %
                 * jusqu'au soir, exactement ce que ce moment existe pour
                 * éviter, et rien ne le dirait.
                 */
                $slot['sun_mode'] = voletautobeSun::SUN_NONE;
                break;
        }
        return $slot;
    }

    /*
     * Le réglage normalisé d'un moment, façade du groupe comprise.
     *
     * C'est le point d'articulation de toute la façade : l'orientation s'écrit
     * à un seul endroit — les trois clés de configuration de l'équipement — et
     * s'injecte à un seul endroit, ici. voletautobeSun est une classe pure, qui
     * ne connaît ni Jeedom ni équipement : elle doit recevoir la façade avec le
     * moment, dans les champs sun_from, sun_to et sun_elevation de la forme
     * normalisée. Ces trois champs ne viennent donc plus du formulaire, ils
     * sont recopiés avant tout usage.
     *
     * Tout passe par ici : le déclenchement, la condition, l'aperçu, la phrase
     * de résumé, le compte rendu de saut. Un chemin qui lirait le moment
     * autrement travaillerait sur la façade par défaut — sud-est à nord-ouest —
     * et fermerait les volets d'une maison orientée au nord-est sans qu'aucune
     * erreur ne soit levée.
     *
     * Un groupe qui n'a jamais été enregistré n'a rien dans sa configuration :
     * il reçoit les valeurs de départ, et non un moment vide.
     */
    public function slotConfig($_key) {
        $stored = $this->getConfiguration($_key);
        if (!is_array($stored)) {
            $stored = self::defaultSlot($_key);
        }
        return voletautobeSun::cleanSlot($this->withFacade($stored), self::slotAction($_key));
    }

    /* La façade du groupe recopiée dans un moment. Voir slotConfig() : c'est le
     * seul endroit où l'orientation entre dans un réglage, et previewSlot() y
     * passe aussi, parce que le formulaire ne l'envoie plus. */
    public function withFacade($_slot) {
        $slot = is_array($_slot) ? $_slot : array();
        $facade = $this->facade();
        $slot['sun_from']      = $facade['from'];
        $slot['sun_to']        = $facade['to'];
        $slot['sun_elevation'] = $facade['elevation'];
        return $slot;
    }

    /* La graine du tirage aléatoire : propre à l'équipement et au moment, pour
     * que deux groupes réglés pareil ne partent pas à la même seconde — dix
     * moteurs qui démarrent ensemble font un bruit qu'on entend de la rue, et
     * la simulation de présence cherche précisément à l'éviter. */
    public function seed($_key) {
        return __CLASS__ . '|' . $this->getId() . '|' . $_key;
    }

    public function doneKey($_key) {
        return __CLASS__ . '::done::' . $this->getId() . '::' . $_key;
    }

    /*
     * La seconde marque : le moment a-t-il vraiment BOUGÉ aujourd'hui ?
     *
     * doneKey() dit qu'un moment a été évalué — il est posé avant même les
     * conditions, et le reste quand elles écartent l'ordre. C'est ce qu'il faut
     * pour ne pas rejouer, et c'est exactement ce qu'il ne faut pas pour savoir
     * si la protection solaire a fermé quelque chose. D'où celle-ci, posée
     * après un applyAction() qui a envoyé au moins un ordre, et elle seule
     * répond à la question que pose la fin de protection.
     *
     * Même durée de vie que l'autre, et même nettoyage : preRemove() et
     * voletautobe_remove().
     */
    public function movedKey($_key) {
        return __CLASS__ . '::moved::' . $this->getId() . '::' . $_key;
    }

    public function failureKey() {
        return __CLASS__ . '::failure::' . $this->getId();
    }

    /* Le prochain horodatage d'un moment, ou null. */
    public function nextOccurrence($_key, $_now = null) {
        $now = ($_now === null) ? time() : $_now;
        $location = self::location();
        $next = voletautobeSun::nextOccurrences(
            $this->slotConfig($_key), $now,
            $location['latitude'], $location['longitude'],
            $this->seed($_key), 1
        );
        return (count($next) > 0) ? $next[0] : null;
    }

    /*
     * Le travail de la minute : jouer ce qui est dû, puis rafraîchir
     * l'affichage.
     *
     * L'ordre compte. Rafraîchir d'abord annoncerait le prochain rendez-vous
     * avant d'avoir honoré celui qui vient de passer, et la tuile afficherait
     * un instant « Fermer aujourd'hui 21:14 » alors qu'il est 21 h 15.
     */
    public function runSchedule($_now = null) {
        $now = ($_now === null) ? time() : $_now;
        /*
         * Un groupe suspendu ne joue rien, mais continue d'annoncer ce qu'il
         * fera à la reprise : c'est ce qui permet de vérifier d'un coup d'oeil
         * qu'on a bien suspendu le bon groupe, et que rien ne bougera demain
         * matin.
         */
        $fired = false;
        if (!$this->isPaused()) {
            foreach (self::SLOTS as $key) {
                $fired = $this->runSlot($key, $now) || $fired;
            }
        }
        $this->refreshInfo($now, !$fired);
    }

    /*
     * Un moment, à l'instant où il est dû.
     *
     * Rend vrai si un ordre vient d'être envoyé : l'appelant saura qu'il ne
     * faut pas relire la position des volets dans la foulée, un tablier met
     * vingt secondes à descendre.
     *
     * La décision se prend une fois, à l'heure dite. Le moment est marqué joué
     * AVANT que ses conditions ne soient évaluées, et il le reste même si
     * l'une d'elles n'est pas remplie : sinon le cron, qui repasse chaque
     * minute pendant tout le délai de rattrapage, réévaluerait la température
     * à chaque passage. Un matin à 4,8 °C pour un seuil de 5 °C verrait donc
     * les volets rester fermés à 7 h 00, puis partir tout seuls à 7 h 09 parce
     * que le soleil a chauffé la sonde d'un dixième de degré.
     * « Il fait trop froid ce matin » est une décision de la journée, pas une
     * mesure qu'on reprend de minute en minute.
     */
    private function runSlot($_key, $_now) {
        $slot = $this->slotConfig($_key);
        if ($slot['enable'] != 1) {
            return false;
        }
        $location = self::location();
        $due = voletautobeSun::dueOccurrence(
            $slot, $_now, $location['latitude'], $location['longitude'],
            $this->seed($_key), self::graceSeconds()
        );
        if ($due === null) {
            return false;
        }

        /* Déjà joué aujourd'hui : le cron repasse toutes les minutes pendant
         * tout le délai de grâce. */
        $doneKey = $this->doneKey($_key);
        if (cache::byKey($doneKey)->getValue('') === $due['day']) {
            return false;
        }
        cache::set($doneKey, $due['day'], self::DONE_MEMORY);

        /*
         * Le couplage protection / fin de protection, avant toute condition.
         *
         * Avant les deux autres contrôles, et il le faut : quand la protection
         * n'a pas eu lieu, il n'y a rien à rouvrir, et annoncer « trop froid »
         * ou « soleil hors de la fenêtre » enverrait l'utilisateur corriger un
         * réglage qui n'a rien de faux. La vraie raison est ailleurs, et elle
         * est plus haute que les conditions du moment.
         */
        if (!$this->shadeEndAllowed($_key, $due['day'])) {
            $text = self::shadeEndSkipText();
            log::add(__CLASS__, 'info', $this->getHumanName() . ' — ' . $text);
            $this->checkAndUpdateCmd('last', $text . ' ' . self::humanDate(time()));
            return false;
        }

        /*
         * La condition de soleil, sur la position de l'instant.
         *
         * Elle s'évalue avant celle de température, et l'ordre se lit dans le
         * compte rendu : une protection solaire un jour couvert à 27 °C ne doit
         * pas être annoncée comme sautée parce qu'il fait trop chaud alors que
         * la vraie raison est que le soleil n'est pas sur cette façade. C'est
         * aussi la plus fréquente des deux : la température passe le seuil tous
         * les jours d'un même épisode de chaleur, le soleil ne fait que
         * traverser la fenêtre d'azimut.
         *
         * sunCheck() rend met=true quand la position du soleil n'est pas
         * calculable, c'est-à-dire quand la position de l'installation n'est
         * pas renseignée. Même règle que pour la sonde muette, et pour la même
         * raison : la condition est un raffinement, le mouvement est le
         * comportement normal — en cas de doute on bouge, et on le dit ici.
         */
        $sun = self::sunNow($_now);
        $sunCheck = voletautobeSun::sunCheck($slot, $sun['azimuth'], $sun['elevation']);

        if (!$sunCheck['met']) {
            $text = $this->sunSkipText($_key, $slot, $sun, $sunCheck['reason']);
            log::add(__CLASS__, 'info', $this->getHumanName() . ' — ' . $text);
            $this->checkAndUpdateCmd('last', $text . ' ' . self::humanDate(time()));
            return false;
        }

        /*
         * La condition de température, sur la mesure de l'instant.
         *
         * temperatureCheck() rend met=true quand la sonde est muette : une
         * sonde en panne ne doit pas laisser la maison volets fermés
         * indéfiniment. La condition est un raffinement, le mouvement est le
         * comportement normal — en cas de doute on bouge, et on le dit ici,
         * parce que c'est la seule trace qui permettra de comprendre pourquoi
         * les volets se sont ouverts un matin de gel.
         */
        $temperature = $this->temperature();
        $check = voletautobeSun::temperatureCheck($slot, $temperature);

        if (!$check['met']) {
            $text = $this->skipText($_key, $slot, $temperature);
            log::add(__CLASS__, 'info', $this->getHumanName() . ' — ' . $text);
            $this->checkAndUpdateCmd('last', $text . ' ' . self::humanDate(time()));
            return false;
        }

        log::add(__CLASS__, 'info', $this->getHumanName() . ' — ' . self::slotName($_key) . ' : '
               . self::orderName($slot['action'], $slot['position'])
               . ' ' . __('à', __FILE__) . ' ' . date('H:i', $due['timestamp'])
               . ' (' . self::humanSlot($slot) . ')'
               . ($check['known'] ? '' : ' — ' . __('sonde muette, ordre envoyé quand même', __FILE__))
               . ($sunCheck['known'] ? '' : ' — ' . __('position du soleil inconnue, ordre envoyé quand même', __FILE__)));

        $result = $this->applyAction($slot['action'], $slot['position'], true, 'schedule');
        /* La marque de mouvement, et seulement si un ordre est parti : un
         * groupe vide, ou dont tous les volets ont échoué, n'a rien fermé, et
         * la fin de protection n'aurait rien à rouvrir. Voir movedKey(). */
        if ($result['sent'] > 0) {
            cache::set($this->movedKey($_key), $due['day'], self::DONE_MEMORY);
        }
        return true;
    }

    /*
     * La fin de protection ne défait que ce que la protection a fait.
     *
     * Un jour à 19 °C, la protection solaire est sautée — seuil 26 °C — et à
     * 18:03 la fin de protection ouvrait quand même les volets. S'ils étaient
     * fermés parce que quelqu'un faisait la sieste, ou pour ne pas être vu de
     * la rue, l'automatisme défaisait un geste que personne ne lui avait
     * demandé de défaire. C'est le pire reproche qu'on puisse faire à un plugin
     * de ce genre, et il ne se voit qu'après coup.
     *
     * D'où la règle : la fin de protection n'agit que si la protection a
     * réellement bougé le jour même — pas seulement si elle a été évaluée, d'où
     * movedKey() et non doneKey().
     *
     * L'EXCEPTION COMPTE AUTANT QUE LA RÈGLE, et c'est elle qui empêche la
     * correction de devenir à son tour un piège : quand « Protection solaire »
     * est décochée, la fin de protection agit seule. Le couplage existe pour ne
     * pas défaire une protection qui n'a pas eu lieu ; si aucune protection
     * n'est configurée, le moment est autonome, et « ouvrir en fin d'après-midi,
     * quand le soleil quitte la façade » est un réglage légitime en soi. Sans
     * cette exception, un utilisateur qui n'a jamais activé la protection
     * verrait sa réouverture cesser du jour au lendemain, à une mise à jour,
     * sans que rien ne le dise — exactement la panne silencieuse que tout ce
     * plugin cherche à éviter.
     *
     * Les trois autres moments ne sont couplés à rien et passent toujours.
     */
    private function shadeEndAllowed($_key, $_day) {
        if ($_key != 'shade_end') {
            return true;
        }
        $heat = $this->slotConfig('heat');
        if ($heat['enable'] != 1) {
            return true;
        }
        return (cache::byKey($this->movedKey('heat'))->getValue('') === $_day);
    }

    /* Le compte rendu du saut, dans le journal et dans « Dernier changement »,
     * comme pour les deux conditions : le moment est marqué joué malgré tout —
     * la décision se prend une fois, à l'heure dite. */
    private static function shadeEndSkipText() {
        /* Le deux-points fait partie de la chaîne traduite : le français met une
         * espace devant, l'anglais la colle. Le laisser en dur ici donnerait
         * « End of protection skipped : … » dans une interface anglaise. */
        return self::slotName('shade_end') . ' ' . __('sautée :', __FILE__) . ' '
             . self::shadeEndReason();
    }

    /* Le motif seul, comme temperatureReason() et sunReason(), et pour la même
     * raison : l'essai le reprend au conditionnel. */
    private static function shadeEndReason() {
        return __('la protection solaire n\'a pas eu lieu aujourd\'hui', __FILE__);
    }

    /* « Le matin sauté : 1,5 °C, seuil 5 °C ». La mesure et le seuil, tous les
     * deux : « trop froid » seul obligerait à ouvrir la configuration pour
     * savoir de combien on a manqué le seuil. */
    private function skipText($_key, $_slot, $_temperature) {
        return self::skipPrefix($_key) . self::temperatureReason($_slot, $_temperature);
    }

    /* Le motif seul — « 1,5 °C, seuil 5 °C » —, sans le moment ni le fait qu'il
     * a été sauté. Deux phrases l'emploient : le compte rendu du saut réel, à
     * l'heure dite, et celui de l'essai, qui dit ce qui se serait passé. Les
     * écrire deux fois, c'est les voir diverger. */
    private static function temperatureReason($_slot, $_temperature) {
        $measured = ($_temperature === null)
            ? __('sonde muette', __FILE__)
            : self::formatTemperature($_temperature);
        return $measured . ', ' . __('seuil', __FILE__) . ' ' . self::formatTemperature($_slot['temp_value']);
    }

    /* « Protection solaire sauté : soleil à 8,4°, minimum 15° », « Protection
     * solaire sauté : soleil au 112° (est-sud-est), fenêtre 135°–315° ». La
     * position relevée et le réglage, tous les deux, pour la même raison que
     * pour la température : c'est ce qui permet de corriger la fenêtre sans
     * ouvrir la configuration. La hauteur d'abord, parce qu'un soleil sous
     * l'horizon a un azimut parfaitement défini et parfaitement hors sujet. */
    private function sunSkipText($_key, $_slot, $_sun, $_reason) {
        return self::skipPrefix($_key) . self::sunReason($_slot, $_sun, $_reason);
    }

    /* Le motif seul, pour la même raison que temperatureReason(). */
    private static function sunReason($_slot, $_sun, $_reason) {
        if ($_reason == 'too_low') {
            return __('soleil à', __FILE__) . ' ' . self::formatAngle($_sun['elevation'])
                 . ', ' . __('minimum', __FILE__) . ' ' . self::formatAngle($_slot['sun_elevation']);
        }
        return __('soleil au', __FILE__) . ' ' . self::azimuthText($_sun['azimuth'])
             . ', ' . __('fenêtre', __FILE__) . ' '
             . self::formatAngle($_slot['sun_from']) . '–' . self::formatAngle($_slot['sun_to']);
    }

    /* Le début commun des deux comptes rendus de saut : le moment, et le fait
     * qu'il n'a rien envoyé. Ce qui suit est le motif, et lui seul change. */
    private static function skipPrefix($_key) {
        return self::slotName($_key) . ' ' . __('sauté :', __FILE__) . ' ';
    }

    /* ================================================================== ESSAI */

    /*
     * Jouer un moment maintenant, et dire ce que ses conditions auraient dit.
     *
     * Les boutons d'essai du groupe commandent Ouvrir, Fermer et Stop : aucun
     * ne joue ce que fera la protection solaire, avec son action, son
     * pourcentage et ses conditions, et il fallait donc attendre le lendemain
     * pour vérifier la chaîne complète.
     *
     * L'ordre part sans se soucier des conditions — un bouton d'essai qui ne
     * fait rien parce qu'il fait 18 °C serait incompréhensible — mais le compte
     * rendu dit ce qui se serait passé à l'heure dite : « Fermeture à 30 %
     * envoyée à 4 volets. Au moment venu, ce moment aurait été sauté : 18,2 °C,
     * seuil 26 °C. » C'est ce second membre de phrase qui a de la valeur.
     *
     * AUCUNE MARQUE D'EXÉCUTION N'EST POSÉE, ni doneKey() ni movedKey(), et
     * c'est le point à ne pas perdre de vue : un essai qui marquerait le moment
     * joué empêcherait la protection solaire de se jouer pour de vrai le jour
     * même, et la fin de protection de rouvrir le soir. Celui qui essaie son
     * réglage à midi le casserait pour la journée, sans le savoir et sans que
     * rien ne le dise — un bouton de vérification qui sabote ce qu'il vérifie
     * est pire que pas de bouton du tout.
     *
     * Rend array('sent', 'errors', 'action', 'conditions', 'would').
     */
    public function testSlot($_key) {
        if (!in_array($_key, self::SLOTS, true)) {
            /* Plutôt qu'un repli sur le premier moment : jouer « Le matin » à
             * la place d'une clé inconnue ouvrirait tous les volets de la
             * maison pour un essai que personne n'a demandé. */
            throw new Exception(__('moment inconnu :', __FILE__) . ' ' . $_key);
        }
        $slot = $this->slotConfig($_key);

        /* Les conditions d'abord, l'ordre ensuite : l'envoi peut durer —
         * l'étalement des ordres attend jusqu'à trente secondes pour un grand
         * groupe — et le compte rendu doit décrire l'instant où l'on a appuyé,
         * pas celui où le dernier volet a reçu sa trame. */
        $conditions = $this->slotConditions($_key, $slot);
        $result = $this->applyAction($slot['action'], $slot['position'], true, 'test');

        return array(
            'sent'       => $result['sent'],
            'errors'     => $result['errors'],
            'action'     => self::orderNoun($slot['action'], $slot['position']),
            'conditions' => $conditions['text'],
            'would'      => $conditions['would'] ? 1 : 0,
        );
    }

    /*
     * Ce que les conditions d'un moment diraient si on était à son heure.
     *
     * Exactement ce que runSlot() évalue une fois le rendez-vous tombé, et dans
     * le même ordre — couplage, soleil, température : le compte rendu de
     * l'essai doit désigner la même cause que le journal du lendemain, sans
     * quoi l'essai envoie corriger le mauvais réglage.
     *
     * L'heure, les jours cochés et les garde-fous n'y sont pas : ils décident
     * QUAND le moment tombe, ce que l'aperçu des trois prochaines occurrences
     * montre déjà, et qui n'a aucun sens à l'instant d'un essai.
     */
    private function slotConditions($_key, $_slot) {
        if ($_slot['enable'] != 1) {
            /* Un moment décoché ne se jouera jamais, quelles que soient ses
             * conditions : le dire ici évite un « aurait été joué » suivi d'un
             * lendemain où rien ne bouge. */
            return array('would' => false,
                         'text'  => __('Ce moment est désactivé : il ne se jouera pas tant que sa case n\'est pas cochée.', __FILE__));
        }

        if (!$this->shadeEndAllowed($_key, date('Y-m-d'))) {
            return array('would' => false, 'text' => self::wouldSkip(self::shadeEndReason()));
        }

        $sun = self::sunNow();
        $sunCheck = voletautobeSun::sunCheck($_slot, $sun['azimuth'], $sun['elevation']);
        if (!$sunCheck['met']) {
            return array('would' => false, 'text' => self::wouldSkip(self::sunReason($_slot, $sun, $sunCheck['reason'])));
        }

        $temperature = $this->temperature();
        $check = voletautobeSun::temperatureCheck($_slot, $temperature);
        if (!$check['met']) {
            return array('would' => false, 'text' => self::wouldSkip(self::temperatureReason($_slot, $temperature)));
        }

        /* Le moment se serait joué — mais peut-être faute de savoir. Une sonde
         * muette et une position du soleil incalculable laissent passer l'ordre
         * par choix, et c'est précisément ce que l'essai doit montrer : la
         * condition est écrite, elle ne filtre rien. */
        $notes = array();
        if (!$sunCheck['known']) {
            $notes[] = __('position du soleil inconnue, ordre envoyé quand même', __FILE__);
        }
        if (!$check['known']) {
            $notes[] = __('sonde muette, ordre envoyé quand même', __FILE__);
        }
        if (count($notes) == 0) {
            return array('would' => true, 'text' => __('Au moment venu, ce moment aurait été joué.', __FILE__));
        }
        return array('would' => true,
                     'text'  => __('Au moment venu, ce moment aurait été joué :', __FILE__)
                              . ' ' . implode(', ', $notes) . '.');
    }

    /* « Au moment venu, ce moment aurait été sauté : 18,2 °C, seuil 26 °C. » Le
     * même motif que le journal écrirait à l'heure dite, au conditionnel. */
    private static function wouldSkip($_reason) {
        return __('Au moment venu, ce moment aurait été sauté :', __FILE__) . ' ' . $_reason . '.';
    }

    /* ============================================================ TEMPÉRATURE */

    /*
     * La sonde du groupe, sinon celle du plugin.
     *
     * Une maison a une température extérieure, pas huit : choisir la sonde
     * groupe par groupe serait huit fois la même manipulation, et huit endroits
     * à corriger le jour où l'on change de station météo. Un groupe peut quand
     * même avoir la sienne — la chambre au nord, une véranda — et c'est alors
     * elle qui l'emporte.
     */
    public function temperatureCmdId() {
        $id = $this->getConfiguration('temperature_cmd', '');
        if ($id === '' || $id === null) {
            $id = config::byKey('temperature_cmd', __CLASS__, '');
        }
        return (is_numeric($id) && (int) $id > 0) ? (int) $id : null;
    }

    /*
     * La mesure, ou null.
     *
     * Aucun appel réseau : la valeur d'une commande d'information est lue dans
     * le cache du coeur, exactement comme le fait un widget de tableau de bord.
     * Tout ce qui n'est pas un nombre — commande supprimée, sonde jamais
     * remontée, valeur vide — rend null, et null veut dire « on ne sait pas »,
     * jamais « 0 °C » : la différence décide de l'ouverture des volets un matin
     * d'hiver.
     */
    public function temperature() {
        $id = $this->temperatureCmdId();
        if ($id === null) {
            return null;
        }
        try {
            $cmd = cmd::byId($id);
            if (!is_object($cmd) || $cmd->getType() != 'info') {
                return null;
            }
            $value = $cmd->execCmd();
            if ($value === '' || $value === null || !is_numeric($value)) {
                return null;
            }
            return (float) $value;
        } catch (Throwable $e) {
            return null;
        }
    }

    /* Le nom lisible de la sonde retenue, pour que l'interface dise laquelle
     * sert : « celle du plugin » n'apprend rien à qui a deux sondes. */
    public function temperatureName() {
        $id = $this->temperatureCmdId();
        if ($id === null) {
            return '';
        }
        try {
            $cmd = cmd::byId($id);
            return is_object($cmd) ? $cmd->getHumanName() : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    /* « 5,5 °C », « 26 °C », « -3 °C ». La virgule décimale parce que le plugin
     * parle français, et pas de décimale inutile : « 26,0 °C » sur une consigne
     * ronde donne l'impression d'une précision qu'aucune sonde n'a. */
    public static function formatTemperature($_value) {
        $text = number_format((float) $_value, 1, ',', '');
        $text = rtrim(rtrim($text, '0'), ',');
        return $text . ' °C';
    }

    /* La condition d'un moment en toutes lettres, ou '' si le moment se joue
     * quelle que soit la température. */
    public static function temperatureText($_slot) {
        $slot = voletautobeSun::cleanSlot($_slot);
        if ($slot['temp_mode'] == voletautobeSun::TEMP_MIN) {
            return __('seulement si', __FILE__) . ' ≥ ' . self::formatTemperature($slot['temp_value']);
        }
        if ($slot['temp_mode'] == voletautobeSun::TEMP_MAX) {
            return __('seulement si', __FILE__) . ' ≤ ' . self::formatTemperature($slot['temp_value']);
        }
        return '';
    }

    /* ================================================================= FAÇADE */

    /*
     * L'orientation de la façade du groupe, normalisée :
     * array('from' => float, 'to' => float, 'elevation' => float).
     *
     * Elle appartient au groupe et non au moment, parce qu'un groupe rassemble
     * les volets d'une pièce ou d'une façade : le soleil y arrive et la quitte
     * aux mêmes azimuts pour tous ses moments. Écrite dans chaque moment, elle
     * était écrite quatre fois, et l'utilisateur ne savait plus laquelle faisait
     * foi le jour où deux copies divergeaient.
     *
     * « from » est l'azimut où le soleil arrive sur la façade, « to » celui où
     * il la quitte, et la fenêtre qu'ils délimitent passe par le nord quand
     * from > to : « de 300° à 30° » est une façade nord-ouest–nord-est, et elle
     * contient 350°.
     */
    public function facade() {
        return array(
            'from'      => voletautobeSun::cleanAzimuth(
                self::facadeValue($this->getConfiguration('facade_from', ''), self::DEFAULT_FACADE_FROM)),
            'to'        => voletautobeSun::cleanAzimuth(
                self::facadeValue($this->getConfiguration('facade_to', ''), self::DEFAULT_FACADE_TO)),
            'elevation' => voletautobeSun::cleanElevation(
                self::facadeValue($this->getConfiguration('facade_elevation', ''), self::DEFAULT_FACADE_ELEVATION)),
        );
    }

    /*
     * Un champ de façade vide reprend sa valeur de départ.
     *
     * Un champ effacé dans le formulaire arrive comme chaîne vide et non comme
     * clé absente, et (float) '' vaut 0 : la façade regarderait plein nord et
     * accepterait le soleil dès l'horizon. Un groupe jamais enregistré depuis
     * l'arrivée de la façade est dans le même cas, et c'est le cas courant à la
     * mise à jour.
     */
    private static function facadeValue($_value, $_default) {
        return ($_value === '' || $_value === null || is_array($_value)) ? $_default : $_value;
    }

    /* « 135°, sud-est » : l'orientation rappelée là où la façade n'est pas sous
     * les yeux — le journal, la carte d'accueil, la phrase de résumé d'un
     * moment. Le chiffre seul ne dit rien à personne ; le nom de direction se
     * vérifie avec une boussole, et c'est ainsi que l'utilisateur pense sa
     * maison. */
    public static function facadeAngleText($_azimuth) {
        return self::formatAngle($_azimuth)
             . ', ' . __(voletautobeSun::compassName($_azimuth), __FILE__);
    }

    /* ================================================================= SOLEIL */

    /*
     * Où est le soleil maintenant : array('azimuth' => ?float, 'elevation' => ?float).
     *
     * Statique comme location(), et pour la même raison : le soleil est le même
     * pour tous les groupes de la maison. C'est ce qui permet à l'interface de
     * l'afficher une fois, en haut de la page, comme outil de réglage — on
     * regarde par la fenêtre, on voit où tape le soleil, on lit l'azimut et on
     * le reporte dans la fenêtre du moment.
     *
     * Sans position d'installation, on rend null plutôt qu'un chiffre. Le
     * calcul aboutirait pourtant : sur le point zéro, au large du golfe de
     * Guinée, avec un azimut parfaitement plausible et parfaitement faux, qui
     * fermerait les volets sur la mauvaise façade. C'est ce null que sunCheck()
     * lit comme « on ne sait pas », et le moment se joue alors quand même.
     */
    public static function sunNow($_now = null) {
        $now = ($_now === null) ? time() : $_now;
        if (!self::hasLocation()) {
            return array('azimuth' => null, 'elevation' => null);
        }
        $location = self::location();
        return voletautobeSun::sunPosition($now, $location['latitude'], $location['longitude']);
    }

    /* « 200° », « 8,4° ». La même forme que formatTemperature() et pour la même
     * raison : la virgule décimale parce que le plugin parle français, et pas de
     * décimale inutile — « 15,0° » sur une hauteur ronde donnerait l'impression
     * d'une précision qu'aucune façade n'a. */
    public static function formatAngle($_value) {
        $text = number_format((float) $_value, 1, ',', '');
        $text = rtrim(rtrim($text, '0'), ',');
        return $text . '°';
    }

    /* « 200° (sud-sud-ouest) ». Le chiffre seul ne dit rien à personne ; le nom
     * de direction se vérifie avec une boussole, et c'est ainsi que
     * l'utilisateur pense sa maison. Le nom vient de voletautobeSun, qui ne
     * connaît pas Jeedom : il se traduit ici, comme les jours de la semaine. */
    public static function azimuthText($_azimuth) {
        return self::formatAngle($_azimuth)
             . ' (' . __(voletautobeSun::compassName($_azimuth), __FILE__) . ')';
    }

    /* La condition de soleil d'un moment en toutes lettres, ou '' si le moment
     * se joue où que soit le soleil. */
    public static function sunText($_slot) {
        $slot = voletautobeSun::cleanSlot($_slot);
        if ($slot['sun_mode'] != voletautobeSun::SUN_WINDOW) {
            return '';
        }
        $height = __('à plus de', __FILE__) . ' ' . self::formatAngle($slot['sun_elevation'])
                . ' ' . __('de hauteur', __FILE__);
        /*
         * Avec un déclencheur de façade, la phrase ne dit rien de la condition,
         * parce qu'il n'y a plus rien à dire : sunCheck() rend « aucune
         * condition » pour ces deux modes.
         *
         * Le déclencheur ne tombe que lorsque le soleil est sur la façade —
         * azimut ET hauteur — donc la condition ne peut plus rien écarter.
         * Annoncer « soleil entre 135° et 315°, à plus de 15° de hauteur »
         * décrirait un filtre qui n'existe pas, et reposerait la question qui a
         * motivé cette reprise : « ma condition fait-elle doublon avec mon
         * déclencheur ? » Elle n'en fait plus, et le résumé doit se taire
         * plutôt que de laisser croire le contraire.
         */
        if (voletautobeSun::isFacadeMode($slot['mode'])) {
            return '';
        }
        return __('soleil entre', __FILE__) . ' ' . self::formatAngle($slot['sun_from'])
             . ' ' . __('et', __FILE__) . ' ' . self::formatAngle($slot['sun_to'])
             . ', ' . $height;
    }

    /* ================================================================= ORDRES */

    /*
     * Envoie l'ordre à tous les volets du groupe.
     *
     * Un volet en échec ne retient pas les suivants : dans un groupe de six,
     * un moteur débranché ne doit pas laisser cinq pièces dans le noir. Les
     * échecs sont rassemblés et rapportés une fois, au centre de messages,
     * parce qu'un groupe devenu muet est invisible autrement — personne ne lit
     * le journal d'un plugin qui a toujours marché.
     *
     * $_position n'est lu que si $_action vaut 'position'.
     */
    public function applyAction($_action, $_position = null, $_report = true, $_source = 'manual') {
        $action = self::cleanAction($_action);
        $position = ($action == voletautobeSun::ACTION_POSITION)
            ? max(0, min(100, (int) $_position)) : null;
        $volets = $this->getConfiguration('volets');
        $sent   = 0;
        $errors = array();

        if (!is_array($volets) || count($volets) == 0) {
            $errors[] = __('aucun volet dans ce groupe', __FILE__);
        }

        /*
         * L'étalement des ordres, et son plafond.
         *
         * Le délai réglé vaut pour l'immense majorité des groupes ; c'est le
         * groupe démesuré qui est dangereux — cent volets à 500 ms tiendraient
         * le cron du coeur cinquante secondes, au-delà de sa limite
         * d'exécution, et c'est alors toute la programmation de la maison qui
         * s'arrête. Le délai est donc réduit pour tenir dans le plafond plutôt
         * que le groupe abandonné.
         *
         * Le fait est écrit en debug et non en info : c'est un détail
         * d'exécution, pas un événement dont l'utilisateur doive être averti
         * chaque matin dans un journal qu'il relira un jour.
         */
        $count = is_array($volets) ? count($volets) : 0;
        $delay = self::orderDelay();
        if ($count > 1 && $delay * ($count - 1) > self::ORDER_SPREAD_MAX * 1000) {
            $reduced = (int) floor((self::ORDER_SPREAD_MAX * 1000) / ($count - 1));
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' : '
                   /* Une flèche plutôt que « de … à … » : le mot « à » est déjà
                    * traduit ailleurs par « at », qui convient à « à 18:03 » et
                    * pas du tout à « de 500 à 300 ms ». Un même fragment ne
                    * peut pas servir deux phrases qui ne se traduisent pas
                    * pareil, et la flèche ne se traduit pas du tout. */
                   . __('délai entre ordres ramené de', __FILE__) . ' ' . $delay
                   . ' → ' . $reduced . ' ms — '
                   . $count . ' ' . __('volets, attente plafonnée à', __FILE__) . ' '
                   . self::ORDER_SPREAD_MAX . ' s');
            $delay = $reduced;
        }

        $firstOrder = true;
        foreach (is_array($volets) ? $volets : array() as $volet) {
            /* Entre deux volets, et jamais après le dernier : attendre une fois
             * le dernier ordre parti ne sert personne et allonge le cron pour
             * rien. L'attente précède donc l'ordre, sauf pour le premier. */
            if (!$firstOrder && $delay > 0) {
                usleep($delay * 1000);
            }
            $firstOrder = false;
            try {
                $this->pushVolet($volet, $action, $position);
                $sent++;
            } catch (Throwable $e) {
                $name = isset($volet['name']) ? $volet['name'] : ('#' . (isset($volet['eq']) ? $volet['eq'] : '?'));
                $errors[] = $name . ' — ' . $e->getMessage();
                log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $name . ' — ' . $e->getMessage());
            }
        }

        if ($sent > 0) {
            /*
             * La position du groupe est celle du dernier ordre envoyé, et non
             * celle des tabliers : le plugin ne surveille pas ce qu'une
             * télécommande murale fait de son côté, et refreshInfo() corrigera
             * dès que les volets auront publié leur hauteur. « Stop » ne pose
             * rien : un volet arrêté en route est à une hauteur que personne ne
             * connaît, et inventer un chiffre serait pire que de garder le
             * précédent.
             */
            if ($position !== null) {
                $this->checkAndUpdateCmd('state', $position);
            } elseif ($action == voletautobeSun::ACTION_UP) {
                $this->checkAndUpdateCmd('state', 100);
            } elseif ($action == voletautobeSun::ACTION_DOWN) {
                $this->checkAndUpdateCmd('state', 0);
            }
            /*
             * « Est-ce que ça a marché ce matin ? » est la première question
             * qu'on se pose, et le journal est le dernier endroit où l'on pense
             * à aller. Une commande la répond seule, sur le tableau de bord.
             */
            $this->checkAndUpdateCmd('last',
                self::doneName($action, $position)
                . ' ' . self::humanDate(time())
                . ' ' . self::sourceLabel($_source)
                . (count($errors) > 0 ? ' — ' . count($errors) . ' ' . __('en échec', __FILE__) : ''));
        }

        if ($_report) {
            message::removeAll(__CLASS__, $this->failureKey());
            if (count($errors) > 0) {
                message::add(__CLASS__, $this->getHumanName() . ' : '
                           . implode(' ; ', $errors), '', $this->failureKey());
            }
        }
        return array('sent' => $sent, 'errors' => $errors);
    }

    public static function cleanAction($_action) {
        switch ($_action) {
            case voletautobeSun::ACTION_DOWN:     return voletautobeSun::ACTION_DOWN;
            case voletautobeSun::ACTION_POSITION: return voletautobeSun::ACTION_POSITION;
            case 'stop':                          return 'stop';
        }
        return voletautobeSun::ACTION_UP;
    }

    /*
     * Un ordre, un volet.
     *
     * Tout est raisonné dans la convention du plugin — 0 fermé, 100 ouvert —
     * et traduit au dernier moment dans celle du volet. Un volet « inversé »
     * échange ses deux boutons et prend 100 - la consigne : c'est la seule
     * façon de faire coexister dans un même groupe un volet qui publie 0 pour
     * « fermé » et un volet qui publie 0 pour « ouvert », ce qui arrive dès
     * qu'on mélange deux protocoles.
     *
     * Le repli est aussi important que l'ordre lui-même : un volet tout ou rien
     * dans un groupe réglé à 30 % ne doit pas rester immobile sans explication,
     * et un volet à curseur seul doit pouvoir s'ouvrir.
     */
    private function pushVolet($_volet, $_action, $_position) {
        $eqLogic = isset($_volet['eq']) ? eqLogic::byId((int) $_volet['eq']) : null;
        if (!is_object($eqLogic)) {
            throw new Exception(__('équipement introuvable, choisissez le volet à nouveau', __FILE__));
        }
        if ($eqLogic->getIsEnable() != 1) {
            throw new Exception(__('équipement désactivé', __FILE__));
        }
        $invert = (isset($_volet['invert']) && $_volet['invert'] == 1);

        /* La hauteur visée dans la convention du plugin : ouvrir c'est 100,
         * fermer c'est 0, et « stop » ne vise aucune hauteur. */
        $target = null;
        if ($_action == voletautobeSun::ACTION_UP) {
            $target = 100;
        } elseif ($_action == voletautobeSun::ACTION_DOWN) {
            $target = 0;
        } elseif ($_action == voletautobeSun::ACTION_POSITION) {
            $target = max(0, min(100, (int) $_position));
        }

        if ($_action == 'stop') {
            $cmd = $this->resolveVoletCmd($_volet, 'stop');
            if (!is_object($cmd)) {
                /* Explicite, et pas un silence : beaucoup de volets radio n'ont
                 * pas d'ordre d'arrêt, et le bouton « Stop » du groupe ne doit
                 * pas laisser croire qu'il a agi. */
                throw new Exception(__('ce volet ne sait pas s\'arrêter', __FILE__));
            }
            $this->execVoletCmd($cmd, array());
            return;
        }

        /* Les essais, dans l'ordre. Le premier qui existe gagne ; les suivants
         * sont des replis, et un repli se dit dans le journal. */
        $role = ($target >= 50) ? 'up' : 'down';
        if ($invert) {
            $role = ($role == 'up') ? 'down' : 'up';
        }
        $sliderValue = $invert ? (100 - $target) : $target;

        if ($_action == voletautobeSun::ACTION_POSITION) {
            $attempts = array(
                array('role' => 'slider', 'options' => array('slider' => $sliderValue)),
                array('role' => $role, 'options' => array()),
            );
        } else {
            $attempts = array(
                array('role' => $role, 'options' => array()),
                array('role' => 'slider', 'options' => array('slider' => $sliderValue)),
            );
        }

        $first = true;
        foreach ($attempts as $attempt) {
            $cmd = $this->resolveVoletCmd($_volet, $attempt['role']);
            if (!is_object($cmd)) {
                $first = false;
                continue;
            }
            if (!$first) {
                log::add(__CLASS__, 'info', $this->getHumanName() . ' : '
                       . $eqLogic->getName() . ' — '
                       . __('pas de commande pour cet ordre, repli sur', __FILE__) . ' ' . $cmd->getName()
                       . (($_action == voletautobeSun::ACTION_POSITION)
                          ? ' (' . __('volet tout ou rien, consigne', __FILE__) . ' ' . $target . ' %)' : ''));
            }
            $this->execVoletCmd($cmd, $attempt['options']);
            return;
        }
        throw new Exception(__('commande introuvable, choisissez le volet à nouveau', __FILE__));
    }

    private function execVoletCmd($_cmd, $_options) {
        if ($_cmd->getType() != 'action') {
            throw new Exception(__('ce n\'est pas une commande d\'action :', __FILE__) . ' ' . $_cmd->getHumanName());
        }
        $_cmd->execCmd($_options);
    }

    /*
     * La commande d'un rôle sur un volet.
     *
     * Elle a été choisie par le sélecteur et enregistrée par son identifiant.
     * Si elle a disparu depuis — équipement supprimé puis recréé, plugin
     * réinstallé — on redemande au détecteur ce que cet équipement sait faire
     * aujourd'hui plutôt que d'abandonner : l'utilisateur n'a rien changé de
     * son point de vue, son volet doit s'ouvrir. Le résultat n'est pas réécrit
     * dans la configuration — un cron n'enregistre pas à la place de
     * l'utilisateur — mais l'ordre part, et l'interface montrera le volet comme
     * à revoir.
     */
    private function resolveVoletCmd($_volet, $_role) {
        if (isset($_volet[$_role]) && $_volet[$_role] !== null && $_volet[$_role] !== '') {
            $cmd = cmd::byId((int) $_volet[$_role]);
            if (is_object($cmd)) {
                return $cmd;
            }
        }

        $eqLogic = isset($_volet['eq']) ? eqLogic::byId((int) $_volet['eq']) : null;
        if (!is_object($eqLogic)) {
            return null;
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
        if ($volet === null || !isset($volet[$_role]) || $volet[$_role] === null) {
            return null;
        }
        $cmd = cmd::byId($volet[$_role]);
        return is_object($cmd) ? $cmd : null;
    }

    /* D'où vient l'ordre : ce qui distingue une fermeture programmée d'un
     * bouton pressé à la main, et qui répond à « pourquoi mes volets se sont
     * fermés à 13 h ? ». */
    public static function sourceLabel($_source) {
        switch ($_source) {
            case 'schedule': return __('(programmation)', __FILE__);
            case 'test':     return __('(essai)', __FILE__);
        }
        return __('(commande)', __FILE__);
    }

    /*
     * La position du groupe telle que les volets la disent, ou null si aucun ne
     * la dit.
     *
     * Aucun appel réseau : les valeurs sont lues dans le cache du coeur. Ce
     * sont les plugins des volets qui les y déposent quand un tablier bouge, y
     * compris lorsqu'on appuie sur la télécommande murale — c'est tout
     * l'intérêt.
     */
    public function realPosition() {
        $volets = $this->getConfiguration('volets');
        if (!is_array($volets) || count($volets) == 0) {
            return null;
        }
        $values = array();
        foreach ($volets as $volet) {
            $values[] = voletautobeVolets::readPosition(
                isset($volet['state']) ? $volet['state'] : null,
                (isset($volet['invert']) && $volet['invert'] == 1) ? 1 : 0
            );
        }
        return voletautobeVolets::aggregatePosition($values);
    }

    /* ============================================================== AFFICHAGE */

    /*
     * Repose les commandes d'information : la position, la température retenue,
     * le prochain rendez-vous de chaque moment, celui qui vient en premier, et
     * les heures du soleil.
     *
     * checkAndUpdateCmd n'écrit que si la valeur change : appelé chaque minute,
     * il ne produit ni événement ni ligne d'historique tant que rien ne bouge.
     */
    public function refreshInfo($_now = null, $_readState = true) {
        $now = ($_now === null) ? time() : $_now;

        /*
         * La hauteur réelle des tabliers l'emporte sur le souvenir du dernier
         * ordre : quelqu'un a pu remonter un volet à la télécommande, un moteur
         * a pu ne pas recevoir l'ordre, et la tuile mentirait jusqu'au
         * lendemain. Quand aucun volet ne publie sa position, le dernier ordre
         * reste la seule chose que l'on sache, et on n'y touche pas.
         *
         * Pas de lecture dans la minute qui suit un ordre : un tablier met une
         * vingtaine de secondes à descendre, et on écraserait la position que
         * l'ordre vient de poser par celle d'avant.
         */
        if ($_readState) {
            $real = $this->realPosition();
            if ($real !== null) {
                $this->checkAndUpdateCmd('state', $real);
            }
        }

        /* Une sonde muette ne remet pas la commande à zéro : « 0 °C » serait
         * une mesure, et l'historique garderait la trace d'un gel qui n'a pas
         * eu lieu. */
        $temperature = $this->temperature();
        if ($temperature !== null) {
            $this->checkAndUpdateCmd('temperature', $temperature);
        }

        $location = self::location();
        $sun = voletautobeSun::sun($now, $location['latitude'], $location['longitude']);

        $this->checkAndUpdateCmd('sunrise', ($sun['sunrise'] === null) ? '--:--' : date('H:i', $sun['sunrise']));
        $this->checkAndUpdateCmd('sunset', ($sun['sunset'] === null) ? '--:--' : date('H:i', $sun['sunset']));

        /* La position du soleil au dixième de degré : c'est la précision d'une
         * boussole, et c'est déjà plus que n'en demande une façade. Une position
         * incalculable ne remet pas les commandes à zéro — « 0° » serait le nord
         * et l'horizon, deux endroits où le soleil n'est pas. */
        $sunPosition = self::sunNow($now);
        if ($sunPosition['azimuth'] !== null) {
            $this->checkAndUpdateCmd('azimuth', round($sunPosition['azimuth'], 1));
        }
        if ($sunPosition['elevation'] !== null) {
            $this->checkAndUpdateCmd('elevation', round($sunPosition['elevation'], 1));
        }

        $this->checkAndUpdateCmd('active', $this->isPaused() ? 0 : 1);

        $best = null;
        foreach (self::SLOTS as $key) {
            $next = $this->nextOccurrence($key, $now);
            $this->checkAndUpdateCmd(self::nextCmdId($key), ($next === null) ? '' : self::humanDate($next, $now));
            if ($next !== null && ($best === null || $next < $best['timestamp'])) {
                $best = array('timestamp' => $next, 'key' => $key);
            }
        }

        /* Un groupe suspendu le dit à la place de son prochain rendez-vous :
         * afficher « Ouvrir demain 07:12 » pour un groupe qui ne s'ouvrira pas
         * serait un mensonge, et c'est justement la tuile qu'on regarde avant
         * de partir. */
        if ($this->isPaused()) {
            $this->checkAndUpdateCmd('next', __('Suspendu', __FILE__));
            return $best;
        }
        if ($best === null) {
            $this->checkAndUpdateCmd('next', __('Aucun', __FILE__));
            return null;
        }
        $slot = $this->slotConfig($best['key']);
        $this->checkAndUpdateCmd('next', self::actionNoun($slot) . ' ' . self::humanDate($best['timestamp'], $now));
        return $best;
    }

    /* « aujourd'hui 07:12 », « demain 21:14 », « jeudi 13:00 ». Le jour de la
     * semaine plutôt que la date : un rendez-vous à huit jours n'existe pas
     * ici, et « jeudi » se lit plus vite que « 25/09 ». */
    public static function humanDate($_timestamp, $_now = null) {
        $now = ($_now === null) ? time() : $_now;
        $days = (int) floor((strtotime(date('Y-m-d', $_timestamp)) - strtotime(date('Y-m-d', $now))) / 86400);
        $hour = date('H:i', $_timestamp);
        if ($days <= 0) {
            return __('aujourd\'hui', __FILE__) . ' ' . $hour;
        }
        if ($days == 1) {
            return __('demain', __FILE__) . ' ' . $hour;
        }
        return __(self::$_days[(int) date('N', $_timestamp)], __FILE__) . ' ' . $hour;
    }

    public static function slotName($_key) {
        switch ($_key) {
            case 'morning':   return __('Le matin', __FILE__);
            case 'heat':      return __('Protection solaire', __FILE__);
            case 'shade_end': return __('Fin de protection', __FILE__);
        }
        return __('Le soir', __FILE__);
    }

    /*
     * Le logicalId de la commande « prochain rendez-vous » d'un moment.
     *
     * « next » suivi de la clé en capitales de tête, souligné compris :
     * shade_end donne nextShadeEnd. Un simple ucfirst() donnerait
     * « nextShade_end », un logicalId que createCommands() ne crée pas — la
     * commande n'existerait pas, checkAndUpdateCmd() ne ferait rien, et le
     * rendez-vous de ce moment ne s'afficherait jamais, sans la moindre erreur.
     */
    public static function nextCmdId($_key) {
        return 'next' . str_replace(' ', '', ucwords(str_replace('_', ' ', $_key)));
    }

    /* L'ordre d'un moment en un mot : « Ouvrir », « Fermer », « Fermer à 30 % ».
     * Le pourcentage fait partie de l'ordre — « Fermer » pour un volet qui
     * s'arrête aux trois quarts serait faux, et c'est sur cette ligne que
     * l'utilisateur vérifie son réglage. */
    public static function actionName($_slot) {
        $slot = voletautobeSun::cleanSlot($_slot);
        return self::orderName($slot['action'], $slot['position']);
    }

    /*
     * Le même ordre, mais nommé et non commandé : « Ouverture », « Fermeture »,
     * « Fermeture à 30 % ».
     *
     * C'est la forme qui annonce un rendez-vous — « Fermeture aujourd'hui
     * 21:14 » sur la tuile du tableau de bord, sur la carte de la page d'accueil
     * et sous chaque moment. « Fermer aujourd'hui 21:14 » se lirait comme un
     * ordre donné à l'utilisateur, alors que c'est le plugin qui annonce ce
     * qu'il fera. L'impératif reste sur les boutons, qui, eux, commandent.
     */
    public static function actionNoun($_slot) {
        $slot = voletautobeSun::cleanSlot($_slot);
        return self::orderNoun($slot['action'], $slot['position']);
    }

    public static function orderNoun($_action, $_position = null) {
        switch (self::cleanAction($_action)) {
            case voletautobeSun::ACTION_DOWN: return __('Fermeture', __FILE__);
            case 'stop':                      return __('Arrêt', __FILE__);
            case voletautobeSun::ACTION_POSITION:
                $position = max(0, min(100, (int) $_position));
                if ($position == 0)   { return __('Fermeture', __FILE__); }
                if ($position == 100) { return __('Ouverture', __FILE__); }
                return __('Fermeture à', __FILE__) . ' ' . $position . ' %';
        }
        return __('Ouverture', __FILE__);
    }

    public static function orderName($_action, $_position = null) {
        switch (self::cleanAction($_action)) {
            case voletautobeSun::ACTION_DOWN: return __('Fermer', __FILE__);
            case 'stop':                      return __('Stop', __FILE__);
            case voletautobeSun::ACTION_POSITION:
                $position = max(0, min(100, (int) $_position));
                /* Deux façons de dire la même hauteur, et une seule se lit :
                 * « Fermer à 0 % » ferait chercher ce que vaut 0. */
                if ($position == 0)   { return __('Fermer', __FILE__); }
                if ($position == 100) { return __('Ouvrir', __FILE__); }
                return __('Fermer à', __FILE__) . ' ' . $position . ' %';
        }
        return __('Ouvrir', __FILE__);
    }

    /* Le même ordre au passé, pour « Dernier changement » : c'est un compte
     * rendu, pas une intention. */
    private static function doneName($_action, $_position = null) {
        switch (self::cleanAction($_action)) {
            case voletautobeSun::ACTION_DOWN: return __('Fermé', __FILE__);
            case 'stop':                      return __('Arrêté', __FILE__);
            case voletautobeSun::ACTION_POSITION:
                $position = max(0, min(100, (int) $_position));
                if ($position == 0)   { return __('Fermé', __FILE__); }
                if ($position == 100) { return __('Ouvert', __FILE__); }
                return __('Fermé à', __FILE__) . ' ' . $position . ' %';
        }
        return __('Ouvert', __FILE__);
    }

    /* Le réglage d'un moment en une phrase, pour le journal et pour
     * l'interface : « 30 min avant le coucher du soleil, pas avant 18:00, du
     * lundi au vendredi, seulement si ≥ 26 °C ». L'ordre lui-même n'y est pas :
     * il est donné à côté, par actionName(), et le répéter alourdirait la seule
     * ligne que l'utilisateur relit vraiment. */
    public static function humanSlot($_slot) {
        $slot = voletautobeSun::cleanSlot($_slot);
        if ($slot['mode'] == voletautobeSun::MODE_FIXED) {
            $when = __('à', __FILE__) . ' ' . $slot['time'];
        } elseif (voletautobeSun::isFacadeMode($slot['mode'])) {
            /*
             * L'azimut est rappelé entre parenthèses, et il le doit : cette
             * phrase se lit dans le journal et sur la carte d'accueil, loin de
             * l'onglet où la façade est réglée. « Quand le soleil arrive sur la
             * façade » sans le chiffre ne permettrait pas de voir qu'on a tapé
             * 13 au lieu de 135.
             *
             * Le décalage garde tout son sens ici : « 20 min après que le
             * soleil arrive sur la façade », c'est le temps que la façade
             * chauffe.
             */
            $in = ($slot['mode'] == voletautobeSun::MODE_FACADE_IN);
            $reached = ' (' . self::facadeAngleText($in ? $slot['sun_from'] : $slot['sun_to']) . ')';
            if ($slot['offset'] == 0) {
                $when = ($in ? __('quand le soleil arrive sur la façade', __FILE__)
                             : __('quand le soleil quitte la façade', __FILE__)) . $reached;
            } else {
                $when = abs($slot['offset']) . ' ' . __('min', __FILE__) . ' '
                      . (($slot['offset'] < 0) ? __('avant', __FILE__) : __('après', __FILE__)) . ' '
                      . ($in ? __('que le soleil arrive sur la façade', __FILE__)
                             : __('que le soleil quitte la façade', __FILE__)) . $reached;
            }
        } else {
            $event = ($slot['mode'] == voletautobeSun::MODE_SUNSET)
                ? __('le coucher du soleil', __FILE__)
                : __('le lever du soleil', __FILE__);
            if ($slot['offset'] == 0) {
                /* « au coucher du soleil », et non « à le coucher du soleil » :
                 * la préposition se contracte avec l'article, et cette phrase
                 * est la ligne que l'utilisateur relit sous chaque moment, sur
                 * la carte d'accueil et dans le journal. Un plugin qui écrit
                 * « à le » perd sa crédibilité sur tout le reste. */
                $when = ($slot['mode'] == voletautobeSun::MODE_SUNSET)
                    ? __('au coucher du soleil', __FILE__)
                    : __('au lever du soleil', __FILE__);
            } else {
                $when = abs($slot['offset']) . ' ' . __('min', __FILE__) . ' '
                      . (($slot['offset'] < 0) ? __('avant', __FILE__) : __('après', __FILE__)) . ' ' . $event;
            }
        }
        if ($slot['random'] > 0) {
            $when .= ' ± ' . $slot['random'] . ' ' . __('min', __FILE__);
        }
        if ($slot['not_before'] !== '') {
            $when .= ', ' . __('pas avant', __FILE__) . ' ' . $slot['not_before'];
        }
        if ($slot['not_after'] !== '') {
            $when .= ', ' . __('pas après', __FILE__) . ' ' . $slot['not_after'];
        }
        if (count($slot['days']) == 0) {
            $when .= ', ' . __('aucun jour coché', __FILE__);
        } elseif (count($slot['days']) < 7) {
            $names = array();
            foreach ($slot['days'] as $day) {
                $names[] = __(self::$_days[$day], __FILE__);
            }
            $when .= ', ' . implode(' ', $names);
        }
        /* Les deux conditions dans l'ordre où elles sont évaluées : la phrase
         * se lit alors comme le compte rendu du saut s'écrira. */
        $sun = self::sunText($slot);
        if ($sun !== '') {
            $when .= ', ' . $sun;
        }
        $temperature = self::temperatureText($slot);
        if ($temperature !== '') {
            $when .= ', ' . $temperature;
        }
        return $when;
    }

    /*
     * Ce que l'interface affiche pour un groupe : ses volets tels qu'ils
     * s'appellent aujourd'hui, leur hauteur si on la connaît, et ceux qui ont
     * disparu.
     */
    public function voletList() {
        $volets = $this->getConfiguration('volets');
        $list = array();
        foreach (is_array($volets) ? $volets : array() as $volet) {
            $described = voletautobeVolets::describe($volet);
            foreach (array('up', 'down', 'stop', 'slider', 'state') as $role) {
                $described[$role] = isset($volet[$role]) ? $volet[$role] : null;
            }
            $described['invert'] = (isset($volet['invert']) && $volet['invert'] == 1) ? 1 : 0;
            $described['value']  = voletautobeVolets::readPosition($described['state'], $described['invert']);
            $list[] = $described;
        }
        return $list;
    }

    /* L'aperçu d'un moment : ses trois prochaines occurrences, telles qu'elles
     * seront jouées, garde-fous et tirage aléatoire compris. C'est la seule
     * façon honnête de montrer ce qu'un réglage va faire. */
    public function previewSlot($_key, $_slot = null, $_count = 3) {
        $now = time();
        $location = self::location();
        /* Un réglage en cours de saisie arrive sans façade : le formulaire d'un
         * moment ne la porte plus, elle est dans l'onglet Volets. Sans cette
         * recopie, l'aperçu montrerait la façade par défaut — sud-est à
         * nord-ouest — pour une maison orientée autrement, et ce sont
         * précisément les heures que l'utilisateur regarde pour se décider. */
        $slot = ($_slot === null) ? $this->slotConfig($_key)
                                  : voletautobeSun::cleanSlot($this->withFacade($_slot), self::slotAction($_key));
        $preview = array();
        foreach (voletautobeSun::nextOccurrences($slot, $now, $location['latitude'], $location['longitude'], $this->seed($_key), $_count) as $timestamp) {
            $preview[] = self::humanDate($timestamp, $now);
        }
        $needsSun = ($slot['mode'] != voletautobeSun::MODE_FIXED);
        $needsTemperature = ($slot['temp_mode'] != voletautobeSun::TEMP_NONE);
        $needsSunPosition = ($slot['sun_mode'] != voletautobeSun::SUN_NONE);
        return array(
            'summary'     => self::humanSlot($slot),
            'occurrences' => $preview,
            'action'      => $slot['action'],
            'actionName'  => self::actionNoun($slot),
            'needsSun'    => $needsSun ? 1 : 0,
            /*
             * Sans position, date_sun_info répond pour le point zéro, au large
             * du golfe de Guinée : un lever à 6 h et un coucher à 18 h toute
             * l'année, sans la moindre erreur. L'aperçu afficherait donc des
             * heures parfaitement plausibles et parfaitement fausses — c'est
             * ici qu'il faut le dire, au moment où l'utilisateur règle le
             * moment.
             *
             * L'azimut et la hauteur sont logés à la même enseigne, et le
             * doivent : sans latitude, ce sont ceux du golfe de Guinée. Les
             * deux modes de façade sont déjà comptés dans $needsSun, la
             * condition de soleil non.
             */
            'noLocation'  => (($needsSun || $needsSunPosition) && !self::hasLocation()) ? 1 : 0,
            'temperature' => self::temperatureText($slot),
            'sun'         => self::sunText($slot),
            /*
             * Une condition posée sans sonde lisible ne bloque rien — le plugin
             * bouge en cas de doute — mais elle ne fait rien non plus. C'est la
             * panne silencieuse de ce plugin, et l'interface est le seul endroit
             * où elle peut se voir.
             */
            'noSensor'    => ($needsTemperature && $this->temperature() === null) ? 1 : 0,
        );
    }

    /*
     * Ce qu'une carte de la page d'accueil montre : combien de volets, et ce
     * qui va se passer.
     *
     * Le prochain rendez-vous est recalculé plutôt que lu dans la commande :
     * c'est un calcul pur, de l'ordre de la fraction de milliseconde, et la
     * commande peut dater si le cron du coeur a pris du retard — or c'est
     * précisément quand quelque chose ne tourne pas rond qu'on regarde cette
     * page.
     *
     * Les volets sont comptés dans la configuration, sans résoudre chaque
     * équipement : une page de dix groupes n'a pas à faire cinquante requêtes
     * pour afficher un nombre.
     */
    public function cardSummary($_now = null) {
        $now = ($_now === null) ? time() : $_now;
        $volets = $this->getConfiguration('volets');
        $summary = array(
            'volets'   => is_array($volets) ? count($volets) : 0,
            'paused'   => $this->isPaused(),
            'position' => $this->realPosition(),
            'text'     => '',
        );

        if ($summary['paused']) {
            $summary['text'] = __('Programmation suspendue', __FILE__);
            return $summary;
        }

        $best = null;
        foreach (self::SLOTS as $key) {
            $next = $this->nextOccurrence($key, $now);
            if ($next !== null && ($best === null || $next < $best['timestamp'])) {
                $best = array('timestamp' => $next, 'key' => $key);
            }
        }
        if ($best === null) {
            $summary['text'] = __('Aucun moment programmé', __FILE__);
            return $summary;
        }
        $slot = $this->slotConfig($best['key']);
        $summary['text'] = self::actionNoun($slot) . ' ' . self::humanDate($best['timestamp'], $now);
        return $summary;
    }

    /* ================================================================= SANTÉ */

    /*
     * Page Santé du coeur. Statique et publique : le coeur l'appelle sur la
     * classe, et l'Error d'un appel statique sur une méthode d'instance n'est
     * pas rattrapée par son catch (Exception) — c'est toute la page qui tombe.
     */
    public static function health() {
        $location = self::hasLocation();
        $groups = self::byType(__CLASS__, true);
        $volets = 0;
        $missing = 0;
        $paused = 0;
        $blindConditions = 0;
        $blindWindows = 0;
        foreach ($groups as $eqLogic) {
            /* La liste est demandée une fois et relue deux fois : elle résout
             * chaque équipement, et la page Santé n'a pas à le faire deux fois
             * pour une installation de dix groupes. */
            $list = $eqLogic->voletList();
            $volets += count($list);
            /* Un volet dont l'équipement a disparu de Jeedom reste dans la
             * configuration du groupe : il échoue à chaque ordre, et le groupe
             * commande moins de volets qu'il n'en affiche. */
            foreach ($list as $volet) {
                if (isset($volet['missing']) && $volet['missing'] == 1) {
                    $missing++;
                }
            }
            if ($eqLogic->isPaused()) {
                $paused++;
            }
            /* Une condition de température sans sonde lisible : le moment se
             * joue toujours, la condition ne filtre plus rien. Rien d'autre
             * dans Jeedom ne le dira. */
            if ($eqLogic->temperature() === null) {
                foreach (self::SLOTS as $key) {
                    $slot = $eqLogic->slotConfig($key);
                    if ($slot['enable'] == 1 && $slot['temp_mode'] != voletautobeSun::TEMP_NONE) {
                        $blindConditions++;
                        break;
                    }
                }
            }
            /* La même panne silencieuse, une cause de plus : une fenêtre de
             * soleil posée sans position d'installation. Faute de latitude, la
             * position du soleil n'est pas calculable, la condition ne filtre
             * plus rien, et le moment se joue tous les jours comme si la façade
             * était au soleil. */
            if (!$location) {
                foreach (self::SLOTS as $key) {
                    $slot = $eqLogic->slotConfig($key);
                    if ($slot['enable'] == 1 && $slot['sun_mode'] != voletautobeSun::SUN_NONE) {
                        $blindWindows++;
                        break;
                    }
                }
            }
        }
        return array(
            array(
                'test'    => __('Position de l\'installation', __FILE__),
                'result'  => $location ? __('renseignée', __FILE__) : __('absente', __FILE__),
                'advice'  => $location ? '' : __('Réglages → Système → Configuration → Général : sans latitude ni longitude, les heures de soleil sont fausses.', __FILE__),
                'state'   => $location,
            ),
            array(
                'test'    => __('Groupes actifs', __FILE__),
                'result'  => count($groups),
                'advice'  => '',
                'state'   => true,
            ),
            array(
                /* Un groupe suspendu et oublié est la panne la plus discrète du
                 * plugin : tout fonctionne, et rien ne bouge. */
                'test'    => __('Groupes suspendus', __FILE__),
                'result'  => $paused,
                'advice'  => ($paused == 0) ? '' : __('Leur programmation ne joue plus tant qu\'elle n\'est pas reprise.', __FILE__),
                'state'   => ($paused == 0),
            ),
            array(
                'test'    => __('Volets programmés', __FILE__),
                'result'  => $volets,
                'advice'  => ($volets > 0) ? '' : __('Aucun volet choisi : les moments ne feront rien.', __FILE__),
                'state'   => ($volets > 0),
            ),
            array(
                'test'    => __('Volets introuvables', __FILE__),
                'result'  => $missing,
                'advice'  => ($missing == 0) ? ''
                    : __('Ouvrez le groupe concerné : ils portent l\'étiquette « Équipement supprimé ». Tant qu\'ils y sont, le groupe commande moins de volets qu\'il n\'en affiche.', __FILE__),
                'state'   => ($missing == 0),
            ),
            array(
                'test'    => __('Sonde de température', __FILE__),
                'result'  => ($blindConditions == 0)
                    ? __('lisible', __FILE__)
                    : $blindConditions . ' ' . __('groupe(s) sans mesure', __FILE__),
                'advice'  => ($blindConditions == 0) ? ''
                    : __('Leur condition de température est sans effet : faute de mesure, les volets bougent quand même. Choisissez une sonde dans la configuration du plugin ou du groupe.', __FILE__),
                'state'   => ($blindConditions == 0),
            ),
            array(
                'test'    => __('Fenêtre de soleil', __FILE__),
                'result'  => ($blindWindows == 0)
                    ? __('calculable', __FILE__)
                    : $blindWindows . ' ' . __('groupe(s) sans position', __FILE__),
                'advice'  => ($blindWindows == 0) ? ''
                    : __('Leur condition de soleil est sans effet : sans position de l\'installation, l\'azimut et la hauteur du soleil ne se calculent pas, et les volets bougent quand même.', __FILE__),
                'state'   => ($blindWindows == 0),
            ),
        );
    }
}

/*
 * La classe de commande est obligatoire, même réduite à son execute() : sans
 * elle, le coeur refuse de créer un équipement du plugin.
 */
class voletautobeCmd extends cmd {

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        switch ($this->getLogicalId()) {
            case 'up':
                $eqLogic->applyAction('up');
                return;
            case 'down':
                $eqLogic->applyAction('down');
                return;
            case 'stop':
                $eqLogic->applyAction('stop');
                return;
            case 'position':
                /* Le curseur arrive dans $_options['slider'] : un widget de
                 * tableau de bord, un scénario et l'essai de la page passent
                 * tous les trois par là. */
                $position = isset($_options['slider']) ? $_options['slider'] : 0;
                $eqLogic->applyAction('position', $position);
                return;
            case 'pause':
                $eqLogic->pauseSchedule(true);
                return;
            case 'resume':
                $eqLogic->pauseSchedule(false);
                return;
        }
    }
}
