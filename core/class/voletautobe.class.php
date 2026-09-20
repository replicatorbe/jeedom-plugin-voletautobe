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
 * Un équipement = un groupe de volets et trois moments : le matin, la
 * protection solaire, le soir.
 *
 * Ce découpage est le plugin tout entier. On aurait pu faire un équipement par
 * ordre — « ouverture du salon », « fermeture du salon » — mais il faudrait
 * alors choisir trois fois les mêmes volets, et les trois moitiés d'une même
 * intention pourraient diverger sans qu'on le voie. Un groupe, trois moments :
 * ce que l'utilisateur ouvre le matin est exactement ce qu'il ferme le soir,
 * par construction.
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

    /* Les trois moments. Les noms sont ceux de la configuration, l'interface
     * les appelle « Le matin », « Le soir » et « Protection solaire ». */
    const SLOTS = array('morning', 'evening', 'heat');

    /* Retard au-delà duquel un ordre manqué n'est plus joué. Une box redémarrée
     * à 3 h du matin ne doit pas rattraper la fermeture de 21 h. Réglable dans
     * la configuration du plugin. */
    const DEFAULT_GRACE_MINUTES = 15;

    /* Combien de temps on se souvient d'avoir joué un moment. Deux jours
     * suffisent : la marque ne sert qu'à ne pas rejouer le même jour. */
    const DONE_MEMORY = 259200;

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

    /* ===================================================== CYCLE DE VIE eqLogic */

    public function preSave() {
        /*
         * Les trois moments arrivent du formulaire tels que le JS les a
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
        }
        message::removeAll(__CLASS__, $this->failureKey());
    }

    /* ============================================================== COMMANDES */

    /*
     * Seize commandes, dont quatre visibles.
     *
     * Le reste sert aux scénarios et à l'historique, et reste masqué : une
     * tuile de tableau de bord qui empile seize widgets ne se lit plus.
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
            array('logicalId' => 'nextEvening', 'name' => __('Prochain soir', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'sunrise', 'name' => __('Lever du soleil', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 0, 'icon' => ''),
            array('logicalId' => 'sunset', 'name' => __('Coucher du soleil', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
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
     * ouvrir à la main — mais ses trois moments se taisent.
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

    /* L'action naturelle d'un moment : le matin on ouvre, le soir on ferme, et
     * la protection solaire descend à une hauteur choisie. C'est seulement le
     * défaut — rien n'empêche de fermer le matin une chambre exposée à l'est. */
    public static function slotAction($_key) {
        switch ($_key) {
            case 'morning': return voletautobeSun::ACTION_UP;
            case 'heat':    return voletautobeSun::ACTION_POSITION;
        }
        return voletautobeSun::ACTION_DOWN;
    }

    /*
     * Le réglage d'un moment à la création d'un groupe.
     *
     * Un plugin qui se crée entièrement vide donne trois moments désactivés à
     * 07:00, et l'utilisateur doit tout régler avant de comprendre ce que le
     * plugin sait faire. Ces valeurs-là racontent l'usage : le matin au lever
     * du soleil mais pas avant 7 h (en juin le soleil se lève à 5 h 30, et
     * personne ne veut ses volets ouverts à 5 h 30), le soir au coucher mais
     * pas avant 18 h (en décembre il se couche à 16 h 40, fermer à 16 h 40 est
     * trop tôt), et la protection solaire à 13 h, désactivée, réglée sur
     * « seulement si ≥ 26 °C » pour qu'il ne reste qu'à cocher la case.
     */
    public static function defaultSlot($_key) {
        $slot = voletautobeSun::emptySlot(self::slotAction($_key));
        switch ($_key) {
            case 'morning':
                $slot['mode'] = voletautobeSun::MODE_SUNRISE;
                $slot['not_before'] = '07:00';
                break;
            case 'evening':
                $slot['mode'] = voletautobeSun::MODE_SUNSET;
                $slot['not_before'] = '18:00';
                break;
            case 'heat':
                $slot['mode'] = voletautobeSun::MODE_FIXED;
                $slot['time'] = '13:00';
                $slot['position'] = 30;
                $slot['temp_mode'] = voletautobeSun::TEMP_MIN;
                $slot['temp_value'] = 26.0;
                break;
        }
        return $slot;
    }

    /* Le réglage normalisé d'un moment. Un groupe qui n'a jamais été enregistré
     * n'a rien dans sa configuration : il reçoit les valeurs de départ, et non
     * un moment vide. */
    public function slotConfig($_key) {
        $stored = $this->getConfiguration($_key);
        if (!is_array($stored)) {
            $stored = self::defaultSlot($_key);
        }
        return voletautobeSun::cleanSlot($stored, self::slotAction($_key));
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
     * AVANT que la condition de température ne soit évaluée, et il le reste
     * même si la condition n'est pas remplie : sinon le cron, qui repasse
     * chaque minute pendant tout le délai de rattrapage, réévaluerait la
     * température à chaque passage. Un matin à 4,8 °C pour un seuil de 5 °C
     * verrait donc les volets rester fermés à 7 h 00, puis partir tout seuls à
     * 7 h 09 parce que le soleil a chauffé la sonde d'un dixième de degré.
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
               . ($check['known'] ? '' : ' — ' . __('sonde muette, ordre envoyé quand même', __FILE__)));

        $this->applyAction($slot['action'], $slot['position'], true, 'schedule');
        return true;
    }

    /* « Le matin sauté : 1,5 °C, seuil 5 °C ». La mesure et le seuil, tous les
     * deux : « trop froid » seul obligerait à ouvrir la configuration pour
     * savoir de combien on a manqué le seuil. */
    private function skipText($_key, $_slot, $_temperature) {
        $measured = ($_temperature === null)
            ? __('sonde muette', __FILE__)
            : self::formatTemperature($_temperature);
        return self::slotName($_key) . ' ' . __('sauté', __FILE__) . ' : ' . $measured
             . ', ' . __('seuil', __FILE__) . ' ' . self::formatTemperature($_slot['temp_value']);
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

        foreach (is_array($volets) ? $volets : array() as $volet) {
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

        $this->checkAndUpdateCmd('active', $this->isPaused() ? 0 : 1);

        $best = null;
        foreach (self::SLOTS as $key) {
            $next = $this->nextOccurrence($key, $now);
            $this->checkAndUpdateCmd('next' . ucfirst($key), ($next === null) ? '' : self::humanDate($next, $now));
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
            case 'morning': return __('Le matin', __FILE__);
            case 'heat':    return __('Protection solaire', __FILE__);
        }
        return __('Le soir', __FILE__);
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
        } else {
            $event = ($slot['mode'] == voletautobeSun::MODE_SUNSET)
                ? __('le coucher du soleil', __FILE__)
                : __('le lever du soleil', __FILE__);
            if ($slot['offset'] == 0) {
                $when = __('à', __FILE__) . ' ' . $event;
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
        $slot = ($_slot === null) ? $this->slotConfig($_key)
                                  : voletautobeSun::cleanSlot($_slot, self::slotAction($_key));
        $preview = array();
        foreach (voletautobeSun::nextOccurrences($slot, $now, $location['latitude'], $location['longitude'], $this->seed($_key), $_count) as $timestamp) {
            $preview[] = self::humanDate($timestamp, $now);
        }
        $needsSun = ($slot['mode'] != voletautobeSun::MODE_FIXED);
        $needsTemperature = ($slot['temp_mode'] != voletautobeSun::TEMP_NONE);
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
             */
            'noLocation'  => ($needsSun && !self::hasLocation()) ? 1 : 0,
            'temperature' => self::temperatureText($slot),
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
        $paused = 0;
        $blindConditions = 0;
        foreach ($groups as $eqLogic) {
            $volets += count($eqLogic->voletList());
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
                'test'    => __('Sonde de température', __FILE__),
                'result'  => ($blindConditions == 0)
                    ? __('lisible', __FILE__)
                    : $blindConditions . ' ' . __('groupe(s) sans mesure', __FILE__),
                'advice'  => ($blindConditions == 0) ? ''
                    : __('Leur condition de température est sans effet : faute de mesure, les volets bougent quand même. Choisissez une sonde dans la configuration du plugin ou du groupe.', __FILE__),
                'state'   => ($blindConditions == 0),
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
