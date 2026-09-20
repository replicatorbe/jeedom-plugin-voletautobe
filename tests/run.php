<?php
/* Jeu d'essai hors ligne du plugin Volets Auto.
 *
 *   php tests/run.php
 *
 * Aucune dépendance : ni Jeedom, ni base de données, ni volet. Les deux classes
 * éprouvées ici — le calcul des moments et la reconnaissance des volets —
 * ignorent volontairement Jeedom, et c'est précisément ce qui rend ce fichier
 * possible.
 *
 * Ce qu'on vérifie n'est pas décoratif : un plugin de programmation se trompe
 * silencieusement. Un décalage de signe, un garde-fou qui annule au lieu de
 * ramener, un tirage aléatoire relancé à chaque minute, une condition de
 * température qui bloque le mouvement quand la sonde se tait — rien de tout
 * cela ne lève d'erreur, et la seule façon de s'en apercevoir en production est
 * de constater, un matin d'hiver, que la maison est restée dans le noir.
 */

date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/../core/class/voletautobeSun.class.php';
require_once __DIR__ . '/../core/class/voletautobeVolets.class.php';

/* Bruxelles. Toutes les heures attendues ci-dessous en découlent. */
const LAT = 50.8503;
const LON = 4.3517;

$ok = 0;
$ko = 0;

function verifie($_titre, $_obtenu, $_attendu) {
    global $ok, $ko;
    if ($_obtenu == $_attendu) {
        $ok++;
        printf("  %-58s ok\n", $_titre);
        return;
    }
    $ko++;
    printf("  %-58s ÉCHEC : obtenu %s, attendu %s\n", $_titre,
           var_export($_obtenu, true), var_export($_attendu, true));
}

function verifieVrai($_titre, $_condition) {
    verifie($_titre, $_condition ? true : false, true);
}

function heure($_timestamp) {
    return ($_timestamp === null) ? null : date('Y-m-d H:i', $_timestamp);
}

/* ------------------------------------------------------------------ 1 ---
 * Les heures tapées à la main. Un champ vide n'est pas minuit : c'est
 * l'absence de garde-fou, et les confondre poserait un plafond à 00:00 qui
 * ramènerait toutes les fermetures au milieu de la nuit. */
echo "\nHeures saisies\n";
verifie('07:05 tel quel', voletautobeSun::cleanTime('07:05'), '07:05');
verifie('7:05 complété', voletautobeSun::cleanTime('7:05'), '07:05');
verifie('7h05 accepté', voletautobeSun::cleanTime('7h05'), '07:05');
verifie('vide reste vide', voletautobeSun::cleanTime(''), '');
verifie('25:00 refusé', voletautobeSun::cleanTime('25:00'), '');
verifie('07:75 refusé', voletautobeSun::cleanTime('07:75'), '');
verifie('texte refusé', voletautobeSun::cleanTime('le matin'), '');

/* ------------------------------------------------------------------ 2 ---
 * La normalisation d'un moment. Tout ce qui sort d'un formulaire est une
 * chaîne, y compris une case décochée qui n'arrive tout simplement pas. */
echo "\nNormalisation d'un moment\n";
$brut = array('enable' => '1', 'action' => 'down', 'mode' => 'sunset', 'offset' => '-45',
              'random' => '999', 'time' => '20h30', 'not_before' => '', 'days' => array('1', '1', 9, '5'));
$slot = voletautobeSun::cleanSlot($brut);
verifie('case cochée devient 1', $slot['enable'], 1);
verifie('décalage devient entier', $slot['offset'], -45);
verifie('aléa borné', $slot['random'], voletautobeSun::RANDOM_MAX);
verifie('heure normalisée', $slot['time'], '20:30');
verifie('jours dédoublonnés et bornés', implode(',', $slot['days']), '1,5');
$vide = voletautobeSun::cleanSlot(array('days' => array()));
verifie('aucun jour coché = jamais', count($vide['days']), 0);
$absent = voletautobeSun::cleanSlot(array('action' => 'up'));
verifie('case absente devient 0', $absent['enable'], 0);
verifie('mode inconnu retombe sur heure fixe',
        voletautobeSun::cleanSlot(array('mode' => 'lune'))['mode'], 'fixed');
verifie('action inconnue retombe sur la valeur par défaut',
        voletautobeSun::cleanSlot(array('action' => 'orienter'), voletautobeSun::ACTION_DOWN)['action'], 'down');
verifie('un moment de descente ouvre à 21:00 par défaut',
        voletautobeSun::emptySlot(voletautobeSun::ACTION_DOWN)['time'], '21:00');
verifie('un moment de montée à 07:00', voletautobeSun::emptySlot()['time'], '07:00');

/* La position n'a de sens que pour l'action « position », mais elle doit être
 * bornée de toute façon : envoyer 140 % à un volet, c'est selon le protocole ne
 * rien faire ou tout ouvrir, deux résultats que personne n'a demandés. */
verifie('position par défaut à 30 %', voletautobeSun::emptySlot()['position'], 30);
verifie('position au-dessus de 100 ramenée',
        voletautobeSun::cleanSlot(array('position' => '140'))['position'], 100);
verifie('position négative ramenée',
        voletautobeSun::cleanSlot(array('position' => -5))['position'], 0);
verifie('position texte devient un entier',
        voletautobeSun::cleanSlot(array('position' => '45'))['position'], 45);
verifie('action position retenue',
        voletautobeSun::cleanSlot(array('action' => 'position'))['action'], voletautobeSun::ACTION_POSITION);

/* Le demi-degré compte pour une consigne de gel, et l'utilisateur tape « 5,5 » :
 * un (float) seul couperait à 5 sans rien dire. */
$virgule = voletautobeSun::cleanSlot(array('temp_mode' => 'min', 'temp_value' => '5,5'));
verifie('la virgule décimale est comprise', $virgule['temp_value'], 5.5);
verifieVrai('et c\'est bien un nombre à virgule', is_float($virgule['temp_value']));
verifie('condition de température retenue', $virgule['temp_mode'], voletautobeSun::TEMP_MIN);
verifie('consigne aberrante bornée',
        voletautobeSun::cleanSlot(array('temp_value' => '260'))['temp_value'], 60.0);
verifie('consigne aberrante bornée par le bas',
        voletautobeSun::cleanSlot(array('temp_value' => '-260'))['temp_value'], -50.0);
verifie('condition inconnue retombe sur aucune',
        voletautobeSun::cleanSlot(array('temp_mode' => 'tiede'))['temp_mode'], voletautobeSun::TEMP_NONE);

/* ------------------------------------------------------------------ 3 ---
 * Le tirage aléatoire. Il doit être stable sur la journée, sinon l'heure
 * annoncée dans l'interface n'est pas celle qui sera jouée, et le moment part
 * au premier tirage qui tombe dans le passé. */
echo "\nTirage aléatoire\n";
$a = voletautobeSun::randomOffset(20, 'salon|evening|2026-01-15');
$b = voletautobeSun::randomOffset(20, 'salon|evening|2026-01-15');
verifie('deux appels, même valeur', $a, $b);
verifieVrai('dans les bornes', $a >= -20 && $a <= 20);
verifieVrai('le lendemain, autre valeur',
    voletautobeSun::randomOffset(20, 'salon|evening|2026-01-16') !== $a
    || voletautobeSun::randomOffset(20, 'salon|evening|2026-01-17') !== $a);
verifieVrai('deux moments du même jour se décalent différemment',
    voletautobeSun::randomOffset(20, 'salon|morning|2026-01-15')
    !== voletautobeSun::randomOffset(20, 'salon|evening|2026-01-15'));
verifie('zéro ne tire rien', voletautobeSun::randomOffset(0, 'peu importe'), 0);
$ecart = array();
for ($jour = 1; $jour <= 60; $jour++) {
    $ecart[] = voletautobeSun::randomOffset(30, 'salon|evening|2026-03-' . $jour);
}
verifieVrai('60 jours, des valeurs différentes', count(array_unique($ecart)) > 10);

/* ------------------------------------------------------------------ 4 ---
 * Le soleil de Bruxelles. Les valeurs attendues sont celles publiées pour la
 * ville, à la minute près ; on tolère dix minutes, l'algorithme de PHP ne
 * prétend pas à mieux. */
echo "\nSoleil à Bruxelles\n";
$ete = voletautobeSun::sun(mktime(0, 0, 0, 6, 21, 2026), LAT, LON);
$hiver = voletautobeSun::sun(mktime(0, 0, 0, 12, 21, 2026), LAT, LON);
verifieVrai('21 juin, coucher vers 22:00', abs($ete['sunset'] - strtotime('2026-06-21 22:00')) < 600);
verifieVrai('21 juin, lever vers 05:30', abs($ete['sunrise'] - strtotime('2026-06-21 05:30')) < 600);
verifieVrai('21 décembre, coucher vers 16:40', abs($hiver['sunset'] - strtotime('2026-12-21 16:40')) < 600);
verifieVrai('21 décembre, lever vers 08:44', abs($hiver['sunrise'] - strtotime('2026-12-21 08:44')) < 600);
/* Le calcul ne doit pas dépendre de l'heure à laquelle on le demande : c'est
 * l'erreur qui ferait basculer un plugin sur le lendemain passé minuit. */
$matin = voletautobeSun::sun(strtotime('2026-06-21 00:10'), LAT, LON);
$soir  = voletautobeSun::sun(strtotime('2026-06-21 23:50'), LAT, LON);
verifie('même jour, même coucher', $matin['sunset'], $soir['sunset']);
verifie('même jour, même lever', $matin['sunrise'], $soir['sunrise']);
/* Au pôle, le soleil ne se couche pas : null, et surtout pas minuit — un volet
 * qui se fermerait à minuit pile au Svalbard serait la trace d'un repli
 * silencieux sur zéro. */
$pole = voletautobeSun::sun(mktime(0, 0, 0, 6, 21, 2026), 78.2, 15.6);
verifie('Svalbard en juin, pas de coucher', $pole['sunset'], null);
verifie('Svalbard en juin, pas de lever non plus', $pole['sunrise'], null);

/* ------------------------------------------------------------------ 5 ---
 * Un moment, un jour. */
echo "\nCalcul d'un moment\n";
$jour = mktime(0, 0, 0, 12, 21, 2026); // un lundi
$fixe = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'fixed', 'time' => '20:00'));
verifie('heure fixe', heure(voletautobeSun::occurrence($fixe, $jour, LAT, LON)), '2026-12-21 20:00');

$avant = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'sunset', 'offset' => -30));
verifieVrai('30 min avant le coucher',
    abs(voletautobeSun::occurrence($avant, $jour, LAT, LON) - ($hiver['sunset'] - 1800)) < 60);

$apres = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'sunrise', 'offset' => 45));
verifieVrai('45 min après le lever',
    abs(voletautobeSun::occurrence($apres, $jour, LAT, LON) - ($hiver['sunrise'] + 2700)) < 60);

$mardi = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'fixed', 'time' => '20:00', 'days' => array(2)));
verifie('jour non coché : rien', voletautobeSun::occurrence($mardi, $jour, LAT, LON), null);

/* Le garde-fou ramène, il n'annule pas. C'est ici que le plugin des volets se
 * distingue de celui des lampes : en décembre le soleil se couche à 16 h 40, et
 * fermer les volets à 16 h 40 revient à s'enfermer en plein après-midi.
 * « Pas avant 18:00 » doit donner 18:00, pas rien du tout — un garde-fou qui
 * annulerait laisserait les volets ouverts toute la nuit d'hiver. */
