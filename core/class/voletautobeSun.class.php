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
 * Quand un moment tombe, où est le soleil, et si la température le permet.
 *
 * Cette classe ne connaît ni Jeedom, ni base de données, ni commande : elle
 * reçoit un réglage, une position de la maison et une mesure, elle rend un
 * horodatage ou un verdict. C'est délibéré. Toute la subtilité du plugin est
 * là — un décalage sur le coucher du soleil, un garde-fou, un tirage
 * aléatoire, un jour de semaine, un seuil de température, la course du soleil
 * dans le ciel — et c'est la seule partie qu'on peut éprouver hors ligne, sur
 * une année entière, en une seconde (voir tests/run.php). Le reste du plugin
 * ne fait qu'appeler ces fonctions et pousser des ordres aux volets.
 */
class voletautobeSun {

    const MODE_FIXED   = 'fixed';
    const MODE_SUNSET  = 'sunset';
    const MODE_SUNRISE = 'sunrise';
    /* Le moment tombe quand le soleil arrive sur la façade, ou quand il la
     * quitte. Une heure fixe est approximative : le 13 h qui convient en juin
     * laisse le soleil taper une heure de trop en août et ne veut plus rien
     * dire en octobre, alors qu'une façade sud-ouest prend le soleil quand son
     * azimut passe 200°, tous les jours de l'année.
     *
     * La façade visée n'est pas un réglage du moment : c'est l'orientation de
     * la maison, qui appartient au groupe et vaut pour tous ses moments, et
     * l'appelant l'a recopiée dans le moment avant d'appeler. Un azimut propre
     * au moment ferait écrire deux fois la même orientation sans dire laquelle
     * fait foi.
     *
     * Les deux modes lisent les deux bornes d'un même intervalle, celui que
     * facadeWindow() découpe dans la journée : le soleil est sur la façade
     * quand son azimut est dans la fenêtre et qu'il est assez haut pour
     * l'éclairer. Il en part donc aussi bien en tournant qu'en descendant ou en
     * se couchant — viser le seul azimut de fin laissait la fin de protection
     * sans date, et avec la façade livrée par défaut, sans date aucun jour de
     * l'année. */
    const MODE_FACADE_IN  = 'facade_in';
    const MODE_FACADE_OUT = 'facade_out';

    /* Bornes d'un décalage. Douze heures suffisent largement à tout usage réel
     * et empêchent un réglage absurde — « coucher du soleil + 2000 minutes » —
     * de fermer les volets deux jours plus tard sans que personne comprenne. */
    const OFFSET_MAX = 720;

    /* Bornes du tirage aléatoire de simulation de présence. */
    const RANDOM_MAX = 120;

    /* Les trois ordres qu'un moment peut donner. Un volet n'est pas une lampe :
     * entre l'ouvert et le fermé il y a tout le reste, et c'est justement ce
     * « reste » qui sert à la protection solaire. */
    const ACTION_UP       = 'up';
    const ACTION_DOWN     = 'down';
    const ACTION_POSITION = 'position';

    /* Les conditions de température. C'est ce qui distingue ce plugin de son
     * frère des lampes : en hiver un volet fermé isole, et l'ouvrir à 7 h par
     * −3 °C coûte plus de chaleur que trois heures de lumière grise n'en
     * rapportent. */
    const TEMP_NONE = 'none';
    const TEMP_MIN  = 'min';   /* seulement si la température est >= temp_value */
    const TEMP_MAX  = 'max';   /* seulement si la température est <= temp_value */

    /* Bornes d'une consigne de température. Au-delà, c'est une faute de frappe :
     * « 260 » pour « 26,0 » ne doit pas rendre la condition impossible à
     * satisfaire au point que le volet ne bouge plus jamais. */
    const TEMP_MIN_VALUE = -50.0;
    const TEMP_MAX_VALUE = 60.0;

    /*
     * La condition de luminosité, facultative, et bâtie exactement sur le
     * modèle de celle de température.
     *
     * Elle répond au cas que ni la façade ni la sonde ne voient : un jour
     * couvert à 27 °C. Le soleil est sur la façade — par le calcul — et il fait
     * chaud, mais rien ne tape sur la vitre, et fermer aux trois quarts plonge
     * la pièce dans la pénombre pour rien. Seule une mesure de lumière sait
     * qu'il y a des nuages.
     *
     * Le seuil est un nombre nu, dans l'unité de la sonde : lux pour un
     * luxmètre, W/m² pour un pyranomètre, indice pour une sonde UV. La classe
     * ne convertit rien ; c'est l'utilisateur qui règle un seuil dans l'unité
     * qu'il lit sur son capteur.
     */
    const LUX_NONE = 'none';
    const LUX_MIN  = 'min';   /* seulement si la luminosité est >= lux_value */
    const LUX_MAX  = 'max';   /* seulement si la luminosité est <= lux_value */

    /* Bornes d'un seuil de luminosité. Le plafond couvre le plein soleil d'été
     * en lux — un peu plus de 100 000 — avec de la marge ; au-delà, c'est une
     * faute de frappe. */
    const LUX_MIN_VALUE = 0.0;
    const LUX_MAX_VALUE = 200000.0;

    /* La condition de soleil : ne jouer le moment que si le soleil est bien
     * sur cette façade-là. C'est le pendant de la condition de température, et
     * elle répond à la même question posée autrement — une protection solaire
     * n'a de sens que si le soleil tape sur la fenêtre. */
    const SUN_NONE   = 'none';
    const SUN_WINDOW = 'window';

    /* Bornes d'un azimut : le tour complet, depuis le nord. */
    const AZIMUTH_MIN_VALUE = 0.0;
    const AZIMUTH_MAX_VALUE = 360.0;

    /* Bornes d'une hauteur minimale. Le plancher descend sous zéro pour qui
     * veut une fenêtre qui commence au ras de l'horizon, et le plafond est le
     * zénith : au-delà, la condition ne serait jamais remplie nulle part. */
    const ELEVATION_MIN_VALUE = -10.0;
    const ELEVATION_MAX_VALUE = 90.0;

    /*
     * Les seize secteurs de la rose des vents, de 22,5° chacun, à partir du
     * nord. Un azimut de 200° ne dit rien à personne ; « sud-sud-ouest » se
     * vérifie depuis sa fenêtre, et c'est ainsi que l'utilisateur pense sa
     * maison.
     *
     * Les noms ne passent pas par __() : cette classe ne connaît pas Jeedom.
     * La traduction se fait chez l'appelant, comme pour voletautobe::$_days.
     */
    public static $_compass = array(
        'nord', 'nord-nord-est', 'nord-est', 'est-nord-est',
        'est', 'est-sud-est', 'sud-est', 'sud-sud-est',
        'sud', 'sud-sud-ouest', 'sud-ouest', 'ouest-sud-ouest',
        'ouest', 'ouest-nord-ouest', 'nord-ouest', 'nord-nord-ouest',
    );

    /* Un réglage vide, tel qu'un équipement neuf le reçoit. */
    public static function emptySlot($_action = self::ACTION_UP) {
        $action = in_array($_action, array(self::ACTION_UP, self::ACTION_DOWN, self::ACTION_POSITION)) ? $_action : self::ACTION_UP;
        return array(
            'enable'     => 0,
            'action'     => $action,
            /* 30 % : presque fermé, mais pas tout à fait. C'est le réglage de
             * protection solaire que l'on veut par défaut — couper le soleil
             * sans plonger la pièce dans le noir. Il ne sert que si l'action
             * est « position ». */
            'position'   => 30,
            'mode'       => self::MODE_FIXED,
            'time'       => ($action == self::ACTION_UP) ? '07:00' : '21:00',
            'offset'     => 0,
            'random'     => 0,
            'not_before' => '',
            'not_after'  => '',
            'days'       => array(1, 2, 3, 4, 5, 6, 7),
            'temp_mode'  => self::TEMP_NONE,
            'temp_value' => 0.0,
            'lux_mode'   => self::LUX_NONE,
            'lux_value'  => 0.0,
            'sun_mode'   => self::SUN_NONE,
            /* La façade du groupe, recopiée ici par l'appelant : la classe ne
             * connaît pas Jeedom et doit recevoir l'orientation avec le
             * moment. Sud-est à nord-ouest, la moitié du ciel où le soleil
             * chauffe vraiment, et 15° de hauteur : sous cette hauteur le
             * soleil rase, il passe derrière les maisons d'en face et ne
             * justifie plus de fermer quoi que ce soit.
             *
             * Ces trois valeurs servent deux fois : elles visent l'azimut des
             * modes de façade, et elles bornent la condition de soleil. */
            'sun_from'      => 135.0,
            'sun_to'        => 315.0,
            'sun_elevation' => 15.0,
        );
    }

