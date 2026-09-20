<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}

/* L'autoload du coeur ne connaît que la classe qui porte le nom du plugin : les
 * deux autres se chargent par elle. Cette page appelle voletautobeVolets avant
 * toute autre chose, pour remplir la liste des sondes — sans ce require, elle
 * meurt sur « Class "voletautobeVolets" not found », et le symptôme ne désigne
 * pas la cause : la fenêtre de configuration du plugin reste vide, le journal du
 * plugin ne dit rien, et tout est dans /var/www/html/log/http.error.
 *
 * C'est arrivé à la première ouverture de la page. */
require_once __DIR__ . '/../core/class/voletautobe.class.php';
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-clock"></i> {{Exécution}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Rattrapage}}</label>
			<div class="col-md-2">
				<input type="number" min="1" max="720" class="configKey form-control" data-l1key="grace_minutes" placeholder="15">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Minutes pendant lesquelles un moment manqué est encore joué — box éteinte, redémarrage, cron en retard. Passé ce délai il est abandonné : ouvrir les volets du matin à midi ne rend service à personne. 15 minutes conviennent dans la quasi-totalité des cas.}}</span>
			</div>
		</div>
	</fieldset>
	<fieldset>
		<legend><i class="fas fa-thermometer-half"></i> {{Température}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Sonde par défaut}}</label>
			<div class="col-md-4">
				<select class="configKey form-control" data-l1key="temperature_cmd">
					<option value="">{{Aucune}}</option>
					<?php
					/* Les sondes sont listées ici plutôt que chargées en AJAX : la page
					 * de configuration d'un plugin n'a pas de JS à elle, et une liste
					 * déroulante vide serait prise pour « aucune sonde dans la maison ».
					 * Les noms viennent des équipements de l'utilisateur : ils sont
					 * échappés, un équipement nommé avec une balise ne doit pas
					 * s'exécuter dans la page de configuration de l'administrateur. */
					foreach (voletautobeVolets::discoverTemperatures() as $sensor) {
						$label = $sensor['object'] . ' — ' . $sensor['name'];
						if ($sensor['value'] !== null) {
							$label .= ' (' . voletautobe::formatTemperature($sensor['value']) . ')';
						}
						echo '<option value="' . (int) $sensor['id'] . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
					}
					?>
				</select>
			</div>
			<div class="col-md-4">
				<span class="help-block" style="margin:0;">{{La sonde que tous les groupes utilisent, sauf ceux qui en choisissent une autre. Une maison a une température extérieure, pas huit : la régler ici évite de la choisir groupe par groupe, et de la corriger partout le jour où l'on change de station météo.}}</span>
			</div>
		</div>
		<div class="form-group">
			<div class="col-md-11 col-md-offset-1">
				<span class="help-block" style="margin:0;">{{Sans sonde lisible, les conditions de température sont sans effet : le plugin bouge les volets quand même. C'est voulu — une sonde en panne ne doit pas laisser la maison volets fermés indéfiniment — mais c'est à savoir.}}</span>
			</div>
		</div>
	</fieldset>
	<fieldset>
		<legend><i class="fas fa-map-marker-alt"></i> {{Position}}</legend>
		<div class="form-group">
			<div class="col-md-11 col-md-offset-1">
				<span class="help-block" style="margin:0;">
					<?php
					if (voletautobe::hasLocation()) {
						$location = voletautobe::location();
						$sun = voletautobeSun::sun(time(), $location['latitude'], $location['longitude']);
						echo '{{Position de l\'installation}} : ' . $location['latitude'] . ', ' . $location['longitude'] . '. ';
						echo '{{Aujourd\'hui, lever}} ' . (($sun['sunrise'] === null) ? '--:--' : date('H:i', $sun['sunrise'])) . ', ';
						echo '{{coucher}} ' . (($sun['sunset'] === null) ? '--:--' : date('H:i', $sun['sunset'])) . '.';
					} else {
						echo '<span class="text-danger">{{La position de l\'installation n\'est pas renseignée : les heures de soleil seraient fausses. Réglages → Système → Configuration → Général.}}</span>';
					}
					?>
				</span>
			</div>
		</div>
	</fieldset>
</form>
