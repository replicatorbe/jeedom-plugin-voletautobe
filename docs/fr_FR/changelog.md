# Changelog

## 1.1

- **La façade appartient au groupe.** L'orientation se déclare une seule fois,
  dans l'onglet *Volets* : l'azimut auquel le soleil arrive sur le mur, celui
  auquel il le quitte, et une hauteur minimale. Ces trois nombres définissent
  ensemble une seule chose — le moment de la journée où le soleil éclaire cette
  façade : il y est quand son azimut est dans la fenêtre **et** que sa hauteur
  dépasse le minimum. Les moments s'y réfèrent, ils ne la redéclarent pas —
  l'orientation d'une maison n'est écrite qu'à un seul endroit. Un groupe par
  façade devient la façon normale de découper.
- **Deux nouveaux déclenchements** : *quand le soleil arrive sur la façade* et
  *quand le soleil quitte la façade*. Le premier est le premier instant de la
  journée où le soleil est sur la façade : il a franchi l'azimut de début, ou il
  se lève déjà en face, ou il vient de passer au-dessus de la hauteur minimale.
  Le second est le premier instant où ce n'est plus vrai : il a tourné au-delà de
  l'azimut de fin, ou il est descendu sous la hauteur, ou il se couche — le
  premier des trois. Aucun angle à saisir, seulement le décalage en minutes et
  son sens, comme les modes lever et coucher. Les jours où le soleil n'atteint
  jamais la façade, aucun des deux n'est joué, et l'aperçu le dit.
- **Le soleil quitte une façade ouest en descendant, pas en tournant.** Sous nos
  latitudes — 50,5° nord — son azimut au coucher ne dépasse jamais 310,1° (232,5°
  au solstice d'hiver, 310,1° à celui d'été). Une façade qui se termine à 315°,
  comme celle livrée par défaut, n'est donc jamais quittée par la rotation du
  soleil : c'est le passage sous la hauteur minimale, ou le coucher, qui met fin
  à la protection solaire. Un modèle qui n'aurait regardé que l'azimut n'aurait
  rouvert les volets aucun jour de l'année.
- **Un quatrième moment : la fin de protection.** Il rouvre les volets quand le
  soleil quitte la façade. Sans lui, la protection solaire laissait la pièce à
  30 % jusqu'au soir, longtemps après que le soleil est passé à l'ouest. Livré
  désactivé, il forme une paire avec la protection solaire.
- **Nouvelle condition par moment** : « seulement quand le soleil est sur la
  façade » — azimut dans la fenêtre et hauteur au-dessus du minimum. Elle n'a
  rien à saisir, elle se sert de la façade du groupe, et c'est avec les
  déclencheurs qui ne regardent pas la façade qu'elle sert : « à 13:00, seulement
  si le soleil est sur la façade ».
- **Avec un déclencheur de façade, la condition de soleil n'a rien à filtrer.**
  Le moment ne tombe qu'au moment où le soleil est sur la façade, azimut et
  hauteur compris : la condition est vraie par construction, l'azimut n'est pas
  retesté — un arrondi de calcul aurait pu le rendre faux d'un millième de degré
  et faire sauter le moment tous les jours — et la page le dit sous le réglage.
  Avec *quitte la façade*, la condition serait même fausse à tous les coups :
  c'est pourquoi « Fin de protection » est livrée sans.
- La hauteur minimale n'est pas une condition de plus, elle fait partie de la
  définition de la façade. Elle évite qu'un soleil d'hiver rasant ne déclenche
  une protection solaire dont personne n'a besoin, et c'est elle qui fait sortir
  le soleil de la façade en fin de journée.
- La façade peut passer par le nord : « de 300° à 30° » contient bien un soleil
  au 350°.
- La position du soleil est affichée en permanence sous le bloc Façade —
  « Soleil au 217° (sud-ouest), 31° de hauteur » : c'est ainsi qu'on relève
  l'orientation d'une façade sans sortir avec une boussole.
- Les azimuts sont nommés partout où ils s'affichent : 200° se lit
  « sud-sud-ouest ».
- Trois commandes d'information de plus, créées masquées : azimut du soleil et
  hauteur du soleil, non historisées, et « Prochaine fin de protection ».
- Quand les deux conditions sont posées, le soleil est évalué avant la
  température, et le texte de saut nomme le vrai motif avec les chiffres :
  « Protection solaire sautée : soleil à 8,4°, minimum 15° ».
- Sans position d'installation renseignée, la condition de soleil ne bloque
  rien : le moment est joué quand même, comme avec une sonde muette. La page
  Santé compte ces groupes sur une ligne « Fenêtre de soleil ».
- Les groupes existants sont repris à la mise à jour : la façade est déduite des
  réglages en place, le moment « Fin de protection » et ses commandes sont
  créés, et un groupe qui échouerait ne prive pas les autres de leur migration.
- Le calcul de la position du soleil est fait en PHP pur, sans dépendance ni
  appel réseau, réfraction atmosphérique corrigée.

## 1.0

Première version.

- Groupes de volets : un groupe, trois moments — le matin, une protection
  solaire à mi-journée, le soir.
- Sélecteur de volets : parcourt l'installation, range par pièce, distingue les
  volets des brise-soleil orientables et des équipements reconnus au nom, et
  fait monter, arrêter ou descendre le volet pour le reconnaître.
- Case « inversé » par volet, pour les modules qui publient 0 quand ils sont
  ouverts. Partout ailleurs, la convention est 0 % = fermé, 100 % = ouvert.
- Déclenchement à heure fixe, ou par rapport au lever ou au coucher du soleil
  avec un décalage en minutes.
- Jours de la semaine, garde-fous « pas avant » et « pas après », décalage
  aléatoire pour la simulation de présence.
- Condition de température par moment : ne rien faire en dessous ou au-dessus
  d'un seuil. Une sonde muette ne bloque rien, le moment est joué quand même et
  le journal le dit.
- Sonde de température par défaut dans la configuration du plugin, qu'un groupe
  peut remplacer par la sienne.
- Aperçu des trois prochaines occurrences de chaque moment, condition comprise.
- Commandes : prochain changement, ouvrir, fermer, stop, position, état,
  température retenue, dernier changement, programmation active, suspendre,
  reprendre, prochain matin, prochaine protection, prochain soir, lever et
  coucher du soleil.
- Repli automatique quand un volet ne sait pas faire : le curseur remplace
  monter et descendre, monter et descendre remplacent le curseur.
- Suspension d'un groupe sans le désactiver, pilotable en scénario.
- Rattrapage des moments manqués, réglable.
- Page Santé : position de l'installation, groupes actifs, groupes suspendus,
  volets programmés, et groupes dont la condition de température n'a pas de
  sonde lisible.
- Ni démon, ni dépendance, ni appel réseau.
