/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* ================================================================== OUTILS */

/* Les quatre moments, dans l'ordre de la journée. La liste est ici une fois pour
   toutes : ajouter un moment sans la mettre à jour donnerait un bloc qui
   s'affiche, se règle, et n'est jamais enregistré. */
var voletautobeSlots = ['morning', 'heat', 'shade_end', 'evening']

/* Les volets du groupe ouvert. C'est la source de vérité de l'onglet Volets :
   l'affichage en découle, et saveEqLogic la recopie dans la configuration. */
var voletautobeSelection = []

/* Ce que le sélecteur a trouvé dans l'installation, gardé le temps de la
   fenêtre pour que filtrer et chercher ne relancent pas la découverte. */
var voletautobePicker = {
  groups: [],
  checked: {},
  /* Les commandes choisies à la main, par équipement :
     { eq: {up: id, down: id, stop: id, slider: id, invert: 0|1} }.
     Elles vivent ici et non dans le DOM, qui est reconstruit à chaque frappe
     dans le champ de recherche — un choix posé dans une liste déroulante
     disparaîtrait à la lettre suivante. */
  choice: {},
  search: '',
  filters: { flap: true, bso: true, guess: true, unknown: false },
  /* Le sélecteur complet coûte un parcours de toute l'installation : il n'est
     demandé au serveur que si l'utilisateur l'ouvre, et une seule fois. */
  loadedAll: false
}

/* Les sondes de l'installation, demandées une fois par chargement de page :
   la liste ne change pas entre deux groupes, et la redemander à chaque
   ouverture ferait parcourir toutes les commandes de Jeedom pour rien. */
var voletautobeSensors = null

/* Vrai pendant que printEqLogic repose les valeurs à l'écran. Reposer une
   valeur dans un champ émet « change » exactement comme une saisie : sans ce
   drapeau, ouvrir un groupe suffirait à le déclarer modifié, et l'avertissement
   « quitter sans enregistrer ? » tomberait sans que rien n'ait été touché. */
var voletautobeRendering = false

/* Requête AJAX vers le contrôleur du plugin.
   _options : { button: <élément à désactiver pendant l'appel>,
                failure: <fonction recevant le message d'erreur>,
                silent: true pour ne rien afficher } */
function voletautobeAjax(_action, _data, _success, _options) {
  var options = _options || {}
  var button = isset(options.button) ? options.button : null
  var released = false
  var release = function () {
    if (button === null || released) { return }
    released = true
    button.removeAttribute('disabled')
    button.classList.remove('disabled')
  }
  if (button !== null) {
    button.setAttribute('disabled', 'disabled')
    button.classList.add('disabled')
    /* Filet de sécurité : jamais de bouton bloqué si la réponse n'arrive pas. */
    setTimeout(release, 60000)
  }

  var payload = Object.assign({ action: _action }, _data || {})
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/voletautobe/core/ajax/voletautobe.ajax.php',
    data: payload,
    dataType: 'json',
    noDisplayError: true,
    error: function (request, status, error) {
      release()
      if (isset(options.failure)) {
        options.failure('{{Jeedom n\'a pas répondu.}}')
        return
      }
      if (options.silent === true) { return }
      domUtils.handleAjaxError(request, status, error)
    },
    success: function (data) {
      release()
      if (data.state != 'ok') {
        if (isset(options.failure)) {
          options.failure(data.result)
          return
        }
        if (options.silent !== true) {
          jeedomUtils.showAlert({ message: data.result, level: 'danger' })
        }
        return
      }
      _success(data.result)
    }
  })
}

/* Identifiant du groupe ouvert, ou null s'il n'est pas encore enregistré. */
function voletautobeCurrentId(_quiet) {
  var input = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  if (input === null || input.value === '') {
    if (_quiet !== true) {
      jeedomUtils.showAlert({ message: '{{Enregistrez d\'abord le groupe.}}', level: 'warning' })
    }
    return null
  }
  return input.value
}

/* Le coeur teste DEUX drapeaux avant d'avertir qu'on quitte une page modifiée :
   n'en poser qu'un laisse passer la perte de données une fois sur deux. */
function voletautobeMarkModified() {
  if (voletautobeRendering) { return }
  if (typeof jeeFrontEnd !== 'undefined') { jeeFrontEnd.modifyWithoutSave = true }
  window.modifyWithoutSave = true
}

/* Une ligne de texte posée sans balisage : les noms affichés viennent des
   équipements de l'utilisateur, et rien ne garantit ce qu'ils contiennent. */
function voletautobeText(_tag, _className, _text) {
  var element = document.createElement(_tag)
  if (_className !== '') { element.className = _className }
  element.textContent = String(isset(_text) ? _text : '')
  return element
}

/* Applique l'inversion d'un volet à une position brute.
   Le serveur, lui, ne peut pas le faire pour l'action « states » : il ne reçoit
   qu'un identifiant de commande, pas la case cochée. La convention du plugin
   est 0 % fermé, 100 % ouvert ; tous les protocoles ne la respectent pas. */
function voletautobeApplyInvert(_value, _invert) {
  if (_value === null || _value === undefined || _value === '') { return null }
  var value = parseInt(_value, 10)
  if (isNaN(value)) { return null }
  if (value < 0) { value = 0 }
  if (value > 100) { value = 100 }
  return (_invert == 1) ? (100 - value) : value
}

/* Les seize secteurs de la rose des vents, de 22,5° chacun, à partir du nord.
   La table est la jumelle de voletautobeSun::$_compass : deux tables qui
   divergeraient feraient dire deux choses différentes au même réglage selon
   qu'on le lit dans le champ ou dans l'aperçu, et c'est le nom, pas le nombre,
   que l'utilisateur va vérifier. */
var voletautobeCompassNames = [
  '{{nord}}', '{{nord-nord-est}}', '{{nord-est}}', '{{est-nord-est}}',
  '{{est}}', '{{est-sud-est}}', '{{sud-est}}', '{{sud-sud-est}}',
  '{{sud}}', '{{sud-sud-ouest}}', '{{sud-ouest}}', '{{ouest-sud-ouest}}',
  '{{ouest}}', '{{ouest-nord-ouest}}', '{{nord-ouest}}', '{{nord-nord-ouest}}'
]

/* Le nom de direction d'un azimut. Le calcul est fait ici et non demandé au
   serveur : le nom doit suivre la frappe, et un aller-retour par azimut tapé
   afficherait « sud » sous un champ déjà passé à 280. */
function voletautobeCompass(_azimuth) {
  var azimuth = parseFloat(_azimuth)
  if (isNaN(azimuth)) { return '' }
  azimuth = ((azimuth % 360) + 360) % 360
  return voletautobeCompassNames[Math.round(azimuth / 22.5) % 16]
}

/* Un angle en degrés tel qu'il est dans un champ, ramené dans ses bornes.
   Un champ vidé reprend le défaut plutôt que de partir vide : le serveur
   lirait « » comme 0, c'est-à-dire plein nord et 0° de hauteur — un réglage
   que personne n'a demandé et qui ne déclencherait plus rien. */
function voletautobeDegrees(_value, _default, _min, _max) {
  var value = parseFloat(String(_value).replace(',', '.'))
  if (isNaN(value)) { return _default }
  if (value < _min) { value = _min }
  if (value > _max) { value = _max }
  return value
}

/* ========================================================= VOLETS DU GROUPE */

/* La position d'un volet, en toutes lettres. « 0 % » et « 100 % » se lisent mal
   d'un coup d'oeil : c'est « fermé » et « ouvert » qu'on cherche sur l'écran,
   et un volet muet doit dire qu'il ne sait pas plutôt que d'afficher 0. */
function voletautobePositionBadge(_value) {
  var badge = document.createElement('span')
  badge.style.marginRight = '6px'
  if (_value === null || _value === undefined || _value === '') {
    badge.className = 'label label-default'
    badge.style.opacity = '0.5'
    badge.textContent = '—'
    badge.title = '{{Position inconnue : ce volet ne publie pas son état.}}'
    return badge
  }
  var position = parseInt(_value, 10)
  if (isNaN(position)) { position = 0 }
  if (position <= 0) {
    badge.className = 'label label-default'
    badge.textContent = '{{fermé}}'
  } else if (position >= 100) {
    badge.className = 'label label-warning'
    badge.textContent = '{{ouvert}}'
  } else {
    badge.className = 'label label-info'
    badge.textContent = position + ' %'
  }
  badge.title = '{{0 % = fermé, 100 % = ouvert}}'
  return badge
}

/* Dessine les volets retenus. Un groupe vide le dit : une liste vide sans un
   mot ressemble à un chargement qui n'a pas abouti. */