$garde = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'sunset', 'offset' => 0, 'not_before' => '18:00'));
verifie('plancher en hiver', heure(voletautobeSun::occurrence($garde, $jour, LAT, LON)), '2026-12-21 18:00');
$juin = mktime(0, 0, 0, 6, 21, 2026);
verifieVrai('plancher sans effet en juin',
    voletautobeSun::occurrence($garde, $juin, LAT, LON) > strtotime('2026-06-21 21:00'));
/* Et le plafond du matin : en décembre le soleil se lève à 8 h 44, on ne va pas
 * attendre 8 h 44 pour ouvrir. */
$plafond = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'sunrise', 'offset' => 0, 'not_after' => '07:30'));
verifie('plafond en hiver', heure(voletautobeSun::occurrence($plafond, $jour, LAT, LON)), '2026-12-21 07:30');

/* Le changement d'heure : le dernier dimanche de mars, 2 h devient 3 h. Une
 * heure fixe doit rester l'heure du mur, pas glisser d'une heure. */
$printemps = voletautobeSun::occurrence($fixe, mktime(0, 0, 0, 3, 29, 2026), LAT, LON);
verifie('heure fixe le jour du changement d\'heure', heure($printemps), '2026-03-29 20:00');
$automne = voletautobeSun::occurrence($fixe, mktime(0, 0, 0, 10, 25, 2026), LAT, LON);
verifie('et au retour à l\'heure d\'hiver', heure($automne), '2026-10-25 20:00');

/* ------------------------------------------------------------------ 6 ---
 * Les prochaines occurrences. */
echo "\nProchaines occurrences\n";
$now = strtotime('2026-12-21 18:00');
$suite = voletautobeSun::nextOccurrences($fixe, $now, LAT, LON, 'test', 3);
verifie('trois dates rendues', count($suite), 3);
verifie('la première est ce soir', heure($suite[0]), '2026-12-21 20:00');
verifieVrai('elles sont croissantes', $suite[0] < $suite[1] && $suite[1] < $suite[2]);
verifieVrai('toutes dans le futur', $suite[0] > $now);

$weekend = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'fixed', 'time' => '09:00', 'days' => array(6, 7)));
$suite = voletautobeSun::nextOccurrences($weekend, $now, LAT, LON, 'test', 2);
verifie('samedi d\'abord', date('N', $suite[0]), '6');
verifie('puis dimanche', date('N', $suite[1]), '7');

$eteint = voletautobeSun::cleanSlot(array('enable' => 0, 'mode' => 'fixed', 'time' => '09:00'));
verifie('moment désactivé : aucune date', count(voletautobeSun::nextOccurrences($eteint, $now, LAT, LON)), 0);

/* Un moment tardif appartient au jour de base, et se retrouve pourtant après
 * minuit : il doit être vu comme à venir quand on est déjà le lendemain. */
$tardif = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'sunset', 'offset' => 480));
$suite = voletautobeSun::nextOccurrences($tardif, strtotime('2026-12-22 00:10'), LAT, LON, 'test', 1);
verifie('le moment de la veille compte encore', date('Y-m-d', $suite[0]), '2026-12-22');
verifieVrai('et tombe bien après minuit', (int) date('H', $suite[0]) === 0);

/* ------------------------------------------------------------------ 7 ---
 * Ce qui est dû maintenant. C'est la décision qui fait bouger le volet. */
echo "\nCe qui est dû\n";
$due = voletautobeSun::dueOccurrence($fixe, strtotime('2026-12-21 20:00'), LAT, LON, 'test', 900);
verifie('à la minute pile', heure($due['timestamp']), '2026-12-21 20:00');
verifie('le jour de base est marqué', $due['day'], '2026-12-21');
verifieVrai('dix minutes après, encore dû',
    voletautobeSun::dueOccurrence($fixe, strtotime('2026-12-21 20:10'), LAT, LON, 'test', 900) !== null);
verifie('trois heures après, abandonné',
    voletautobeSun::dueOccurrence($fixe, strtotime('2026-12-21 23:00'), LAT, LON, 'test', 900), null);
verifie('avant l\'heure, rien',
    voletautobeSun::dueOccurrence($fixe, strtotime('2026-12-21 19:59'), LAT, LON, 'test', 900), null);
verifie('moment désactivé, rien',
    voletautobeSun::dueOccurrence($eteint, strtotime('2026-12-21 09:00'), LAT, LON, 'test', 900), null);
/* Le moment tardif de la veille doit être joué après minuit, avec le jour de la
 * veille comme marque : sinon il repartirait le soir même. */
$due = voletautobeSun::dueOccurrence($tardif, strtotime('2026-12-22 00:45'), LAT, LON, 'test', 900);
verifie('moment d\'après minuit, marque de la veille', $due['day'], '2026-12-21');

/* ------------------------------------------------------------------ 8 ---
 * La condition de température. C'est ce que ce plugin a de plus que son frère
 * des lampes, et c'est aussi la partie la plus facile à régler à l'envers. */
echo "\nCondition de température\n";
$sans = voletautobeSun::cleanSlot(array('enable' => 1, 'temp_mode' => 'none'));
$froid = voletautobeSun::cleanSlot(array('enable' => 1, 'temp_mode' => 'min', 'temp_value' => 5));
$chaud = voletautobeSun::cleanSlot(array('enable' => 1, 'temp_mode' => 'max', 'temp_value' => 26));

$verdict = voletautobeSun::temperatureCheck($sans, 12.0);
verifie('aucune condition : on bouge', $verdict['met'], true);
verifie('et la raison le dit', $verdict['reason'], 'none');
verifie('aucune condition : rien à savoir', $verdict['known'], true);
/* Une condition « seulement si ≥ 5 °C » sur l'ouverture du matin : à −3 °C, le
 * volet fermé isole, on le laisse fermé. */
$verdict = voletautobeSun::temperatureCheck($froid, -3.0);
verifie('trop froid : on ne bouge pas', $verdict['met'], false);
verifie('et on sait pourquoi', $verdict['reason'], 'too_cold');
$verdict = voletautobeSun::temperatureCheck($froid, 12.0);
verifie('assez doux : on ouvre', $verdict['met'], true);
verifie('raison ok', $verdict['reason'], 'ok');
verifie('le seuil pile compte comme rempli',
        voletautobeSun::temperatureCheck($froid, 5.0)['met'], true);
verifie('un demi-degré sous le seuil ne passe pas',
        voletautobeSun::temperatureCheck($froid, 4.5)['met'], false);
/* La condition inverse, « seulement si ≤ 26 °C », sert au moment du matin :
 * on n'ouvre que s'il ne fait pas déjà trop chaud dehors, sans quoi l'ouverture
 * de 7 h transforme la maison en serre pour la journée. */
$verdict = voletautobeSun::temperatureCheck($chaud, 31.0);
verifie('trop chaud : on ne bouge pas', $verdict['met'], false);
verifie('et on sait pourquoi', $verdict['reason'], 'too_warm');
verifie('sous le plafond : on bouge', voletautobeSun::temperatureCheck($chaud, 18.0)['met'], true);

/*
 * Le cas qui compte : la sonde ne dit rien. Pile morte, plugin tiers arrêté,
 * valeur illisible. Si « on ne sait pas » empêchait le mouvement, la maison
 * resterait volets fermés des semaines sans que rien ne l'explique. On bouge,
 * et on l'écrit dans le journal.
 */
$verdict = voletautobeSun::temperatureCheck($froid, null);
verifie('sonde muette : on bouge quand même', $verdict['met'], true);
verifie('mais on note qu\'on ne savait pas', $verdict['known'], false);
verifie('raison inconnue', $verdict['reason'], 'unknown');
$verdict = voletautobeSun::temperatureCheck($chaud, null);
verifie('idem pour un plafond', $verdict['met'], true);
verifie('idem, on ne savait pas', $verdict['reason'], 'unknown');
/* Sans condition, une sonde muette n'est même pas un sujet. */
verifie('aucune condition, sonde muette : rien à signaler',
        voletautobeSun::temperatureCheck($sans, null)['reason'], 'none');
/* Zéro degré est une mesure, pas une absence de mesure : la confusion
 * laisserait les volets fermés exactement le jour où le seuil de gel compte. */
verifie('zéro degré reste une mesure',
        voletautobeSun::temperatureCheck($froid, 0.0)['known'], true);
verifie('et zéro degré est bien trop froid',
        voletautobeSun::temperatureCheck($froid, 0.0)['reason'], 'too_cold');

/* ------------------------------------------------------------------ 9 ---
 * Une année entière, jour par jour : aucune date manquante, aucune aberration.
 * C'est le test qui attrape les erreurs de saison — celles qu'on découvrirait
 * six mois plus tard. */
echo "\nUne année de fermetures\n";
$annee = voletautobeSun::cleanSlot(array('enable' => 1, 'action' => 'down', 'mode' => 'sunset',
                                         'offset' => 0, 'random' => 10, 'not_before' => '18:00'));
$manquants = 0;
$horsBornes = 0;
$plusTot = null;
$plusTard = null;
for ($j = 0; $j < 365; $j++) {
    $base = strtotime('2026-01-01 +' . $j . ' day');
    $timestamp = voletautobeSun::occurrence($annee, $base, LAT, LON, 'annee');
    if ($timestamp === null) {
        $manquants++;
        continue;
    }
    if (date('Y-m-d', $timestamp) !== date('Y-m-d', $base)) {
        $horsBornes++;
    }
    $h = (int) date('H', $timestamp);
    $plusTot = ($plusTot === null) ? $h : min($plusTot, $h);
    $plusTard = ($plusTard === null) ? $h : max($plusTard, $h);
}
verifie('aucun jour sans fermeture', $manquants, 0);
verifie('aucune fermeture hors de son jour', $horsBornes, 0);
verifie('jamais avant 18 h, garde-fou compris', $plusTot, 18);
verifie('le plus tard est en juin, vers 22 h', $plusTard, 22);

/* Et une année d'ouvertures au lever du soleil, plafonnée à 08:00 : le matin
 * d'hiver doit rester utilisable, pas glisser jusqu'à 8 h 44. */
$matins = voletautobeSun::cleanSlot(array('enable' => 1, 'action' => 'up', 'mode' => 'sunrise',
                                          'offset' => 0, 'not_after' => '08:00'));
$manquants = 0;
$tropTard = 0;
for ($j = 0; $j < 365; $j++) {
    $base = strtotime('2026-01-01 +' . $j . ' day');
    $timestamp = voletautobeSun::occurrence($matins, $base, LAT, LON, 'annee');
    if ($timestamp === null) {
        $manquants++;
        continue;
    }
    if ($timestamp > strtotime(date('Y-m-d', $base) . ' 08:00')) {
        $tropTard++;
    }
}
verifie('aucun jour sans ouverture', $manquants, 0);
verifie('jamais après 08:00', $tropTard, 0);

/* ----------------------------------------------------------------- 10 ---
 * La position du soleil dans le ciel. C'est ce qui remplace l'heure fixe de la
 * protection solaire : une façade sud-ouest prend le soleil quand son azimut
 * passe 200°, pas à 13 h.
 *
 * Deux des repères se retrouvent de tête, et c'est ce qui les rend précieux :
 * au midi solaire d'un solstice, le soleil culmine à 90° − latitude ± 23,44°,
 * l'inclinaison de l'axe de la Terre. 90 − 50,85 + 23,44 = 62,6° le 21 juin,
 * et 15,7° le 21 décembre. Une erreur de déclinaison, de longitude ou de
 * fuseau se verrait immédiatement ici. */
echo "\nPosition du soleil\n";

/* Le midi solaire vient de date_sun_info(), c'est-à-dire du coeur de PHP :
 * lui opposer notre azimut, c'est confronter deux calculs qui n'ont rien en
 * commun — et c'est le contrôle croisé qui attrape une heure locale employée
 * là où il fallait de l'UTC, décalage qu'aucune erreur ne signalerait. */
