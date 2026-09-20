<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* Une modale de plugin est incluse par index.php, qui n'a vérifié que la
 * connexion : le profil, lui, se contrôle ici, comme sur la page du plugin. */
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}

/*
 * La charpente seulement : la liste est posée par le JS depuis l'action ajax
 * « volets ». Les noms affichés ici viennent des équipements de l'utilisateur,
 * et sont donc écrits en texte par le JS, jamais en balisage.
 */
?>
<div id="div_voletautobePicker">
	<div class="alert alert-info" style="margin:0 0 10px 0;">
		{{Le plugin a parcouru votre installation et retenu les équipements qui savent monter, descendre ou se mettre à une position. Cochez ceux de ce groupe. Les boutons}} <i class="fas fa-arrow-up"></i> <i class="fas fa-stop"></i> <i class="fas fa-arrow-down"></i> {{font réellement bouger le volet : c'est la seule façon de savoir lequel est « Module 3 ». Le bouton}} <i class="fas fa-sliders-h"></i> {{montre les commandes retenues, qu'on peut changer.}}
		<br>{{Votre volet n'apparaît pas ? « Tous les équipements » montre tout ce qui porte une commande d'action — un module, un micromodule, un moteur mal déclaré — à charge de désigner vous-même la commande qui monte et celle qui descend.}}
	</div>

	<div class="row" style="margin:0 0 10px 0;">
		<div class="col-sm-6" style="padding-left:0;">
			<div class="input-group">
				<span class="input-group-addon roundedLeft"><i class="fas fa-search"></i></span>
				<input type="text" class="form-control roundedRight" id="in_voletautobeSearch" placeholder="{{Filtrer par nom de volet ou de pièce}}">
			</div>
		</div>
		<div class="col-sm-6 text-right" style="padding-right:0;">
			<div class="btn-group">
				<a class="btn btn-sm btn-default active vabFilter" data-filter="flap"><i class="fas fa-align-justify"></i> {{Volets}} <span class="badge" data-count="flap">0</span></a>
				<a class="btn btn-sm btn-default active vabFilter" data-filter="bso"><i class="fas fa-grip-lines"></i> {{BSO}} <span class="badge" data-count="bso">0</span></a>
				<a class="btn btn-sm btn-default active vabFilter" data-filter="guess"><i class="fas fa-question"></i> {{Autres}} <span class="badge" data-count="guess">0</span></a>
				<a class="btn btn-sm btn-default vabFilter" data-filter="unknown" title="{{Montre tous les équipements qui portent une commande d'action}}"><i class="fas fa-th-list"></i> {{Tous les équipements}} <span class="badge" data-count="unknown">0</span></a>
			</div>
		</div>
	</div>

	<div id="div_voletautobePickerList" style="max-height:55vh;overflow:auto;">
		<div class="text-center" style="padding:30px;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
	</div>

	<div style="border-top:1px solid rgba(128,128,128,0.3);margin-top:10px;padding-top:10px;">
		<span id="span_voletautobePickerCount" class="label label-info">0 {{sélectionné(s)}}</span>
		<span class="pull-right">
			<a class="btn btn-default" id="bt_voletautobePickerCancel">{{Annuler}}</a>
			<a class="btn btn-success" id="bt_voletautobePickerValidate"><i class="fas fa-check"></i> {{Valider la sélection}}</a>
		</span>
	</div>
</div>