    /*
     * Impose sa forme à un réglage venu du formulaire.
     *
     * Tout ce qui sort d'une page web est une chaîne, y compris « 0 » et « »,
     * et une comparaison faite plus tard sur une case à cocher absente serait
     * fausse sans bruit : le moment ne partirait jamais et rien ne le dirait.
     * On normalise donc une fois pour toutes, à l'enregistrement.
     */
    public static function cleanSlot($_slot, $_action = self::ACTION_UP) {
        $clean = self::emptySlot($_action);
        if (!is_array($_slot)) {
            return $clean;
        }

        $clean['enable'] = (isset($_slot['enable']) && ($_slot['enable'] == 1 || $_slot['enable'] === true || $_slot['enable'] === 'on')) ? 1 : 0;
        if (isset($_slot['action']) && in_array($_slot['action'], array(self::ACTION_UP, self::ACTION_DOWN, self::ACTION_POSITION))) {
            $clean['action'] = $_slot['action'];
        }
        if (isset($_slot['position'])) {
            /* Une consigne hors bornes n'est pas une erreur à signaler mais un
             * curseur mal relu : on la ramène. Envoyer « 140 % » à un volet,
             * c'est selon le protocole ne rien faire du tout ou tout ouvrir,
             * deux résultats que l'utilisateur n'a pas demandés. */
            $clean['position'] = max(0, min(100, (int) $_slot['position']));
        }
        if (isset($_slot['mode']) && in_array($_slot['mode'], array(self::MODE_FIXED, self::MODE_SUNSET, self::MODE_SUNRISE, self::MODE_FACADE_IN, self::MODE_FACADE_OUT))) {
            $clean['mode'] = $_slot['mode'];
        }
        $time = self::cleanTime(isset($_slot['time']) ? $_slot['time'] : '');
        if ($time !== '') {
            $clean['time'] = $time;
        }
        if (isset($_slot['offset'])) {
            $clean['offset'] = max(-self::OFFSET_MAX, min(self::OFFSET_MAX, (int) $_slot['offset']));
        }
        if (isset($_slot['random'])) {
            $clean['random'] = max(0, min(self::RANDOM_MAX, (int) $_slot['random']));
        }
        $clean['not_before'] = self::cleanTime(isset($_slot['not_before']) ? $_slot['not_before'] : '');
        $clean['not_after']  = self::cleanTime(isset($_slot['not_after']) ? $_slot['not_after'] : '');

        if (isset($_slot['days']) && is_array($_slot['days'])) {
            $days = array();
            foreach ($_slot['days'] as $day) {
                $day = (int) $day;
                if ($day >= 1 && $day <= 7 && !in_array($day, $days)) {
                    $days[] = $day;
                }
            }
            sort($days);
            /*
             * Aucun jour coché veut dire « jamais », pas « tous les jours » :
             * l'inverse ferait fermer les volets sept soirs par semaine à
             * quelqu'un qui vient de tout décocher pour suspendre le moment.
             */
            $clean['days'] = $days;
        }

        if (isset($_slot['temp_mode']) && in_array($_slot['temp_mode'], array(self::TEMP_NONE, self::TEMP_MIN, self::TEMP_MAX))) {
            $clean['temp_mode'] = $_slot['temp_mode'];
        }
        if (isset($_slot['temp_value'])) {
            $clean['temp_value'] = self::cleanTemperature($_slot['temp_value']);
        }

        if (isset($_slot['lux_mode']) && in_array($_slot['lux_mode'], array(self::LUX_NONE, self::LUX_MIN, self::LUX_MAX))) {
            $clean['lux_mode'] = $_slot['lux_mode'];
        }
        if (isset($_slot['lux_value'])) {
            $clean['lux_value'] = self::cleanLux($_slot['lux_value']);
        }

        if (isset($_slot['sun_mode']) && in_array($_slot['sun_mode'], array(self::SUN_NONE, self::SUN_WINDOW))) {
            $clean['sun_mode'] = $_slot['sun_mode'];
        }
        if (isset($_slot['sun_from'])) {
            $clean['sun_from'] = self::cleanAzimuth($_slot['sun_from']);
        }
        if (isset($_slot['sun_to'])) {
            $clean['sun_to'] = self::cleanAzimuth($_slot['sun_to']);
        }
        if (isset($_slot['sun_elevation'])) {
            $clean['sun_elevation'] = self::cleanElevation($_slot['sun_elevation']);
        }
        return $clean;
    }

    /*
     * Une consigne de température, en nombre à virgule.
     *
     * Le demi-degré compte : une consigne de gel se règle à 0,5 °C et non à
     * 1 °C. Et l'utilisateur est francophone — il tape « 5,5 », que (float)
     * seul couperait à 5, silencieusement.
     */
    public static function cleanTemperature($_value) {
        return max(self::TEMP_MIN_VALUE, min(self::TEMP_MAX_VALUE, self::toFloat($_value)));
    }