$infoEte   = date_sun_info(mktime(12, 0, 0, 6, 21, 2026), LAT, LON);
$infoHiver = date_sun_info(mktime(12, 0, 0, 12, 21, 2026), LAT, LON);
$midiEte   = $infoEte['transit'];
$midiHiver = $infoHiver['transit'];

$hautEte   = voletautobeSun::sunPosition($midiEte, LAT, LON);
$hautHiver = voletautobeSun::sunPosition($midiHiver, LAT, LON);
verifieVrai('21 juin, le soleil culmine à 62,6°', abs($hautEte['elevation'] - 62.6) < 1);
verifieVrai('21 décembre, il ne monte qu\'à 15,7°', abs($hautHiver['elevation'] - 15.7) < 1);
verifieVrai('et au midi solaire il est plein sud, en juin', abs($hautEte['azimuth'] - 180) < 1);
verifieVrai('comme en décembre', abs($hautHiver['azimuth'] - 180) < 1);
verifie('« plein sud » se dit aussi en toutes lettres',
        voletautobeSun::compassName($hautEte['azimuth']), 'sud');

/*
 * L'azimut du lever, confronté à la trigonométrie sphérique seule :
 *
 *     cos A = (sin déclinaison − sin h · sin latitude) / (cos h · cos latitude)
 *
 * où h = −0,833° est la hauteur du centre du soleil à l'instant où
 * date_sun_info() déclare le lever — le demi-diamètre du disque plus la
 * réfraction. Ce chemin-là ne passe ni par l'équation du temps, ni par l'angle
 * horaire, ni par la longitude : il ne partage rien avec le nôtre, et l'écart
 * entre les deux est la mesure la plus honnête dont on dispose hors ligne.
 *
 * Elle donne 49,6° en juin et 127,8° en décembre pour Bruxelles ; le contrat
 * annonçait « ≈ 48° » et « ≈ 129° », arrondis à un degré et demi près — c'est
 * la formule qui fait foi, et les deux calculs du plugin s'accordent avec elle
 * à trois centièmes de degré.
 */
function azimutLeverTheorique($_declinaison, $_hauteur = -0.833) {
    $cosinus = (sin(deg2rad($_declinaison)) - sin(deg2rad($_hauteur)) * sin(deg2rad(LAT)))
             / (cos(deg2rad($_hauteur)) * cos(deg2rad(LAT)));
    return rad2deg(acos(max(-1.0, min(1.0, $cosinus))));
}

$leverEte     = voletautobeSun::sunPosition($ete['sunrise'], LAT, LON);
$coucherEte   = voletautobeSun::sunPosition($ete['sunset'], LAT, LON);
$leverHiver   = voletautobeSun::sunPosition($hiver['sunrise'], LAT, LON);
$coucherHiver = voletautobeSun::sunPosition($hiver['sunset'], LAT, LON);

/* À l'heure du lever rendue par sun(), notre hauteur doit être nulle. C'est le
 * contrôle croisé le plus précieux du fichier : deux calculs indépendants —
 * date_sun_info() du coeur de PHP et le nôtre — doivent tomber sur le même
 * instant, et une erreur de fuseau les écarterait d'une heure, soit une
 * dizaine de degrés de hauteur. */
verifieVrai('21 juin, hauteur nulle à l\'heure du lever', abs($leverEte['elevation']) < 1.5);
verifieVrai('21 juin, hauteur nulle à l\'heure du coucher', abs($coucherEte['elevation']) < 1.5);
verifieVrai('21 décembre, hauteur nulle au lever', abs($leverHiver['elevation']) < 1.5);
verifieVrai('21 décembre, hauteur nulle au coucher', abs($coucherHiver['elevation']) < 1.5);

verifieVrai('21 juin, lever au 49,6°',
    abs($leverEte['azimuth'] - azimutLeverTheorique(23.44)) < 0.3);
verifieVrai('21 juin, coucher au 310,4°, symétrique du lever',
    abs($coucherEte['azimuth'] - (360 - azimutLeverTheorique(23.44))) < 0.3);
verifieVrai('21 décembre, lever au 127,8°',
    abs($leverHiver['azimuth'] - azimutLeverTheorique(-23.44)) < 0.3);
verifieVrai('21 décembre, coucher au 232,2°',
    abs($coucherHiver['azimuth'] - (360 - azimutLeverTheorique(-23.44))) < 0.3);
verifie('le soleil de juin se lève au nord-est',
        voletautobeSun::compassName($leverEte['azimuth']), 'nord-est');
verifie('celui de décembre au sud-est',
        voletautobeSun::compassName($leverHiver['azimuth']), 'sud-est');

/* La nuit, la hauteur est négative. Zéro par défaut ferait passer minuit pour
 * un lever de soleil, et une condition « au moins 0° de hauteur » serait
 * remplie toute la nuit. */
verifieVrai('à minuit le soleil est sous l\'horizon',
    voletautobeSun::sunPosition(mktime(0, 0, 0, 6, 21, 2026), LAT, LON)['elevation'] < 0);
verifieVrai('et très loin dessous en décembre',
    voletautobeSun::sunPosition(mktime(0, 0, 0, 12, 21, 2026), LAT, LON)['elevation'] < -50);

/* La réfraction relève le soleil rasant d'un demi-degré, soit tout le diamètre
 * du disque : sans elle, la hauteur rendue ne serait ni celle des éphémérides
 * ni celle qu'on voit par la fenêtre. */
verifieVrai('la réfraction relève le soleil à l\'horizon',
    abs(voletautobeSun::refraction(0) - 0.48) < 0.05);
verifie('et ne corrige rien au zénith', voletautobeSun::refraction(90), 0.0);

/* Sous nos latitudes, le soleil tourne dans le même sens toute la journée :
 * l'azimut croît du lever au coucher, sans jamais reculer. Un recul trahirait
 * un repliement manqué autour du méridien — l'après-midi rendu comme un matin,
 * et une façade ouest jamais protégée. */
$precedent = null;
$reculs    = 0;
for ($t = $ete['sunrise']; $t <= $ete['sunset']; $t += 300) {
    $azimut = voletautobeSun::sunPosition($t, LAT, LON)['azimuth'];
    if ($precedent !== null && $azimut <= $precedent) {
        $reculs++;
    }
    $precedent = $azimut;
}
verifie('l\'azimut croît du lever au coucher', $reculs, 0);

/*
 * Le fuseau du serveur ne doit rien changer : l'entrée est un horodatage, pas
 * une heure murale, et tout le calcul se fait en UTC. C'est le piège invisible
 * de ce lot — un calcul mené en heure locale se décalerait d'une heure du
 * dernier dimanche de mars au dernier d'octobre, la protection solaire
 * partirait une heure trop tard tout l'été, et rien, nulle part, ne lèverait
 * la moindre erreur.
 */
$instant   = mktime(15, 0, 0, 8, 4, 2026);
$bruxelles = voletautobeSun::sunPosition($instant, LAT, LON);
date_default_timezone_set('UTC');
$ailleurs = voletautobeSun::sunPosition($instant, LAT, LON);
date_default_timezone_set('Europe/Brussels');
verifieVrai('le fuseau du serveur ne déplace pas le soleil',
    abs($bruxelles['azimuth'] - $ailleurs['azimuth']) < 0.01);
verifieVrai('ni en hauteur', abs($bruxelles['elevation'] - $ailleurs['elevation']) < 0.01);

/* Sans position d'installation, il n'y a pas de position du soleil : rendre
 * zéro ferait calculer la course du soleil au large du golfe de Guinée, et la
 * condition de soleil filtrerait sur des valeurs inventées. */
verifie('sans latitude, pas d\'azimut',
        voletautobeSun::sunPosition($instant, null, null)['azimuth'], null);
verifie('ni de hauteur',
        voletautobeSun::sunPosition($instant, null, null)['elevation'], null);
verifie('une latitude impossible ne rend rien non plus',
        voletautobeSun::sunPosition($instant, 120, 4.35)['azimuth'], null);
/* Une configuration Jeedom non renseignée arrive en chaîne vide, et (float) ''
 * vaut zéro : sans ce contrôle, le plugin calculerait la course du soleil au
 * large du golfe de Guinée et la condition filtrerait sur un soleil
 * imaginaire, sans que rien ne signale l'absence de position. */
verifie('une position vide n\'est pas la latitude zéro',
        voletautobeSun::sunPosition($instant, '', '')['azimuth'], null);

/* ----------------------------------------------------------------- 11 ---
 * Le moment déclenché par la façade. L'azimut visé n'appartient pas au moment
 * mais au groupe : « le soleil arrive sur la façade » vise son azimut de
 * début, « il la quitte » celui de fin, et l'appelant a recopié les deux dans
 * le réglage avant d'appeler. Le calcul n'a pas de formule directe : on balaie
 * la journée par pas de dix minutes, puis on affine par dichotomie. */
echo "\nMoment déclenché par la façade\n";
$jourEte   = mktime(0, 0, 0, 6, 21, 2026);
$jourHiver = mktime(0, 0, 0, 12, 21, 2026);

verifie('le mode « arrivée sur la façade » est retenu',
        voletautobeSun::cleanSlot(array('mode' => 'facade_in'))['mode'],
        voletautobeSun::MODE_FACADE_IN);
verifie('le mode « départ de la façade » aussi',
        voletautobeSun::cleanSlot(array('mode' => 'facade_out'))['mode'],
        voletautobeSun::MODE_FACADE_OUT);
verifieVrai('les deux se reconnaissent ensemble',
    voletautobeSun::isFacadeMode(voletautobeSun::MODE_FACADE_IN)
    && voletautobeSun::isFacadeMode(voletautobeSun::MODE_FACADE_OUT));
verifieVrai('le coucher du soleil n\'en est pas un',
    !voletautobeSun::isFacadeMode(voletautobeSun::MODE_SUNSET));
/* L'ancien mode « azimut » et son champ propre au moment ont disparu : une
 * façade qui commence à 200° les remplace exactement, avec un réglage de
 * moins et une seule orientation écrite quelque part. Un réglage resté dans
 * l'ancien format retombe sur l'heure fixe — c'est la migration qui le
 * traduit, pas la normalisation. */
verifie('l\'ancien mode « azimut » n\'existe plus',
        voletautobeSun::cleanSlot(array('mode' => 'azimuth'))['mode'],
        voletautobeSun::MODE_FIXED);
verifieVrai('et le moment ne porte plus d\'azimut à lui',
    !array_key_exists('azimuth', voletautobeSun::emptySlot()));
verifieVrai('un azimut envoyé par erreur n\'est pas conservé',
    !array_key_exists('azimuth', voletautobeSun::cleanSlot(array('azimuth' => 200))));
/* La façade, elle, reste dans la forme normalisée du moment : la classe ne
 * connaît pas Jeedom et ne peut pas aller la chercher toute seule. */
verifie('la façade du groupe arrive avec le moment',
        voletautobeSun::cleanSlot(array('sun_from' => '202,5'))['sun_from'], 202.5);

/* Une façade sud-sud-ouest : le soleil y arrive au 200° et la quitte au 260°.
 * Les mêmes deux nombres servent au déclencheur et à la condition — c'est tout
 * l'objet de cette reprise. */
$facade = voletautobeSun::cleanSlot(array('enable' => 1, 'action' => 'position',
                                          'mode' => 'facade_in',
                                          'sun_from' => 200, 'sun_to' => 260));
$quand   = voletautobeSun::occurrence($facade, $jourEte, LAT, LON);
$atteint = voletautobeSun::sunPosition($quand, LAT, LON);
verifieVrai('à l\'heure trouvée, le soleil arrive bien au 200°',
    abs($atteint['azimuth'] - 200) < 0.2);
