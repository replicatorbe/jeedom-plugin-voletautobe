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
 * Le bloc d'un moment, écrit une fois et posé quatre fois : le matin, la
 * protection solaire, sa fin et le soir se règlent exactement de la même façon,
 * seuls leur nom, leur icône et leur texte d'aide diffèrent. Quatre formulaires
 * jumeaux écrits à la main divergeraient au premier ajout de champ, et c'est
 * toujours le dernier qu'on oublie de mettre à jour.
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
					<!-- Les deux moments de la façade ne demandent aucun angle ici : ils
					     se règlent avec l'orientation du groupe, déclarée une seule fois
					     dans l'onglet « Volets ». Un azimut réglable par moment
					     laisserait écrire l'orientation de la maison à quatre endroits,
					     sans qu'aucun ne fasse foi. -->
					<option value="facade_in">{{Quand le soleil arrive sur la façade}}</option>
					<option value="facade_out">{{Quand le soleil quitte la façade}}</option>
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
			<label class="col-sm-3 control-label">
				{{Soleil}}
				<sup><i class="fas fa-question-circle" title="{{L'autre condition qui peut empêcher ce moment, et celle qui rend la protection solaire juste : ne rien faire tant que le soleil n'est pas sur la façade du groupe. Elle sert surtout aux moments déclenchés à heure fixe — « à 13:00, seulement si le soleil est sur la façade ». Sur un moment déjà déclenché par la façade, elle ne vérifie plus que la hauteur, ce qui est dit juste en dessous. Le plugin sait où est le soleil, pas s'il brille : un jour couvert, le moment est joué quand même — comme il l'est si la position de l'installation n'est pas renseignée.}}"></i></sup>
			</label>
			<div class="col-sm-9">
				<select class="eqLogicAttr form-control vabSunMode vabPreviewTrigger" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" data-l3key="sun_mode">
					<option value="none">{{Aucune}}</option>
					<option value="window">{{Seulement quand le soleil est sur la façade}}</option>
				</select>
			</div>
		</div>

		<!--
		     La condition n'a plus d'angles à elle : l'orientation appartient au
		     groupe et se déclare une seule fois, dans l'onglet « Volets ». Des
		     azimuts réglables ici les feraient écrire quatre fois, sans qu'on
		     sache lequel fait foi — et posés sur un moment déclenché par la
		     façade, ils étaient vrais par construction et ne filtraient rien.

		     Les deux lignes sont donc du texte, écrit par le JS : ce que vaut la
		     façade du groupe, et — quand le déclencheur est la façade — ce que
		     cette condition vérifie encore réellement. C'est la question que
		     l'utilisateur se posait devant cet écran, elle reçoit sa réponse
		     à l'endroit exact où elle se pose.
		-->
		<div class="vabSunBlock">
			<div class="form-group">
				<label class="col-sm-3 control-label">&nbsp;</label>
				<div class="col-sm-9">
					<span class="help-block vabSunRecall" style="margin:0;"></span>
					<span class="help-block vabSunNote text-warning" style="margin:4px 0 0 0;"></span>
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

		<!--
		     L'essai du moment, juste sous l'aperçu : c'est là que l'utilisateur
		     se demande « et concrètement, ça donne quoi ? », et l'aperçu ne
		     répond qu'à la moitié de la question — il dit quand, pas ce que ça
		     fera dans la pièce. Le bouton joue l'action du moment, ses
		     conditions comprises dans le compte rendu, sans attendre le
		     lendemain.
		-->
		<div class="form-group">
			<label class="col-sm-3 control-label">{{Essai}}</label>
			<div class="col-sm-9">
				<a class="btn btn-default btn-sm vabSlotTest"><i class="fas fa-vial"></i> {{Jouer ce moment maintenant}}</a>
				<span class="help-block vabSlotTestResult" style="margin:6px 0 0 0;"></span>
				<span class="help-block" style="margin:6px 0 0 0;">{{Ce bouton commande réellement les volets enregistrés, comme l'essai de l'onglet « Volets » : sauvegardez d'abord si vous venez de modifier ce moment. Il envoie l'action sans se soucier des conditions — un essai qui ne ferait rien parce qu'il fait 18 °C serait incompréhensible — et dit ensuite ce que les conditions auraient décidé au moment venu. Le moment se jouera quand même à son heure : l'essai ne le marque pas comme joué.}}</span>
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
			echo '<li>{{Onglet « Programmation » : réglez le matin et le soir, à heure fixe ou par rapport au soleil. Si vos étés sont chauds, réglez aussi la protection solaire et sa fin, qui ferment quand le soleil arrive sur la façade et rouvrent quand il la quitte — l\'orientation de la façade se déclare une fois dans l\'onglet « Volets ».}}</li>';
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
									<span class="help-block" style="margin:0;">{{Suspendre arrête les quatre moments sans désactiver le groupe : les boutons continuent de fonctionner, le groupe reste sur le tableau de bord, et les commandes « Suspendre » et « Reprendre » se pilotent en scénario — un mode vacances, des volets ouverts pour les peintres.}}</span>
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
						<!--
						     La façade appartient au groupe, pas à ses moments : un groupe
						     rassemble les volets d'une pièce ou d'une façade, et son
						     orientation est la même pour tous ses moments. Déclarée une
						     seule fois ici, elle sert à la fois aux déclencheurs « quand le
						     soleil arrive / quitte la façade » et à la condition de soleil
						     de l'onglet « Programmation ». Écrite moment par moment, elle se
						     serait contredite d'un bloc à l'autre sans que rien ne le dise.
						-->
						<fieldset>
							<legend><i class="fas fa-compass"></i> {{Façade}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">
									{{Le soleil y arrive à}}
									<sup><i class="fas fa-question-circle" title="{{L'azimut où le soleil apparaît sur cette façade : la direction d'où il vient, comptée depuis le nord, 90° à l'est, 180° plein sud, 270° à l'ouest. Une façade plein sud voit le soleil de 135° (sud-est) à 315° (nord-ouest). Pas de boussole ? Ouvrez cette page au moment précis où le soleil arrive sur la façade et recopiez ici l'azimut affiché plus bas ; recommencez le soir, quand il la quitte, pour l'autre valeur.}}"></i></sup>
								</label>
								<div class="col-sm-8">
									<div class="input-group">
										<input type="number" min="0" max="360" step="5" class="eqLogicAttr form-control roundedLeft vabFacadeFrom" data-l1key="configuration" data-l2key="facade_from" placeholder="135">
										<span class="input-group-addon roundedRight">°</span>
									</div>
									<!-- Le nom de la direction, tenu à jour par le JS à chaque
									     frappe : « 200 » ne se vérifie pas, « sud-sud-ouest » se
									     vérifie d'un coup d'oeil par la fenêtre. -->
									<span class="help-block vabFacadeFromName" style="margin:2px 0 0 0;"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">
									{{Il la quitte à}}
									<sup><i class="fas fa-question-circle" title="{{L'azimut où le soleil abandonne cette façade. La plage peut passer par le nord : « de 300° à 30° » est une façade nord-ouest–nord-est, et elle contient bien 350°. Deux valeurs identiques font une façade vide : le soleil n'y serait jamais, et la condition de soleil empêcherait le moment tous les jours.}}"></i></sup>
								</label>
								<div class="col-sm-8">
									<div class="input-group">
										<input type="number" min="0" max="360" step="5" class="eqLogicAttr form-control roundedLeft vabFacadeTo" data-l1key="configuration" data-l2key="facade_to" placeholder="315">
										<span class="input-group-addon roundedRight">°</span>
									</div>
									<span class="help-block vabFacadeToName" style="margin:2px 0 0 0;"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">
									{{Hauteur minimale}}
									<sup><i class="fas fa-question-circle" title="{{La hauteur du soleil au-dessus de l'horizon en dessous de laquelle il ne chauffe pas cette façade. Sans elle, un jour de décembre où le soleil rase à 8° déclencherait une protection solaire qui n'a aucun sens : la direction est bonne, mais le soleil passe derrière les maisons d'en face. 15° est un bon point de départ ; montez la valeur si un arbre ou un mur vous protège déjà en début de journée.}}"></i></sup>
								</label>
								<div class="col-sm-8">
									<div class="input-group">
										<input type="number" min="-10" max="90" step="5" class="eqLogicAttr form-control roundedLeft vabFacadeElevation" data-l1key="configuration" data-l2key="facade_elevation" placeholder="15">
										<span class="input-group-addon roundedRight">°</span>
									</div>
								</div>
							</div>
							<!--
							     Ce que cette façade donne sur une année, en une phrase.
							     À 50,5° de latitude nord le soleil ne dépasse jamais 310°
							     au coucher : une façade déclarée jusqu'à 340° est
							     parfaitement cohérente à l'écran et ne se comportera
							     jamais comme l'utilisateur l'imagine. Le plugin sait tout
							     ce qu'il faut pour le lui dire, c'est ici qu'il le dit —
							     sous les champs qu'il faudrait corriger. La phrase vient
							     du serveur : elle est calculée par le même code que les
							     déclenchements.
							-->
							<div class="form-group">
								<label class="col-sm-4 control-label">&nbsp;</label>
								<div class="col-sm-8">
									<span class="help-block vabFacadeReach" style="margin:0;"></span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Soleil maintenant}}</label>
								<div class="col-sm-8">
									<!-- Où est le soleil en ce moment, juste sous les deux
									     azimuts : c'est l'outil de réglage de la façade. On
									     regarde par la fenêtre, on voit le soleil arriver dessus,
									     on lit l'azimut ici et on le recopie au-dessus — sans
									     boussole, sans sortir, et sans attendre une saison pour
									     vérifier. -->
									<span id="span_voletautobeSun" class="label label-default" title="{{La position du soleil maintenant, calculée à partir de la position de votre installation. Ouvrez cette page au moment où le soleil arrive sur la façade : l'azimut affiché ici est celui à recopier au-dessus.}}">—</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">&nbsp;</label>
								<div class="col-sm-8">
									<span class="help-block" style="margin:0;">{{Cette orientation sert deux fois dans l'onglet « Programmation » : elle déclenche les moments réglés sur « quand le soleil arrive sur la façade » ou « quand il la quitte », et elle fixe la fenêtre de la condition de soleil. Un groupe par façade est donc la bonne façon de découper la maison. Les aperçus, eux, sont calculés sur la façade enregistrée : sauvegardez pour les voir suivre.}}</span>
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
				<!--
				     Les quatre moments dans l'ordre de la journée, deux par ligne :
				     matin et protection solaire, puis fin de protection et soir. Les
				     deux moments de la protection sont ainsi voisins à l'écran, ce
				     qu'ils sont dans la journée — l'un ferme, l'autre rouvre, et les
				     régler l'un sans l'autre laisse la pièce dans le noir jusqu'au
				     soir.
				-->
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
							'{{Fermer aux trois quarts quand le soleil arrive sur la façade, les jours chauds seulement, et ne rien faire le reste de l\'année : c\'est le moment qui justifie à lui seul la sonde de température. Fermer à heure fixe convenait en juin, laissait le soleil taper une heure de trop en août et ne correspondait à rien en octobre ; la façade, elle, suit les saisons toute seule.}}');
						?>
					</form>
				</div>
				<div class="clearfix"></div>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<?php
						voletautobeSlot('shade_end', '{{Fin de protection}}', 'fas fa-cloud-sun',
							'{{Rouvrir quand le soleil quitte la façade. Sans ce moment, les volets baissés à midi restent baissés jusqu\'au soir et la pièce reste sombre pour rien, alors qu\'il n\'y a plus rien à protéger. Il ne demande aucune condition de soleil : à l\'instant où il tombe, le soleil vient justement de quitter la façade.}}');
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