function voletautobeRenderVolets() {
  var container = document.getElementById('div_voletautobeVolets')
  if (container === null) { return }
  container.innerHTML = ''

  if (voletautobeSelection.length === 0) {
    var empty = document.createElement('div')
    empty.className = 'alert alert-warning'
    empty.style.margin = '5px'
    empty.textContent = '{{Aucun volet dans ce groupe : la programmation n\'aura rien à commander. Cliquez sur « Choisir les volets ».}}'
    container.appendChild(empty)
    return
  }

  var table = document.createElement('table')
  table.className = 'table table-condensed table-bordered'
  var tbody = document.createElement('tbody')
  table.appendChild(tbody)

  for (var i = 0; i < voletautobeSelection.length; i++) {
    var volet = voletautobeSelection[i]
    var row = document.createElement('tr')

    var nameCell = document.createElement('td')
    nameCell.appendChild(voletautobePositionBadge(isset(volet.value) ? volet.value : null))
    nameCell.appendChild(voletautobeText('span', '', volet.name))
    if (isset(volet.object) && volet.object !== '') {
      var room = voletautobeText('span', 'label label-default', volet.object)
      room.style.marginLeft = '8px'
      nameCell.appendChild(room)
    }
    /* L'inversion se voit ici, sinon elle ne se voit nulle part : une position
       affichée à l'envers se remarque, une case cochée par erreur au fond du
       sélecteur ne se retrouve pas. */
    if (volet.invert == 1) {
      var inverted = voletautobeText('span', 'label label-info', '{{inversé}}')
      inverted.style.marginLeft = '8px'
      nameCell.appendChild(inverted)
    }
    /* Un volet supprimé de Jeedom reste dans la configuration : le dire est la
       seule façon d'expliquer pourquoi le groupe ne bouge plus tout entier. */
    if (volet.missing == 1) {
      var missing = voletautobeText('span', 'label label-danger', '{{Équipement supprimé}}')
      missing.style.marginLeft = '8px'
      nameCell.appendChild(missing)
    } else if (isset(volet.enabled) && volet.enabled == 0) {
      var disabled = voletautobeText('span', 'label label-warning', '{{Équipement désactivé}}')
      disabled.style.marginLeft = '8px'
      nameCell.appendChild(disabled)
    }
    row.appendChild(nameCell)

    var actionCell = document.createElement('td')
    actionCell.style.width = '170px'
    actionCell.style.textAlign = 'right'
    if (volet.missing != 1) {
      actionCell.innerHTML = '<a class="btn btn-xs btn-default vabVoletUp" title="{{Ouvrir ce volet}}"><i class="fas fa-arrow-up"></i></a> '
                           + '<a class="btn btn-xs btn-default vabVoletStop" title="{{Arrêter ce volet}}"><i class="fas fa-stop"></i></a> '
                           + '<a class="btn btn-xs btn-default vabVoletDown" title="{{Fermer ce volet}}"><i class="fas fa-arrow-down"></i></a> '
    }
    actionCell.innerHTML += '<a class="btn btn-xs btn-danger vabVoletRemove" title="{{Retirer du groupe}}"><i class="fas fa-times"></i></a>'
    actionCell.setAttribute('data-eq', volet.eq)
    row.appendChild(actionCell)

    tbody.appendChild(row)
  }
  container.appendChild(table)
}

/* L'état de la programmation : l'étiquette, le bouton qui l'inverse. Un groupe
   suspendu doit se voir sans qu'on ait à chercher — c'est la panne la plus
   discrète du plugin : tout fonctionne, et rien ne bouge. */
function voletautobeShowPaused(_paused, _since) {
  var label = document.getElementById('span_voletautobePaused')
  var button = document.getElementById('bt_voletautobePause')
  if (label === null || button === null) { return }

  if (_paused) {
    label.className = 'label label-warning'
    label.textContent = (isset(_since) && _since !== '')
      ? '{{Suspendue depuis le}} ' + _since
      : '{{Suspendue}}'
    button.innerHTML = '<i class="fas fa-play"></i> {{Reprendre}}'
    button.setAttribute('data-state', '0')
  } else {
    label.className = 'label label-success'
    label.textContent = '{{Active}}'
    button.innerHTML = '<i class="fas fa-pause"></i> {{Suspendre}}'
    button.setAttribute('data-state', '1')
  }
}

/* La mesure retenue par le groupe, avec le nom de la sonde qui l'a donnée.
   Une condition de température réglée sur une sonde muette ne fait rien du
   tout : c'est ici, à côté du choix de la sonde, que ça doit se voir. */
function voletautobeShowTemperature(_value, _name) {
  var target = document.getElementById('span_voletautobeTemperature')
  if (target === null) { return }
  if (_value === null || _value === undefined || _value === '') {
    target.className = 'label label-warning'
    target.textContent = (isset(_name) && _name !== '')
      ? '{{Sonde illisible}} : ' + _name
      : '{{Aucune sonde}}'
    return
  }
  target.className = 'label label-info'
  target.textContent = String(_value).replace('.', ',') + ' °C'
    + ((isset(_name) && _name !== '') ? ' — ' + _name : '')
}

/* Où est le soleil maintenant, juste sous les deux azimuts de la façade. C'est
   l'outil de réglage du bloc : on regarde par la fenêtre, on voit le soleil
   arriver sur la façade, on lit l'azimut ici et on le recopie au-dessus — sans
   boussole, sans sortir et sans attendre une saison pour vérifier. */
function voletautobeShowSun(_azimuth, _elevation) {
  var target = document.getElementById('span_voletautobeSun')
  if (target === null) { return }
  if (!isset(_azimuth) || _azimuth === '' || !isset(_elevation) || _elevation === '') {
    /* Sans position d'installation, le serveur ne rend rien plutôt que la
       position du golfe de Guinée : une direction plausible et fausse serait
       recopiée telle quelle dans la façade. */
    target.className = 'label label-warning'
    target.textContent = '{{Position du soleil inconnue}}'
    return
  }
  var azimuth = Math.round(parseFloat(_azimuth))
  var elevation = Math.round(parseFloat(_elevation))
  /* Sous l'horizon, l'azimut reste parfaitement défini et parfaitement hors
     sujet : l'annoncer en degrés de hauteur ferait régler une façade sur la
     position de minuit. */
  target.className = (elevation < 0) ? 'label label-default' : 'label label-info'
  target.textContent = '{{Soleil au}} ' + azimuth + '° (' + voletautobeCompass(azimuth) + '), '
    + ((elevation < 0) ? '{{sous l\'horizon}}' : elevation + '° {{de hauteur}}')
}

/* ============================================================ FAÇADE DU GROUPE */

/* La façade d'un groupe neuf. Une maison plein sud voit le soleil de 135°
   (sud-est) à 315° (nord-ouest), et sous 15° de hauteur il ne chauffe plus
   grand-chose. Ces valeurs sont les jumelles de celles de voletautobe::facade() :
   deux points de départ qui divergeraient feraient afficher une orientation que
   le serveur n'applique pas. */
var voletautobeFacadeDefaults = { from: 135, to: 315, elevation: 15 }

/* Les trois champs de la façade. Ce sont des .eqLogicAttr — le coeur les pose et
   les relit tout seul — mais il ne sait ni écrire « sud-est » à côté, ni les
   remplir sur un groupe enregistré avant que la façade n'existe. */
var voletautobeFacadeFields = '.vabFacadeFrom, .vabFacadeTo, .vabFacadeElevation'

/* La façade telle qu'elle est à l'écran, bornée. Elle est lue dans le formulaire
   et non dans la réponse du serveur : les rappels des moments doivent suivre la
   frappe, sinon on croit que la façade qu'on vient de taper n'a pas été prise. */
function voletautobeFacadeValues() {
  var from = document.querySelector('.vabFacadeFrom')
  var to = document.querySelector('.vabFacadeTo')
  var elevation = document.querySelector('.vabFacadeElevation')
  return {
    from: voletautobeDegrees((from === null) ? '' : from.value, voletautobeFacadeDefaults.from, 0, 360),
    to: voletautobeDegrees((to === null) ? '' : to.value, voletautobeFacadeDefaults.to, 0, 360),
    elevation: voletautobeDegrees((elevation === null) ? '' : elevation.value, voletautobeFacadeDefaults.elevation, -10, 90)
  }
}

/* Pose la façade du groupe ouvert. Le coeur a déjà rempli les trois champs, mais
   un groupe enregistré avant que la façade n'existe n'a aucune de ces clés : les
   champs resteraient vides, le serveur lirait « » comme 0 — plein nord et 0° de
   hauteur — et la protection solaire se déclencherait au milieu de la nuit. */
function voletautobeApplyFacade(_configuration) {
  var configuration = _configuration || {}
  var facade = {
    '.vabFacadeFrom': voletautobeDegrees(configuration.facade_from, voletautobeFacadeDefaults.from, 0, 360),
    '.vabFacadeTo': voletautobeDegrees(configuration.facade_to, voletautobeFacadeDefaults.to, 0, 360),
    '.vabFacadeElevation': voletautobeDegrees(configuration.facade_elevation, voletautobeFacadeDefaults.elevation, -10, 90)
  }
  for (var selector in facade) {
    var field = document.querySelector(selector)
    if (field !== null) { field.value = facade[selector] }
  }
  voletautobeSyncFacade()
}