verifieVrai('et c\'est l\'après-midi', (int) date('H', $quand) >= 12);
/* Le même appel doit rendre la même heure : une dichotomie qui partirait d'un
 * tirage ou d'un « maintenant » ferait danser l'heure annoncée dans
 * l'interface d'un rafraîchissement à l'autre. */
verifie('deux appels, même heure', voletautobeSun::occurrence($facade, $jourEte, LAT, LON), $quand);

$sortie = voletautobeSun::cleanSlot(array('enable' => 1, 'action' => 'up',
                                          'mode' => 'facade_out',
                                          'sun_from' => 200, 'sun_to' => 260));
$fin    = voletautobeSun::occurrence($sortie, $jourEte, LAT, LON);
$quitte = voletautobeSun::sunPosition($fin, LAT, LON);
verifieVrai('et le soleil quitte la façade au 260°', abs($quitte['azimuth'] - 260) < 0.2);
/* La fin de protection vient forcément après son début : si les deux modes
 * lisaient le même champ, les volets rouvriraient à l'heure où ils auraient dû
 * se fermer, et personne ne comprendrait pourquoi. */
verifieVrai('la fin de protection vient après le début', $fin > $quand);
$autreFin = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_in',
                                            'sun_from' => 200, 'sun_to' => 300));
verifie('l\'arrivée ne dépend que de l\'azimut de début',
        voletautobeSun::occurrence($autreFin, $jourEte, LAT, LON), $quand);

$plein = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_in',
                                         'sun_from' => 180, 'sun_to' => 270));
verifieVrai('une façade plein sud est prise au midi solaire de juin',
    abs(voletautobeSun::occurrence($plein, $jourEte, LAT, LON) - $midiEte) < 60);
verifieVrai('et à celui de décembre',
    abs(voletautobeSun::occurrence($plein, $jourHiver, LAT, LON) - $midiHiver) < 60);

/*
 * La façade que le soleil n'atteint jamais. À Bruxelles le 21 décembre, il se
 * lève déjà au 128° : une façade plein est, de 45° à 110°, ne voit pas le
 * soleil de la journée. Elle ne doit se replier ni sur minuit ni sur le lever,
 * sinon sa protection solaire partirait tous les jours de l'hiver, en pleine
 * nuit, sans que rien ne l'explique.
 */
$est = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_in',
                                       'sun_from' => 45, 'sun_to' => 110,
                                       'sun_elevation' => 5));
$jamais = voletautobeSun::occurrence($est, $jourHiver, LAT, LON);
verifieVrai('une façade plein est n\'est pas atteinte en décembre', $jamais === null);
verifieVrai('et ce n\'est pas un zéro qui passerait pour minuit', $jamais !== 0);
verifieVrai('ni l\'heure du lever, faute de mieux', $jamais !== $hiver['sunrise']);
verifieVrai('ni minuit de ce jour-là', $jamais !== $jourHiver);
verifieVrai('alors qu\'en juin, si',
    voletautobeSun::occurrence($est, $jourEte, LAT, LON) !== null);
/* Et puisque le départ se cherche à partir de l'arrivée, il n'a pas lieu non
 * plus : une fin de protection sans protection n'aurait aucun sens. */
$estSortie = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_out',
                                             'sun_from' => 45, 'sun_to' => 110,
                                             'sun_elevation' => 5));
verifieVrai('et le départ n\'a pas lieu non plus ce jour-là',
    voletautobeSun::occurrence($estSortie, $jourHiver, LAT, LON) === null);

/*
 * La hauteur minimale que le soleil n'atteint pas du jour. C'est l'autre façon
 * de n'être jamais sur la façade, et elle ne se voit pas sur l'azimut : le
 * 21 décembre à Bruxelles le soleil passe bien du sud-est au sud-ouest, mais
 * il culmine à 15,8° et une façade qui demande 40° ne le voit pas.
 */
$tropHaut = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_in',
                                            'sun_from' => 135, 'sun_to' => 315,
                                            'sun_elevation' => 40));
$tropHautFin = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_out',
                                               'sun_from' => 135, 'sun_to' => 315,
                                               'sun_elevation' => 40));
verifieVrai('une hauteur jamais atteinte : pas d\'arrivée',
    voletautobeSun::occurrence($tropHaut, $jourHiver, LAT, LON) === null);
verifieVrai('et pas de départ non plus',
    voletautobeSun::occurrence($tropHautFin, $jourHiver, LAT, LON) === null);
verifieVrai('alors que le même réglage tient en juin',
    voletautobeSun::occurrence($tropHaut, $jourEte, LAT, LON) !== null);
$fenetreVide = voletautobeSun::facadeWindow($tropHaut, $jourHiver, LAT, LON);
verifieVrai('l\'intervalle entier est vide, franchement',
    $fenetreVide['in'] === null && $fenetreVide['out'] === null);

/* Le même cas par l'autre bout : en décembre, quand le soleil tourne enfin au
 * sud-ouest, il rase à moins de 15° et n'est donc jamais sur cette façade-là.
 * La fin de protection n'a pas lieu, et c'est juste : la protection elle-même
 * n'a pas eu lieu non plus. */
$jusquAuCoucher = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_out',
                                                  'sun_from' => 200, 'sun_to' => 300));
verifieVrai('le soleil ne quitte pas cette façade en décembre',
    voletautobeSun::occurrence($jusquAuCoucher, $jourHiver, LAT, LON) === null);
verifieVrai('mais il la quitte en juin',
    voletautobeSun::occurrence($jusquAuCoucher, $jourEte, LAT, LON) !== null);

/* Le soleil déjà sur la façade en se levant y arrive à l'heure du lever, et
 * pas dix minutes plus tard : le balayage doit accepter la borne de son
 * premier intervalle. La façade descend ici jusqu'à l'horizon — une hauteur
 * minimale retarderait l'arrivée, et c'est le contrôle suivant qui le dit. */
$auLever = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_in',
                                           'sun_from' => $leverEte['azimuth'] - 5,
                                           'sun_to' => 300,
                                           'sun_elevation' => -10));
verifieVrai('une façade prise dès le lever est datée de l\'heure du lever',
    abs(voletautobeSun::occurrence($auLever, $jourEte, LAT, LON) - $ete['sunrise']) < 60);
/* La même façade avec une hauteur minimale : l'arrivée n'est plus le lever
 * mais le passage au-dessus de la hauteur, le soleil étant déjà dans la
 * fenêtre d'azimut en se levant. C'est la moitié du modèle que la première
 * version oubliait. */
$auLeverHaut = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_in',
                                               'sun_from' => $leverEte['azimuth'] - 5,
                                               'sun_to' => 300,
                                               'sun_elevation' => 10));
$arriveeHaute = voletautobeSun::occurrence($auLeverHaut, $jourEte, LAT, LON);
verifieVrai('avec une hauteur minimale, l\'arrivée est plus tard',
    $arriveeHaute > $ete['sunrise'] + 1800);
verifieVrai('et c\'est très exactement le passage au-dessus de la hauteur',
    abs(voletautobeSun::sunPosition($arriveeHaute, LAT, LON)['elevation'] - 10) < 0.05);

/*
 * Les trois façons de quitter une façade, et c'est tout l'objet de cette
 * reprise. Le soleil s'en va par le côté, par le bas, ou parce qu'il se
 * couche — la première des trois qui survient. Ne viser que l'azimut de fin
 * laissait la fin de protection sans date la moitié de l'année ; avec la
 * façade livrée par défaut, qui va jusqu'au 315°, elle n'en avait aucun jour.
 */
$parAzimut     = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_out',
                                                 'sun_from' => 90, 'sun_to' => 180,
                                                 'sun_elevation' => 15));
$sortieAzimut  = voletautobeSun::occurrence($parAzimut, $jourEte, LAT, LON);
$positionSortie = voletautobeSun::sunPosition($sortieAzimut, LAT, LON);
verifieVrai('une façade est se quitte au 180°, par l\'azimut',
    abs($positionSortie['azimuth'] - 180) < 0.2);
verifieVrai('et non au coucher, huit heures plus tard',
    $sortieAzimut < $ete['sunset'] - 8 * 3600);
verifieVrai('le soleil y est même au plus haut de sa journée',
    $positionSortie['elevation'] > 60);

$parHauteur     = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_out',
                                                  'sun_from' => 135, 'sun_to' => 315,
                                                  'sun_elevation' => 15));
$sortieHauteur  = voletautobeSun::occurrence($parHauteur, $jourEte, LAT, LON);
$positionSortie = voletautobeSun::sunPosition($sortieHauteur, LAT, LON);
verifieVrai('la façade par défaut, elle, se quitte par la hauteur',
    abs($positionSortie['elevation'] - 15) < 0.05);
verifieVrai('son azimut étant encore loin du 315°',
    $positionSortie['azimuth'] < 300);
verifieVrai('et le soleil se couchant près de deux heures plus tard',
    $sortieHauteur < $ete['sunset'] - 3600);

/* Et le coucher, quand ni l'un ni l'autre ne survient : en décembre, le soleil
 * se couche au 232° sans avoir atteint le 315° ni être passé sous l'horizon
 * avant l'heure. C'est le coucher qui le fait quitter la façade, à la seconde
 * où date_sun_info le place. */
$parCoucher = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_out',
                                              'sun_from' => 200, 'sun_to' => 315,
                                              'sun_elevation' => -10));
verifie('faute de mieux, le soleil quitte la façade en se couchant',
        voletautobeSun::occurrence($parCoucher, $jourHiver, LAT, LON), $hiver['sunset']);

/* « 20 minutes après que le soleil arrive sur la façade » : le temps qu'elle
 * chauffe. Le décalage s'ajoute comme pour le coucher du soleil, et il se
 * retranche tout aussi bien de la fin de protection. */
$apresFacade = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_in',
                                               'sun_from' => 200, 'sun_to' => 260,
                                               'offset' => 20));
verifie('le décalage s\'ajoute à l\'arrivée sur la façade',
        voletautobeSun::occurrence($apresFacade, $jourEte, LAT, LON), $quand + 1200);
$avantFin = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_out',
                                            'sun_from' => 200, 'sun_to' => 260,
                                            'offset' => -15));
verifie('et se retranche du départ',
        voletautobeSun::occurrence($avantFin, $jourEte, LAT, LON), $fin - 900);

/* Les garde-fous et les jours de semaine valent aussi ici : ils s'appliquent
 * après le calcul, comme pour tous les autres modes. */
$mardiSeul = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_in',
                                             'sun_from' => 200, 'days' => array(2)));
verifie('jour non coché : rien, même en mode façade',
        voletautobeSun::occurrence($mardiSeul, $jourHiver, LAT, LON), null);
$gardeFacade = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_in',
                                               'sun_from' => 200, 'sun_elevation' => 0,
                                               'not_before' => '16:00'));
verifie('le plancher s\'applique aussi au mode façade',
        heure(voletautobeSun::occurrence($gardeFacade, $jourHiver, LAT, LON)), '2026-12-21 16:00');

/* Au Svalbard en juin le soleil ne se couche pas, et sun() ne rend ni lever ni
 * coucher : sans fenêtre à balayer, il n'y a pas d'heure à annoncer. */
verifie('au Svalbard en juin, aucune façade n\'est datée',
        voletautobeSun::occurrence($plein, $jourEte, 78.2, 15.6), null);

/*
 * Une année entière, et l'invariant qui compte : dès qu'il y a une arrivée, il
 * y a un départ. C'est ce qui manquait — une protection solaire qui se ferme
 * sans jamais se rouvrir est pire que pas de protection du tout.
 *
 * La façade sud-sud-ouest de 200° à 260°, avec les 15° de hauteur minimale par
 * défaut, n'est prise que 321 jours sur 365 : en plein hiver, quand le soleil
 * tourne enfin au sud-ouest, il rase déjà à moins de 15°. Un déclencheur de
 * façade peut légitimement ne rien donner certains jours, et le moment est
 * alors simplement absent du programme. Une façade plein est, elle, n'est
 * éclairée qu'à la belle saison : le soleil d'hiver se lève déjà trop au sud.
 */
