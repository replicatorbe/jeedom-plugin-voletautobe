# Changelog

## 1.2

- **La fin de protection ne défait plus ce que la protection n'a pas fait.** Les
  deux moments étaient indépendants l'un de l'autre : un jour à 19 °C, la
  protection solaire était sautée — seuil 26 °C, il ne faisait pas assez chaud —
  et à 18:03 la fin de protection ouvrait quand même les volets. S'ils étaient
  fermés parce que quelqu'un faisait la sieste, ou pour ne pas être vu de la rue,
  l'automatisme défaisait un geste que personne ne lui avait demandé de défaire,
  et cela ne se voyait qu'après coup. Désormais, la fin de protection ne rouvre
  que si la protection solaire a **réellement bougé** les volets le jour même —
  pas si elle a seulement été évaluée. Les jours où elle est sautée, la fin de
  protection l'est aussi, et elle le dit dans le journal comme dans « Dernier
  changement » : « Fin de protection sautée : la protection solaire n'a pas eu
  lieu aujourd'hui ». Le moment est marqué joué malgré tout : la décision se
  prend une fois, à l'heure dite, comme pour les conditions.
- **L'exception compte autant : quand la protection solaire est désactivée, la
  fin de protection agit seule.** Le couplage existe pour ne pas défaire une
  protection qui n'a pas eu lieu ; là où aucune protection n'est configurée, il
  n'y a rien à ne pas défaire, et « ouvrir en fin d'après-midi, quand le soleil
  quitte la façade » est un réglage légitime en soi. Il continue donc de jouer
  tous les jours, comme le matin et le soir.
- **Le plugin dit désormais si le soleil atteint votre façade.** À 50,5° de
  latitude nord, le soleil ne dépasse jamais 310,1° au coucher : une façade
  déclarée jusqu'à 340° est parfaitement cohérente à l'écran et ne se terminera
  pourtant jamais sur son azimut de fin. Le plugin parcourt une année de courses
  du soleil, un jour sur cinq, et écrit sous les champs de la façade ce qu'elle
  donne vraiment — « Cette façade est éclairée 340 jours sur 365, jusqu'à 8 h 00
  par jour », ou l'avertissement qui convient : jamais quittée par l'azimut de
  fin, ou jamais éclairée du tout. La phrase se rafraîchit pendant qu'on règle,
  au lieu de laisser découvrir la chose des mois plus tard.
- **Un bouton d'essai par moment.** Il exécute l'action du moment pour de vrai —
  son « faire », son pourcentage, ses volets — sans se soucier des conditions, un
  bouton d'essai qui ne ferait rien parce qu'il fait 18 °C étant
  incompréhensible, **et il rend compte de ce que les conditions auraient dit** :
  « Fermeture à 30 % envoyée à 4 volets. Au moment venu, ce moment aurait été
  sauté : 18,2 °C, seuil 26 °C. » Les deux moitiés comptent, la seconde surtout :
  elle répond en plein mois de mars à une question qu'on ne pouvait poser qu'au
  lendemain matin. L'essai ne marque pas le moment comme joué, il se jouera quand
  même à son heure.
- **Un délai réglable entre deux ordres, 400 ms par défaut.** Un groupe de huit
  volets, ce sont huit trames envoyées dans la même milliseconde ; en 433 MHz une
  ou deux se perdent, un volet ne bouge pas, et **rien ne le signale** puisque la
  commande a bien été jouée. Le délai s'insère entre les volets et pas après le
  dernier, se règle de 0 à 5000 ms dans la configuration du plugin, et l'attente
  totale d'un groupe est plafonnée à 30 secondes pour ne pas bloquer le cron du
  cœur.
- **La page Santé compte les volets introuvables** — ceux dont l'équipement a été
  supprimé de Jeedom. Ils échouent à chaque ordre et alimentent le centre de
  messages, et tant qu'ils sont là, le groupe commande moins de volets qu'il n'en
  affiche. Ils portent l'étiquette « Équipement supprimé » dans le groupe.

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
