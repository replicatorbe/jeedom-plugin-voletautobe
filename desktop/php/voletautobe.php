<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('voletautobe');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());

/* Les jours, dans l'ordre européen. La clé est celle de date('N'). */
$voletautobeDays = array(1 => '{{Lun}}', 2 => '{{Mar}}', 3 => '{{Mer}}', 4 => '{{Jeu}}',
                         5 => '{{Ven}}', 6 => '{{Sam}}', 7 => '{{Dim}}');

/*
 * Le bloc d'un moment, écrit une fois et posé trois fois : le matin, la
 * protection solaire et le soir se règlent exactement de la même façon, seuls
 * leur nom, leur icône et leur texte d'aide diffèrent. Trois formulaires
 * jumeaux écrits à la main divergeraient au premier ajout de champ, et c'est
 * toujours le troisième qu'on oublie de mettre à jour.
 */
function voletautobeSlot($_key, $_title, $_icon, $_help) {
	global $voletautobeDays;
	?>
	<fieldset class="vabSlot" data-slot="<?php echo $_key; ?>">
		<legend><i class="<?php echo $_icon; ?>"></i> <?php echo $_title; ?></legend>

		<div class="form-group">
			<label class="col-sm-3 control-label">{{Activer ce moment}}</label>
			<div class="col-sm-2">
				<input type="checkbox" class="eqLogicAttr vabPreviewTrigger" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" data-l3key="enable">
			</div>
			<label class="col-sm-2 control-label">{{Faire}}</label>
			<div class="col-sm-5">
				<div class="input-group">
					<select class="eqLogicAttr form-control vabAction vabPreviewTrigger roundedLeft" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" data-l3key="action">
						<option value="up">{{Ouvrir}}</option>
						<option value="down">{{Fermer}}</option>
						<option value="position">{{Mettre à une position}}</option>
					</select>
					<!-- Le pourcentage n'a de sens que pour « Mettre à une position » :
					     le montrer en permanence ferait régler une hauteur à un ordre
					     d'ouverture, qui l'ignore. Le JS le montre et le cache. -->
					<span class="input-group-addon vabPositionBlock" style="padding:0;border:0;">
						<input type="number" min="0" max="100" step="5" class="form-control vabPositionValue vabPreviewTrigger" placeholder="30" style="width:70px;display:inline-block;">
					</span>
					<span class="input-group-addon roundedRight vabPositionBlock">%</span>
				</div>
				<!-- La position, reconstituée par le JS : le cœur ne descend pas
				     jusqu'au troisième niveau d'un champ qu'il n'a pas posé. -->
				<input type="text" class="eqLogicAttr" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" data-l3key="position" style="display:none;">
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-3 control-label">{{Quand}}</label>
			<div class="col-sm-4">
				<select class="eqLogicAttr form-control vabMode vabPreviewTrigger" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" data-l3key="mode">
					<option value="fixed">{{À une heure fixe}}</option>
					<option value="sunrise">{{Par rapport au lever du soleil}}</option>
					<option value="sunset">{{Par rapport au coucher du soleil}}</option>
				</select>
			</div>
			<div class="col-sm-5 vabFixed">
				<div class="input-group">
					<span class="input-group-addon roundedLeft">{{à}}</span>
					<input type="time" class="eqLogicAttr form-control roundedRight vabPreviewTrigger" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" data-l3key="time" placeholder="07:00">
				</div>
			</div>
			<div class="col-sm-5 vabSun">
				<div class="input-group">
					<input type="number" min="0" max="720" step="5" class="form-control roundedLeft vabOffsetValue vabPreviewTrigger" placeholder="30">
					<span class="input-group-addon">{{min}}</span>
					<select class="form-control vabOffsetWay vabPreviewTrigger">
						<option value="before">{{avant}}</option>
						<option value="after">{{après}}</option>
					</select>
					<span class="input-group-addon roundedRight vabEventName"></span>
				</div>
				<!-- Le décalage signé, reconstitué par le JS : « 30 minutes avant »
				     se lit mieux que « -30 », et se tape sans erreur de signe. -->
				<input type="text" class="eqLogicAttr" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" data-l3key="offset" style="display:none;">
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-3 control-label">{{Jours}}</label>
			<div class="col-sm-9">
				<?php foreach ($voletautobeDays as $number => $label) { ?>
					<label class="checkbox-inline" style="padding-left:20px;">
						<input type="checkbox" class="vabDay vabPreviewTrigger" data-slot="<?php echo $_key; ?>" data-day="<?php echo $number; ?>"> <?php echo $label; ?>
					</label>
				<?php } ?>
				<a class="btn btn-default btn-xs vabAllDays" data-slot="<?php echo $_key; ?>" style="margin-left:10px;">{{Tous}}</a>
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-3 control-label">
				{{Garde-fous}}
				<sup><i class="fas fa-question-circle" title="{{Le coucher du soleil varie de 16 h 40 en décembre à 22 h 00 en juin : fermer les volets à 16 h 40 est trop tôt. Un garde-fou ramène le moment dans la fenêtre, il ne l'annule pas — « pas avant 18:00 » ferme à 18:00 les soirs où le soleil se couche plus tôt. Laisser vide pour suivre le soleil toute l'année.}}"></i></sup>
			</label>
			<div class="col-sm-4">
				<div class="input-group">
					<span class="input-group-addon roundedLeft">{{Pas avant}}</span>
					<input type="time" class="eqLogicAttr form-control roundedRight vabPreviewTrigger" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" data-l3key="not_before">
				</div>
			</div>
			<div class="col-sm-4">
				<div class="input-group">
					<span class="input-group-addon roundedLeft">{{Pas après}}</span>
					<input type="time" class="eqLogicAttr form-control roundedRight vabPreviewTrigger" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" data-l3key="not_after">
				</div>
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-3 control-label">
				{{Décalage aléatoire}}
				<sup><i class="fas fa-question-circle" title="{{Simulation de présence : le moment est avancé ou retardé au hasard, dans cette limite. Le tirage est fait une fois par jour, l'heure annoncée est donc celle qui sera réellement jouée.}}"></i></sup>
			</label>
			<div class="col-sm-4">
				<div class="input-group">
					<span class="input-group-addon roundedLeft">±</span>
					<input type="number" min="0" max="120" step="5" class="eqLogicAttr form-control vabPreviewTrigger" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" data-l3key="random" placeholder="0">
					<span class="input-group-addon roundedRight">{{min}}</span>
				</div>
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-3 control-label">
				{{Température}}
				<sup><i class="fas fa-question-circle" title="{{La condition qui peut empêcher ce moment. En hiver, un volet fermé isole : l'ouvrir à 7 h par −3 °C fait perdre de la chaleur pour trois heures de lumière grise. L'été, on ne ferme à mi-journée que les jours où il fait vraiment chaud. Si la sonde ne répond pas, le moment est joué quand même : une sonde en panne ne doit pas laisser la maison volets fermés.}}"></i></sup>
			</label>
			<div class="col-sm-4">
				<select class="eqLogicAttr form-control vabTempMode vabPreviewTrigger" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" data-l3key="temp_mode">
					<option value="none">{{Sans condition}}</option>
					<option value="min">{{Seulement s'il fait au moins}}</option>
					<option value="max">{{Seulement s'il fait au plus}}</option>
				</select>
			</div>
			<div class="col-sm-4 vabTempBlock">
				<div class="input-group">
					<!-- Un champ de type « number » vide en silence une saisie à la
					     virgule sur un navigateur en français : « 5,5 » disparaîtrait
					     à l'enregistrement. Le texte est relu côté serveur, qui
					     accepte la virgule comme le point. -->
					<input type="text" class="eqLogicAttr form-control roundedLeft vabTempValue vabPreviewTrigger" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" data-l3key="temp_value" placeholder="26">
					<span class="input-group-addon roundedRight">°C</span>
				</div>
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-3 control-label">&nbsp;</label>
			<div class="col-sm-9">
				<span class="help-block" style="margin:0;"><?php echo $_help; ?></span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-3 control-label">{{Prochaines fois}}</label>
			<div class="col-sm-9">
				<div class="vabPreview well well-sm" style="margin:0;padding:8px 12px;">—</div>
			</div>
		</div>
	</fieldset>
	<?php
}
?>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter un groupe}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>
		<legend><i class="fas fa-align-justify"></i> {{Mes groupes de volets}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucun groupe pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Cliquez sur « Ajouter un groupe » et donnez-lui un nom, par exemple « Chambres » ou « Façade sud ».}}</li>';
			echo '<li>{{Cliquez sur « Choisir les volets » : le plugin va les chercher dans votre installation, vous n\'avez qu\'à cocher. Les boutons de chaque ligne font monter ou descendre le volet, c\'est la seule façon de savoir lequel est « Module 3 ».}}</li>';
			echo '<li>{{Onglet « Programmation » : réglez le matin et le soir, à heure fixe ou par rapport au soleil, et la protection solaire si vos étés sont chauds.}}</li>';
			echo '<li>{{Enregistrez. Rien d\'autre à faire, aucun scénario à écrire.}}</li>';
			echo '</ol>';
			echo '</div>';
		}
		if (!voletautobe::hasLocation()) {
			echo '<div class="alert alert-warning" style="margin:5px;">';
			echo '<i class="fas fa-exclamation-triangle"></i> ';
			echo '{{La position de votre installation n\'est pas renseignée : les heures de lever et de coucher du soleil seraient fausses. Renseignez-la dans Réglages → Système → Configuration → Général.}}';
			echo '</div>';
		}
		echo '<div class="input-group" style="margin:5px;">';
		echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
		echo '<div class="input-group-btn">';
		echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
		echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
		echo '</div>';
		echo '</div>';
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($eqLogics as $eqLogic) {
			$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
			/* Ce que ce groupe fera aujourd'hui, sur la carte : c'est la seule page
			   d'où l'on voit toute la maison d'un coup d'oeil, et un nombre de
			   volets n'y apprend rien qu'on ne sache déjà. */
			$summary = $eqLogic->cardSummary();
			$count = isset($summary['volets']) ? $summary['volets'] : 0;
			$paused = !empty($summary['paused']);
			$text = isset($summary['text']) ? $summary['text'] : '';
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<i class="fas ' . ($paused ? 'fa-pause-circle' : 'fa-align-justify') . '" style="font-size:4em;"></i>';
			echo '<br>';
			echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
			echo '<br><span style="font-size:0.85em;opacity:0.7;">' . $count . ' {{volet(s)}}</span>';
			echo '<br><span style="font-size:0.85em;' . ($paused ? 'color:#f0ad4e;' : 'opacity:0.7;') . '">'
			   . $text . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
		?>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-default btn-sm eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-align-justify"></i><span class="hidden-xs"> {{Volets}}</span></a></li>
			<li role="presentation"><a href="#scheduletab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-clock"></i><span class="hidden-xs"> {{Programmation}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ============================================ VOLETS ============================================ -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-5">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Nom du groupe}}</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Chambres}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Objet parent}}</label>
								<div class="col-sm-7">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach (jeeObject::buildTree(null, false) as $object) {
											echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Activer}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
								</div>
								<label class="col-sm-2 control-label">{{Visible}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-power-off"></i> {{Programmation}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{État}}</label>
								<div class="col-sm-8">
									<span id="span_voletautobePaused" class="label label-success">{{Active}}</span>
									<a class="btn btn-sm btn-default" id="bt_voletautobePause" style="margin-left:8px;"><i class="fas fa-pause"></i> {{Suspendre}}</a>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">&nbsp;</label>
								<div class="col-sm-8">
									<span class="help-block" style="margin:0;">{{Suspendre arrête les trois moments sans désactiver le groupe : les boutons continuent de fonctionner, le groupe reste sur le tableau de bord, et les commandes « Suspendre » et « Reprendre » se pilotent en scénario — un mode vacances, des volets ouverts pour les peintres.}}</span>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-thermometer-half"></i> {{Température}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Sonde}}</label>
								<div class="col-sm-8">
									<select class="eqLogicAttr form-control" id="in_voletautobeSensor" data-l1key="configuration" data-l2key="temperature_cmd">
										<option value="">{{Celle du plugin}}</option>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Mesure}}</label>
								<div class="col-sm-8">
									<span id="span_voletautobeTemperature" class="label label-default">—</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">&nbsp;</label>
								<div class="col-sm-8">
									<span class="help-block" style="margin:0;">{{Une maison a une température extérieure, pas huit : laissez « Celle du plugin » et réglez-la une fois dans la configuration. Un groupe qui mérite sa propre sonde — la chambre au nord, une véranda — choisit la sienne ici. Sans sonde lisible, les conditions de température de l'onglet Programmation sont sans effet : les moments sont joués quand même.}}</span>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-vial"></i> {{Essai}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Tout le groupe}}</label>
								<div class="col-sm-8">
									<a class="btn btn-default" id="bt_voletautobeTestUp"><i class="fas fa-arrow-up"></i> {{Ouvrir}}</a>
									<a class="btn btn-default" id="bt_voletautobeTestStop"><i class="fas fa-stop"></i> {{Stop}}</a>
									<a class="btn btn-default" id="bt_voletautobeTestDown"><i class="fas fa-arrow-down"></i> {{Fermer}}</a>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">&nbsp;</label>
								<div class="col-sm-8">
									<span class="help-block" style="margin:0;">{{L'essai commande réellement les volets enregistrés : sauvegardez d'abord si vous venez de modifier la sélection.}}</span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-7">
					<legend>
						<i class="fas fa-list-ul"></i> {{Volets de ce groupe}}
						<a class="btn btn-sm btn-success pull-right" id="bt_voletautobePick"><i class="fas fa-search"></i> {{Choisir les volets}}</a>
					</legend>
					<div id="div_voletautobeVolets"></div>
				</div>
			</div>

			<!-- ========================================= PROGRAMMATION ========================================= -->
			<div role="tabpanel" class="tab-pane" id="scheduletab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<?php
						voletautobeSlot('morning', '{{Le matin}}', 'fas fa-sun',
							'{{Le moment où l\'on veut de la lumière : au lever du soleil, ou à heure fixe. En hiver, « seulement s\'il fait au moins 2 °C » garde les volets fermés les matins de gel, où ils isolent plus qu\'ils n\'éclairent.}}');
						?>
					</form>
				</div>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<?php
						voletautobeSlot('heat', '{{Protection solaire}}', 'fas fa-temperature-high',
							'{{Fermer aux trois quarts pendant les heures chaudes, les jours chauds seulement, et ne rien faire le reste de l\'année : c\'est le moment qui justifie à lui seul la sonde de température. Sans condition, il fermerait aussi les après-midi d\'avril à 14 °C.}}');
						?>
					</form>
				</div>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<?php
						voletautobeSlot('evening', '{{Le soir}}', 'fas fa-moon',
							'{{Le moment où l\'on ferme pour la nuit : le plus souvent au coucher du soleil, avec « pas avant 18:00 » — en décembre le soleil se couche à 16 h 40, et fermer à 16 h 40 est trop tôt.}}');
						?>
					</form>
				</div>
			</div>

			<!-- ============================================ COMMANDES ========================================== -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="alert alert-info" style="margin:5px;">
					{{Ces commandes sont créées et tenues à jour par le plugin. « Prochain changement » se pose sur un tableau de bord, « Ouvrir », « Fermer » et « Stop » agissent sur tout le groupe, « Position » le met à une hauteur, et « État » donne la position du groupe — 0 % fermé, 100 % ouvert.}}
				</div>
				<table id="table_cmd" class="table table-bordered table-condensed">
					<thead>
						<tr>
							<th>{{Nom}}</th>
							<th>{{Type}}</th>
							<th>{{Options}}</th>
							<th>{{Action}}</th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
		</div>
	</div>
</div>

<?php include_file('core', 'plugin.template', 'js'); ?>
<?php include_file('desktop', 'voletautobe', 'js', 'voletautobe'); ?>