    /* Un seuil de luminosité. « 20 000 » s'écrit avec une espace chez les
     * francophones, et (float) le couperait à 20 : la condition serait remplie
     * par une nuit de pleine lune. Les espaces, fines comprises, sont donc
     * retirées avant la conversion. */
    public static function cleanLux($_value) {
        if (is_string($_value)) {
            $_value = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $_value);
        }
        return max(self::LUX_MIN_VALUE, min(self::LUX_MAX_VALUE, self::toFloat($_value)));
    }

    /* Un azimut, ramené sur le tour de cadran. Une saisie hors bornes est une
     * faute de frappe — « 1800 » pour « 180 » — et non une orientation : la
     * ramener vaut mieux que de rendre la fenêtre de soleil impossible à
     * satisfaire au point que le moment ne se joue plus jamais. */
    public static function cleanAzimuth($_value) {
        return max(self::AZIMUTH_MIN_VALUE, min(self::AZIMUTH_MAX_VALUE, self::toFloat($_value)));
    }

    /* Une hauteur minimale de soleil, en degrés au-dessus de l'horizon. */
    public static function cleanElevation($_value) {
        return max(self::ELEVATION_MIN_VALUE, min(self::ELEVATION_MAX_VALUE, self::toFloat($_value)));
    }

    /* La virgule décimale des francophones, que (float) couperait en silence :
     * « 22,5 » deviendrait 22 et l'azimut serait faux d'un demi-degré sans que
     * rien ne le signale. */
    public static function toFloat($_value) {
        if (is_string($_value)) {
            $_value = str_replace(',', '.', trim($_value));
        }
        return (float) $_value;
    }

    /* « 7:5 », « 07h05 », « 0705 » — tout ce qu'un humain tape pour une heure,
     * ramené à HH:MM, ou vide si ce n'en est pas une. */
    public static function cleanTime($_value) {
        $value = trim((string) $_value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('/^(\d{1,2})[:hH.]?(\d{2})$/', $value, $matches)) {
            return '';
        }
        $hours   = (int) $matches[1];
        $minutes = (int) $matches[2];
        if ($hours > 23 || $minutes > 59) {
            return '';
        }
        return sprintf('%02d:%02d', $hours, $minutes);
    }

    /*
     * La condition de température d'un moment, évaluée sur une mesure.
     *
     * Rend array('met' => bool, 'known' => bool, 'reason' => string) où reason
     * vaut 'none', 'unknown', 'ok', 'too_cold' ou 'too_warm'.
     *
     * Le cas d'une mesure absente est la décision la plus importante du
     * plugin : on bouge quand même. Une sonde débranchée, une pile morte, un
     * plugin tiers qui ne répond plus, et la condition devient « on ne sait
     * pas » — si « on ne sait pas » empêchait le mouvement, la maison
     * resterait volets fermés des semaines durant sans que rien ne l'explique.
     * La condition est un raffinement ; le mouvement est le comportement
     * normal. En cas de doute on bouge, et on l'écrit dans le journal pour que
     * la sonde morte finisse par se voir.
     */
    public static function temperatureCheck($_slot, $_temperature) {
        $slot = self::cleanSlot($_slot);
        if ($slot['temp_mode'] == self::TEMP_NONE) {
            return array('met' => true, 'known' => true, 'reason' => 'none');
        }
        if ($_temperature === null) {
            return array('met' => true, 'known' => false, 'reason' => 'unknown');
        }

        $temperature = (float) $_temperature;
        if ($slot['temp_mode'] == self::TEMP_MIN) {
            $met = ($temperature >= $slot['temp_value']);
            return array('met' => $met, 'known' => true, 'reason' => $met ? 'ok' : 'too_cold');
        }
        $met = ($temperature <= $slot['temp_value']);
        return array('met' => $met, 'known' => true, 'reason' => $met ? 'ok' : 'too_warm');
    }

    /*
     * La condition de luminosité d'un moment, évaluée sur une mesure.
     *
     * Rend array('met' => bool, 'known' => bool, 'reason' => string) où reason
     * vaut 'none', 'unknown', 'ok', 'too_dark' ou 'too_bright'.
     *
     * Même règle que pour la température, et pour la même raison : une mesure
     * absente laisse passer l'ordre. Un luxmètre dont la pile est morte ne doit
     * pas supprimer la protection solaire pour tout l'été sans que rien ne le
     * dise.
     */
    public static function luxCheck($_slot, $_lux) {
        $slot = self::cleanSlot($_slot);
        if ($slot['lux_mode'] == self::LUX_NONE) {
            return array('met' => true, 'known' => true, 'reason' => 'none');
        }
        if ($_lux === null) {
            return array('met' => true, 'known' => false, 'reason' => 'unknown');
        }

        $lux = (float) $_lux;
        if ($slot['lux_mode'] == self::LUX_MIN) {
            $met = ($lux >= $slot['lux_value']);
            return array('met' => $met, 'known' => true, 'reason' => $met ? 'ok' : 'too_dark');
        }
        $met = ($lux <= $slot['lux_value']);
        return array('met' => $met, 'known' => true, 'reason' => $met ? 'ok' : 'too_bright');
    }

    /*
     * La condition de soleil d'un moment, évaluée sur une position du soleil.
     *
     * Rend array('met' => bool, 'known' => bool, 'reason' => string) où reason
     * vaut 'none', 'unknown', 'ok', 'azimuth_out' ou 'too_low'.
     *
     * Une position inconnue se traite exactement comme une sonde muette, et
     * pour la même raison : on bouge quand même. Le cas se produit quand la
     * position de l'installation n'est pas renseignée — sans latitude ni
     * longitude, le calcul porterait sur le golfe de Guinée. Si « on ne sait
     * pas » empêchait le mouvement, la protection solaire ne se jouerait
     * jamais et rien, dans l'interface, ne dirait pourquoi. La condition est
     * un raffinement ; le mouvement est le comportement normal.
     *
     * Avec un déclencheur de façade, il n'y a plus rien à filtrer du tout : la
     * raison est écrite à l'endroit du test.
     */
    public static function sunCheck($_slot, $_azimuth, $_elevation) {
        $slot = self::cleanSlot($_slot);
        if ($slot['sun_mode'] == self::SUN_NONE) {
            return array('met' => true, 'known' => true, 'reason' => 'none');
        }
        /*
         * Quand c'est la façade elle-même qui déclenche le moment, la condition
         * de soleil n'a plus rien à filtrer : elle est vraie par construction,
         * et c'est ici que disparaît la dernière trace du doublon.
         *
         * Le déclencheur ne vise plus un azimut mais l'intervalle de la journée
         * où le soleil est sur la façade — azimut dans la fenêtre *et* hauteur
         * au-dessus du seuil. Les deux moitiés de la condition sont donc déjà
         * garanties à la seconde où le moment tombe : les retester, c'est au
         * mieux répondre « oui » à une question déjà tranchée, au pire jouer le
         * moment à pile ou face. Car la dichotomie s'arrête à la seconde près,
         * et le soleil se retrouve aussi bien un millième de degré en deçà de
         * la limite qu'au-delà : un « soleil hors de la fenêtre », ou un
         * « soleil trop bas », un jour sur deux, sur un réglage parfaitement
         * juste, sans rien dans l'interface pour l'expliquer. La fin de
         * protection, elle, serait sautée tous les jours — au moment où le
         * soleil quitte la façade, il n'y est par définition plus, ni par
         * l'azimut, ni par la hauteur, ni parce qu'il se couche.
         *
         * La position n'est même pas regardée : il n'y a pas de « on ne sait
         * pas » à noter quand il n'y a rien à savoir.
         */
        if (self::isFacadeMode($slot['mode'])) {
            return array('met' => true, 'known' => true, 'reason' => 'none');
        }
        if ($_azimuth === null || $_elevation === null) {
            return array('met' => true, 'known' => false, 'reason' => 'unknown');
        }

        /*
         * La hauteur se teste avant l'azimut. Un soleil sous l'horizon a un
         * azimut parfaitement défini et parfaitement hors sujet : annoncer
         * « soleil hors de la fenêtre » à minuit, alors que la vraie raison
         * est qu'il fait nuit, envoie l'utilisateur corriger une fenêtre qui
         * n'a rien de faux. « Trop bas » est la raison utile.
         */
        if ((float) $_elevation < $slot['sun_elevation']) {
            return array('met' => false, 'known' => true, 'reason' => 'too_low');
        }
        if (!self::azimuthInWindow($_azimuth, $slot['sun_from'], $slot['sun_to'])) {
            return array('met' => false, 'known' => true, 'reason' => 'azimuth_out');
        }
        return array('met' => true, 'known' => true, 'reason' => 'ok');
    }

    /* Le moment est-il déclenché par la façade du groupe ? Les deux modes se
     * traitent ensemble partout — ils ne diffèrent que par l'azimut visé — et
     * l'appelant a lui aussi à les distinguer des heures et du soleil. */
    public static function isFacadeMode($_mode) {
        return ($_mode == self::MODE_FACADE_IN || $_mode == self::MODE_FACADE_OUT);
    }

    /*
     * Un azimut est-il dans la fenêtre d'une façade ?
     *
     * La fenêtre passe par le nord quand le début est après la fin. « De 300°
     * à 30° » est une façade nord-ouest–nord-est, et un simple
     * « from <= a && a <= to » la rendrait toujours vide : la condition ne
     * serait jamais remplie, le moment ne partirait plus, et le réglage aurait
     * pourtant l'air juste dans l'interface.
     *
     * Un début égal à la fin est une fenêtre vide, donc jamais remplie, et non
     * « tout le tour » : quelqu'un qui tape deux fois le même azimut a fait
     * une erreur de saisie, et fermer les volets à toute heure du jour serait
     * la pire façon de la lui apprendre.
     */
    public static function azimuthInWindow($_azimuth, $_from, $_to) {
        $azimuth = self::normalizeAngle($_azimuth);
        $from    = self::cleanAzimuth($_from);
        $to      = self::cleanAzimuth($_to);
        if ($from == $to) {
            return false;
        }
        if ($from < $to) {
            return ($azimuth >= $from && $azimuth <= $to);
        }
        return ($azimuth >= $from || $azimuth <= $to);
    }

    /* « sud », « sud-sud-ouest », « ouest-nord-ouest »… Le nom du secteur de
     * 22,5° où tombe un azimut : c'est ce que l'utilisateur peut vérifier
     * depuis sa fenêtre, alors que « 200° » ne se vérifie pas. */
    public static function compassName($_azimuth) {
        $azimuth = self::normalizeAngle($_azimuth);
        return self::$_compass[((int) round($azimuth / 22.5)) % 16];
    }

    /* Un angle ramené dans 0..360. */
    public static function normalizeAngle($_angle) {
        $angle = fmod(self::toFloat($_angle), 360.0);
        return ($angle < 0) ? $angle + 360.0 : $angle;
    }

    /*
     * L'écart entre deux azimuts, ramené dans −180..180.
     *
     * Le soleil passe de 359° à 1° sans reculer de 358° : c'est la seule
     * arithmétique correcte sur un cadran, et l'oublier ferait croire au
     * balayage qu'il a franchi tous les azimuts d'un coup.
     */
    public static function angleDifference($_from, $_to) {
        $delta = fmod(self::toFloat($_to) - self::toFloat($_from), 360.0);
        if ($delta > 180.0) {
            $delta -= 360.0;
        }
        if ($delta < -180.0) {
            $delta += 360.0;
        }
        return $delta;
    }

    /*
     * Lever et coucher du soleil pour le jour d'un horodatage.
     *
     * date_sun_info() est dans PHP depuis la version 5.1 : aucune dépendance à
     * installer, aucun service à interroger, et le résultat est le même que
     * celui qu'affiche le coeur de Jeedom dans ses scénarios, qui appelle la
     * même fonction avec la même position.
     *
     * Elle rend true ou false, et non un horodatage, quand le soleil ne se lève
     * ou ne se couche pas du jour — cercle polaire. Les deux valeurs sont
     * rendues telles quelles à l'appelant sous forme de null : un moment qui
     * n'existe pas ne doit pas se replier sur minuit.
     */
    public static function sun($_timestamp, $_latitude, $_longitude) {
        /* Midi et non l'heure reçue : à 23 h 30, le « coucher du soleil » que
         * date_sun_info rend pour l'instant présent est celui du jour en cours,
         * mais un appel fait à 00 h 10 porterait déjà sur le lendemain. Partir
         * de midi rend le résultat indépendant de l'heure d'appel. */
        $noon = mktime(12, 0, 0, (int) date('n', $_timestamp), (int) date('j', $_timestamp), (int) date('Y', $_timestamp));
        $info = @date_sun_info($noon, (float) $_latitude, (float) $_longitude);

        $result = array('sunrise' => null, 'sunset' => null);
        if (!is_array($info)) {
            return $result;
        }
        foreach (array('sunrise', 'sunset') as $key) {
            if (isset($info[$key]) && !is_bool($info[$key])) {
                $result[$key] = (int) $info[$key];
            }
        }
        return $result;
    }

    /*
     * Où est le soleil à un instant donné.
     *
     * Rend array('azimuth' => float, 'elevation' => float), ou deux null si le
     * calcul n'aboutit pas. L'azimut compte de 0 à 360 depuis le nord, dans le
     * sens des aiguilles d'une montre — 0 nord, 90 est, 180 sud, 270 ouest :
     * c'est la convention de tout ce qui parle d'orientation de façade, et la
     * seule que l'utilisateur puisse vérifier avec une boussole. L'élévation
     * est la hauteur au-dessus de l'horizon, négative la nuit.
     *
     * L'algorithme est celui du NOAA Solar Calculator, en PHP pur : la classe
     * ne connaît ni Jeedom, ni réseau, ni bibliothèque d'éphémérides, et le
     * jeu d'essai peut donc la confronter hors ligne aux valeurs publiées.
     *
     * Deux précautions qui ne lèvent aucune erreur quand on les oublie :
     *
     * - tout se calcule en UTC, jamais en heure locale. La box est réglée sur
     *   Europe/Brussels ; un calcul fait en heure murale se décalerait d'une
     *   heure du dernier dimanche de mars au dernier d'octobre, la protection
     *   solaire partirait une heure trop tard tout l'été, et personne ne
     *   ferait le lien avec le changement d'heure ;
     * - la réfraction atmosphérique est corrigée, sans quoi la hauteur rendue
     *   ne serait pas celle des éphémérides ni celle que l'on voit : au ras de
     *   l'horizon l'écart vaut plus d'un demi-degré, c'est-à-dire tout le
     *   diamètre du soleil.
     */
    public static function sunPosition($_timestamp, $_latitude, $_longitude) {
        $unknown = array('azimuth' => null, 'elevation' => null);
        /* La chaîne vide compte comme une position absente : une configuration
         * Jeedom non renseignée arrive ainsi, et (float) '' vaut zéro — c'est
         * le golfe de Guinée, une position parfaitement valide qui rendrait un
         * azimut faux au lieu de dire qu'on ne sait pas, et la fenêtre de
         * soleil filtrerait alors sur un soleil imaginaire. */
        if ($_timestamp === null || $_latitude === null || $_longitude === null
            || $_latitude === '' || $_longitude === '') {
            return $unknown;
        }
        $timestamp = (int) $_timestamp;
        $latitude  = (float) $_latitude;
        $longitude = (float) $_longitude;
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return $unknown;
        }

        $jd = $timestamp / 86400.0 + 2440587.5;
        $t  = ($jd - 2451545.0) / 36525.0;

        $l0 = self::normalizeAngle(280.46646 + $t * (36000.76983 + $t * 0.0003032));
        $m  = 357.52911 + $t * (35999.05029 - 0.0001537 * $t);
        $e  = 0.016708634 - $t * (0.000042037 + 0.0000001267 * $t);

        $centre = sin(deg2rad($m)) * (1.914602 - $t * (0.004817 + 0.000014 * $t))
                + sin(deg2rad(2 * $m)) * (0.019993 - 0.000101 * $t)
                + sin(deg2rad(3 * $m)) * 0.000289;

        /* Longitude apparente : la nutation et l'aberration valent moins d'un
         * centième de degré, mais elles ne coûtent rien et gardent le calcul
         * comparable aux éphémérides publiées. */
        $lambda = $l0 + $centre - 0.00569 - 0.00478 * sin(deg2rad(125.04 - 1934.136 * $t));

        $eps0 = 23 + (26 + (21.448 - $t * (46.815 + $t * (0.00059 - $t * 0.001813))) / 60) / 60;
        $eps  = $eps0 + 0.00256 * cos(deg2rad(125.04 - 1934.136 * $t));

        $declination = asin(sin(deg2rad($eps)) * sin(deg2rad($lambda)));

        /* L'équation du temps, en minutes : l'écart entre le soleil vrai et le
         * soleil moyen, jusqu'à un quart d'heure en novembre. L'ignorer
         * décalerait le midi solaire d'autant, et l'azimut de près de 4°. */
        $y = pow(tan(deg2rad($eps / 2)), 2);
        $equationOfTime = 4 * rad2deg(
            $y * sin(2 * deg2rad($l0))
            - 2 * $e * sin(deg2rad($m))
            + 4 * $e * $y * sin(deg2rad($m)) * cos(2 * deg2rad($l0))
            - 0.5 * $y * $y * sin(4 * deg2rad($l0))
            - 1.25 * $e * $e * sin(2 * deg2rad($m))
        );

        /* gmdate() et non date() : les minutes écoulées dans la journée se
         * comptent en UTC, quel que soit le fuseau de la box. */
        $minutes = (int) gmdate('H', $timestamp) * 60 + (int) gmdate('i', $timestamp)
                 + (int) gmdate('s', $timestamp) / 60.0;

        $trueSolarTime = $minutes + $equationOfTime + 4 * $longitude;
        $hourAngle     = $trueSolarTime / 4 - 180;

        $latitudeR = deg2rad($latitude);
        $cosZenith = sin($latitudeR) * sin($declination)
                   + cos($latitudeR) * cos($declination) * cos(deg2rad($hourAngle));
        /* Les arrondis flottants peuvent sortir d'un cheveu de −1..1, et
         * acos() rendrait alors NAN — un NAN qui se propagerait jusqu'à la
         * tuile sans jamais lever d'erreur. */
        $cosZenith = max(-1.0, min(1.0, $cosZenith));
        $zenith    = acos($cosZenith);

        $denominator = cos($latitudeR) * sin($zenith);
        if (abs($denominator) < 1e-9) {
            /* Au pôle exact, ou soleil au zénith exact : l'azimut n'existe
             * pas. Une position sans azimut n'oriente aucune façade, on la
             * rend inconnue entière plutôt que moitié juste. */
            return $unknown;
        }
        $azimuth = rad2deg(acos(max(-1.0, min(1.0, (sin($latitudeR) * $cosZenith - sin($declination)) / $denominator))));
        /* acos() ne rend que 0..180 : l'angle horaire dit de quel côté du
         * méridien on se trouve, et c'est lui qui distingue le matin du soir.
         * Sans ce repliement, tous les après-midi seraient rendus comme des
         * matins et une façade ouest ne serait jamais protégée. */
        $azimuth = ($hourAngle > 0) ? self::normalizeAngle($azimuth + 180) : self::normalizeAngle(540 - $azimuth);

        $elevation = 90 - rad2deg($zenith);
        return array('azimuth' => $azimuth, 'elevation' => $elevation + self::refraction($elevation));
    }

    /*
     * La correction de réfraction atmosphérique, en degrés, pour une hauteur
     * géométrique donnée. Formule du NOAA, qui rend des secondes d'arc.
     *
     * L'atmosphère relève le soleil : au ras de l'horizon il paraît 0,57° plus
     * haut qu'il n'est, soit plus que son propre diamètre. C'est ce qui fait
     * que le soleil « se couche » alors qu'il est déjà géométriquement sous
     * l'horizon, et c'est pourquoi la hauteur rendue au lever calculé par
     * date_sun_info() doit valoir zéro et non −0,6°.
     */
    public static function refraction($_elevation) {
        $elevation = (float) $_elevation;
        if ($elevation > 85) {
            return 0.0;
        }
        $tangent = tan(deg2rad($elevation));
        if ($elevation > 5) {
            $seconds = 58.1 / $tangent - 0.07 / pow($tangent, 3) + 0.000086 / pow($tangent, 5);
        } elseif ($elevation > -0.575) {
            $seconds = 1735 + $elevation * (-518.2 + $elevation * (103.4 + $elevation * (-12.79 + $elevation * 0.711)));
        } else {
            $seconds = -20.772 / $tangent;
        }
        return $seconds / 3600.0;
    }

    /*
     * L'instant du jour où le soleil atteint un azimut, ou null s'il ne
     * l'atteint jamais au-dessus de l'horizon ce jour-là.
     *
     * Ce n'est plus ce que visent les modes de façade — le soleil quitte une
     * façade aussi bien en tournant qu'en descendant, et c'est facadeWindow()
     * qui en tient compte — mais la question « à quelle heure le soleil
     * passe-t-il au 200° ? » garde un sens à elle seule, et la réponse se lit
     * dans l'aperçu.
     *
     * On ne cherche qu'entre le lever et le coucher : un azimut franchi sous
     * l'horizon n'éclaire aucune façade, et le retenir ferait fermer les
     * volets en pleine nuit. Le cas « jamais » est parfaitement normal — un
     * azimut de 90° n'est pas atteint à Bruxelles en décembre, le soleil s'y
     * lève déjà au 129° — et il doit rendre null franchement, surtout pas se
     * replier sur minuit ou sur le lever, ce qui jouerait le moment tous les
     * jours de l'hiver à contretemps.
     *
     * La méthode est un balayage de dix minutes pour trouver l'intervalle où
     * l'azimut franchit la consigne, puis une dichotomie pour descendre à la
     * seconde. Il n'existe pas de formule directe, et aucune n'est nécessaire :
     * vingt itérations sur un calcul de cette taille ne se mesurent pas.
     */
    public static function azimuthTime($_azimuth, $_dayTimestamp, $_latitude, $_longitude) {
        $target = self::cleanAzimuth($_azimuth);
        $sun    = self::sun($_dayTimestamp, $_latitude, $_longitude);
        if ($sun['sunrise'] === null || $sun['sunset'] === null || $sun['sunset'] <= $sun['sunrise']) {
            return null;
        }

        $step        = 600;
        $start       = null;
        $end         = null;
        $startDelta  = 0;
        $from        = $sun['sunrise'];
        $azimuthFrom = self::sunPosition($from, $_latitude, $_longitude);
        while ($from < $sun['sunset'] && $azimuthFrom['azimuth'] !== null) {
            $to        = min($from + $step, $sun['sunset']);
            $azimuthTo = self::sunPosition($to, $_latitude, $_longitude);
            if ($azimuthTo['azimuth'] === null) {
                return null;
            }
            /* Course parcourue sur l'intervalle, et distance qui sépare son
             * début de la consigne : les deux ramenées dans −180..180, sans
             * quoi un intervalle qui enjambe le 360° paraîtrait couvrir tout
             * le tour du cadran et happerait n'importe quelle consigne. */
            $travel = self::angleDifference($azimuthFrom['azimuth'], $azimuthTo['azimuth']);
            $offset = self::angleDifference($azimuthFrom['azimuth'], $target);
            if (($travel > 0 && $offset >= 0 && $offset <= $travel)
                || ($travel < 0 && $offset <= 0 && $offset >= $travel)) {
                $start      = $from;
                $end        = $to;
                $startDelta = $offset;
                break;
            }
            $from        = $to;
            $azimuthFrom = $azimuthTo;
        }
        if ($start === null) {
            return null;
        }
        /* L'écart au début de l'intervalle donne le signe de référence : on
         * garde à chaque pas la moitié où il change, c'est-à-dire celle qui
         * contient encore l'instant cherché. */
        if ($startDelta == 0) {
            return $start;
        }
        for ($iteration = 0; $iteration < 20 && ($end - $start) > 1; $iteration++) {
            $middle   = (int) floor(($start + $end) / 2);
            $position = self::sunPosition($middle, $_latitude, $_longitude);
            if ($position['azimuth'] === null) {
                break;
            }
            $delta = self::angleDifference($position['azimuth'], $target);
            if ($delta == 0) {
                return $middle;
            }
            if (($delta > 0) === ($startDelta > 0)) {
                $start      = $middle;
                $startDelta = $delta;
            } else {
                $end = $middle;
            }
        }
        return $end;
    }

    /*
     * Le soleil est-il sur la façade à cet instant précis ?
     *
     * Les deux moitiés comptent, et l'oubli de la seconde est le défaut que
     * cette version corrige : le soleil est sur la façade quand son azimut est
     * dans la fenêtre *et* qu'il est assez haut pour l'éclairer. Un soleil au
     * 250° mais à 3° de hauteur passe derrière les maisons d'en face ; il n'est
     * pas plus « sur la façade » qu'un soleil au nord.
     *
     * Une position que le calcul ne sait pas rendre — le pôle exact, le zénith
     * exact — compte comme « pas sur la façade » : une façade ne s'oriente pas
     * sans azimut, et le cas ne se produit à aucune latitude habitée.
     */
    public static function onFacade($_timestamp, $_slot, $_latitude, $_longitude) {
        $slot     = self::cleanSlot($_slot);
        $position = self::sunPosition($_timestamp, $_latitude, $_longitude);
        if ($position['azimuth'] === null || $position['elevation'] === null) {
            return false;
        }
        return (self::azimuthInWindow($position['azimuth'], $slot['sun_from'], $slot['sun_to'])
                && $position['elevation'] >= $slot['sun_elevation']);
    }

    /*
     * L'intervalle de la journée pendant lequel le soleil est sur la façade.
     *
     * Rend array('in' => ..., 'out' => ...), deux horodatages, ou deux null si
     * le soleil n'y est à aucun moment du jour. C'est de là que viennent les
     * deux modes de façade : l'arrivée lit « in », le départ lit « out ».
     *
     * Le modèle est celui du soleil qui tourne *et* qui monte, et c'est tout
     * l'objet de cette méthode. Viser l'azimut de fin, comme on le faisait
     * d'abord, laisse la fin de protection sans date la moitié de l'année —
     * pire, à 50,5° de latitude le soleil ne se couche jamais au-delà du 310°,
     * si bien que la façade livrée par défaut, qui va jusqu'au 315°, n'était
     * quittée *aucun jour de l'année* et ne rouvrait jamais les volets. Le
     * soleil quitte une façade de trois façons : il en sort par le côté, il
     * descend sous la hauteur qui l'éclaire encore, ou il se couche. La
     * première des trois qui survient est le départ.
     *
     * La méthode est celle d'azimuthTime(), appliquée au prédicat entier :
     * balayage de dix minutes du lever au coucher pour situer les deux
     * changements d'état, puis dichotomie à la seconde. Dix minutes suffisent
     * parce que le soleil ne traverse pas une façade en dix minutes — la plus
     * courte des fenêtres réelles, celle d'un solstice d'hiver contre une
     * hauteur minimale, dure encore une heure et demie.
     *
     * Le premier intervalle commence au lever : un soleil déjà dans la fenêtre
     * en se levant y arrive à l'heure du lever, et non dix minutes plus tard.
     * Et si le soleil est encore sur la façade au coucher, c'est le coucher qui
     * l'en fait partir.
     */
    public static function facadeWindow($_slot, $_dayTimestamp, $_latitude, $_longitude) {
        $slot  = self::cleanSlot($_slot);
        $empty = array('in' => null, 'out' => null);
        $sun   = self::sun($_dayTimestamp, $_latitude, $_longitude);
        if ($sun['sunrise'] === null || $sun['sunset'] === null || $sun['sunset'] <= $sun['sunrise']) {
            return $empty;
        }
        $step = 600;

        /* L'arrivée. On avance jusqu'au premier instant balayé où le soleil est
         * sur la façade, en gardant le précédent, qui n'y était pas : le
         * changement d'état est entre les deux. */
        $before = null;
        $inside = null;
        $time   = $sun['sunrise'];
        while (true) {
            if (self::onFacade($time, $slot, $_latitude, $_longitude)) {
                $inside = $time;
                break;
            }
            $before = $time;
            if ($time >= $sun['sunset']) {
                break;
            }
            $time = min($time + $step, $sun['sunset']);
        }
        if ($inside === null) {
            /* Le soleil n'est sur cette façade à aucun moment du jour. Le cas
             * est parfaitement normal — une façade est en décembre, une hauteur
             * minimale que le soleil n'atteint pas — et il doit rendre null
             * franchement, surtout pas se replier sur minuit ou sur le lever,
             * ce qui jouerait le moment tous les jours de l'hiver à
             * contretemps. */
            return $empty;
        }
        $in = ($before === null)
            ? $inside
            : self::facadeTransition($before, $inside, $slot, $_latitude, $_longitude);

        /* Le départ, cherché à partir de l'arrivée : c'est le premier instant
         * où le soleil n'est plus sur la façade, quelle que soit celle des
         * trois raisons qui l'emporte. */
        $last  = $inside;
        $after = null;
        $time  = $inside;
        while ($time < $sun['sunset']) {
            $time = min($time + $step, $sun['sunset']);
            if (!self::onFacade($time, $slot, $_latitude, $_longitude)) {
                $after = $time;
                break;
            }
            $last = $time;
        }
        $out = ($after === null)
            ? $sun['sunset']
            : self::facadeTransition($last, $after, $slot, $_latitude, $_longitude);

        return array('in' => $in, 'out' => $out);
    }

    /*
     * L'instant, à la seconde, où le soleil change d'état entre deux instants
     * qui l'encadrent : l'un le voit sur la façade, l'autre non.
     *
     * Rend le premier instant qui porte l'état de $_to — celui de l'arrivée
     * quand on encadre une arrivée, celui du départ quand on encadre un
     * départ. Dix itérations suffisent à descendre de dix minutes à la seconde,
     * et il n'existe pas de formule directe : la hauteur et l'azimut se
     * croisent selon la latitude et le jour.
     */
    public static function facadeTransition($_from, $_to, $_slot, $_latitude, $_longitude) {
        $from   = (int) $_from;
        $to     = (int) $_to;
        $target = self::onFacade($to, $_slot, $_latitude, $_longitude);
        while (($to - $from) > 1) {
            $middle = (int) floor(($from + $to) / 2);
            if (self::onFacade($middle, $_slot, $_latitude, $_longitude) === $target) {
                $to = $middle;
            } else {
                $from = $middle;
            }
        }
        return $to;
    }

    /*
     * La hauteur maximale que le soleil atteint dans la journée, en degrés, ou
     * null si le calcul n'aboutit pas.
     *
     * C'est le nombre qui permet de dire à quelqu'un « le soleil ne monte
     * jamais au-dessus de 62,6° chez vous », et donc que sa hauteur minimale de
     * 70° ne se satisfera aucun jour de l'année. Autant dire qu'il doit être
     * juste, y compris là où la journée ne ressemble pas à une journée.
     *
     * Le cas ordinaire est gratuit : la hauteur culmine au midi solaire, et le
     * milieu du lever et du coucher tombe dessus. Ce milieu n'est pas le midi
     * solaire à la seconde près — la déclinaison bouge d'un jour à l'autre, ce
     * qui décale les deux bornes de quelques secondes chacune — mais la hauteur
     * y est à son maximum, donc sa dérivée y est nulle : l'écart sur le
     * résultat se compte en millièmes de degré, très en deçà du dixième auquel
     * facadeReach() arrondit.
     *
     * Le cas polaire, lui, n'a ni lever ni coucher, et c'est le piège : se
     * contenter du cas ordinaire ferait sauter les jours de soleil de minuit,
     * c'est-à-dire précisément les plus hauts de l'année. Au Svalbard, la
     * hauteur maximale annoncée tomberait à 22° au lieu de 35° — un nombre
     * faux, pas une absence, et l'utilisateur lirait « le soleil ne monte
     * jamais au-dessus de 22° » un jour où il est à 35°. On balaie donc les
     * vingt-quatre heures, d'heure en heure, puis de minute en minute autour du
     * meilleur point. La hauteur n'a qu'un maximum par jour, le balayage ne peut
     * pas se tromper de bosse, et il ne coûte que les jours où la journée n'a
     * pas de bornes — jamais aux latitudes habitées par les utilisateurs du
     * plugin.
     */
    public static function dayMaxElevation($_dayTimestamp, $_latitude, $_longitude) {
        $sun = self::sun($_dayTimestamp, $_latitude, $_longitude);
        if ($sun['sunrise'] !== null && $sun['sunset'] !== null && $sun['sunset'] > $sun['sunrise']) {
            $top = self::sunPosition((int) floor(($sun['sunrise'] + $sun['sunset']) / 2), $_latitude, $_longitude);
            return $top['elevation'];
        }

        $midnight = mktime(0, 0, 0, (int) date('n', $_dayTimestamp), (int) date('j', $_dayTimestamp), (int) date('Y', $_dayTimestamp));
        if ($midnight === false) {
            return null;
        }
        $best      = null;
        $bestValue = null;
        foreach (array(3600, 60) as $step) {
            /* Le premier passage couvre la journée entière ; le second n'affine
             * qu'autour du point trouvé, une heure de part et d'autre. */
            $from = ($best === null) ? $midnight : $best - 3600;
            $to   = ($best === null) ? $midnight + 86400 : $best + 3600;
            for ($time = $from; $time <= $to; $time += $step) {
                $position = self::sunPosition($time, $_latitude, $_longitude);
                if ($position['elevation'] === null) {
                    continue;
                }
                if ($bestValue === null || $position['elevation'] > $bestValue) {
                    $bestValue = $position['elevation'];
                    $best      = $time;
                }
            }
            if ($best === null) {
                return null;
            }
        }
        return $bestValue;
    }

    /*
     * Ce que cette façade donne sur une année, à cette position.
     *
     * Rend un dénombrement : combien de jours échantillonnés le soleil éclaire
     * la façade, par quoi il y arrive, par quoi il en part, jusqu'où il monte,
     * et combien de temps dure la plus longue exposition.
     *
     * À quoi cela sert. À 50,5° de latitude nord le soleil ne dépasse jamais
     * le 310,1° au coucher ni les 62,6° de hauteur : une façade déclarée
     * jusqu'au 340°, ou une hauteur minimale de 70°, donne un réglage
     * parfaitement cohérent à l'écran et qui ne se comportera jamais comme
     * l'utilisateur l'imagine — au mieux la fenêtre ne se referme que sur la
     * hauteur ou au coucher, au pire elle n'existe aucun jour de l'année et la
     * protection solaire ne part jamais. Le plugin sait tout ce qu'il faut pour
     * le dire ; il ne lui manquait que de le compter. C'est exactement le
     * défaut qui s'est produit avec la façade livrée par défaut, découvert
     * après coup et seulement parce qu'on l'a mesuré.
     *
     * $_step : pas d'échantillonnage en jours, 5 par défaut, soit 73 points sur
     * l'année. C'est un compromis assumé, pas un calcul exact : un jour sur
     * cinq suffit à dire « éclairée toute l'année », « jamais » ou « seulement
     * la belle saison », et un jour d'écart sur une frontière de saison ne
     * change rien à la phrase que l'utilisateur lira. En échange, le coût
     * tient : un facadeWindow() vaut 0,44 ms mesurées, donc une trentaine de
     * millisecondes pour les 73 points — ce qui interdit d'appeler cette
     * méthode à chaque minute de cron, mais convient très bien à une action
     * AJAX déclenchée quand l'utilisateur règle sa façade. Un appelant qui veut
     * le compte exact passe $_step = 1 et paie les deux secondes.
     *
     * Les nombres rendus se lisent donc en proportion de `sampled`, jamais en
     * jours d'une année réelle : `days` vaut 73 sur 73, et c'est à l'appelant
     * de le traduire en « 365 jours sur 365 » s'il veut une phrase.
     */
    public static function facadeReach($_slot, $_latitude, $_longitude, $_now = null, $_step = 5) {
        $slot  = self::cleanSlot($_slot);
        $reach = array(
            'sampled'       => 0,
            'days'          => 0,
            'in_azimuth'    => 0,
            'in_sunrise'    => 0,
            'in_elevation'  => 0,
            'out_azimuth'   => 0,
            'out_elevation' => 0,
            'out_sunset'    => 0,
            'max_elevation' => null,
            'longest'       => 0,
        );
        /* Sans position d'installation, il n'y a rien à compter : (float) ''
         * vaut zéro, c'est le golfe de Guinée, et l'appelant afficherait en
         * toute confiance la course du soleil sur un point de l'Atlantique.
         * Zéro jour échantillonné dit « on ne sait pas », et se distingue d'un
         * « le soleil n'éclaire jamais cette façade » par ce seul compte. */
        if ($_latitude === null || $_longitude === null || $_latitude === '' || $_longitude === '') {
            return $reach;
        }
        $now  = ($_now === null) ? time() : (int) $_now;
        /* Un pas nul ou négatif ferait tourner la boucle sans fin ; au-delà de
         * l'année, il ne reste qu'un point. */
        $step = max(1, min(365, (int) $_step));

        for ($day = 0; $day < 365; $day += $step) {
            $base = strtotime('+' . $day . ' day', $now);
            if ($base === false) {
                continue;
            }
            $reach['sampled']++;
            $sun = self::sun($base, $_latitude, $_longitude);

            $top = self::dayMaxElevation($base, $_latitude, $_longitude);
            if ($top !== null && ($reach['max_elevation'] === null || $top > $reach['max_elevation'])) {
                $reach['max_elevation'] = $top;
            }

            $window = self::facadeWindow($slot, $base, $_latitude, $_longitude);
            if ($window['in'] === null || $window['out'] === null) {
                continue;
            }
            $reach['days']++;
            if (($window['out'] - $window['in']) > $reach['longest']) {
                $reach['longest'] = $window['out'] - $window['in'];
            }

            /*
             * À quoi attribuer l'arrivée et le départ.
             *
             * facadeWindow() ne rend qu'un horodatage ; la cause, elle, se
             * relit sur place. Le raisonnement est le même aux deux bouts :
             * « être sur la façade » est la conjonction de deux conditions —
             * l'azimut dans la fenêtre, la hauteur au-dessus du seuil — bornée
             * par le jour lui-même. On regarde donc, à l'instant rendu,
             * laquelle des deux vient de basculer.
             *
             * L'arrivée d'abord. Si elle tombe à l'heure du lever, c'est le
             * lever qui l'a faite : le soleil était déjà dans la fenêtre et
             * assez haut en se levant, il n'a fait qu'apparaître.
             * facadeWindow() rend alors l'horodatage du lever à la seconde,
             * sans dichotomie, d'où la comparaison exacte. Sinon, la seconde qui
             * précède l'arrivée voit un soleil qui n'est pas encore sur la
             * façade : la condition qui lui manque là est la cause.
             *
             * Le départ ensuite, en miroir. Si l'horodatage est celui du
             * coucher, c'est le coucher qui l'a fait, et facadeWindow() le rend
             * lui aussi tel quel. Sinon, à l'instant du départ — le premier où
             * le soleil n'est plus sur la façade — la condition qui manque est
             * la cause.
             *
             * Ce qui rend ce comptage utile est qu'il se lit à l'envers :
             * out_azimuth à zéro sur 73 jours ne dit pas que l'azimut de fin
             * est mal réglé, il dit que le soleil ne l'atteint jamais à cette
             * latitude — la fenêtre se referme toujours sur la hauteur ou au
             * coucher. C'est ce raisonnement, et pas le nombre, qu'il faudra
             * se rappeler devant un comptage surprenant.
             *
             * Reste un cas où l'attribution tranche arbitrairement : quand les
             * deux conditions basculent dans la même seconde — un soleil qui
             * entre dans la fenêtre à l'instant même où il passe la hauteur.
             * L'azimut l'emporte alors, parce qu'il est testé le premier. Le
             * cas est rare, il ne fausse qu'un jour sur soixante-treize, et
             * aucune des phrases construites à partir de ces nombres n'en
             * dépend.
             */
            if ($window['in'] <= $sun['sunrise']) {
                $reach['in_sunrise']++;
            } else {
                $before = self::sunPosition($window['in'] - 1, $_latitude, $_longitude);
                if ($before['azimuth'] === null
                    || !self::azimuthInWindow($before['azimuth'], $slot['sun_from'], $slot['sun_to'])) {
                    $reach['in_azimuth']++;
                } else {
                    $reach['in_elevation']++;
                }
            }

            if ($window['out'] >= $sun['sunset']) {
                $reach['out_sunset']++;
            } else {
                $gone = self::sunPosition($window['out'], $_latitude, $_longitude);
                if ($gone['azimuth'] === null
                    || !self::azimuthInWindow($gone['azimuth'], $slot['sun_from'], $slot['sun_to'])) {
                    $reach['out_azimuth']++;
                } else {
                    $reach['out_elevation']++;
                }
            }
        }

        /* Le dixième de degré : au-delà, on afficherait une précision que ni
         * l'échantillonnage ni la réfraction ne garantissent. */
        if ($reach['max_elevation'] !== null) {
            $reach['max_elevation'] = round($reach['max_elevation'], 1);
        }
        return $reach;
    }

    /*
     * L'horodatage du moment pour un jour donné, ou null s'il n'y a rien ce
     * jour-là : jour de semaine décoché, ou soleil qui ne se couche pas.
     *
     * $_seed distingue les tirages aléatoires de deux moments d'un même jour :
     * sans lui, le soir et le matin d'un même groupe se décaleraient de la
     * même durée, ce qui n'est pas aléatoire mais décalé.
     */
    public static function occurrence($_slot, $_dayTimestamp, $_latitude, $_longitude, $_seed = '') {
        $slot = self::cleanSlot($_slot);
        $day  = date('Y-m-d', $_dayTimestamp);

        /*
         * Le jour de semaine est celui du jour de base, pas celui de l'ordre
         * final : « lundi, coucher du soleil + 5 h » reste le programme du lundi
         * même si l'ordre part le mardi à 1 h du matin. C'est ainsi que
         * l'utilisateur l'a pensé en cochant la case.
         */
        if (count($slot['days']) > 0 && !in_array((int) date('N', $_dayTimestamp), $slot['days'])) {
            return null;
        }

        if ($slot['mode'] == self::MODE_FIXED) {
            $timestamp = strtotime($day . ' ' . $slot['time']);
            if ($timestamp === false) {
                return null;
            }
        } elseif (self::isFacadeMode($slot['mode'])) {
            /* Le soleil arrive sur la façade et il la quitte : les deux modes
             * lisent les deux bornes du même intervalle, celui que la façade du
             * groupe découpe dans la journée. Ils ne diffèrent que par la borne
             * lue, et l'orientation vient du groupe. */
            $window    = self::facadeWindow($slot, $_dayTimestamp, $_latitude, $_longitude);
            $timestamp = ($slot['mode'] == self::MODE_FACADE_IN) ? $window['in'] : $window['out'];
            if ($timestamp === null) {
                return null;
            }
            /* Le décalage a autant de sens ici qu'au coucher du soleil, et il
             * en a même un très concret : « 20 minutes après que le soleil
             * arrive sur la façade », c'est le temps qu'il faut à celle-ci pour
             * chauffer. */
            $timestamp += $slot['offset'] * 60;
        } else {
            $sun = self::sun($_dayTimestamp, $_latitude, $_longitude);
            if ($sun[$slot['mode']] === null) {
                return null;
            }
            $timestamp = $sun[$slot['mode']] + $slot['offset'] * 60;
        }

        $timestamp += self::randomOffset($slot['random'], $_seed . '|' . $day) * 60;

        /*
         * Les garde-fous ramènent le moment dans la fenêtre, ils ne l'annulent
         * pas. « Jamais avant 18 h » veut dire « pas avant 18 h », donc à 18 h :
         * en décembre, en Belgique, le soleil se couche à 16 h 40, et fermer
         * les volets à 16 h 40 revient à s'enfermer en plein après-midi. Un
         * garde-fou qui annulerait, lui, laisserait les volets ouverts toute la
         * nuit — l'exact contraire de ce qu'on cherchait en le posant.
         */
        $timestamp = self::clamp($timestamp, $day, $slot['not_before'], $slot['not_after']);
        return $timestamp;
    }

    /* Ramène un horodatage entre les deux bornes du jour, quand elles existent. */
    public static function clamp($_timestamp, $_day, $_notBefore, $_notAfter) {
        if ($_notBefore !== '') {
            $floor = strtotime($_day . ' ' . $_notBefore);
            if ($floor !== false && $_timestamp < $floor) {
                $_timestamp = $floor;
            }
        }
        if ($_notAfter !== '') {
            $ceiling = strtotime($_day . ' ' . $_notAfter);
            if ($ceiling !== false && $_timestamp > $ceiling) {
                $_timestamp = $ceiling;
            }
        }
        return $_timestamp;
    }

    /*
     * Décalage aléatoire, en minutes, mais tiré une fois pour toutes pour un
     * jour donné.
     *
     * rand() ne conviendrait pas : le cron repasse toutes les minutes et
     * retirerait un nombre différent à chaque passage. L'heure affichée dans
     * l'interface changerait sans arrêt, et le moment partirait au premier
     * tirage qui tombe dans le passé, c'est-à-dire presque toujours le plus tôt
     * possible. Une empreinte de la graine et du jour donne au contraire une
     * valeur stable de minuit à minuit, et différente le lendemain.
     */
    public static function randomOffset($_random, $_seed) {
        $random = (int) $_random;
        if ($random <= 0) {
            return 0;
        }
        return (int) (crc32($_seed) % (2 * $random + 1)) - $random;
    }

    /*
     * Les prochains horodatages d'un moment, à partir de maintenant.
     *
     * L'horizon vaut huit jours par défaut : un moment qui n'a qu'un seul jour
     * de semaine coché doit rester annonçable toute la semaine qui précède.
     */
    public static function nextOccurrences($_slot, $_now, $_latitude, $_longitude, $_seed = '', $_count = 1, $_horizon = 8) {
        $slot = self::cleanSlot($_slot);
        $found = array();
        if ($slot['enable'] != 1) {
            return $found;
        }

        /*
         * On commence la veille : un moment réglé sur « coucher du soleil
         * + 6 h » appartient au jour d'hier et tombe pourtant après minuit,
         * c'est-à-dire dans l'avenir vu d'ici.
         */
        for ($day = -1; $day <= $_horizon; $day++) {
            $timestamp = self::occurrence($slot, strtotime($day . ' day', $_now), $_latitude, $_longitude, $_seed);
            if ($timestamp === null || $timestamp <= $_now) {
                continue;
            }
            $found[] = $timestamp;
            if (count($found) >= $_count) {
                break;
            }
        }
        sort($found);
        return $found;
    }

    /*
     * Le moment à jouer maintenant, s'il y en a un : celui qui est passé depuis
     * moins que le délai de grâce et qui n'a pas encore été joué.
     *
     * Rend array('timestamp' => ..., 'day' => 'Y-m-d') — le jour de base, qui
     * sert de marque d'exécution : deux passages du cron dans la même minute ne
     * doivent pas envoyer deux fois l'ordre, et une box rallumée le lendemain
     * ne doit pas rejouer le soir de la veille.
     *
     * Le délai de grâce existe pour les coupures de courant et les crons en
     * retard : un ordre manqué de dix minutes a encore tout son sens, un ordre
     * manqué de six heures n'en a plus aucun — ouvrir les volets du matin à
     * deux heures de l'après-midi, ou les fermer à trois heures du matin,
     * réveille la maison au lieu de la servir.
     */
    public static function dueOccurrence($_slot, $_now, $_latitude, $_longitude, $_seed = '', $_grace = 900) {
        $slot = self::cleanSlot($_slot);
        if ($slot['enable'] != 1) {
            return null;
        }
        $best = null;

        /* Deux jours en arrière : un décalage négatif important sur le lever du
         * soleil peut ramener le moment du jour dans la nuit précédente. */
        for ($day = -2; $day <= 0; $day++) {
            $base = strtotime($day . ' day', $_now);
            $timestamp = self::occurrence($slot, $base, $_latitude, $_longitude, $_seed);
            if ($timestamp === null || $timestamp > $_now || ($_now - $timestamp) > $_grace) {
                continue;
            }
            /* Le plus récent gagne : si deux jours de base produisent tous deux
             * un moment jouable, c'est le dernier qui reflète l'intention. */
            if ($best === null || $timestamp > $best['timestamp']) {
                $best = array('timestamp' => $timestamp, 'day' => date('Y-m-d', $base));
            }
        }
        return $best;
    }
}
