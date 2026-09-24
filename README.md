# Plugin Jeedom — Volets Auto

Ouvrir les volets le matin et les fermer le soir, sans écrire un seul scénario —
et ne pas le faire quand la température s'y oppose.

## Ce qu'il apporte

- **Les volets trouvés pour vous.** Le plugin parcourt l'installation et ne
  propose que les équipements qui savent monter, descendre ou se placer à un
  pourcentage, rangés par pièce, les volets avant les brise-soleil et les
  équipements reconnus au nom. Il n'y a ni liste de commandes à dépouiller, ni
  « Monter » et « Descendre » à choisir un par un : on coche un nom.
- **Trois boutons pour reconnaître le bon volet.** Chaque ligne du sélecteur
  fait monter, arrêter et descendre le volet pour de vrai, et une pastille
  montre sa position. C'est la seule façon de savoir lequel est « Module 3 ».
- **Trois moments, pas davantage.** Un groupe, un moment le matin, un moment le
  soir, et une protection solaire à mi-journée qui ne sert que les jours chauds.
- **À l'heure ou au soleil.** Heure fixe, ou tant de minutes avant ou après le
  lever ou le coucher du soleil — calculé pour la position de votre
  installation, celle dont Jeedom se sert déjà dans ses scénarios.
- **Une condition de température par moment.** Ne pas ouvrir en dessous de
  5 °C : en hiver, un volet fermé isole, et l'ouvrir à 7 h par −3 °C fait perdre
  de la chaleur pour trois heures de lumière grise. Ne fermer aux trois quarts
  qu'au-dessus de 26 °C : c'est la canicule qu'on vise, pas le mois d'avril.
- **Une condition de luminosité, facultative.** Avec un luxmètre, une station
  météo ou un capteur de rayonnement, ne pas fermer la protection solaire un
  jour couvert. Le seuil s'écrit dans l'unité de la sonde.
- **Une sonde en panne ne bloque rien.** Si la mesure est illisible, ou figée
  depuis plus de 3 heures, le moment est joué quand même et le journal le dit.
  La condition est un raffinement, le mouvement est le comportement normal.
- **Des garde-fous.** « Jamais avant 18:00 » pour les soirs d'hiver où le soleil
  se couche à 16 h 40, « jamais avant 07:00 » pour les matins de juin.
- **Une simulation de présence en un champ.** Un décalage aléatoire de ± n
  minutes, tiré une fois par jour : l'heure annoncée est celle qui sera jouée.
- **Un aperçu qui ne ment pas.** Sous chaque moment, les trois prochaines fois,
  calculées par le code qui décidera de l'ordre au moment venu.

## Ce qu'il ne fait pas

Il ne regarde ni le vent, ni la pluie, ni la présence. Ces conditions-là
dépendent d'un événement et non de l'heure : elles sont affaire de scénario, qui
suspend le groupe ou appelle ses commandes directement. Ce qui se répète tous
les jours est dans le plugin, le reste est ailleurs.

Il ne règle pas l'orientation des lames d'un brise-soleil : il l'ouvre, le ferme
et le place à un pourcentage, comme un volet.

Il n'a ni démon, ni dépendance, ni appel réseau : tout est en PHP, dans le cron
du cœur.

## Convention

**0 % = fermé, 100 % = ouvert**, celle du type générique *Volet* du cœur. Les
modules qui font l'inverse se corrigent un par un avec la case « inversé » du
sélecteur.

## Prérequis

La position de l'installation doit être renseignée dans Réglages → Système →
Configuration → Général. Sans latitude ni longitude, les heures de lever et de
coucher du soleil sont celles du point zéro, au large du golfe de Guinée — et
rien ne le signale à part ce plugin.

Une sonde de température n'est nécessaire que si vous vous servez des conditions
de température. Elle se désigne une fois dans la configuration du plugin, et un
groupe peut avoir la sienne. Il en va de même, facultativement, d'une sonde de
luminosité pour les conditions de luminosité.

## Installation

Plugins → Gestion des plugins → Ajouter → Github, puis :

| Champ | Valeur |
|---|---|
| Utilisateur | `replicatorbe` |
| Dépôt | `jeedom-plugin-voletautobe` |
| Branche | `master` (stable) ou `beta` |

## Branches

- **`master`** — version stable. Tout commit poussé ici est proposé en mise à
  jour aux utilisateurs, Jeedom identifiant la version par le SHA du dernier
  commit de la branche.
- **`beta`** — développement. C'est la branche par défaut du dépôt.

## Architecture

```
cron du cœur (chaque minute)
        │
        ├─ pour chaque groupe actif, pour chaque moment activé :
        │     voletautobeSun::dueOccurrence()
        │         heure fixe | soleil ± n min → garde-fous → tirage du jour
        │         passé depuis moins que le rattrapage, pas déjà joué ?
        │                                │
        │                       oui ─────┤
        │                                │
        │     soleil, puis temperatureCheck(), puis luxCheck()
        │         condition remplie, ou mesure inconnue ou figée
        │                                │
        │                       oui ─────┴──► ordre retenu pour la minute
        │                       non ────────► journal + « Dernier changement »
        │                                     (la protection solaire se
        │                                      réessaie chaque minute)
        │
        ├─ les ordres de la minute, envoyés dans UNE tâche de fond du cœur
        │     (voletautobe::sendOrders), un délai entre deux ordres compté
        │     tous groupes confondus ; sur place s'il n'y a rien à attendre
        │
        └─ mise à jour des commandes d'information
              « prochain changement », position réelle, température et
              luminosité retenues, heures du soleil
```

Le calcul est dans `voletautobeSun`, qui ne connaît pas Jeedom : c'est ce qui
permet de l'éprouver sur une année entière hors ligne (`php tests/run.php`). La
reconnaissance des volets est dans `voletautobeVolets`, dont les parties
décisives — « cet équipement est-il un volet ? », « cette commande mesure-t-elle
une température ? » — ne connaissent pas Jeedom non plus.

## Contrôles

```bash
php tests/run.php            # les contrôles hors ligne, sans Jeedom
php tests/check-classes.php  # les pièges du cœur, par réflexion
```

## Documentation

- [Français](docs/fr_FR/index.md)
- [English](docs/en_US/index.md)

## Licence

AGPL v3. Voir [LICENSE](LICENSE).