/* La façade a changé : son nom de direction s'écrit à côté, et les quatre
   moments la rappellent. Un moment qui afficherait encore l'ancienne orientation
   ferait croire que la saisie n'a pas porté, et l'utilisateur la retaperait là
   où elle n'existe plus. */
function voletautobeSyncFacade() {
  voletautobeShowCompass(document, '.vabFacadeFrom', '.vabFacadeFromName')
  voletautobeShowCompass(document, '.vabFacadeTo', '.vabFacadeToName')
  for (var s = 0; s < voletautobeSlots.length; s++) {
    voletautobeSyncSlotUi(voletautobeSlots[s])
  }
}

/* ============================================================ SONDES ET GROUPE */

/* Remplit la liste des sondes et y repose le choix du groupe. Le coeur a déjà
   tenté de le faire, sur une liste qui ne contenait alors que « Celle du
   plugin » : sans cette seconde passe, la sonde choisie retomberait sur le
   défaut à chaque ouverture, et l'enregistrement suivant l'effacerait. */
function voletautobeFillSensors(_selected) {
  var select = document.getElementById('in_voletautobeSensor')
  if (select === null || voletautobeSensors === null) { return }

  while (select.options.length > 1) { select.remove(1) }
  for (var i = 0; i < voletautobeSensors.length; i++) {
    var sensor = voletautobeSensors[i]
    var option = document.createElement('option')
    option.value = sensor.id
    var suffix = (isset(sensor.object) && sensor.object !== '') ? ' — ' + sensor.object : ''
    option.textContent = sensor.name + suffix
    select.appendChild(option)
  }
  select.value = isset(_selected) ? String(_selected) : ''
  /* La sonde enregistrée a pu être supprimée depuis : la liste retomberait
     silencieusement sur « Celle du plugin », ce qui est faux. */
  if (select.value === '' && isset(_selected) && String(_selected) !== '') {
    var orphan = document.createElement('option')
    orphan.value = _selected
    orphan.textContent = '{{Commande supprimée}} (#' + _selected + ')'
    select.appendChild(orphan)
    select.value = String(_selected)
  }
}

function voletautobeLoadSensors(_selected) {
  if (voletautobeSensors !== null) {
    voletautobeFillSensors(_selected)
    return
  }
  voletautobeAjax('temperatures', {}, function (result) {
    voletautobeSensors = isset(result.sensors) ? result.sensors : []
    voletautobeFillSensors(_selected)
  }, { silent: true })
}

/* Ce que le serveur sait du groupe : ses volets tels qu'ils s'appellent
   aujourd'hui, les prochaines occurrences de chaque moment, et la température
   qu'il retiendrait maintenant. */
function voletautobeLoadGroup(_id) {
  voletautobeAjax('group', { id: _id }, function (result) {
    /* La réponse peut arriver après que l'utilisateur a ouvert un autre groupe :
       l'écrire alors mélangerait deux équipements. */
    var current = voletautobeCurrentId(true)
    if (current === null || String(current) !== String(_id)) { return }

    /* Les noms viennent du serveur, la composition du groupe reste celle de
       l'écran : l'utilisateur a peut-être coché des volets depuis. */
    var volets = isset(result.volets) ? result.volets : []
    for (var i = 0; i < voletautobeSelection.length; i++) {
      for (var j = 0; j < volets.length; j++) {
        if (String(volets[j].eq) === String(voletautobeSelection[i].eq)) {
          voletautobeSelection[i].name = volets[j].name
          voletautobeSelection[i].object = volets[j].object
          voletautobeSelection[i].missing = volets[j].missing
          voletautobeSelection[i].enabled = volets[j].enabled
        }
      }
    }
    voletautobeRenderVolets()
    voletautobeRefreshStates(0)

    for (var s = 0; s < voletautobeSlots.length; s++) {
      voletautobeShowPreview(voletautobeSlots[s], result[voletautobeSlots[s]])
    }
    voletautobeShowPaused(result.paused == 1, result.pausedSince)
    voletautobeShowTemperature(
      isset(result.temperature) ? result.temperature : null,
      isset(result.temperatureName) ? result.temperatureName : '')
    voletautobeShowSun(
      isset(result.azimuth) ? result.azimuth : null,
      isset(result.elevation) ? result.elevation : null)
  }, { silent: true })
}

/* Relit la position de chaque volet du groupe.
   _delay temporise : un volet met une vingtaine de secondes à parcourir sa
   course, et relire tout de suite après un ordre afficherait la position de
   départ — celle qu'on vient justement de quitter. */
var voletautobeStatesTimer = null
function voletautobeRefreshStates(_delay) {
  if (voletautobeStatesTimer !== null) { clearTimeout(voletautobeStatesTimer) }
  voletautobeStatesTimer = setTimeout(function () {
    var cmds = {}
    var wanted = 0
    for (var i = 0; i < voletautobeSelection.length; i++) {
      var volet = voletautobeSelection[i]
      if (volet.missing == 1) { continue }
      if (!isset(volet.state) || volet.state === null || volet.state === '') { continue }
      cmds[volet.eq] = volet.state
      wanted++
    }
    if (wanted === 0) { return }

    voletautobeAjax('states', { cmds: JSON.stringify(cmds) }, function (result) {
      for (var j = 0; j < voletautobeSelection.length; j++) {
        var known = voletautobeSelection[j]
        /* hasOwnProperty : une position rendue à null est une réponse, pas un
           silence — garder l'ancienne valeur afficherait une position périmée
           pour un volet qui vient justement de cesser de la publier. */
        if (!result.hasOwnProperty(known.eq)) { continue }
        known.value = voletautobeApplyInvert(result[known.eq], known.invert)
      }
      voletautobeRenderVolets()
    }, { silent: true })
  }, isset(_delay) ? _delay : 0)
}

/* =============================================================== SÉLECTEUR */

/* Ferme le sélecteur. jeeDialog n'expose pas de fonction de fermeture : elle
   est accrochée à l'élément de la fenêtre, que le getter du coeur retrouve. */
function voletautobeClosePicker() {
  if (typeof jeeDialog === 'undefined') { return }
  var dialog = jeeDialog.get('#jee_modal')
  if (dialog !== null && typeof dialog.close === 'function') { dialog.close() }
}

function voletautobeOpenPicker() {
  if (typeof jeeDialog === 'undefined') {
    jeedomUtils.showAlert({ message: '{{Cette version de Jeedom ne sait pas ouvrir le sélecteur.}}', level: 'danger' })
    return
  }
  /* Les volets déjà retenus arrivent cochés : le sélecteur sert aussi bien à
     ajouter qu'à retirer, et rouvrir sur une liste vierge donnerait
     l'impression d'avoir tout perdu. */
  voletautobePicker.checked = {}
  voletautobePicker.choice = {}
  for (var i = 0; i < voletautobeSelection.length; i++) {
    var known = voletautobeSelection[i]
    voletautobePicker.checked[known.eq] = true
    /* Ce qui a déjà été retenu pour ce volet, y compris un choix fait à la main
       la fois précédente : le sélecteur doit rouvrir sur l'existant. */
    voletautobePicker.choice[known.eq] = {
      up: known.up, down: known.down, stop: known.stop, slider: known.slider,
      invert: isset(known.invert) ? known.invert : 0
    }
  }
  voletautobePicker.search = ''
  voletautobePicker.loadedAll = false

  jeeDialog.dialog({
    id: 'jee_modal',
    title: '{{Choisir les volets de ce groupe}}',
    contentUrl: 'index.php?v=d&plugin=voletautobe&modal=volet.picker',
    callback: function () { voletautobePickerStart() }
  })
}

/* La fenêtre existe : on branche ses écouteurs et on lance la découverte.
   Les écouteurs sont posés ici, sur la racine de la fenêtre, et disparaissent
   avec elle — sur le corps du document, ils s'empileraient à chaque ouverture. */
