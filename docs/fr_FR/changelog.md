# Changelog

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
