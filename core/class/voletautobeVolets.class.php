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

/*
 * Trouver les volets tout seul.
 *
 * C'est la raison d'être du plugin autant que la programmation elle-même. Une
 * installation Jeedom un peu fournie compte plusieurs centaines de commandes ;
 * demander à l'utilisateur d'aller y choisir une par une la commande
 * « Monter », la commande « Descendre », le « Stop » et le curseur de chaque
 * volet, c'est lui demander le travail que le plugin est censé lui épargner —
 * et c'est la première occasion de se tromper de commande sans le voir.
 *
 * On raisonne donc par équipement et non par commande : un équipement est un
 * volet s'il porte de quoi le monter, de quoi le descendre ou de quoi lui
 * donner une position, et le plugin retient lui-même les commandes.
 * L'utilisateur ne coche qu'un nom.
 *
 * Quatre niveaux de certitude, parce que toutes les installations ne sont pas
 * rangées de la même façon :
 *
 *   « flap »   : l'équipement porte les types génériques Volet du coeur. Aucun
 *                doute possible, c'est ce que le plugin propose en premier.
 *   « bso »    : brise-soleil orientable. C'en est un, mais à part : ses lames
 *                s'orientent, et l'utilisateur doit savoir ce qu'il programme.
 *   « guess »  : pas de type générique, mais des commandes qui s'appellent
 *                « Monter » et « Descendre ». Beaucoup de protocoles anciens
 *                ne remplissent pas les types génériques ; sans ce dernier
 *                filet, leurs volets seraient introuvables et le plugin
 *                paraîtrait vide.
 *   « unknown »: rien de reconnaissable, mais des commandes d'action. Ceux-là
 *                ne sont proposés que sur demande, dans le sélecteur complet,
 *                et l'utilisateur désigne lui-même les commandes.
 */
class voletautobeVolets {

    const FLAP = 'flap';
    const BSO  = 'bso';
    const GUESS = 'guess';

    /* Quatrième famille, celle du sélecteur complet : un équipement dont on ne
     * sait rien dire, mais qui porte des commandes d'action. Le plugin ne
     * devine rien pour lui — c'est l'utilisateur qui désigne la commande qui
     * monte et celle qui descend. Sans cette porte de sortie, un volet piloté
     * par un module déclaré en « interrupteur » resterait hors d'atteinte, et
     * le sélecteur aurait l'air de mentir. */
    const UNKNOWN = 'unknown';

    /* Les types génériques du coeur, par rôle. Un équipement qui en porte est
     * décrit par son intégrateur : c'est la meilleure source possible. Ces sept
     * types existent bien dans le coeur 4.6 — un type générique inventé ne lève
     * aucune erreur, il ne reconnaît simplement jamais rien. */
    public static $_generics = array(
        'up'     => array('FLAP_UP' => self::FLAP, 'FLAP_BSO_UP' => self::BSO),
        'down'   => array('FLAP_DOWN' => self::FLAP, 'FLAP_BSO_DOWN' => self::BSO),
        'stop'   => array('FLAP_STOP' => self::FLAP),
        'slider' => array('FLAP_SLIDER' => self::FLAP),
        'state'  => array('FLAP_STATE' => self::FLAP, 'FLAP_BSO_STATE' => self::BSO),
    );

    /* Noms de commandes acceptés en dernier recours, sans accent ni casse.
     * Volontairement courts et exacts : « Allumer » ou « On » ne sont pas ici,
     * un volet n'est pas une lampe. */
    public static $_names = array(
        'up'     => array('monter', 'ouvrir', 'up', 'open', 'haut', 'ouverture', 'montee', 'lever'),
        'down'   => array('descendre', 'fermer', 'down', 'close', 'bas', 'fermeture', 'descente', 'baisser'),
        'stop'   => array('stop', 'arreter', 'arret', 'pause'),
        'slider' => array('position', 'niveau', 'slider', 'consigne', 'ouverture'),
    );

    /* Mots qui, dans le nom d'un équipement ou de son objet parent, font penser
     * à un volet. Ils ne servent qu'à trier : un module dont le nom parle de
     * store est proposé avant les autres, jamais coché d'office. */
    public static $_hints = array(
        'volet', 'volets', 'store', 'stores', 'roulant', 'roulants', 'persienne',
        'persiennes', 'jalousie', 'brisesoleil', 'shutter', 'shutters', 'blind',
        'blinds', 'velux', 'rideau', 'rideaux', 'banne', 'screen', 'bso',
    );