function voletautobePickerStart() {
  var root = document.getElementById('div_voletautobePicker')
  if (root === null) { return }

  root.addEventListener('input', function (event) {
    if (event.target.closest('#in_voletautobeSearch')) {
      voletautobePicker.search = event.target.value.trim().toLowerCase()
      voletautobePickerRender()
    }
  })

  root.addEventListener('click', function (event) {
    var target = null

    if (target = event.target.closest('.vabFilter')) {
      var filter = target.getAttribute('data-filter')
      voletautobePicker.filters[filter] = !voletautobePicker.filters[filter]
      target.classList.toggle('active', voletautobePicker.filters[filter])
      /* Les équipements inconnus ne sont pas dans la première réponse : les
         montrer demande de redemander la liste, complète cette fois. */
      if (filter === 'unknown' && voletautobePicker.filters.unknown && !voletautobePicker.loadedAll) {
        voletautobePickerLoad(true, target)
        return
      }
      voletautobePickerRender()
      return
    }
    if (target = event.target.closest('.vabPickTune')) {
      var block = target.closest('.vabPickRow').querySelector('.vabPickCmds')
      block.style.display = (block.style.display === 'none') ? '' : 'none'
      return
    }
    if (target = event.target.closest('.vabPickRoom')) {
      /* Cocher une pièce entière est le geste le plus fréquent : « tous les
         volets de la chambre » est une intention, pas six décisions. */
      var room = target.getAttribute('data-room')
      var checkboxes = document.querySelectorAll('#div_voletautobePickerList .vabPickVolet[data-room="' + CSS.escape(room) + '"]')
      var check = target.getAttribute('data-check') !== '0'
      for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = check
        voletautobePicker.checked[checkboxes[i].getAttribute('data-eq')] = check
      }
      target.setAttribute('data-check', check ? '0' : '1')
      voletautobePickerCount()
      return
    }
    if (target = event.target.closest('.vabPickUp')) {
      voletautobePickerMove(target, 'up')
      return
    }
    if (target = event.target.closest('.vabPickStop')) {
      voletautobePickerMove(target, 'stop')
      return
    }
    if (target = event.target.closest('.vabPickDown')) {
      voletautobePickerMove(target, 'down')
      return
    }
    if (event.target.closest('#bt_voletautobePickerValidate')) {
      voletautobePickerValidate()
      return
    }
    if (event.target.closest('#bt_voletautobePickerCancel')) {
      voletautobeClosePicker()
      return
    }
  })

  root.addEventListener('change', function (event) {
    var checkbox = event.target.closest('.vabPickVolet')
    if (checkbox !== null) {
      voletautobePicker.checked[checkbox.getAttribute('data-eq')] = checkbox.checked
      voletautobePickerCount()
      return
    }

    var invert = event.target.closest('.vabPickInvert')
    if (invert !== null) {
      var invertEq = invert.getAttribute('data-eq')
      if (!isset(voletautobePicker.choice[invertEq])) { voletautobePicker.choice[invertEq] = {} }
      voletautobePicker.choice[invertEq].invert = invert.checked ? 1 : 0
      /* La pastille suit immédiatement : c'est à elle qu'on voit si la case est
         cochée dans le bon sens — un volet ouvert doit afficher « ouvert ». */
      voletautobePickerRefreshDot(invertEq)
      return
    }

    var select = event.target.closest('.vabPickCmd')
    if (select === null) { return }
    var eq = select.getAttribute('data-eq')
    if (!isset(voletautobePicker.choice[eq])) { voletautobePicker.choice[eq] = {} }
    voletautobePicker.choice[eq][select.getAttribute('data-role')] = (select.value === '') ? null : parseInt(select.value, 10)
    /* Désigner une commande, c'est vouloir le volet : cocher soi-même ensuite
       serait un geste de plus pour rien. */
    var row = select.closest('.vabPickRow').querySelector('.vabPickVolet')
    if (row !== null && !row.checked && select.value !== '') {
      row.checked = true
      voletautobePicker.checked[eq] = true
      voletautobePickerCount()
    }
  })

  voletautobePickerLoad(false, null)
}

/* Demande la liste au serveur. _all ajoute les équipements dont le plugin ne
   sait rien dire. */
function voletautobePickerLoad(_all, _button) {
  var list = document.getElementById('div_voletautobePickerList')
  if (list !== null) {
    list.innerHTML = '<div class="text-center" style="padding:30px;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>'
  }
  voletautobeAjax('volets', _all ? { all: 1 } : {}, function (result) {
    voletautobePicker.groups = isset(result.groups) ? result.groups : []
    if (_all) { voletautobePicker.loadedAll = true }
    voletautobePickerRender()
  }, {
    button: _button,
    failure: function (message) {
      var target = document.getElementById('div_voletautobePickerList')
      if (target === null) { return }
      target.innerHTML = ''
      target.appendChild(voletautobeText('div', 'alert alert-danger', message))
    }
  })
}

/* Les volets d'un groupe du sélecteur, quel que soit le nom que la réponse leur
   donne. */
function voletautobePickerItems(_group) {
  return isset(_group.volets) ? _group.volets : []
}

/* Retrouve un volet dans la découverte, par identifiant d'équipement. */
function voletautobePickerFind(_eq) {
  for (var g = 0; g < voletautobePicker.groups.length; g++) {
    var items = voletautobePickerItems(voletautobePicker.groups[g])
    for (var i = 0; i < items.length; i++) {
      if (String(items[i].eq) === String(_eq)) { return items[i] }
    }
  }
  return null
}

/* Ce qu'on choisit pour un rôle : la désignation de l'utilisateur d'abord, la
   détection ensuite. hasOwnProperty et non une valeur par défaut, sans quoi un
   choix remis à « aucune » retomberait sur la détection. */
function voletautobePickerRole(_volet, _role) {
  var choice = isset(voletautobePicker.choice[_volet.eq]) ? voletautobePicker.choice[_volet.eq] : {}
  return choice.hasOwnProperty(_role) ? choice[_role] : _volet[_role]
}

function voletautobePickerInvert(_eq) {
  var choice = isset(voletautobePicker.choice[_eq]) ? voletautobePicker.choice[_eq] : {}
  return (isset(choice.invert) && choice.invert == 1) ? 1 : 0
}

/* Fait bouger un volet depuis le sélecteur, pour le reconnaître. La position
   est relue ensuite, une fois le volet arrivé. */
function voletautobePickerMove(_button, _order) {
  var eq = _button.getAttribute('data-eq')
  var volet = voletautobePickerFind(eq)
  if (volet === null) { return }
  /* La commande désignée à la main l'emporte : pour un équipement inconnu, le
     serveur n'a rien d'autre pour savoir quoi jouer.

     L'inversion est appliquée ici, et pas côté serveur : quand le navigateur
     nomme une commande précise, le serveur la joue telle quelle — il ne sait
     pas quel rôle elle tenait, donc il ne peut pas l'échanger. Sans cet
     échange, le bouton « monter » d'un volet coché « inversé » jouerait sa
     commande up, celle qui le fait descendre. Ce serait exactement la panne que
     la case existe pour corriger, sur le bouton même que la documentation
     recommande pour la vérifier. */
  var role = _order
  if (voletautobePickerInvert(eq) == 1) {
    role = (_order === 'up') ? 'down' : ((_order === 'down') ? 'up' : _order)
  }
  var cmd = voletautobePickerRole(volet, role)
  voletautobeAjax('switchVolet', {
    eq: eq,
    order: _order,
    cmd: (isset(cmd) && cmd !== null) ? cmd : '',
    invert: voletautobePickerInvert(eq)
  }, function (result) {
    /* Le nom de la commande jouée est la seule preuve qu'on a commandé le bon
       volet avec le bon ordre : « Store terrasse DOWN » se lit, « ok » non. */
    if (isset(result.cmd) && result.cmd !== '') {
      jeedomUtils.showAlert({ message: result.cmd, level: 'success', timeOut: 3000 })
    }
    voletautobePickerStates(eq)
  }, { button: _button })
}

/* Relit la position d'un volet du sélecteur, une fois sa course finie. */
function voletautobePickerStates(_eq) {
  var volet = voletautobePickerFind(_eq)
  if (volet === null || !isset(volet.state) || volet.state === null || volet.state === '') { return }
  var cmds = {}
  cmds[_eq] = volet.state
  setTimeout(function () {
    voletautobeAjax('states', { cmds: JSON.stringify(cmds) }, function (result) {
      if (!result.hasOwnProperty(_eq)) { return }
      volet.value = result[_eq]
      voletautobePickerRefreshDot(_eq)
    }, { silent: true })
  }, 8000)
}

/* Repose la pastille d'un volet sans redessiner la liste : un rendu complet
   rabattrait les commandes dépliées et effacerait la recherche en cours. */
function voletautobePickerRefreshDot(_eq) {
  var dot = document.querySelector('#div_voletautobePickerList .vabPickDot[data-eq="' + _eq + '"]')
  var volet = voletautobePickerFind(_eq)
  if (dot === null || volet === null) { return }
  dot.replaceWith(voletautobePickerDot(_eq, volet.value))
}

function voletautobePickerDot(_eq, _value) {
  var dot = voletautobePositionBadge(voletautobeApplyInvert(_value, voletautobePickerInvert(_eq)))
  dot.classList.add('vabPickDot')
  dot.setAttribute('data-eq', _eq)
  return dot
}