$manquants = 0;
$atteints  = 0;
$sansFin   = 0;
$inverses  = 0;
for ($j = 0; $j < 365; $j++) {
    $base   = strtotime('2026-01-01 +' . $j . ' day');
    $debut  = voletautobeSun::occurrence($facade, $base, LAT, LON, 'annee');
    $depart = voletautobeSun::occurrence($sortie, $base, LAT, LON, 'annee');
    if (voletautobeSun::occurrence($est, $base, LAT, LON, 'annee') !== null) {
        $atteints++;
    }
    if ($debut === null) {
        $manquants++;
        /* Un départ sans arrivée : les volets rouvriraient sans s'être
         * fermés, ce qui est aussi faux que l'inverse. */
        if ($depart !== null) {
            $inverses++;
        }
        continue;
    }
    if ($depart === null) {
        $sansFin++;
    } elseif ($depart <= $debut) {
        $inverses++;
    }
}
verifie('la façade sud-sud-ouest n\'est prise que 321 jours sur 365',
        365 - $manquants, 321);
verifieVrai('la façade plein est seulement la belle saison',
    $atteints > 200 && $atteints < 260);
/* L'invariant, et le défaut corrigé : le soleil finit toujours par quitter la
 * façade — par le côté, par le bas, ou en se couchant. Un jour où il y arrive
 * sans jamais en partir n'existe pas. */
verifie('jamais d\'arrivée sans départ, aucun jour de l\'année', $sansFin, 0);
/* Et jamais, aucun jour de l'année, la fin de protection ne précède le début :
 * ce serait rouvrir les volets avant de les avoir fermés. */
verifie('la fin de protection ne précède jamais le début', $inverses, 0);

/*
 * La façade livrée par défaut, à la latitude de l'installation. C'est la
 * mesure qui a fait découvrir le défaut : à 50,5476° de latitude, le soleil ne
 * se couche jamais au-delà du 310,1° — 232,5° au solstice d'hiver, 310,1° à
 * celui d'été. La façade par défaut va jusqu'au 315° : viser cet azimut,
 * c'était attendre un instant qui n'arrive aucun jour de l'année, et la fin de
 * protection ne rouvrait jamais rien.
 *
 * Seule la latitude compte ici — c'est elle qui borne la course du soleil ; la
 * longitude ne fait que décaler l'horloge.
 */
echo "\nLa façade livrée par défaut, à la latitude de l'installation\n";
$latMaison = 50.5476;
$lonMaison = 5.0;
$coucherMaximal = 0.0;
for ($j = 0; $j < 365; $j++) {
    $base    = strtotime('2026-01-01 +' . $j . ' day');
    $journee = voletautobeSun::sun($base, $latMaison, $lonMaison);
    $azimut  = voletautobeSun::sunPosition($journee['sunset'], $latMaison, $lonMaison)['azimuth'];
    if ($azimut > $coucherMaximal) {
        $coucherMaximal = $azimut;
    }
}
verifieVrai('le soleil ne se couche jamais au-delà du 310,1°',
    $coucherMaximal > 310.0 && $coucherMaximal < 310.2);
verifieVrai('le 315° de la façade par défaut n\'est donc jamais atteint',
    $coucherMaximal < voletautobeSun::emptySlot()['sun_to']);

$defautDebut = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_in'));
$defautFin   = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_out'));
verifie('c\'est bien la façade par défaut, sans rien y toucher',
        $defautDebut['sun_from'] . '-' . $defautDebut['sun_to'] . '@' . $defautDebut['sun_elevation'],
        '135-315@15');

$joursHauts = 0;
$sansDebut  = 0;
$sansDepart = 0;
$inverses   = 0;
$plusCourte = null;
$jourCourt  = '';
for ($j = 0; $j < 365; $j++) {
    $base    = strtotime('2026-01-01 +' . $j . ' day');
    $journee = @date_sun_info(strtotime(date('Y-m-d', $base) . ' 12:00'), $latMaison, $lonMaison);
    $culmine = voletautobeSun::sunPosition($journee['transit'], $latMaison, $lonMaison)['elevation'];
    if ($culmine < $defautDebut['sun_elevation']) {
        continue;
    }
    $joursHauts++;
    $debut  = voletautobeSun::occurrence($defautDebut, $base, $latMaison, $lonMaison, 'maison');
    $depart = voletautobeSun::occurrence($defautFin, $base, $latMaison, $lonMaison, 'maison');
    if ($debut === null) {
        $sansDebut++;
        continue;
    }
    if ($depart === null) {
        $sansDepart++;
        continue;
    }
    if ($depart <= $debut) {
        $inverses++;
    }
    if ($plusCourte === null || ($depart - $debut) < $plusCourte) {
        $plusCourte = $depart - $debut;
        $jourCourt  = date('Y-m-d', $base);
    }
}
/* À cette latitude le soleil monte au-dessus de 15° tous les jours de
 * l'année : il culmine encore à 16,1° au solstice d'hiver. */
verifie('le soleil passe les 15° les 365 jours de l\'année', $joursHauts, 365);
verifie('la protection a un début chacun de ces jours', $sansDebut, 0);
verifie('et une fin chacun de ces jours', $sansDepart, 0);
verifie('la fin suivant toujours le début', $inverses, 0);
/* La journée la plus courte est celle du solstice d'hiver, et la fenêtre y
 * dure 1 h 54 min 29 s — 6 869 secondes mesurées, d'un 166,4° à 15,00° de
 * hauteur jusqu'à un 193,6° à 15,00° : c'est la hauteur qui ouvre la fenêtre
 * et la hauteur qui la referme, l'azimut n'y est jamais pour rien. La fenêtre
 * existe donc bel et bien, et elle est courte, ce qui est exactement ce qu'on
 * veut : les volets se ferment à midi et se rouvrent au début de
 * l'après-midi. */
verifie('la plus courte fenêtre de l\'année tombe au solstice d\'hiver',
        $jourCourt, '2026-12-21');
verifieVrai('et elle dure 1 h 54 min (6 869 s mesurées)',
    abs($plusCourte - 6869) < 30);
$solstice  = mktime(0, 0, 0, 12, 21, 2026);
$fenetreHiver = voletautobeSun::facadeWindow($defautDebut, $solstice, $latMaison, $lonMaison);
$entree = voletautobeSun::sunPosition($fenetreHiver['in'], $latMaison, $lonMaison);
$sortieH = voletautobeSun::sunPosition($fenetreHiver['out'], $latMaison, $lonMaison);
verifieVrai('elle s\'ouvre sur la hauteur, à 15° pile',
    abs($entree['elevation'] - 15) < 0.05);
verifieVrai('et se referme sur la hauteur, à 15° pile aussi',
    abs($sortieH['elevation'] - 15) < 0.05);
verifieVrai('l\'azimut, lui, reste au sud d\'un bout à l\'autre',
    $entree['azimuth'] > 160 && $sortieH['azimuth'] < 200);
/* Au solstice d'été, la même façade tient huit heures, et c'est la hauteur qui
 * la referme là encore, une heure et demie avant le coucher. */
$fenetreEte = voletautobeSun::facadeWindow($defautDebut, mktime(0, 0, 0, 6, 21, 2026), $latMaison, $lonMaison);
verifieVrai('au solstice d\'été, la même fenêtre dure huit heures (28 788 s)',
    abs(($fenetreEte['out'] - $fenetreEte['in']) - 28788) < 60);
verifieVrai('elle s\'ouvre au 135°, sur l\'azimut cette fois',
    abs(voletautobeSun::sunPosition($fenetreEte['in'], $latMaison, $lonMaison)['azimuth'] - 135) < 0.2);
verifieVrai('et se referme sur la hauteur, avant le coucher',
    $fenetreEte['out'] < voletautobeSun::sun(mktime(0, 0, 0, 6, 21, 2026), $latMaison, $lonMaison)['sunset'] - 3600);

/* ----------------------------------------------------------------- 12 ---
 * La condition de soleil, et les noms de direction. C'est le pendant de la
 * condition de température : le moment est joué, mais seulement si le soleil
 * est réellement sur cette façade-là. */
echo "\nCondition de soleil\n";
$sansSoleil = voletautobeSun::cleanSlot(array('enable' => 1, 'sun_mode' => 'none'));
/* Un moment à heure fixe : « à 13 h, seulement si le soleil est sur la
 * façade ». C'est le cas où la fenêtre filtre vraiment, et il faut le dire ici
 * puisque ce n'est plus vrai de tous les moments — un déclencheur de façade,
 * lui, a déjà posé le soleil sur sa borne. */
$facadeSO   = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'fixed',
                                              'time' => '13:00', 'sun_mode' => 'window',
                                              'sun_from' => 135, 'sun_to' => 315,
                                              'sun_elevation' => 15));
verifie('fenêtre par défaut : du sud-est au nord-ouest',
        voletautobeSun::emptySlot()['sun_from'], 135.0);
verifie('jusqu\'au 315°', voletautobeSun::emptySlot()['sun_to'], 315.0);
verifie('hauteur minimale par défaut à 15°',
        voletautobeSun::emptySlot()['sun_elevation'], 15.0);
verifie('aucune condition de soleil par défaut',
        voletautobeSun::emptySlot()['sun_mode'], voletautobeSun::SUN_NONE);
verifie('condition de soleil inconnue retombe sur aucune',
        voletautobeSun::cleanSlot(array('sun_mode' => 'plein'))['sun_mode'], voletautobeSun::SUN_NONE);
verifie('azimut de début borné',
        voletautobeSun::cleanSlot(array('sun_from' => '400'))['sun_from'], 360.0);
verifie('azimut de fin borné par le bas',
        voletautobeSun::cleanSlot(array('sun_to' => '-20'))['sun_to'], 0.0);
verifie('hauteur minimale bornée par le bas',
        voletautobeSun::cleanSlot(array('sun_elevation' => '-90'))['sun_elevation'], -10.0);
verifie('et par le haut',
        voletautobeSun::cleanSlot(array('sun_elevation' => '200'))['sun_elevation'], 90.0);
verifie('une hauteur à virgule est comprise',
        voletautobeSun::cleanSlot(array('sun_elevation' => '12,5'))['sun_elevation'], 12.5);

$verdict = voletautobeSun::sunCheck($sansSoleil, 12.0, 40.0);
verifie('aucune condition : on bouge', $verdict['met'], true);
verifie('et la raison le dit', $verdict['reason'], 'none');
verifie('aucune condition : rien à savoir', $verdict['known'], true);

$verdict = voletautobeSun::sunCheck($facadeSO, 200.0, 35.0);
verifie('soleil sur la façade : on ferme', $verdict['met'], true);
verifie('raison ok', $verdict['reason'], 'ok');
$verdict = voletautobeSun::sunCheck($facadeSO, 100.0, 35.0);
verifie('soleil à l\'est : on ne ferme pas', $verdict['met'], false);
verifie('et on sait pourquoi', $verdict['reason'], 'azimuth_out');
$verdict = voletautobeSun::sunCheck($facadeSO, 200.0, 8.4);
verifie('soleil trop bas : on ne ferme pas', $verdict['met'], false);
verifie('et on sait pourquoi', $verdict['reason'], 'too_low');
verifie('le seuil de hauteur pile compte comme rempli',
        voletautobeSun::sunCheck($facadeSO, 200.0, 15.0)['met'], true);
verifie('un dixième de degré sous le seuil ne passe pas',
        voletautobeSun::sunCheck($facadeSO, 200.0, 14.9)['reason'], 'too_low');