    /*
     * Décide si un équipement est un volet, d'après ses seules commandes.
     *
     * Volontairement sans Jeedom : $_cmds est une liste de tableaux
     * array('id', 'name', 'type', 'subType', 'generic'). C'est ce qui permet
     * d'éprouver la reconnaissance hors ligne, sur des installations qu'on n'a
     * pas sous la main (voir tests/run.php).
     *
     * Rend null si l'équipement n'a ni de quoi monter, ni de quoi descendre, ni
     * de quoi se positionner.
     */
    public static function classify($_name, $_cmds, $_objectName = '') {
        $found = array('up' => null, 'down' => null, 'stop' => null, 'slider' => null, 'state' => null);
        $confidence = null;

        /* Premier passage : les types génériques. Ils l'emportent toujours sur
         * les noms, y compris quand une commande « Monter » traîne à côté. */
        foreach ($_cmds as $cmd) {
            $generic = isset($cmd['generic']) ? (string) $cmd['generic'] : '';
            if ($generic === '') {
                continue;
            }
            foreach (self::$_generics as $role => $generics) {
                if (!isset($generics[$generic]) || $found[$role] !== null) {
                    continue;
                }
                if (!self::roleMatchesType($role, $cmd)) {
                    continue;
                }
                $found[$role] = $cmd['id'];
                if ($generics[$generic] == self::FLAP) {
                    $confidence = self::FLAP;
                } elseif ($confidence === null) {
                    $confidence = self::BSO;
                }
            }
        }

        /* Second passage : les noms, pour les rôles restés vides. */
        foreach ($_cmds as $cmd) {
            $normalized = self::normalize(isset($cmd['name']) ? $cmd['name'] : '');
            foreach (self::$_names as $role => $names) {
                if ($found[$role] !== null || !self::roleMatchesType($role, $cmd)) {
                    continue;
                }
                if (!self::roleAllowedForCmd($role, $cmd)) {
                    continue;
                }
                if (in_array($normalized, $names)) {
                    $found[$role] = $cmd['id'];
                    if ($confidence === null) {
                        $confidence = self::GUESS;
                    }
                }
            }
        }

        /*
         * Troisième passage : les noms composés. « Volet salon Monter »,
         * « Store terrasse DOWN » — l'ordre est le dernier mot, et le reste du
         * nom dit qu'il s'agit bien d'un volet.
         *
         * Les deux conditions comptent autant l'une que l'autre. Sans le
         * dernier mot, « Volet salon » passerait pour un ordre de descente
         * parce qu'il finit par les lettres « on ». Sans l'indice de volet,
         * « Portail ouvrir » deviendrait un volet, et le plugin fermerait le
         * portail tous les soirs au coucher du soleil.
         */
        foreach ($_cmds as $cmd) {
            $name = isset($cmd['name']) ? $cmd['name'] : '';
            if (!self::looksLikeVolet($name)) {
                continue;
            }
            $words = explode(' ', self::normalizeWords($name));
            $last = end($words);
            foreach (self::$_names as $role => $names) {
                if ($found[$role] !== null || !self::roleMatchesType($role, $cmd)) {
                    continue;
                }
                if (!self::roleAllowedForCmd($role, $cmd)) {
                    continue;
                }
                if (in_array($last, $names)) {
                    $found[$role] = $cmd['id'];
                    if ($confidence === null) {
                        $confidence = self::GUESS;
                    }
                }
            }
        }

        /*
         * Sans de quoi monter, ni de quoi descendre, ni de quoi se positionner,
         * ce n'est pas un volet. Un équipement qui ne sait que s'arrêter n'en
         * est pas un : « Stop » tout seul se trouve sur une vanne, une pompe,
         * un scénario, et le proposer reviendrait à remplir le sélecteur de
         * bruit. Un curseur seul, en revanche, suffit : beaucoup de volets
         * modernes n'exposent qu'une position de 0 à 100.
         */
        if ($found['up'] === null && $found['down'] === null && $found['slider'] === null) {
            return null;
        }
        if ($confidence === null) {
            $confidence = self::GUESS;
        }

        $hinted = self::looksLikeVolet($_name) || self::looksLikeVolet($_objectName);
        return array(
            'up'         => $found['up'],
            'down'       => $found['down'],
            'stop'       => $found['stop'],
            'slider'     => $found['slider'],
            'state'      => $found['state'],
            'confidence' => $confidence,
            /* Un module nommé « Store terrasse » remonte avec les volets ; un
             * module nommé « Portail » reste dans son coin. */
            'hinted'     => $hinted ? 1 : 0,
        );
    }