/* Dessine la liste, filtres et recherche appliqués. */
function voletautobePickerRender() {
  var list = document.getElementById('div_voletautobePickerList')
  if (list === null) { return }
  list.innerHTML = ''

  var counts = { flap: 0, bso: 0, guess: 0, unknown: 0 }
  var shown = 0

  for (var g = 0; g < voletautobePicker.groups.length; g++) {
    var group = voletautobePicker.groups[g]
    var items = voletautobePickerItems(group)
    var visible = []

    for (var l = 0; l < items.length; l++) {
      var volet = items[l]
      counts[volet.confidence]++
      if (voletautobePicker.filters[volet.confidence] !== true) { continue }
      if (voletautobePicker.search !== '') {
        var haystack = (volet.name + ' ' + group.object + ' ' + volet.plugin).toLowerCase()
        if (haystack.indexOf(voletautobePicker.search) === -1) { continue }
      }
      visible.push(volet)
    }
    if (visible.length === 0) { continue }
    shown += visible.length

    var header = document.createElement('div')
    header.style.cssText = 'margin:10px 0 4px 0;padding-bottom:3px;border-bottom:1px solid rgba(128,128,128,0.3);'
    header.appendChild(voletautobeText('b', '', group.object))
    var all = document.createElement('a')
    all.className = 'btn btn-xs btn-default pull-right vabPickRoom'
    all.setAttribute('data-room', group.object)
    all.setAttribute('data-check', '1')
    all.textContent = '{{Tout cocher}}'
    header.appendChild(all)
    list.appendChild(header)

    for (var v = 0; v < visible.length; v++) {
      list.appendChild(voletautobePickerRow(visible[v], group.object))
    }
  }

  for (var key in counts) {
    var badge = document.querySelector('#div_voletautobePicker .badge[data-count="' + key + '"]')
    if (badge !== null) { badge.textContent = counts[key] }
  }

  if (shown === 0) {
    var empty = document.createElement('div')
    empty.className = 'alert alert-warning'
    empty.textContent = (voletautobePicker.search !== '')
      ? '{{Aucun volet ne correspond à cette recherche.}}'
      : '{{Aucun volet trouvé avec ces filtres. Essayez « Autres » : certains protocoles ne renseignent pas le type des commandes.}}'
    list.appendChild(empty)
  }
  voletautobePickerCount()
}

function voletautobePickerRow(_volet, _room) {
  var eq = parseInt(_volet.eq, 10)
  var isUnknown = (_volet.confidence === 'unknown')

  var row = document.createElement('div')
  row.className = 'vabPickRow'
  row.setAttribute('data-eq', eq)
  row.style.cssText = 'padding:3px 0;'

  var line = document.createElement('div')
  line.style.cssText = 'display:flex;align-items:center;'

  var label = document.createElement('label')
  label.style.cssText = 'flex:1;margin:0;font-weight:normal;cursor:pointer;'

  var checkbox = document.createElement('input')
  checkbox.type = 'checkbox'
  checkbox.className = 'vabPickVolet'
  checkbox.setAttribute('data-eq', eq)
  checkbox.setAttribute('data-room', _room)
  checkbox.checked = (voletautobePicker.checked[eq] === true)
  checkbox.style.marginRight = '8px'
  label.appendChild(checkbox)

  label.appendChild(voletautobePickerDot(eq, _volet.value))
  label.appendChild(voletautobeText('span', '', _volet.name))

  /* D'où vient le volet et à quel point on en est sûr : sans cela, deux
     équipements homonymes venus de deux plugins seraient indiscernables. */
  var origin = voletautobeText('span', 'label label-default', _volet.plugin)
  origin.style.marginLeft = '8px'
  origin.style.opacity = '0.7'
  label.appendChild(origin)

  if (_volet.confidence !== 'flap') {
    var kind = voletautobeText('span', isUnknown ? 'label label-warning' : 'label label-info',
      (_volet.confidence === 'bso') ? '{{brise-soleil}}'
        : (isUnknown ? '{{à désigner}}' : '{{reconnu au nom}}'))
    kind.style.marginLeft = '4px'
    label.appendChild(kind)
  }
  line.appendChild(label)

  /* Les trois ordres, en clair : bouger le volet est la seule façon de savoir
     lequel des six modules identiques est « Module 3 ». */
  var buttons = document.createElement('span')
  buttons.style.whiteSpace = 'nowrap'
  buttons.innerHTML = '<a class="btn btn-xs btn-default vabPickTune" title="{{Voir et changer les commandes retenues}}"><i class="fas fa-sliders-h"></i></a> '
                    + '<a class="btn btn-xs btn-default vabPickUp" data-eq="' + eq + '" title="{{Ouvrir pour le reconnaître}}"><i class="fas fa-arrow-up"></i></a> '
                    + '<a class="btn btn-xs btn-default vabPickStop" data-eq="' + eq + '" title="{{Arrêter}}"><i class="fas fa-stop"></i></a> '
                    + '<a class="btn btn-xs btn-default vabPickDown" data-eq="' + eq + '" title="{{Fermer pour le reconnaître}}"><i class="fas fa-arrow-down"></i></a>'
  line.appendChild(buttons)
  row.appendChild(line)

  /*
   * Les commandes retenues, modifiables, et le sens de lecture de la position.
   *
   * Dépliées d'office pour un équipement inconnu : c'est la seule chose à y
   * faire, et une ligne cochée sans commande ne commanderait rien. Repliées
   * pour les autres, dont le plugin s'est déjà chargé.
   */
  var cmds = document.createElement('div')
  cmds.className = 'vabPickCmds'
  cmds.style.cssText = 'padding:4px 0 8px 26px;display:' + (isUnknown ? '' : 'none') + ';'
  cmds.appendChild(voletautobePickerCmdSelect(_volet, 'up', '{{Ouvrir avec}}'))
  cmds.appendChild(voletautobePickerCmdSelect(_volet, 'down', '{{Fermer avec}}'))
  cmds.appendChild(voletautobePickerCmdSelect(_volet, 'stop', '{{Arrêter avec}}'))
  cmds.appendChild(voletautobePickerCmdSelect(_volet, 'slider', '{{Position avec}}'))
  cmds.appendChild(voletautobePickerInvertBox(eq))
  row.appendChild(cmds)

  return row
}

/* La case « inversé » : tous les volets ne publient pas leur position dans le
   même sens, et un groupe réglé à 30 % qui s'ouvre aux trois quarts est le seul
   symptôme qu'on en verra. */
function voletautobePickerInvertBox(_eq) {
  var group = document.createElement('div')
  group.style.cssText = 'display:inline-block;margin-right:12px;'

  var label = document.createElement('label')
  label.style.cssText = 'font-weight:normal;margin:0;cursor:pointer;'

  var box = document.createElement('input')
  box.type = 'checkbox'
  box.className = 'vabPickInvert'
  box.setAttribute('data-eq', _eq)
  box.checked = (voletautobePickerInvert(_eq) === 1)
  box.style.marginRight = '5px'
  label.appendChild(box)
  label.appendChild(voletautobeText('span', '', '{{inversé (ce volet publie 0 pour « ouvert »)}}'))

  group.appendChild(label)
  return group
}

/* Une liste déroulante des commandes de l'équipement, positionnée sur celle que
   le plugin a retenue — ou sur celle que l'utilisateur a désignée. */
function voletautobePickerCmdSelect(_volet, _role, _label) {
  var eq = parseInt(_volet.eq, 10)
  var chosen = voletautobePickerRole(_volet, _role)

  var group = document.createElement('div')
  group.style.cssText = 'display:inline-block;margin-right:12px;'
  var caption = voletautobeText('span', '', _label + ' ')
  caption.style.opacity = '0.75'
  group.appendChild(caption)

  var select = document.createElement('select')
  select.className = 'vabPickCmd'
  select.setAttribute('data-eq', eq)
  select.setAttribute('data-role', _role)
  select.style.cssText = 'max-width:200px;display:inline-block;'
  select.classList.add('form-control', 'input-sm')

  var none = document.createElement('option')
  none.value = ''
  none.textContent = '{{aucune}}'
  select.appendChild(none)

  var cmds = isset(_volet.cmds) ? _volet.cmds : []
  for (var i = 0; i < cmds.length; i++) {
    var option = document.createElement('option')
    option.value = cmds[i].id
    /* Le sous-type est affiché : « Consigne » en curseur et « Consigne » en
       bouton ne se distinguent pas autrement, et se tromper de ligne donne un
       volet qui ne se met jamais à la bonne hauteur. */
    option.textContent = cmds[i].name + (isset(cmds[i].subType) && cmds[i].subType === 'slider' ? ' (0-100)' : '')
    if (chosen !== null && chosen !== undefined && String(chosen) === String(cmds[i].id)) {
      option.selected = true
    }
    select.appendChild(option)
  }
  group.appendChild(select)
  return group
}

function voletautobePickerCount() {
  var count = 0
  for (var eq in voletautobePicker.checked) {
    if (voletautobePicker.checked[eq] === true) { count++ }
  }
  var label = document.getElementById('span_voletautobePickerCount')
  if (label !== null) { label.textContent = count + ' {{sélectionné(s)}}' }
}

/* Reporte la sélection dans le groupe. Les commandes retenues suivent : le
   plugin les a trouvées seul, l'utilisateur n'a jamais à les voir. */
