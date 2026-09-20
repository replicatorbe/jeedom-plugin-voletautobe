<?php
/* Fabrique l'icône du plugin.
 *
 *   php tools/make-icon.php
 *
 * L'icône est dessinée ici plutôt que déposée en binaire opaque : quatre
 * couleurs et un volet à mi-course, qu'on peut relire et refaire. Le dessin est
 * fait en 1024 puis réduit en 256, ce qui donne les bords lissés que GD ne
 * produit pas sur un remplissage direct.
 *
 * Le volet est dessiné à mi-course et non fermé : un rectangle gris plein ne
 * dirait rien, c'est la bande de jour qui passe dessous qui fait lire « volet »
 * et non « fenêtre ». La palette est celle du plugin frère des lampes — même
 * gris, même jaune — pour qu'on voie d'un coup d'oeil dans le menu que les deux
 * plugins vont ensemble.
 */

const TAILLE = 256;
const ECHELLE = 4;

$grand = imagecreatetruecolor(TAILLE * ECHELLE, TAILLE * ECHELLE);
imagesavealpha($grand, true);
imagealphablending($grand, false);
imagefill($grand, 0, 0, imagecolorallocatealpha($grand, 0, 0, 0, 127));
imagealphablending($grand, true);

$jaune     = imagecolorallocate($grand, 0xF5, 0xB3, 0x00);
$jauneVif  = imagecolorallocate($grand, 0xFF, 0xD1, 0x4A);
$gris      = imagecolorallocate($grand, 0x5B, 0x6B, 0x73);
$grisSombre = imagecolorallocate($grand, 0x36, 0x44, 0x4B);
$grisClair = imagecolorallocate($grand, 0xA5, 0xB5, 0xBC);

$e = function ($_valeur) { return (int) round($_valeur * ECHELLE); };

/* Le jour d'abord, sur toute la hauteur de la baie : le tablier viendra le
 * recouvrir jusqu'à mi-course, et ce qui reste est exactement la bande de
 * lumière. */
imagefilledrectangle($grand, $e(52), $e(58), $e(204), $e(228), $jauneVif);
imagefilledrectangle($grand, $e(52), $e(196), $e(204), $e(228), $jaune);

/* Les montants et le seuil, qui font la baie. */
imagefilledrectangle($grand, $e(36), $e(58), $e(52), $e(228), $gris);
imagefilledrectangle($grand, $e(204), $e(58), $e(220), $e(228), $gris);
imagefilledrectangle($grand, $e(30), $e(228), $e(226), $e(242), $gris);

/* Le coffre, en haut : c'est lui qui dit que le tablier s'enroule quelque part
 * et n'est pas un store posé. */
imagefilledrectangle($grand, $e(30), $e(26), $e(226), $e(58), $grisSombre);

/* Le tablier, arrêté à mi-course. */
imagefilledrectangle($grand, $e(52), $e(58), $e(204), $e(152), $grisClair);

/* Les lames, en creux : sans elles le tablier est un rectangle gris qui ne
 * raconte rien. */
imagesetthickness($grand, $e(4));
foreach (array(76, 96, 116, 136) as $y) {
    imageline($grand, $e(52), $e($y), $e(204), $e($y), $gris);
}

/* La barre finale, plus épaisse et plus sombre que les lames : c'est le bord
 * bas du volet, et il doit se voir. */
imagefilledrectangle($grand, $e(46), $e(152), $e(210), $e(168), $grisSombre);

$icone = imagecreatetruecolor(TAILLE, TAILLE);
imagesavealpha($icone, true);
imagealphablending($icone, false);
imagefill($icone, 0, 0, imagecolorallocatealpha($icone, 0, 0, 0, 127));
imagecopyresampled($icone, $grand, 0, 0, 0, 0, TAILLE, TAILLE, TAILLE * ECHELLE, TAILLE * ECHELLE);

$cible = __DIR__ . '/../plugin_info/voletautobe_icon.png';
imagepng($icone, $cible);
echo "Écrit : $cible\n";
