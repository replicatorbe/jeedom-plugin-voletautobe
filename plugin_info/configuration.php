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
		<div class="form-group">
			<label class="col-md-4 control-label">{{Délai entre deux volets}}</label>
			<div class="col-md-2">
				<input type="number" min="0" max="5000" class="configKey form-control" data-l1key="order_delay" placeholder="400">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Millisecondes d'attente entre deux ordres, tous groupes confondus : quand plusieurs groupes tombent à la même minute, le premier volet de l'un attend aussi le dernier de l'autre. Les passerelles radio — le 433 MHz en particulier — perdent les trames envoyées coup sur coup : le volet ne bouge pas, et rien ne le signale, puisque la commande a bien été jouée. C'est la panne la plus difficile à voir du plugin, et elle se corrige en laissant respirer la passerelle. 400 ms conviennent dans la plupart des cas ; 0 envoie tout d'un coup, ce qui ne se justifie que sur une installation entièrement filaire. Les ordres programmés partent en arrière-plan, sans retenir le cron de Jeedom ; l'attente d'un groupe reste plafonnée à 30 secondes.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Sonde muette après}}</label>
			<div class="col-md-2">
				<div class="input-group">
					<input type="number" min="0" max="168" class="configKey form-control roundedLeft" data-l1key="sensor_max_age" placeholder="3">
					<span class="input-group-addon roundedRight">{{h}}</span>
				</div>
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Heures sans nouvelle mesure au-delà desquelles une sonde — température ou luminosité — est considérée comme muette. Une sonde à la pile vide ne dit pas qu'elle s'est tue : Jeedom garde sa dernière valeur, indéfiniment, et une condition jugerait tout l'hiver sur la température d'un après-midi d'octobre. Une sonde muette ne filtre plus rien, comme une sonde absente, et la page du groupe l'affiche « figée ». 0 désactive le contrôle.}}</span>
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
		<legend><i class="fas fa-sun"></i> {{Luminosité}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Sonde par défaut}}</label>
			<div class="col-md-4">
				<select class="configKey form-control" data-l1key="lux_cmd">
					<option value="">{{Aucune}}</option>
					<?php
					/* Même liste que pour la température, mêmes précautions : noms
					 * échappés, liste remplie ici faute de JS propre à la page. La
					 * valeur s'affiche avec l'unité de la sonde et non avec
					 * formatTemperature : un luxmètre parle en lx, un capteur de
					 * rayonnement en W/m², et « 20000 °C » ne voudrait rien dire. */
					foreach (voletautobeVolets::discoverLuminosities() as $sensor) {
						$label = $sensor['object'] . ' — ' . $sensor['name'];
						if ($sensor['value'] !== null) {
							$unit = (isset($sensor['unit']) && $sensor['unit'] !== '') ? $sensor['unit'] : 'lx';
							$label .= ' (' . $sensor['value'] . ' ' . $unit . ')';
						}
						echo '<option value="' . (int) $sensor['id'] . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
					}
					?>
				</select>
			</div>
			<div class="col-md-4">
				<span class="help-block" style="margin:0;">{{Facultatif : un luxmètre, une station météo ou un capteur de rayonnement. C'est ce qui dit si le soleil brille, là où le plugin ne sait que où il est — et ce qui permet de ne pas fermer la protection solaire un jour couvert. Réglée ici, elle sert à tous les groupes qui n'en choisissent pas une autre.}}</span>
			</div>
		</div>
		<div class="form-group">
			<div class="col-md-11 col-md-offset-1">
				<span class="help-block" style="margin:0;">{{Sans sonde lisible, les conditions de luminosité sont sans effet : le moment est joué quand même, comme pour la température.}}</span>
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