function voletautobePickerValidate() {
  var selection = []
  var sansCommande = []

  for (var g = 0; g < voletautobePicker.groups.length; g++) {
    var group = voletautobePicker.groups[g]
    var items = voletautobePickerItems(group)
    for (var l = 0; l < items.length; l++) {
      var volet = items[l]
      if (voletautobePicker.checked[volet.eq] !== true) { continue }

      var up = voletautobePickerRole(volet, 'up')
      var down = voletautobePickerRole(volet, 'down')
      var stop = voletautobePickerRole(volet, 'stop')
      var slider = voletautobePickerRole(volet, 'slider')

      /* Ni montée, ni descente, ni curseur : rien à commander. Un équipement
         qui ne sait que s'arrêter n'est pas un volet, et l'écarter en silence
         serait pire que de le dire. */
      if (!isset(up) && !isset(down) && !isset(slider)) {
        sansCommande.push(volet.name)
        continue
      }

      selection.push({
        eq: volet.eq, name: volet.name, object: group.object,
        up: isset(up) ? up : null, down: isset(down) ? down : null,
        stop: isset(stop) ? stop : null, slider: isset(slider) ? slider : null,
        state: isset(volet.state) ? volet.state : null,
        invert: voletautobePickerInvert(volet.eq),
        value: voletautobeApplyInvert(volet.value, voletautobePickerInvert(volet.eq)),
        missing: 0, enabled: 1
      })
    }
  }

  voletautobeSelection = selection
  voletautobeRenderVolets()
  voletautobeMarkModified()
  voletautobeClosePicker()

  if (sansCommande.length > 0) {
    jeedomUtils.showAlert({
      message: '{{Écartés, faute de commande désignée :}} ' + sansCommande.join(', '),
      level: 'warning', timeOut: 8000
    })
  }
  jeedomUtils.showAlert({ message: selection.length + ' {{volet(s) dans ce groupe. Pensez à sauvegarder.}}', level: 'success' })
}

/* =========================================================== PROGRAMMATION */

/* Ce que le plugin poserait de toute façon à l'enregistrement d'un groupe neuf.
   Sans cela, le formulaire montrerait quatre moments vides, « Ouvrir » aussi
   bien le soir que le matin, et un aperçu qui annonce que rien ne se
   déclenchera jamais : l'écran mentirait dès la création. Rien n'est exécuté
   tant que le groupe n'a pas de volet.

   La protection solaire part sur la façade et non sur une heure fixe : c'est
   tout l'objet du réglage, 13 h convenait en juin et ne correspondait à rien en
   octobre. Sa condition de soleil est posée, puisqu'elle n'a plus d'angles à
   elle — elle suit la façade du groupe, et sur un déclencheur de façade elle ne
   vérifie plus que la hauteur.

   Sa fin, elle, n'en pose aucune : à l'instant où le soleil quitte la façade, il
   n'y est par définition plus. Une fenêtre de soleil en condition ferait sauter
   la réouverture tous les jours, et les volets resteraient baissés jusqu'au
   soir — exactement ce que ce moment existe pour éviter. */
var voletautobeDefaults = {
  morning: {
    enable: 1, action: 'up', position: 100, mode: 'sunrise', time: '07:00', offset: 0,
    random: 0, not_before: '07:00', not_after: '', days: [1, 2, 3, 4, 5, 6, 7],
    temp_mode: 'none', temp_value: '', sun_mode: 'none'
  },
  heat: {
    enable: 0, action: 'position', position: 30, mode: 'facade_in', time: '13:00', offset: 0,
    random: 0, not_before: '', not_after: '', days: [1, 2, 3, 4, 5, 6, 7],
    temp_mode: 'min', temp_value: '26', sun_mode: 'none'
  },
  shade_end: {
    enable: 0, action: 'up', position: 100, mode: 'facade_out', time: '17:00', offset: 0,
    random: 0, not_before: '', not_after: '', days: [1, 2, 3, 4, 5, 6, 7],
    temp_mode: 'none', temp_value: '', sun_mode: 'none'
  },
  evening: {
    enable: 1, action: 'down', position: 0, mode: 'sunset', time: '21:00', offset: 0,
    random: 0, not_before: '18:00', not_after: '', days: [1, 2, 3, 4, 5, 6, 7],
    temp_mode: 'none', temp_value: '', sun_mode: 'none'
  }
}

/* Les champs d'un moment que le coeur ne voit pas changer, parce qu'ils sont
   posés et relus à la main. La liste est ici une fois pour toutes : un champ
   ajouté sans elle se perdrait à l'enregistrement, sans le moindre message. */
var voletautobeManualFields = '.vabDay, .vabOffsetValue, .vabOffsetWay, .vabPositionValue'

function voletautobeSlotElement(_key) {
  return document.querySelector('.vabSlot[data-slot="' + _key + '"]')
}

/* Montre les champs qui servent au réglage choisi : le pourcentage n'a de sens
   que pour « Mettre à une position », la valeur en degrés que si une condition
   est posée, et l'événement est nommé dans le groupe de saisie — « 30 min avant
   [le lever du soleil] » se relit tout seul, « 30 min avant » ne veut rien
   dire. */
function voletautobeSyncSlotUi(_key) {
  var block = voletautobeSlotElement(_key)
  if (block === null) { return }

  var mode = block.querySelector('.vabMode').value
  var isFixed = (mode === 'fixed')
  var isFacade = (mode === 'facade_in' || mode === 'facade_out')
  block.querySelector('.vabFixed').style.display = isFixed ? '' : 'none'
  block.querySelector('.vabSun').style.display = isFixed ? 'none' : ''
  /* Le décalage se dit du même souffle que l'événement : « 20 min après [que le
     soleil arrive sur la façade] » se relit tout seul, et ce décalage a un sens —
     c'est le temps que la façade mette à chauffer. */
  block.querySelector('.vabEventName').textContent = (mode === 'facade_in')
    ? '{{que le soleil arrive sur la façade}}'
    : ((mode === 'facade_out') ? '{{que le soleil quitte la façade}}'
      : ((mode === 'sunrise') ? '{{le lever du soleil}}' : '{{le coucher du soleil}}'))

  var isPosition = (block.querySelector('.vabAction').value === 'position')
  var positionBlocks = block.querySelectorAll('.vabPositionBlock')
  for (var i = 0; i < positionBlocks.length; i++) {
    positionBlocks[i].style.display = isPosition ? '' : 'none'
  }

  var hasCondition = (block.querySelector('.vabTempMode').value !== 'none')
  block.querySelector('.vabTempBlock').style.display = hasCondition ? '' : 'none'

  var hasSun = (block.querySelector('.vabSunMode').value !== 'none')
  block.querySelector('.vabSunBlock').style.display = hasSun ? '' : 'none'

  /* La condition parle de « la façade », encore faut-il dire laquelle : elle se
     règle dans l'onglet « Volets », et sans ce rappel on la chercherait ici. */
  var facade = voletautobeFacadeValues()
  var recall = block.querySelector('.vabSunRecall')
  if (recall !== null) {
    recall.textContent = '{{Façade du groupe : de}} '
      + facade.from + '° (' + voletautobeCompass(facade.from) + ') {{à}} '
      + facade.to + '° (' + voletautobeCompass(facade.to) + '), '
      + '{{au-dessus de}} ' + facade.elevation + '°. '
      + '{{Elle se règle une fois pour toutes dans l\'onglet « Volets ».}}'
  }

  /* Le doublon, dit à l'écran, à l'endroit précis où l'utilisateur s'est posé
     la question.

     « Quand le soleil arrive sur la façade » ne tombe que lorsque le soleil y
     est vraiment — dans la fenêtre d'azimut ET au-dessus de la hauteur
     minimale. La condition n'a donc plus rien à écarter : ni la direction, ni
     la hauteur. La laisser s'évaluer aurait même été nuisible, le déclencheur
     posant le soleil pile sur sa borne, là où un arrondi flottant décide à
     pile ou face. */
  var note = block.querySelector('.vabSunNote')
  if (note !== null) {
    note.style.display = isFacade ? '' : 'none'
    note.textContent = '{{Ce moment est déjà déclenché par la façade : il ne tombe que lorsque le soleil y est, direction et hauteur comprises. La condition n\'a donc rien à filtrer de plus, et n\'est pas évaluée. Elle sert avec les autres déclencheurs — une heure fixe, le lever ou le coucher.}}'
  }
}

/* Écrit le nom de direction à côté d'un champ d'azimut. « 200 » ne se vérifie
   pas ; « sud-sud-ouest » se vérifie d'un coup d'oeil par la fenêtre, et c'est
   ainsi que l'utilisateur pense sa maison. */
function voletautobeShowCompass(_block, _field, _target) {
  var field = _block.querySelector(_field)
  var target = _block.querySelector(_target)
  if (field === null || target === null) { return }
  target.textContent = voletautobeCompass(field.value)
}

/* Relève un moment tel qu'il est à l'écran. Les jours, le décalage signé et le
   pourcentage ne passent pas par la mécanique du coeur : ils sont lus à la
   main. */