    /* Un ordre est une action, un état est une information, une consigne est un
     * curseur. Sans ce contrôle, une commande info nommée « Position » serait
     * retenue pour piloter le volet et l'ordre partirait dans le vide, sans
     * erreur ; et une action « other » nommée « Position » recevrait un
     * execCmd(array('slider' => 30)) que le protocole ignorerait. */
    public static function roleMatchesType($_role, $_cmd) {
        $type = isset($_cmd['type']) ? $_cmd['type'] : '';
        $subType = isset($_cmd['subType']) ? $_cmd['subType'] : '';
        if ($_role == 'state') {
            return ($type == 'info');
        }
        if ($_role == 'slider') {
            return ($type == 'action' && $subType == 'slider');
        }
        return ($type == 'action');
    }

    /*
     * Le mot « Ouverture » appartient aux deux vocabulaires : c'est l'ordre de
     * montée sur un volet à boutons, et le nom du curseur sur un volet à
     * position. Un seul nom, deux rôles — il faut trancher, et le sous-type le
     * fait mieux que le nom.
     *
     * Une commande de sous-type « slider » ne peut donc être que le curseur : la
     * retenir comme ordre de montée l'enverrait à 100 % à chaque matin, sans
     * jamais se servir de la position demandée, et la protection solaire à 30 %
     * ouvrirait le volet en grand.
     */
    public static function roleAllowedForCmd($_role, $_cmd) {
        $subType = isset($_cmd['subType']) ? $_cmd['subType'] : '';
        if ($subType == 'slider') {
            return ($_role == 'slider');
        }
        return true;
    }

    /* En deçà de cette longueur, un indice n'est cherché qu'en mot entier. Trois
     * lettres se retrouvent partout : « bso » est dans « absorbsoleil » comme
     * dans n'importe quel identifiant de module, et un équipement quelconque
     * remonterait avec les volets. */
    const HINT_MIN_LENGTH = 5;

    /* Minuscules, sans accent ni ponctuation : « Fermé » et « ferme » doivent
     * se ressembler. */
    public static function normalize($_value) {
        return str_replace(' ', '', self::normalizeWords($_value));
    }

    /* La même chose, mais les séparateurs deviennent des espaces : c'est la
     * forme qui permet de chercher un mot entier. */
    public static function normalizeWords($_value) {
        $value = mb_strtolower(trim((string) $_value), 'UTF-8');
        $value = strtr($value, array(
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u',
            'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ));
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value));
    }

    /*
     * Le nom parle-t-il d'un volet ?
     *
     * Deux façons de chercher, parce que les noms d'équipements arrivent sous
     * deux formes : « Volet du salon », et « voletsalonsud » tout collé, tel
     * qu'un identifiant MQTT le donne. Un indice un peu long se cherche donc
     * dans le nom collé, où il reste reconnaissable ; un indice court ne se
     * cherche qu'en mot entier, sans quoi « bso » se retrouverait au milieu du
     * premier identifiant venu.
     */
    public static function looksLikeVolet($_name) {
        $words = self::normalizeWords($_name);
        if ($words === '') {
            return false;
        }
        $collapsed = str_replace(' ', '', $words);
        $spaced = ' ' . $words . ' ';

        foreach (self::$_hints as $hint) {
            if (strlen($hint) >= self::HINT_MIN_LENGTH) {
                if (strpos($collapsed, $hint) !== false) {
                    return true;
                }
            } elseif (strpos($spaced, ' ' . $hint . ' ') !== false) {
                return true;
            }
        }
        return false;
    }