verifie('les bornes de la fenêtre sont dedans',
        voletautobeSun::sunCheck($facadeSO, 135.0, 35.0)['met'], true);
verifie('l\'autre borne aussi',
        voletautobeSun::sunCheck($facadeSO, 315.0, 35.0)['met'], true);
verifie('un dixième de degré au-delà, dehors',
        voletautobeSun::sunCheck($facadeSO, 315.1, 35.0)['met'], false);

/* La hauteur se teste avant l'azimut. En pleine nuit le soleil a un azimut
 * parfaitement défini et parfaitement hors sujet : annoncer « hors de la
 * fenêtre » enverrait l'utilisateur corriger une fenêtre qui n'a rien de faux,
 * alors que la vraie raison est qu'il fait nuit. */
verifie('la nuit, la raison est « trop bas » et non « hors fenêtre »',
        voletautobeSun::sunCheck($facadeSO, 100.0, -20.0)['reason'], 'too_low');

/*
 * La position inconnue se traite comme la sonde muette : on bouge quand même.
 * Le cas se produit quand la position de l'installation n'est pas renseignée,
 * et si « on ne sait pas » empêchait le mouvement, la protection solaire ne se
 * jouerait jamais sans que rien ne l'explique.
 */
$verdict = voletautobeSun::sunCheck($facadeSO, null, null);
verifie('position inconnue : on bouge quand même', $verdict['met'], true);
verifie('mais on note qu\'on ne savait pas', $verdict['known'], false);
verifie('raison inconnue', $verdict['reason'], 'unknown');
verifie('un azimut sans hauteur ne suffit pas',
        voletautobeSun::sunCheck($facadeSO, 200.0, null)['reason'], 'unknown');
verifie('une hauteur sans azimut non plus',
        voletautobeSun::sunCheck($facadeSO, null, 35.0)['reason'], 'unknown');
verifie('sans condition, une position inconnue n\'est pas un sujet',
        voletautobeSun::sunCheck($sansSoleil, null, null)['reason'], 'none');
/* Un azimut de zéro est une direction — le nord — et non une absence de
 * mesure : les confondre rendrait la condition muette chaque fois que le
 * soleil passe au nord, c'est-à-dire en pleine nuit d'été. */
verifie('un azimut de zéro reste une mesure',
        voletautobeSun::sunCheck($facadeSO, 0.0, 35.0)['known'], true);

/*
 * La fenêtre qui passe par le nord. « De 300° à 30° » est une façade
 * nord-ouest–nord-est : elle doit contenir 350°, pas l'exclure. Un simple
 * « from <= a && a <= to » la rendrait toujours vide, la condition ne serait
 * jamais remplie, et le réglage aurait pourtant l'air juste dans l'interface.
 */
$facadeNord = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'fixed',
                                              'time' => '13:00', 'sun_mode' => 'window',
                                              'sun_from' => 300, 'sun_to' => 30,
                                              'sun_elevation' => 0));
verifie('la fenêtre passe par le nord : 350° est dedans',
        voletautobeSun::sunCheck($facadeNord, 350.0, 20.0)['met'], true);
verifie('310° aussi', voletautobeSun::sunCheck($facadeNord, 310.0, 20.0)['met'], true);
verifie('le nord lui-même aussi', voletautobeSun::sunCheck($facadeNord, 0.0, 20.0)['met'], true);
verifie('et 20°, de l\'autre côté', voletautobeSun::sunCheck($facadeNord, 20.0, 20.0)['met'], true);
verifie('mais le sud est dehors', voletautobeSun::sunCheck($facadeNord, 180.0, 20.0)['met'], false);
verifie('et l\'est aussi', voletautobeSun::sunCheck($facadeNord, 100.0, 20.0)['reason'], 'azimuth_out');
/* Et dans l'autre sens, la même fenêtre lue à l'endroit exclut ce que la
 * précédente contenait : les deux écritures ne doivent pas se confondre. */
verifieVrai('de 30° à 300°, 350° est dehors', !voletautobeSun::azimuthInWindow(350, 30, 300));
verifieVrai('et 180° est dedans', voletautobeSun::azimuthInWindow(180, 30, 300));
/* Un début égal à la fin est une fenêtre vide, pas le tour complet : c'est une
 * erreur de saisie, et fermer les volets à toute heure du jour serait la pire
 * façon de l'apprendre à son auteur. */
verifieVrai('début égal à la fin : fenêtre vide',
    !voletautobeSun::azimuthInWindow(200, 180, 180));
verifieVrai('pas même son propre azimut',
    !voletautobeSun::azimuthInWindow(180, 180, 180));

/* Le cas réel qui justifie la hauteur minimale : le 21 décembre à 15 h, le
 * soleil est bien sur la façade sud-ouest, mais il rase à moins de 10° — il
 * passe derrière les maisons d'en face, et fermer les volets à ce moment-là ne
 * protège de rien. */
$decembre = voletautobeSun::sunPosition(strtotime('2026-12-21 15:00'), LAT, LON);
verifieVrai('un après-midi de décembre, le soleil est sur la façade',
    voletautobeSun::azimuthInWindow($decembre['azimuth'], 135, 315));
verifie('mais trop bas pour qu\'on ferme',
        voletautobeSun::sunCheck($facadeSO, $decembre['azimuth'], $decembre['elevation'])['reason'], 'too_low');
$juillet = voletautobeSun::sunPosition(strtotime('2026-06-21 15:00'), LAT, LON);
verifie('le même quart d\'heure en juin, on ferme',
        voletautobeSun::sunCheck($facadeSO, $juillet['azimuth'], $juillet['elevation'])['reason'], 'ok');

/*
 * Le doublon, et c'est le contrôle le plus important de cette section.
 *
 * Quand c'est la façade qui déclenche le moment, la condition de soleil n'a
 * plus rien à filtrer du tout. Le déclencheur ne vise plus un azimut : il rend
 * l'instant où le soleil arrive sur la façade, ou celui où il la quitte, et
 * « être sur la façade » veut dire azimut dans la fenêtre *et* hauteur
 * au-dessus du seuil. Les deux moitiés de la condition sont donc vraies par
 * construction à la seconde où le moment tombe.
 *
 * Les retester serait pire qu'inutile : la dichotomie s'arrête à la seconde
 * près, et le soleil se retrouve aussi bien un millième de degré en deçà de la
 * limite qu'au-delà. Ce serait jouer le moment à pile ou face — un « soleil
 * hors de la fenêtre », ou un « soleil trop bas », un jour sur deux, sur un
 * réglage parfaitement juste, sans rien dans l'interface pour l'expliquer. Et
 * la fin de protection, elle, serait sautée tous les jours : au moment où le
 * soleil quitte la façade, il n'y est par définition plus.
 */
echo "\nCondition de soleil et déclencheur de façade\n";
$arrivee = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_in',
                                           'sun_mode' => 'window',
                                           'sun_from' => 135, 'sun_to' => 315,
                                           'sun_elevation' => 15));
$verdict = voletautobeSun::sunCheck($arrivee, 135.0, 35.0);
verifie('à la limite exacte de la fenêtre, le moment se joue', $verdict['met'], true);
verifie('et la raison le dit : il n\'y a plus de condition', $verdict['reason'], 'none');
/* Le millième de degré en deçà : très exactement ce que rend une dichotomie à
 * la seconde près, et très exactement ce qui aurait sauté un jour sur deux. */
verifie('un millième de degré en deçà ne change rien',
        voletautobeSun::sunCheck($arrivee, 134.999, 35.0)['met'], true);
verifie('l\'azimut n\'est plus évalué du tout',
        voletautobeSun::sunCheck($arrivee, 10.0, 35.0)['met'], true);
verifie('« hors de la fenêtre » ne peut plus être la raison',
        voletautobeSun::sunCheck($arrivee, 10.0, 35.0)['reason'], 'none');
/* La hauteur non plus, et c'est la nouveauté : le déclencheur la garantit
 * désormais lui-même, puisqu'il ne retient que les instants où le soleil est
 * assez haut pour éclairer la façade. La retester ici ferait le même pile ou
 * face au millième de degré, sur une fenêtre d'hiver qui s'ouvre et se referme
 * très exactement sur ce seuil-là. */
$verdict = voletautobeSun::sunCheck($arrivee, 135.0, 8.4);
verifie('la hauteur non plus n\'est plus évaluée', $verdict['met'], true);
verifie('et la raison reste « aucune »', $verdict['reason'], 'none');
verifie('un dixième de degré sous le seuil ne saute plus rien',
        voletautobeSun::sunCheck($arrivee, 135.0, 14.9)['reason'], 'none');
verifie('ni même un soleil donné sous l\'horizon',
        voletautobeSun::sunCheck($arrivee, 200.0, -20.0)['met'], true);
/* Position inconnue : il n'y a plus rien qu'on ignore, puisqu'il n'y a plus
 * rien à savoir. On bouge, et le journal n'a pas de doute à signaler. */
verifie('position inconnue : on bouge quand même',
        voletautobeSun::sunCheck($arrivee, null, null)['met'], true);
verifie('et il n\'y a plus rien qu\'on ignore',
        voletautobeSun::sunCheck($arrivee, null, null)['known'], true);
verifie('la raison restant « aucune »',
        voletautobeSun::sunCheck($arrivee, null, null)['reason'], 'none');

/* La fin de protection est le cas le plus net : au moment où le soleil quitte
 * la façade, il n'y est par définition plus — ni par l'azimut, ni par la
 * hauteur, ni parce qu'il se couche. Poser la fenêtre en condition la ferait
 * sauter tous les jours, et les volets resteraient à 30 % jusqu'au soir :
 * l'exact contraire de ce qu'on voulait. */
$depart = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_out',
                                          'sun_mode' => 'window',
                                          'sun_from' => 135, 'sun_to' => 315,
                                          'sun_elevation' => 5));
verifie('la fin de protection n\'est pas sautée par sa propre fenêtre',
        voletautobeSun::sunCheck($depart, 315.0, 20.0)['met'], true);
verifie('ni un dixième de degré au-delà',
        voletautobeSun::sunCheck($depart, 315.1, 20.0)['met'], true);
/* Et surtout pas par sa propre hauteur minimale : une façade quittée par le
 * bas l'est très exactement au seuil, et une façade quittée au coucher l'est
 * bien en dessous. C'est là que la fin de protection se perdait. */
verifie('ni par la hauteur, quittée très exactement au seuil',
        voletautobeSun::sunCheck($depart, 250.0, 5.0)['met'], true);
verifie('ni par un soleil déjà couché',
        voletautobeSun::sunCheck($depart, 232.0, -0.3)['reason'], 'none');

/*
 * Et cela, quelles que soient la position donnée et la fenêtre réglée : sur
 * les deux modes de façade, la condition de soleil ne rend plus jamais autre
 * chose que « aucune ». Sept positions — dont les deux limites, un soleil de
 * nuit et une position inconnue — par quatre fenêtres, dont celle qui passe
 * par le nord et celle, vide, dont les deux bornes sont égales.
 */
$positions = array(array(135.0, 35.0), array(315.0, 20.0), array(315.1, 20.0),
                   array(10.0, 35.0), array(200.0, -20.0), array(0.0, 0.0),
                   array(null, null));
$fenetres  = array(array(135, 315, 15), array(300, 30, 0),
                   array(180, 180, 40), array(0, 360, -10));