function voletautobeReadSlot(_key) {
  var block = voletautobeSlotElement(_key)
  if (block === null) { return null }

  var value = parseInt(block.querySelector('.vabOffsetValue').value, 10)
  if (isNaN(value) || value < 0) { value = 0 }
  var offset = (block.querySelector('.vabOffsetWay').value === 'before') ? -value : value

  var position = parseInt(block.querySelector('.vabPositionValue').value, 10)
  if (isNaN(position)) { position = voletautobeDefaults[_key].position }
  if (position < 0) { position = 0 }
  if (position > 100) { position = 100 }

  var days = []
  var boxes = block.querySelectorAll('.vabDay')
  for (var i = 0; i < boxes.length; i++) {
    if (boxes[i].checked) { days.push(parseInt(boxes[i].getAttribute('data-day'), 10)) }
  }

  var enableBox = block.querySelector('.eqLogicAttr[data-l3key="enable"]')
  return {
    enable: (enableBox !== null && enableBox.checked) ? 1 : 0,
    action: block.querySelector('.vabAction').value,
    position: position,
    mode: block.querySelector('.vabMode').value,
    time: block.querySelector('.eqLogicAttr[data-l3key="time"]').value,
    offset: offset,
    random: parseInt(block.querySelector('.eqLogicAttr[data-l3key="random"]').value, 10) || 0,
    not_before: block.querySelector('.eqLogicAttr[data-l3key="not_before"]').value,
    not_after: block.querySelector('.eqLogicAttr[data-l3key="not_after"]').value,
    days: days,
    temp_mode: block.querySelector('.vabTempMode').value,
    /* La valeur part telle qu'elle a été tapée, virgule comprise : c'est le
       serveur qui normalise, et convertir ici ferait deux règles pour un même
       « 5,5 ». */
    temp_value: block.querySelector('.vabTempValue').value,
    /* Le moment ne porte plus d'angles : « sur la façade » veut dire celle du
       groupe, et c'est le serveur qui l'y recopie avant tout calcul. Les relire
       ici, dans des champs qui n'existent plus, écrirait l'orientation de la
       maison à cinq endroits — quatre moments et le groupe — sans qu'aucun ne
       fasse foi. */
    sun_mode: block.querySelector('.vabSunMode').value
  }
}

/* Pose un moment à l'écran. Le coeur a déjà rempli les .eqLogicAttr : restent
   les jours, le décalage qu'on montre en valeur absolue et en sens, le
   pourcentage, et les valeurs d'un groupe qui vient d'être créé. */
function voletautobeApplySlot(_key, _slot) {
  var block = voletautobeSlotElement(_key)
  if (block === null) { return }
  var slot = _slot || {}

  if (!isset(slot.mode)) {
    slot = voletautobeDefaults[_key]
    block.querySelector('.eqLogicAttr[data-l3key="enable"]').checked = (slot.enable == 1)
    block.querySelector('.vabAction').value = slot.action
    block.querySelector('.vabMode').value = slot.mode
    block.querySelector('.eqLogicAttr[data-l3key="time"]').value = slot.time
    block.querySelector('.eqLogicAttr[data-l3key="random"]').value = slot.random
    block.querySelector('.eqLogicAttr[data-l3key="not_before"]').value = slot.not_before
    block.querySelector('.eqLogicAttr[data-l3key="not_after"]').value = slot.not_after
    block.querySelector('.vabTempMode').value = slot.temp_mode
    block.querySelector('.vabTempValue').value = slot.temp_value
    block.querySelector('.vabSunMode').value = slot.sun_mode
  }

  var offset = parseInt(slot.offset, 10)
  if (isNaN(offset)) { offset = 0 }
  block.querySelector('.vabOffsetValue').value = Math.abs(offset)
  block.querySelector('.vabOffsetWay').value = (offset > 0) ? 'after' : 'before'

  var position = parseInt(slot.position, 10)
  if (isNaN(position)) { position = voletautobeDefaults[_key].position }
  block.querySelector('.vabPositionValue').value = position

  /* Un groupe enregistré avant que le plugin ne sache où est le soleil n'a pas
     de clé sun_mode : le coeur laisse alors la liste sur une valeur vide, que
     l'enregistrement suivant renverrait telle quelle. */
  var sunMode = block.querySelector('.vabSunMode')
  if (sunMode.value !== 'none' && sunMode.value !== 'window') {
    sunMode.value = voletautobeDefaults[_key].sun_mode
  }

  /* Même filet sur le déclencheur. Un moment resté en mode « azimut », le temps
     que la mise à jour du plugin le traduise, désigne une option qui n'existe
     plus : la liste retombe alors sur une valeur vide, et l'enregistrement
     suivant écrirait un mode que personne ne sait jouer. */
  var mode = block.querySelector('.vabMode')
  if (mode.selectedIndex < 0 || mode.value === '') {
    mode.value = voletautobeDefaults[_key].mode
  }

  /* Un groupe neuf n'a pas de jours enregistrés : tous cochés, parce qu'une
     programmation qui ne s'applique aucun jour ne sert à rien et que l'erreur
     ne se verrait qu'au bout d'une semaine. Aucun jour coché veut dire
     « jamais », pas « tous les jours » : l'inverse ferait ouvrir les volets
     sept matins par semaine à quelqu'un qui vient de tout décocher pour
     suspendre le moment. */
  var days = (isset(slot.days) && Array.isArray(slot.days)) ? slot.days : [1, 2, 3, 4, 5, 6, 7]
  var boxes = block.querySelectorAll('.vabDay')
  for (var i = 0; i < boxes.length; i++) {
    boxes[i].checked = (days.indexOf(parseInt(boxes[i].getAttribute('data-day'), 10)) !== -1)
  }

  voletautobeSyncSlotUi(_key)
}

/* Affiche l'aperçu rendu par le serveur. */
function voletautobeShowPreview(_key, _preview) {
  var block = voletautobeSlotElement(_key)
  if (block === null) { return }
  var target = block.querySelector('.vabPreview')
  target.innerHTML = ''

  if (!isset(_preview) || !isset(_preview.occurrences)) {
    target.textContent = '—'
    return
  }
  if (_preview.occurrences.length === 0) {
    target.appendChild(voletautobeText('span', 'text-warning',
      '{{Ce moment ne se déclenchera jamais : moment désactivé, aucun jour coché, ou position de l\'installation manquante.}}'))
    return
  }

  target.appendChild(voletautobeText('b', '', _preview.actionName + ' : ' + _preview.occurrences.join(', ')))
  target.appendChild(document.createElement('br'))
  target.appendChild(voletautobeText('small', 'text-muted', _preview.summary))

  /* Une condition posée sur une sonde muette ne filtre rien : le moment sera
     joué de toute façon. C'est la panne silencieuse propre à ce plugin, et cet
     aperçu est le seul endroit où l'utilisateur la regarde. */
  if (_preview.noSensor == 1) {
    target.appendChild(document.createElement('br'))
    target.appendChild(voletautobeText('small', 'text-danger',
      '{{Aucune sonde lisible : cette condition de température est sans effet, le moment sera joué quand même.}}'))
  }

  /* Les heures et les positions de soleil ci-dessus sont plausibles et fausses
     tant que la position de l'installation n'est pas renseignée : le dire ici
     est le seul moment où l'utilisateur regarde.

     noLocation porte déjà le « et ça compte pour ce moment » du serveur : il ne
     vaut 1 que si le moment suit le soleil — lever, coucher, azimut atteint ou
     fenêtre de soleil. Le retester ici sur needsSun laisserait passer les deux
     réglages ajoutés en dernier, qui sont justement ceux qui ne veulent rien
     dire sans position. */
  if (_preview.noLocation == 1) {
    target.appendChild(document.createElement('br'))
    target.appendChild(voletautobeText('small', 'text-danger',
      '{{Position de l\'installation absente : les heures de soleil et la position du soleil sont fausses. Réglages → Système → Configuration → Général.}}'))
  }
}

/* Demande l'aperçu au serveur, en laissant retomber la saisie.
   Le calcul est fait là-bas : c'est le même code qui décidera de l'ordre au
   moment venu, et deux implémentations divergeraient — c'est l'interface qu'on
   croirait. */
var voletautobePreviewTimer = { morning: null, heat: null, shade_end: null, evening: null }
function voletautobeRefreshPreview(_key) {
  /* hasOwnProperty et non isset : la valeur de départ est null, et un test de
     présence sur la valeur refuserait le tout premier aperçu de chaque moment. */
  if (!voletautobePreviewTimer.hasOwnProperty(_key)) { return }
  if (voletautobePreviewTimer[_key] !== null) {
    clearTimeout(voletautobePreviewTimer[_key])
  }
  voletautobePreviewTimer[_key] = setTimeout(function () {
    var slot = voletautobeReadSlot(_key)
    if (slot === null) { return }
    voletautobeAjax('preview', {
      key: _key,
      slot: JSON.stringify(slot),
      id: voletautobeCurrentId(true) || ''
    }, function (result) {
      voletautobeShowPreview(_key, result)
    }, { silent: true })
  }, 300)
}

/* ================================================== CYCLE DE VIE DE LA PAGE */