    /*
     * La position d'un groupe, à partir de celle de ses volets.
     *
     * La moyenne, et non le minimum ou le maximum : un groupe à moitié ouvert
     * est à 50 %, et c'est ce que l'on veut voir sur la tuile. Les volets qui
     * ne publient rien sont ignorés plutôt que comptés pour zéro — sans quoi un
     * groupe de deux volets grands ouverts, dont un seul remonte sa position,
     * s'afficherait à 50 % et paraîtrait à moitié fermé.
     *
     * Rend null quand aucun volet ne se prononce : il faut alors pouvoir dire
     * qu'on ne sait pas. Beaucoup de modules commandés en 433 MHz ne renvoient
     * rien, et leur groupe doit garder le souvenir du dernier ordre envoyé,
     * faute de mieux.
     *
     * Sans Jeedom : c'est la règle d'agrégation, et elle s'éprouve hors ligne.
     */
    public static function aggregatePosition($_values) {
        $sum = 0;
        $count = 0;
        foreach ($_values as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $sum += (float) $value;
            $count++;
        }
        if ($count == 0) {
            return null;
        }
        return (int) round($sum / $count);
    }

    /*
     * La commande mesure-t-elle une température ?
     *
     * Volontairement sans Jeedom, pour la même raison que classify() : la liste
     * des sondes proposées dans la configuration est ce qui décide si la
     * condition de température sera réglable, et une sonde oubliée est une
     * fonction entière du plugin qui paraît absente.
     *
     * $_cmd est le tableau habituel, avec en plus 'unit'. Le type générique
     * d'abord, parce qu'il vient de l'intégrateur ; puis, pour les protocoles
     * qui ne le remplissent pas, l'unité et le nom. On exige toujours une
     * information numérique : une commande d'action nommée « Température de
     * consigne » règle le chauffage, elle ne le mesure pas.
     */
    public static function isTemperature($_cmd) {
        $generics = array('TEMPERATURE', 'THERMOSTAT_TEMPERATURE', 'THERMOSTAT_TEMPERATURE_OUTDOOR', 'WEATHER_TEMPERATURE');
        $generic = isset($_cmd['generic']) ? (string) $_cmd['generic'] : '';
        $type = isset($_cmd['type']) ? $_cmd['type'] : '';
        $subType = isset($_cmd['subType']) ? $_cmd['subType'] : '';

        if ($generic !== '' && in_array($generic, $generics) && $type == 'info') {
            return true;
        }
        if ($type != 'info' || $subType != 'numeric') {
            return false;
        }

        /*
         * L'unité doit désigner une température, et le degré seul n'en désigne
         * aucune.
         *
         * Une girouette publie sa direction en « ° », un robot son cap en « ° » :
         * accepter le symbole nu les faisait entrer dans la liste des sondes,
         * et une condition « seulement si ≥ 5 °C » posée par erreur sur une
         * direction de vent à 338 serait toujours remplie. Le moment partirait
         * tous les jours, l'utilisateur croirait sa condition active, et rien
         * n'en dirait un mot. Relevé sur une installation réelle.
         *
         * On compare donc l'unité entière, débarrassée du degré et des espaces,
         * à la liste des échelles connues : « °C » et « C » passent, « ° » non.
         */
        $unit = strtoupper(str_replace(array(' ', '°', '.'), '', isset($_cmd['unit']) ? (string) $_cmd['unit'] : ''));
        if (in_array($unit, array('C', 'F', 'K', 'DEGC', 'DEGF', 'CELSIUS', 'FAHRENHEIT'))) {
            return true;
        }
        return (strpos(self::normalize(isset($_cmd['name']) ? $_cmd['name'] : ''), 'temp') !== false);
    }