$autreQueAucune = 0;
$essais         = 0;
foreach (array(voletautobeSun::MODE_FACADE_IN, voletautobeSun::MODE_FACADE_OUT) as $mode) {
    foreach ($fenetres as $fenetre) {
        $reglage = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => $mode,
                                                   'sun_mode' => 'window',
                                                   'sun_from' => $fenetre[0],
                                                   'sun_to' => $fenetre[1],
                                                   'sun_elevation' => $fenetre[2]));
        foreach ($positions as $position) {
            $essais++;
            $verdict = voletautobeSun::sunCheck($reglage, $position[0], $position[1]);
            if ($verdict['reason'] !== 'none' || $verdict['met'] !== true || $verdict['known'] !== true) {
                $autreQueAucune++;
            }
        }
    }
}
verifie('cinquante-six combinaisons éprouvées', $essais, 56);
verifie('et pas une qui filtre quoi que ce soit', $autreQueAucune, 0);

/*
 * Et le même 315,1°, sur un moment à heure fixe, est bien dehors : la fenêtre
 * garde tout son sens dès que le déclencheur ne vise pas la façade. « À 13 h,
 * seulement si le soleil est sur la façade » est un réglage parfaitement
 * utile, et c'est lui qu'on protège en ne coupant l'azimut que pour les deux
 * modes de façade.
 */
verifie('à heure fixe, le même 315,1° est bien dehors',
        voletautobeSun::sunCheck($facadeSO, 315.1, 20.0)['reason'], 'azimuth_out');
verifie('et le 10° aussi',
        voletautobeSun::sunCheck($facadeSO, 10.0, 35.0)['reason'], 'azimuth_out');
verifie('tandis que le 200° passe', voletautobeSun::sunCheck($facadeSO, 200.0, 35.0)['met'], true);
/* Y compris une fenêtre qui passe par le nord : elle non plus ne se confond
 * pas avec un déclencheur de façade. */
verifie('à heure fixe, la fenêtre par le nord tient toujours',
        voletautobeSun::sunCheck($facadeNord, 350.0, 20.0)['met'], true);
verifie('et exclut toujours le sud',
        voletautobeSun::sunCheck($facadeNord, 180.0, 20.0)['reason'], 'azimuth_out');
/* Le lever et le coucher du soleil ne visent aucun azimut : leur fenêtre
 * filtre comme celle d'une heure fixe. */
$soir = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'sunset',
                                        'sun_mode' => 'window',
                                        'sun_from' => 135, 'sun_to' => 315,
                                        'sun_elevation' => 0));
verifie('au coucher du soleil, l\'azimut filtre encore',
        voletautobeSun::sunCheck($soir, 100.0, 20.0)['reason'], 'azimuth_out');

/* Sans condition posée, un déclencheur de façade ne filtre plus rien du tout,
 * pas même la hauteur : c'est le réglage par défaut de la fin de protection,
 * et c'est voulu. */
$departSansCondition = voletautobeSun::cleanSlot(array('enable' => 1, 'mode' => 'facade_out',
                                                       'sun_mode' => 'none'));
verifie('sans condition, la fin de protection se joue quoi qu\'il arrive',
        voletautobeSun::sunCheck($departSansCondition, 315.0, -5.0)['met'], true);
verifie('et la raison reste « aucune »',
        voletautobeSun::sunCheck($departSansCondition, 315.0, -5.0)['reason'], 'none');

echo "\nNoms de direction\n";
verifie('seize secteurs', count(voletautobeSun::$_compass), 16);
verifie('0° au nord', voletautobeSun::compassName(0), 'nord');
verifie('90° à l\'est', voletautobeSun::compassName(90), 'est');
verifie('180° au sud', voletautobeSun::compassName(180), 'sud');
verifie('270° à l\'ouest', voletautobeSun::compassName(270), 'ouest');
verifie('200° est sud-sud-ouest', voletautobeSun::compassName(200), 'sud-sud-ouest');
verifie('112° est est-sud-est', voletautobeSun::compassName(112), 'est-sud-est');
verifie('217° est sud-ouest', voletautobeSun::compassName(217), 'sud-ouest');
/* Le dernier secteur revient au nord. Un modulo oublié chercherait un
 * dix-septième nom à minuit d'été, quand le soleil passe sous le pôle : une
 * erreur d'indice indéfini au pire moment, sur la tuile. */
verifie('350° revient au nord', voletautobeSun::compassName(350), 'nord');
verifie('360° aussi', voletautobeSun::compassName(360), 'nord');
verifie('un azimut négatif fait le tour', voletautobeSun::compassName(-10), 'nord');
verifie('et un azimut de 370° aussi', voletautobeSun::compassName(370), 'nord');

/* ----------------------------------------------------------------- 13 ---
 * La reconnaissance des volets. */
echo "\nReconnaissance des volets\n";
$somfy = array(
    array('id' => 1, 'name' => 'Monter', 'type' => 'action', 'subType' => 'other', 'generic' => 'FLAP_UP'),
    array('id' => 2, 'name' => 'Descendre', 'type' => 'action', 'subType' => 'other', 'generic' => 'FLAP_DOWN'),
    array('id' => 3, 'name' => 'Stop', 'type' => 'action', 'subType' => 'other', 'generic' => 'FLAP_STOP'),
    array('id' => 4, 'name' => 'Position', 'type' => 'action', 'subType' => 'slider', 'generic' => 'FLAP_SLIDER'),
    array('id' => 5, 'name' => 'État', 'type' => 'info', 'subType' => 'numeric', 'generic' => 'FLAP_STATE'),
);
$volet = voletautobeVolets::classify('Volet salon', $somfy, 'Salon');
verifie('volet sûr', $volet['confidence'], voletautobeVolets::FLAP);
verifie('montée retenue', $volet['up'], 1);
verifie('descente retenue', $volet['down'], 2);
verifie('arrêt retenu', $volet['stop'], 3);
verifie('curseur retenu', $volet['slider'], 4);
verifie('état retenu', $volet['state'], 5);
verifie('le nom parle de volet', $volet['hinted'], 1);

$bso = array(
    array('id' => 11, 'name' => 'Monter', 'type' => 'action', 'subType' => 'other', 'generic' => 'FLAP_BSO_UP'),
    array('id' => 12, 'name' => 'Descendre', 'type' => 'action', 'subType' => 'other', 'generic' => 'FLAP_BSO_DOWN'),
    array('id' => 13, 'name' => 'État', 'type' => 'info', 'subType' => 'numeric', 'generic' => 'FLAP_BSO_STATE'),
);
$lames = voletautobeVolets::classify('Brise-soleil bureau', $bso, 'Bureau');
verifie('brise-soleil à part', $lames['confidence'], voletautobeVolets::BSO);
verifie('montée du BSO retenue', $lames['up'], 11);
verifie('état du BSO retenu', $lames['state'], 13);
verifie('« Brise-soleil » est un indice', $lames['hinted'], 1);

