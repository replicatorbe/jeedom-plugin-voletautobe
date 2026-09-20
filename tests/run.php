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

/* ----------------------------------------------------------------- 11 ---
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

/* ----------------------------------------------------------------- 12 ---
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

/* ----------------------------------------------------------------- 13 ---
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