    /*
     * Une commande est-elle une mesure de luminosité ?
     *
     * Même démarche que pour la température : le type générique d'abord, puis
     * l'unité, puis le nom. LIGHT_BRIGHTNESS n'y figure pas, et c'est voulu :
     * c'est le niveau de variation d'une lampe, pas la lumière du jour, et une
     * condition posée dessus dépendrait de l'éclairage du salon.
     *
     * Le rayonnement solaire (W/m²) et l'indice UV sont acceptés : ils
     * répondent aussi bien que des lux à la seule question posée — y a-t-il
     * du soleil sur la vitre ou des nuages ? — et beaucoup de stations météo
     * ne publient que ceux-là.
     */
    public static function isLuminosity($_cmd) {
        $generics = array('BRIGHTNESS', 'UV');
        $generic = isset($_cmd['generic']) ? (string) $_cmd['generic'] : '';
        $type = isset($_cmd['type']) ? $_cmd['type'] : '';
        $subType = isset($_cmd['subType']) ? $_cmd['subType'] : '';

        if ($generic !== '' && in_array($generic, $generics) && $type == 'info') {
            return true;
        }
        if ($type != 'info' || $subType != 'numeric') {
            return false;
        }
        $unit = strtoupper(str_replace(array(' ', '²', '.'), array('', '2', ''), isset($_cmd['unit']) ? (string) $_cmd['unit'] : ''));
        if (in_array($unit, array('LX', 'LUX', 'KLX', 'KLUX', 'W/M2', 'WM2'))) {
            return true;
        }
        /* « luminosite » et non « lumin » : un « Luminaire » est une lampe, et
         * son niveau de variation n'a rien à dire du ciel. */
        $name = self::normalizeWords(isset($_cmd['name']) ? $_cmd['name'] : '');
        foreach (array('luminosite', 'luminance', 'lux', 'eclairement', 'rayonnement', 'irradiance', 'illuminance', 'uv') as $word) {
            if (preg_match('/\b' . $word . '/', $name)) {
                return true;
            }
        }
        return false;
    }

    /* ==================================================== CÔTÉ JEEDOM */

    /*
     * Parcourt l'installation et rend les volets, groupés par objet.
     *
     * $_all ajoute les équipements dont on ne sait rien dire mais qui portent
     * des commandes d'action : c'est le sélecteur complet, celui qu'on ouvre
     * quand le volet cherché n'apparaît nulle part. Ils arrivent sans commandes
     * retenues — l'utilisateur les désigne — et jamais cochés.
     */
    public static function discover($_all = false) {
        $groups = array();

        foreach (eqLogic::all() as $eqLogic) {
            /* Le plugin ne se propose pas lui-même : un groupe qui se
             * contiendrait s'appellerait sans fin, et le sélecteur n'y
             * gagnerait rien. */
            if ($eqLogic->getEqType_name() == 'voletautobe') {
                continue;
            }
            /* Le filtre se fait ici et non par eqLogic::all(true) : le SQL du
             * coeur accroche sa condition isEnable au ON d'une jointure, où elle
             * ne filtre rien. Un équipement désactivé n'exécuterait pas l'ordre,
             * le proposer serait promettre un volet qui ne bougera pas. */
            if ($eqLogic->getIsEnable() != 1) {
                continue;
            }

            $objectName = '';
            try {
                $object = $eqLogic->getObject();
                if (is_object($object)) {
                    $objectName = $object->getName();
                }
            } catch (Throwable $e) {
                $objectName = '';
            }

            $cmds = array();
            $actions = array();
            $states = array();
            foreach ($eqLogic->getCmd() as $cmd) {
                $description = array(
                    'id'      => (int) $cmd->getId(),
                    'name'    => $cmd->getName(),
                    'type'    => $cmd->getType(),
                    'subType' => $cmd->getSubType(),
                    'generic' => (string) $cmd->getGeneric_type(),
                );
                $cmds[] = $description;
                if ($description['type'] == 'action') {
                    /* Le sous-type accompagne la commande : c'est lui qui dit à
                     * l'interface laquelle des listes déroulantes peut recevoir
                     * quoi, et qui empêche de désigner un bouton poussoir comme
                     * curseur de position. */
                    $actions[] = array(
                        'id'      => $description['id'],
                        'name'    => $description['name'],
                        'subType' => $description['subType'],
                    );
                } elseif ($description['subType'] == 'numeric' || $description['subType'] == 'binary') {
                    $states[] = $description['id'];
                }
            }

            $volet = self::classify($eqLogic->getName(), $cmds, $objectName);
            if ($volet === null) {
                /* Sans commande d'action, il n'y a rien à proposer, même dans le
                 * sélecteur complet : une sonde de température ne fermera jamais
                 * rien. */
                if (!$_all || count($actions) == 0) {
                    continue;
                }
                $volet = array(
                    'up' => null, 'down' => null, 'stop' => null, 'slider' => null,
                    /* Faute de type générique, le premier état numérique ou
                     * binaire de l'équipement sert de pastille : c'est un
                     * indice, pas une certitude, et il vaut mieux que rien pour
                     * reconnaître le volet qu'on vient de faire bouger. */
                    'state'      => (count($states) > 0) ? $states[0] : null,
                    'confidence' => self::UNKNOWN,
                    'hinted'     => (self::looksLikeVolet($eqLogic->getName()) || self::looksLikeVolet($objectName)) ? 1 : 0,
                );
            }

            /* Les commandes d'action de l'équipement accompagnent toujours le
             * volet : elles alimentent les quatre listes déroulantes qui
             * permettent de corriger un choix du détecteur, et de désigner les
             * siennes pour un équipement inconnu. */
            $volet['cmds'] = $actions;

            $volet['eq']     = (int) $eqLogic->getId();
            $volet['name']   = $eqLogic->getName();
            $volet['object'] = ($objectName === '') ? __('Sans objet parent', __FILE__) : $objectName;
            $volet['plugin'] = $eqLogic->getEqType_name();
            $volet['value']  = self::readPosition($volet['state']);
            /* Découvert, donc jamais inversé : c'est l'utilisateur qui coche la
             * case quand il constate que le volet annonce 0 alors qu'il est
             * ouvert. Deviner l'inversion serait inverser un volet sur deux. */
            $volet['invert'] = 0;

            $key = $volet['object'];
            if (!isset($groups[$key])) {
                $groups[$key] = array('object' => $key, 'volets' => array());
            }
            $groups[$key]['volets'][] = $volet;
        }

        /* Les pièces dans l'ordre alphabétique, « Sans objet parent » en dernier :
         * c'est le fourre-tout, il n'a pas à ouvrir la liste. */
        uksort($groups, function ($_a, $_b) {
            $orphan = __('Sans objet parent', __FILE__);
            if ($_a == $orphan) { return 1; }
            if ($_b == $orphan) { return -1; }
            return strcasecmp($_a, $_b);
        });

        foreach ($groups as &$group) {
            usort($group['volets'], function ($_a, $_b) {
                $rank = array(self::FLAP => 0, self::BSO => 1, self::GUESS => 2, self::UNKNOWN => 3);
                if ($rank[$_a['confidence']] != $rank[$_b['confidence']]) {
                    return $rank[$_a['confidence']] - $rank[$_b['confidence']];
                }
                if ($_a['hinted'] != $_b['hinted']) {
                    return $_b['hinted'] - $_a['hinted'];
                }
                return strcasecmp($_a['name'], $_b['name']);
            });
        }
        unset($group);

        return array_values($groups);
    }