$vieux = array(
    array('id' => 20, 'name' => 'Ouvrir', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
    array('id' => 21, 'name' => 'Fermer', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
    array('id' => 22, 'name' => 'Arrêt', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
);
$devine = voletautobeVolets::classify('Vieux module 433', $vieux);
verifie('reconnu au nom', $devine['confidence'], voletautobeVolets::GUESS);
verifie('ouverture trouvée au nom', $devine['up'], 20);
verifie('fermeture trouvée au nom', $devine['down'], 21);
verifie('arrêt trouvé au nom', $devine['stop'], 22);
verifie('et rien ne le rattache à un volet', $devine['hinted'], 0);
verifie('nom de la pièce pris en compte',
        voletautobeVolets::classify('Module 3', $vieux, 'Stores du séjour')['hinted'], 1);

/* Une lampe n'est pas un volet. C'est le test qui protège du ridicule : le
 * plugin des lampes et celui des volets tournent sur la même box, et proposer
 * le lampadaire du salon dans le sélecteur des volets ferait douter des deux. */
$lampe = array(
    array('id' => 30, 'name' => 'On', 'type' => 'action', 'subType' => 'other', 'generic' => 'LIGHT_ON'),
    array('id' => 31, 'name' => 'Off', 'type' => 'action', 'subType' => 'other', 'generic' => 'LIGHT_OFF'),
    array('id' => 32, 'name' => 'Etat', 'type' => 'info', 'subType' => 'binary', 'generic' => 'LIGHT_STATE'),
);
verifie('une lampe n\'est pas un volet', voletautobeVolets::classify('Lampadaire', $lampe, 'Salon'), null);
$vieilleLampe = array(
    array('id' => 33, 'name' => 'Allumer', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
    array('id' => 34, 'name' => 'Éteindre', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
);
verifie('une lampe sans type générique non plus',
        voletautobeVolets::classify('Lampe de chevet', $vieilleLampe), null);

$capteur = array(
    array('id' => 40, 'name' => 'Température', 'type' => 'info', 'subType' => 'numeric', 'generic' => 'TEMPERATURE'),
    array('id' => 41, 'name' => 'Ouverture', 'type' => 'info', 'subType' => 'binary', 'generic' => 'OPENING'),
);
verifie('un capteur n\'est pas un volet', voletautobeVolets::classify('Sonde salon', $capteur), null);

/* Un curseur seul suffit : beaucoup de volets modernes n'exposent qu'une
 * position de 0 à 100, et les refuser reviendrait à ignorer les plus récents. */
$curseur = array(
    array('id' => 50, 'name' => 'Position', 'type' => 'action', 'subType' => 'slider', 'generic' => 'FLAP_SLIDER'),
);
$seul = voletautobeVolets::classify('Store véranda', $curseur);
verifie('un curseur seul suffit', $seul['slider'], 50);
verifie('et c\'est un volet sûr', $seul['confidence'], voletautobeVolets::FLAP);
verifie('sans montée retenue', $seul['up'], null);

/* « Stop » tout seul se trouve sur une vanne, une pompe, un scénario. Ce n'est
 * pas un volet, et le proposer remplirait le sélecteur de bruit. */
$arret = array(
    array('id' => 60, 'name' => 'Stop', 'type' => 'action', 'subType' => 'other', 'generic' => 'FLAP_STOP'),
);
verifie('« stop » seul ne suffit pas', voletautobeVolets::classify('Module inconnu', $arret), null);

/* Les noms composés d'un plugin tiers : l'ordre est le dernier mot, et le reste
 * du nom dit qu'il s'agit d'un volet. Les deux conditions comptent : sans la
 * première, « Volet salon » passerait pour un ordre ; sans la seconde,
 * « Portail ouvrir » deviendrait un volet et le plugin fermerait le portail
 * tous les soirs. */
echo "\nOrdres en fin de nom\n";
$compose = array(
    array('id' => 71, 'name' => 'Volet salon Monter', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
    array('id' => 72, 'name' => 'Volet salon Descendre', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
    array('id' => 73, 'name' => 'Aller au preset', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
);
$trouve = voletautobeVolets::classify('Module MQTT', $compose);
verifie('« Volet salon Monter » monte', $trouve['up'], 71);
verifie('« Volet salon Descendre » descend', $trouve['down'], 72);
verifie('et reste une reconnaissance au nom', $trouve['confidence'], voletautobeVolets::GUESS);

$store = array(
    array('id' => 74, 'name' => 'Store terrasse UP', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
    array('id' => 75, 'name' => 'Store terrasse DOWN', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
);
$terrasse = voletautobeVolets::classify('Zigbee 0x00124b', $store);
verifie('« Store terrasse UP » monte', $terrasse['up'], 74);
verifie('« Store terrasse DOWN » descend', $terrasse['down'], 75);

$portail = array(
    array('id' => 76, 'name' => 'Portail ouvrir', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
    array('id' => 77, 'name' => 'Portail fermer', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
);
verifie('un portail n\'est pas un volet', voletautobeVolets::classify('Motorisation', $portail), null);

$homonyme = array(
    array('id' => 78, 'name' => 'Volet salon', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
);
verifie('« Volet salon » ne finit pas par un ordre',
        voletautobeVolets::classify('Module', $homonyme), null);

/* Le type générique l'emporte sur le nom : une commande nommée « Monter » qui
 * n'est pas la montée du volet ne doit pas voler la place. */
$piege = array(
    array('id' => 80, 'name' => 'Monter', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
    array('id' => 81, 'name' => 'Ouverture du volet', 'type' => 'action', 'subType' => 'other', 'generic' => 'FLAP_UP'),
    array('id' => 82, 'name' => 'Descendre', 'type' => 'action', 'subType' => 'other', 'generic' => 'FLAP_DOWN'),
);
verifie('le type générique prime sur le nom', voletautobeVolets::classify('Volet', $piege)['up'], 81);

/* Une commande d'information nommée « Monter » ne commande rien : la retenir
 * ferait partir l'ordre dans le vide, sans la moindre erreur. */
$menteur = array(
    array('id' => 85, 'name' => 'Monter', 'type' => 'info', 'subType' => 'binary', 'generic' => ''),
    array('id' => 86, 'name' => 'Descendre', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
);
$moitie = voletautobeVolets::classify('Module bizarre', $menteur);
verifie('une info nommée « Monter » ne monte pas', $moitie['up'], null);
verifie('mais la descente reste retenue', $moitie['down'], 86);

/* « Ouverture » appartient aux deux vocabulaires : ordre de montée sur un volet
 * à boutons, nom du curseur sur un volet à position. Le sous-type tranche —
 * sinon la protection solaire à 30 % ouvrirait le volet en grand. */
echo "\nLe mot « Ouverture », qui sert deux fois\n";
$ambigu = array(
    array('id' => 90, 'name' => 'Ouverture', 'type' => 'action', 'subType' => 'slider', 'generic' => ''),
    array('id' => 91, 'name' => 'Fermer', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
);
$tranche = voletautobeVolets::classify('Store bureau', $ambigu);
verifie('« Ouverture » en curseur devient le curseur', $tranche['slider'], 90);
verifie('et ne devient pas la montée', $tranche['up'], null);

$bouton = array(
    array('id' => 92, 'name' => 'Ouverture', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
    array('id' => 93, 'name' => 'Fermeture', 'type' => 'action', 'subType' => 'other', 'generic' => ''),
);
$boutons = voletautobeVolets::classify('Store cuisine', $bouton);
verifie('« Ouverture » en bouton devient la montée', $boutons['up'], 92);
verifie('et aucun curseur n\'est inventé', $boutons['slider'], null);
verifie('« Fermeture » devient la descente', $boutons['down'], 93);

/* Un curseur qui s'appelle « Position » ne doit pas non plus être pris pour
 * autre chose, et roleMatchesType le dit explicitement. */
echo "\nRôles et types de commandes\n";
verifieVrai('un état est une information',
    voletautobeVolets::roleMatchesType('state', array('type' => 'info', 'subType' => 'numeric')));
verifie('un état n\'est pas une action',
    voletautobeVolets::roleMatchesType('state', array('type' => 'action', 'subType' => 'other')), false);
verifieVrai('un curseur est une action de sous-type slider',
    voletautobeVolets::roleMatchesType('slider', array('type' => 'action', 'subType' => 'slider')));
verifie('une action « other » n\'est pas un curseur',
    voletautobeVolets::roleMatchesType('slider', array('type' => 'action', 'subType' => 'other')), false);
verifieVrai('une montée est une action',
    voletautobeVolets::roleMatchesType('up', array('type' => 'action', 'subType' => 'other')));

/* ----------------------------------------------------------------- 14 ---
 * Les noms qui parlent de volets : « Volet du salon » d'un côté,
 * « voletsalonsud » de l'autre, tel qu'un identifiant MQTT le donne. Et
 * « bso », trois lettres qui se retrouvent partout si on les cherche mal. */
echo "\nNoms qui parlent de volets\n";
verifieVrai('« Volet salon »', voletautobeVolets::looksLikeVolet('Volet salon'));
verifieVrai('« voletsalonsud » collé', voletautobeVolets::looksLikeVolet('voletsalonsud'));
verifieVrai('« Store terrasse »', voletautobeVolets::looksLikeVolet('Store terrasse'));
verifieVrai('« Volet roulant chambre »', voletautobeVolets::looksLikeVolet('Volet roulant chambre'));
verifieVrai('« Velux bureau »', voletautobeVolets::looksLikeVolet('Velux bureau'));
verifieVrai('« BSO » en mot entier', voletautobeVolets::looksLikeVolet('Module 4 BSO'));
verifieVrai('« bso » en minuscules aussi', voletautobeVolets::looksLikeVolet('bso sud'));
verifie('« absorbsoleil » n\'est pas un BSO',
        voletautobeVolets::looksLikeVolet('absorbsoleil'), false);
verifie('« Chaudière » n\'en est pas', voletautobeVolets::looksLikeVolet('Chaudière'), false);
verifie('« Portail » n\'en est pas', voletautobeVolets::looksLikeVolet('Portail'), false);
verifie('un nom vide n\'en est pas', voletautobeVolets::looksLikeVolet(''), false);

/* La normalisation elle-même : accents, casse, ponctuation. */
verifie('« Arrêt » se normalise', voletautobeVolets::normalize('Arrêt'), 'arret');
verifie('les séparateurs deviennent des espaces',
        voletautobeVolets::normalizeWords('Volet — salon (sud)'), 'volet salon sud');

/* ----------------------------------------------------------------- 15 ---
 * Les sondes de température. Une sonde oubliée, c'est toute la condition de
 * température qui paraît absente. */
echo "\nReconnaissance des sondes\n";
verifieVrai('type générique TEMPERATURE', voletautobeVolets::isTemperature(
    array('id' => 100, 'name' => 'Température', 'type' => 'info', 'subType' => 'numeric',
          'generic' => 'TEMPERATURE', 'unit' => '°C')));
verifieVrai('température extérieure d\'un thermostat', voletautobeVolets::isTemperature(
    array('id' => 101, 'name' => 'Ext', 'type' => 'info', 'subType' => 'numeric',
          'generic' => 'THERMOSTAT_TEMPERATURE_OUTDOOR', 'unit' => '')));
verifieVrai('température météo', voletautobeVolets::isTemperature(
    array('id' => 102, 'name' => 'Maintenant', 'type' => 'info', 'subType' => 'numeric',
          'generic' => 'WEATHER_TEMPERATURE', 'unit' => '')));
/* Sans type générique : c'est le cas courant des vieux protocoles, et le nom
 * suffit — sans quoi la liste des sondes serait vide sur une installation
 * entière. */
verifieVrai('« Température extérieure » sans type générique', voletautobeVolets::isTemperature(
    array('id' => 103, 'name' => 'Température extérieure', 'type' => 'info', 'subType' => 'numeric',
          'generic' => '', 'unit' => '')));
verifieVrai('reconnue à son unité', voletautobeVolets::isTemperature(
    array('id' => 104, 'name' => 'Sonde 3', 'type' => 'info', 'subType' => 'numeric',
          'generic' => '', 'unit' => '°C')));
verifie('une commande d\'action n\'est pas une sonde', voletautobeVolets::isTemperature(
    array('id' => 105, 'name' => 'Consigne température', 'type' => 'action', 'subType' => 'slider',
          'generic' => '', 'unit' => '°C')), false);
verifie('l\'humidité n\'est pas une température', voletautobeVolets::isTemperature(
    array('id' => 106, 'name' => 'Humidité', 'type' => 'info', 'subType' => 'numeric',
          'generic' => 'HUMIDITY', 'unit' => '%')), false);
verifie('une luminosité non plus', voletautobeVolets::isTemperature(
    array('id' => 107, 'name' => 'Luminosité', 'type' => 'info', 'subType' => 'numeric',
          'generic' => 'BRIGHTNESS', 'unit' => 'lux')), false);
verifie('une distance en cm non plus', voletautobeVolets::isTemperature(
    array('id' => 108, 'name' => 'Distance', 'type' => 'info', 'subType' => 'numeric',
          'generic' => '', 'unit' => 'cm')), false);
verifie('un texte n\'est pas une mesure', voletautobeVolets::isTemperature(
    array('id' => 109, 'name' => 'Température du jour', 'type' => 'info', 'subType' => 'string',
          'generic' => '', 'unit' => '')), false);

/* Le degré nu n'est pas une échelle de température. Relevé sur une installation
 * réelle : une girouette et le cap d'un robot aspirateur publient « ° », et
 * remontaient dans la liste des sondes. Une condition « seulement si ≥ 5 °C »
 * posée par erreur sur une direction de vent à 338 serait toujours remplie, et
 * rien ne dirait à l'utilisateur que sa condition ne filtre rien. */
verifie('une direction de vent en degrés n\'est pas une sonde', voletautobeVolets::isTemperature(
    array('id' => 110, 'name' => 'Direction du vent', 'type' => 'info', 'subType' => 'numeric',
          'generic' => 'WIND_DIRECTION', 'unit' => '°')), false);
verifie('une orientation en degrés non plus', voletautobeVolets::isTemperature(
    array('id' => 111, 'name' => 'Orientation', 'type' => 'info', 'subType' => 'numeric',
          'generic' => '', 'unit' => '°')), false);
verifieVrai('« C » sans le degré reste une température', voletautobeVolets::isTemperature(
    array('id' => 112, 'name' => 'Sonde 4', 'type' => 'info', 'subType' => 'numeric',
          'generic' => '', 'unit' => 'C')));
verifieVrai('les degrés Fahrenheit aussi', voletautobeVolets::isTemperature(
    array('id' => 113, 'name' => 'Sonde 5', 'type' => 'info', 'subType' => 'numeric',
          'generic' => '', 'unit' => '°F')));
/* Un compteur en kWh partage son « C »… non, mais un « cm » et un « lux »
 * contiennent des lettres que l'ancienne règle cherchait une par une : c'est
 * l'unité entière qui est comparée, et eux restent dehors. */
verifie('une puissance en W n\'est pas une température', voletautobeVolets::isTemperature(
    array('id' => 114, 'name' => 'Puissance', 'type' => 'info', 'subType' => 'numeric',
          'generic' => 'POWER', 'unit' => 'W')), false);

/* ----------------------------------------------------------------- 16 ---
 * La position d'un groupe à partir de celle de ses volets.
 *
 * Le cas qui compte est le dernier : un groupe dont aucun volet ne publie sa
 * position ne doit pas être déclaré fermé, sinon la tuile afficherait « 0 % »
 * en permanence pour tous les modules 433 MHz, et le dernier ordre du plugin —
 * la seule chose que l'on sache — serait perdu. */
echo "\nPosition d'un groupe\n";
verifie('moyenne de trois volets', voletautobeVolets::aggregatePosition(array(0, 50, 100)), 50);
verifie('tous fermés', voletautobeVolets::aggregatePosition(array(0, 0, 0)), 0);
verifie('tous ouverts', voletautobeVolets::aggregatePosition(array(100, 100)), 100);
verifie('un seul volet', voletautobeVolets::aggregatePosition(array(30)), 30);
verifie('moyenne arrondie', voletautobeVolets::aggregatePosition(array(33, 34)), 34);
verifie('les inconnues ne comptent pas', voletautobeVolets::aggregatePosition(array(null, 100)), 100);
verifie('une connue parmi des inconnues',
        voletautobeVolets::aggregatePosition(array(null, 40, null)), 40);
verifie('aucune ne se prononce : on ne sait pas',
        voletautobeVolets::aggregatePosition(array(null, null)), null);
verifie('groupe vide : on ne sait pas', voletautobeVolets::aggregatePosition(array()), null);

/* ---------------------------------------------------------------- BILAN --- */
echo "\n";
if ($ko == 0) {
    echo "Tous les contrôles passent ($ok).\n";
    exit(0);
}
echo "$ok contrôle(s) passé(s), $ko en échec.\n";
exit(1);