function printEqLogic(_eqLogic) {
  voletautobeRendering = true
  try {
    var configuration = (isset(_eqLogic) && isset(_eqLogic.configuration)) ? _eqLogic.configuration : {}

    voletautobeSelection = []
    var volets = isset(configuration.volets) ? configuration.volets : []
    for (var i = 0; i < volets.length; i++) {
      voletautobeSelection.push(Object.assign({}, volets[i]))
    }
    voletautobeRenderVolets()

    /* La façade avant les moments : ils la rappellent tous, et la poser après
       leur ferait afficher un instant celle du groupe précédemment ouvert. */
    voletautobeApplyFacade(configuration)

    for (var s = 0; s < voletautobeSlots.length; s++) {
      var key = voletautobeSlots[s]
      voletautobeApplySlot(key, isset(configuration[key]) ? configuration[key] : null)
      voletautobeShowPreview(key, null)
    }

    /* Le coeur ne réinitialise que les .eqLogicAttr : sans cela, l'étiquette et
       la mesure garderaient l'état du groupe précédemment ouvert. */
    voletautobeShowPaused(false, '')
    voletautobeShowTemperature(null, '')
    voletautobeShowSun(null, null)
    voletautobeLoadSensors(isset(configuration.temperature_cmd) ? configuration.temperature_cmd : '')
  } finally {
    voletautobeRendering = false
  }

  if (isset(_eqLogic.id) && _eqLogic.id != '') {
    voletautobeLoadGroup(_eqLogic.id)
  } else {
    /* Groupe neuf : l'aperçu se calcule quand même, sur ce que montre l'écran. */
    for (var n = 0; n < voletautobeSlots.length; n++) {
      voletautobeRefreshPreview(voletautobeSlots[n])
    }
  }
}

/* Appelée par plugin.template.js juste avant l'enregistrement. Les volets et
   les moments sont des listes imbriquées : data-lXkey ne descend pas jusque-là,
   il faut les poser à la main. */
function saveEqLogic(_eqLogic) {
  if (!isset(_eqLogic.configuration)) { _eqLogic.configuration = {} }

  var volets = []
  for (var i = 0; i < voletautobeSelection.length; i++) {
    var volet = voletautobeSelection[i]
    volets.push({
      eq: volet.eq, name: volet.name, object: volet.object,
      up: volet.up, down: volet.down, stop: volet.stop, slider: volet.slider,
      state: volet.state, invert: isset(volet.invert) ? volet.invert : 0
    })
  }
  _eqLogic.configuration.volets = volets

  for (var k = 0; k < voletautobeSlots.length; k++) {
    var slot = voletautobeReadSlot(voletautobeSlots[k])
    if (slot !== null) { _eqLogic.configuration[voletautobeSlots[k]] = slot }
  }
  return _eqLogic
}

/* ================================================================ COMMANDES */

/* Ligne du tableau des commandes. */
function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  /* Le champ caché « id » n'est pas décoratif : sans lui, chaque sauvegarde
     détruit et recrée les commandes — historique perdu, scénarios cassés. */
  var tr = '<td>'
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked>{{Historiser}}</label>'
  tr += '<span class="cmdAttr" data-l1key="htmlstate" style="display:inline-block;margin-left:5px;"></span>'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '<a class="btn btn-danger btn-xs cmdAction pull-right" data-action="remove"><i class="fas fa-minus-circle"></i></a>'
  tr += '</td>'

  /* Une ligne créée en DOM : insertAdjacentHTML sur la table génère un <tbody>
     par insertion et toutes les commandes se retrouveraient dans la même ligne. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant interne}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* ================================================================ ÉCOUTEURS */

/* Les pages sont chargées en AJAX : DOMContentLoaded a déjà eu lieu. Les
   écouteurs sont donc posés à la racine du script, sur le conteneur de page —
   qui est remplacé à chaque navigation, ce qui les emporte avec lui. */
var voletautobeContainer = document.getElementById('div_pageContainer') || document.body

voletautobeContainer.addEventListener('change', function (event) {
  /* La façade n'est pas dans un bloc de moment — elle est dans l'onglet
     « Volets » — mais elle change ce que les quatre blocs racontent. */
  if (event.target.closest(voletautobeFacadeFields)) {
    voletautobeSyncFacade()
    voletautobeMarkModified()
    return
  }

  var block = event.target.closest('.vabSlot')
  if (block === null) { return }
  if (event.target.closest('.vabMode') || event.target.closest('.vabAction')
      || event.target.closest('.vabTempMode') || event.target.closest('.vabSunMode')) {
    voletautobeSyncSlotUi(block.getAttribute('data-slot'))
  }
  /* Les jours, le décalage et le pourcentage ne sont pas des .eqLogicAttr : le
     coeur ne les voit pas changer, et sans cela on quitterait la page en
     perdant un réglage tout juste posé, sans le moindre avertissement. */
  if (event.target.closest(voletautobeManualFields)) {
    voletautobeMarkModified()
  }
  if (event.target.closest('.vabPreviewTrigger')) {
    voletautobeRefreshPreview(block.getAttribute('data-slot'))
  }
})

voletautobeContainer.addEventListener('input', function (event) {
  /* Le nom de direction et les rappels des moments suivent la frappe : attendre
     la sortie du champ afficherait « sud » à côté d'un azimut déjà passé à 280.
     Les aperçus, eux, ne bougent pas — le serveur les calcule sur la façade
     enregistrée, et les redemander ici annoncerait l'ancienne orientation comme
     si la nouvelle n'avait pas porté. */
  if (event.target.closest(voletautobeFacadeFields)) {
    voletautobeSyncFacade()
    voletautobeMarkModified()
    return
  }

  var block = event.target.closest('.vabSlot')
  if (block === null || !event.target.closest('.vabPreviewTrigger')) { return }
  /* Une frappe dans le décalage ou le pourcentage n'émet « change » qu'à la
     sortie du champ : taper puis cliquer ailleurs dans la page perdrait la
     saisie sans avertissement. */
  if (event.target.closest(voletautobeManualFields)) {
    voletautobeMarkModified()
  }
  voletautobeRefreshPreview(block.getAttribute('data-slot'))
})

voletautobeContainer.addEventListener('click', function (event) {
  var target = null

  if (event.target.closest('#bt_voletautobePick')) {
    voletautobeOpenPicker()
    return
  }

  if (target = event.target.closest('.vabAllDays')) {
    var boxes = voletautobeSlotElement(target.getAttribute('data-slot')).querySelectorAll('.vabDay')
    for (var i = 0; i < boxes.length; i++) { boxes[i].checked = true }
    voletautobeMarkModified()
    voletautobeRefreshPreview(target.getAttribute('data-slot'))
    return
  }

  if (target = event.target.closest('.vabVoletRemove')) {
    var eq = target.closest('td').getAttribute('data-eq')
    for (var j = voletautobeSelection.length - 1; j >= 0; j--) {
      if (String(voletautobeSelection[j].eq) === String(eq)) {
        voletautobeSelection.splice(j, 1)
      }
    }
    voletautobeRenderVolets()
    voletautobeMarkModified()
    return
  }

  if ((target = event.target.closest('.vabVoletUp')) || (target = event.target.closest('.vabVoletStop'))
      || (target = event.target.closest('.vabVoletDown'))) {
    var order = target.classList.contains('vabVoletUp') ? 'up'
      : (target.classList.contains('vabVoletDown') ? 'down' : 'stop')
    var cell = target.closest('td')
    var voletEq = cell.getAttribute('data-eq')
    var invert = 0
    for (var v = 0; v < voletautobeSelection.length; v++) {
      if (String(voletautobeSelection[v].eq) === String(voletEq)) {
        invert = isset(voletautobeSelection[v].invert) ? voletautobeSelection[v].invert : 0
      }
    }
    voletautobeAjax('switchVolet', { eq: voletEq, order: order, cmd: '', invert: invert }, function (result) {
      if (isset(result.cmd) && result.cmd !== '') {
        jeedomUtils.showAlert({ message: result.cmd, level: 'success', timeOut: 3000 })
      }
      /* Le volet met une vingtaine de secondes à finir sa course : relire tout
         de suite afficherait la position de départ. */
      voletautobeRefreshStates(8000)
    }, { button: target })
    return
  }

  if (target = event.target.closest('#bt_voletautobePause')) {
    var pauseId = voletautobeCurrentId()
    if (pauseId === null) { return }
    voletautobeAjax('pause', { id: pauseId, state: target.getAttribute('data-state') }, function (result) {
      voletautobeShowPaused(result.paused == 1, '')
      voletautobeLoadGroup(pauseId)
      jeedomUtils.showAlert({ message: result.summary, level: (result.paused == 1) ? 'warning' : 'success' })
    }, { button: target })
    return
  }

  if ((target = event.target.closest('#bt_voletautobeTestUp')) || (target = event.target.closest('#bt_voletautobeTestStop'))
      || (target = event.target.closest('#bt_voletautobeTestDown'))) {
    var groupId = voletautobeCurrentId()
    if (groupId === null) { return }
    var groupOrder = (target.id === 'bt_voletautobeTestUp') ? 'up'
      : ((target.id === 'bt_voletautobeTestDown') ? 'down' : 'stop')
    voletautobeAjax('testGroup', { id: groupId, order: groupOrder, position: '' }, function (result) {
      jeedomUtils.showAlert({ message: result.summary, level: 'success' })
      voletautobeRefreshStates(8000)
    }, { button: target })
    return
  }
})