    /*
     * Les sondes de température de l'installation, à plat, triées par pièce puis
     * par nom.
     *
     * À plat et non groupées : c'est une liste déroulante, pas un sélecteur, et
     * une maison compte quelques sondes là où elle compte des centaines de
     * commandes. Le nom de la pièce voyage avec la sonde pour que « Température »
     * reste distinguable de « Température » quand il y en a une par étage.
     */
    public static function discoverTemperatures() {
        return self::discoverSensors(array(__CLASS__, 'isTemperature'));
    }

    /* Les sondes de luminosité, sous la même forme, pour la condition de
     * luminosité. Une maison en compte encore moins que de sondes de
     * température, souvent une seule, sur la station météo. */
    public static function discoverLuminosities() {
        return self::discoverSensors(array(__CLASS__, 'isLuminosity'));
    }

    /* Le parcours commun aux deux listes : seul le critère change. */
    private static function discoverSensors($_matcher) {
        $sensors = array();

        foreach (eqLogic::all() as $eqLogic) {
            if ($eqLogic->getIsEnable() != 1) {
                continue;
            }
            /* Les groupes du plugin publient leur « Température retenue » et
             * leur « Luminosité retenue » : ce sont des copies de la sonde
             * choisie, et en choisir une comme sonde ferait tourner la
             * condition en rond sur la valeur de la veille. */
            if ($eqLogic->getEqType_name() == 'voletautobe') {
                continue;
            }
            $objectName = '';
            try {
                $object = $eqLogic->getObject();
                if (is_object($object)) {
                    $objectName = $object->getName();
                }
            } catch (Throwable $e) {
                $objectName = '';
            }

            foreach ($eqLogic->getCmd() as $cmd) {
                $description = array(
                    'id'      => (int) $cmd->getId(),
                    'name'    => $cmd->getName(),
                    'type'    => $cmd->getType(),
                    'subType' => $cmd->getSubType(),
                    'generic' => (string) $cmd->getGeneric_type(),
                    'unit'    => (string) $cmd->getUnite(),
                );
                if (!call_user_func($_matcher, $description)) {
                    continue;
                }

                $value = null;
                try {
                    $read = $cmd->execCmd();
                    if ($read !== '' && $read !== null) {
                        $value = (float) $read;
                    }
                } catch (Throwable $e) {
                    /* Une sonde illisible reste proposée, sans mesure : c'est
                     * peut-être celle qu'on vient d'installer, et la refuser
                     * parce qu'elle n'a pas encore remonté de valeur ferait
                     * croire qu'elle n'existe pas. */
                    $value = null;
                }

                $sensors[] = array(
                    'id'     => $description['id'],
                    'name'   => $eqLogic->getName() . ' — ' . $description['name'],
                    'object' => ($objectName === '') ? __('Sans objet parent', __FILE__) : $objectName,
                    'plugin' => $eqLogic->getEqType_name(),
                    'value'  => $value,
                    'unit'   => $description['unit'],
                );
            }
        }

        usort($sensors, function ($_a, $_b) {
            $orphan = __('Sans objet parent', __FILE__);
            if ($_a['object'] != $_b['object']) {
                if ($_a['object'] == $orphan) { return 1; }
                if ($_b['object'] == $orphan) { return -1; }
                return strcasecmp($_a['object'], $_b['object']);
            }
            return strcasecmp($_a['name'], $_b['name']);
        });

        return $sensors;
    }

    /*
     * La position d'un volet, de 0 (fermé) à 100 (ouvert), ou null si on ne sait
     * pas. Un état binaire vaut 0 ou 100 : un volet n'a alors que deux états, et
     * répondre « 1 % » serait mentir sur la précision.
     *
     * $_invert pour les modules qui comptent à l'envers — 0 veut dire « ouvert »
     * chez eux. La convention du plugin est écrite partout, y compris dans la
     * documentation : 0 % = fermé, 100 % = ouvert. Tous les plugins ne la
     * respectent pas, et une pastille qui annonce « fermé » sur un volet grand
     * ouvert décrédibilise tout le reste.
     */
    public static function readPosition($_cmdId, $_invert = 0) {
        if ($_cmdId === null || $_cmdId === '' || (int) $_cmdId <= 0) {
            return null;
        }
        try {
            $cmd = cmd::byId((int) $_cmdId);
            if (!is_object($cmd)) {
                return null;
            }
            $value = $cmd->execCmd();
            if ($value === '' || $value === null || $value === false) {
                return null;
            }
            if ($cmd->getSubType() == 'binary') {
                $position = ($value == 0) ? 0 : 100;
            } else {
                $position = (int) round((float) $value);
            }
            $position = max(0, min(100, $position));
            return ($_invert == 1) ? (100 - $position) : $position;
        } catch (Throwable $e) {
            /* Une valeur illisible n'est pas une erreur du sélecteur : le volet
             * reste proposé, sans pastille. */
            return null;
        }
    }

    /*
     * Retrouve un volet déjà choisi, pour le réafficher et pour l'exécution.
     *
     * Les commandes sont enregistrées par leur identifiant : un renommage du
     * volet ou de la pièce ne casse rien, et le nom affiché est rafraîchi à
     * chaque ouverture. Une suppression, si — et c'est le seul cas où l'on doit
     * le dire à l'utilisateur plutôt que d'échouer en silence.
     */
    public static function describe($_volet) {
        $describe = array(
            'eq'      => isset($_volet['eq']) ? (int) $_volet['eq'] : 0,
            'name'    => isset($_volet['name']) ? $_volet['name'] : '',
            'object'  => isset($_volet['object']) ? $_volet['object'] : '',
            'missing' => 0,
        );

        $eqLogic = ($describe['eq'] > 0) ? eqLogic::byId($describe['eq']) : null;
        if (!is_object($eqLogic)) {
            $describe['missing'] = 1;
            return $describe;
        }

        $describe['name'] = $eqLogic->getName();
        try {
            $object = $eqLogic->getObject();
            $describe['object'] = is_object($object) ? $object->getName() : '';
        } catch (Throwable $e) {
            $describe['object'] = '';
        }
        $describe['enabled'] = ($eqLogic->getIsEnable() == 1) ? 1 : 0;
        return $describe;
    }
}
