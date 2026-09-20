# Volets Auto

Ouvrir les volets le matin, les fermer le soir, sans écrire un seul scénario —
et ne pas le faire quand la température s'y oppose.

Le plugin ne pilote pas de matériel : il commande les volets que vos autres
plugins ont créés — Zigbee, Z-Wave, Somfy, KNX, MQTT, modules 433 MHz. Tout ce
qui sait monter, descendre ou se placer à un pourcentage dans Jeedom peut entrer
dans un groupe.

## Avant de commencer

Renseignez la position de votre installation dans **Réglages → Système →
Configuration → Général**. Sans latitude ni longitude, les heures de lever et de
coucher du soleil sont fausses toute l'année, et rien d'autre que ce plugin ne
vous le dira.

C'est la même position que celle dont Jeedom se sert pour `#sunrise#` et
`#sunset#` dans les scénarios : les heures du plugin et celles de vos scénarios
ne peuvent donc pas diverger.

Si vous comptez vous servir des conditions de température, désignez aussi une
sonde par défaut dans la configuration du plugin. Une maison a une température
extérieure, pas huit : la régler une fois évite de la choisir groupe par groupe,
et un groupe pourra toujours avoir la sienne s'il le faut — la chambre au nord,
une véranda.

## Une convention à retenir : 0 % = fermé, 100 % = ouvert

Partout dans le plugin, dans l'interface comme dans les commandes, **0 % veut
dire fermé et 100 % veut dire ouvert**. C'est la convention du cœur de Jeedom
pour le type générique *Volet*, et c'est celle que le plugin affiche.

Tous les volets ne la respectent pas : certains modules publient 0 pour « grand
ouvert » et 100 pour « fermé ». Ceux-là se corrigent volet par volet, avec la
case **inversé** du sélecteur — voir plus bas.

## Créer un groupe

Un **groupe** rassemble les volets qu'on ouvre et qu'on ferme ensemble : la
façade sud, le rez-de-chaussée, les chambres. Un groupe a trois moments — le
matin, la protection solaire, le soir — et c'est tout ce qu'il faut régler.

1. **Plugins → Automatisation → Volets Auto → Ajouter un groupe.** Donnez-lui le
   nom de ce qu'il commande.
2. **Choisir les volets.** Le bouton ouvre le sélecteur.
3. **Onglet Programmation.** Réglez le matin, réglez le soir, et la protection
   solaire si vous en voulez une.
4. **Sauvegarder.** Il n'y a rien d'autre à faire.

Un groupe par façade vaut souvent mieux qu'un groupe par étage : ce sont les
façades qui prennent le soleil, et la protection solaire de midi n'a de sens que
là où il tape.

## Le sélecteur de volets

Le sélecteur parcourt l'installation et ne montre que ce qui sait bouger, rangé
par pièce. Quatre familles, dont trois affichées d'emblée :

| Famille | Ce que c'est |
|---|---|
| **Volets** | L'équipement porte les types génériques *Volet* du cœur. Aucun doute. |
| **BSO** | Un brise-soleil orientable. C'en est un, mais il se règle autrement : le plugin le montre à part pour que vous sachiez ce que vous cochez. |
| **Autres** | Ni l'un ni l'autre, mais des commandes qui s'appellent « Monter », « Descendre », « Ouvrir », « Position ». Beaucoup de modules anciens ne remplissent pas les types génériques ; sans ce filet, leurs volets seraient introuvables. |
| **Tous les équipements** | Tout ce qui porte une commande d'action, y compris ce dont le plugin ne sait rien dire. À n'ouvrir que si votre volet n'apparaît nulle part ailleurs : ici, c'est vous qui désignez la commande qui monte, celle qui descend, celle qui arrête et celle qui place. |

Vous cochez des **équipements**, pas des commandes : le plugin retient lui-même
laquelle monte, laquelle descend, laquelle arrête et laquelle porte le
pourcentage. Un volet qui n'a qu'un curseur, sans bouton monter ni descendre,
est accepté : le plugin lui enverra 100 % pour ouvrir et 0 % pour fermer. Un
équipement qui ne sait que s'arrêter n'est pas un volet et n'est jamais proposé.

Le bouton **⚙** de chaque ligne déplie les quatre commandes retenues et permet
de les changer : un module dont le « Monter » s'appelle « Impulsion 1 », un
équipement à deux relais dont un seul porte le volet, un store dont la position
se règle par une commande qui ne s'appelle pas « Position » — tout cela se
corrige là, sans quitter le sélecteur.

### Les trois boutons qui font bouger le volet

Chaque ligne porte un bouton **monter**, un bouton **stop** et un bouton
**descendre**, qui agissent pour de vrai, tout de suite, sur ce volet seul. Une
pastille montre sa position quand l'équipement la publie.

C'est la seule façon de savoir lequel des trois volets s'appelle « Module 3 ».
Un nom ne se vérifie pas de mémoire ; un volet qui bouge, si. Le bouton **stop**
est là pour rattraper : on fait descendre, on reconnaît, on arrête à mi-course,
et on referme la fenêtre du sélecteur avec la bonne case cochée.

### La case « inversé »

Dépliez une ligne et vous trouverez, sous les quatre commandes, une case
**inversé**. Cochée, elle dit au plugin que ce volet-là parle à l'envers : son
« monter » descend, son « descendre » monte, et la position qu'il publie est
comptée depuis l'autre bout.

Elle existe parce que la convention 0 % = fermé n'est pas respectée partout, et
que la panne qui en résulte est particulièrement déplaisante : le groupe ferme le
soir, et un volet sur six s'ouvre en grand. Le symptôme est visible, la cause ne
l'est pas — surtout si le volet fautif est dans une pièce où l'on ne va pas le
soir.

Le moyen le plus sûr de la régler est le bouton monter de la ligne : si le volet
descend, cochez la case. Un seul essai, une fois, et c'est réglé pour de bon.

## Les trois moments

Les trois moments se règlent exactement de la même façon, et se lisent dans
l'ordre de la journée : le matin, la protection solaire, le soir.

**Faire** — ouvrir, fermer, ou placer à un pourcentage. Le matin ouvre et le
soir ferme par défaut, mais rien n'oblige à s'y tenir : un matin réglé sur
« placer à 60 % » laisse entrer la lumière sans donner la chambre à voir depuis
la rue.

**Quand** — trois possibilités :

- **À une heure fixe** : 07:00, toute l'année.
- **Par rapport au lever du soleil** : tant de minutes avant ou après.
- **Par rapport au coucher du soleil** : idem.

**Jours** — les jours de la semaine concernés. Aucun jour coché veut dire
*jamais*, pas *tous les jours* : c'est ce qu'on attend quand on vient de tout
décocher pour suspendre un moment. Un matin calé sur les jours ouvrés et un
autre groupe pour le week-end est le réglage le plus courant du plugin.

**Garde-fous** — « pas avant » et « pas après ». À Bruxelles, le soleil se couche
à 16 h 40 le 21 décembre et à 22 h 00 le 21 juin : une fermeture calée sur le
coucher varierait de cinq heures dans l'année, et fermerait les volets du salon
en plein goûter à la Noël. « Pas avant 18:00 » ramène les soirs d'hiver à
18:00 — le garde-fou **ramène**, il n'annule pas. Le même raisonnement vaut le
matin : « pas avant 07:00 » évite qu'une ouverture calée sur le lever ne réveille
la maison à 5 h 30 en juin. Laisser vide pour suivre le soleil toute l'année.

**Décalage aléatoire** — simulation de présence. Le moment est avancé ou retardé
au hasard dans cette limite. Le tirage est fait **une fois par jour** : l'heure
annoncée dans l'aperçu est bien celle qui sera jouée, et deux groupes réglés de
la même façon ne bougent pas à la même seconde. Des volets qui se ferment tous
les soirs à 21:00 tapantes se remarquent de la rue ; c'est précisément ce qu'on
cherche à éviter en partant.

**Prochaines fois** — sous chaque moment, les trois prochaines occurrences,
calculées par le code même qui décidera de l'ordre au moment venu. Si un moment
n'est jamais annoncé, c'est qu'il ne se déclenchera jamais : moment désactivé,
aucun jour coché, ou position de l'installation manquante.

### La protection solaire

Le troisième moment est celui qui justifie à lui seul la sonde de température :
fermer les volets **aux trois quarts** pendant les heures chaudes, **les jours
chauds seulement**, et ne rien faire le reste de l'année.

Il est livré désactivé, réglé sur 13:00, « placer à 30 % », et « seulement si la
température est ≥ 26 °C ». Ainsi réglé, il ne fait rien d'avril à juin, ferme aux
trois quarts pendant la canicule, et se tait de nouveau en septembre — sans que
vous ayez à l'activer ni à le désactiver au fil des saisons.

30 % plutôt que 0 % n'est pas un caprice : un volet complètement fermé fait une
pièce noire à midi. Aux trois quarts, la chaleur est arrêtée et il reste de quoi
vivre sans allumer.

## La condition de température

Chaque moment peut être soumis à une condition : **aucune**, **seulement si la
température est ≥** une valeur, ou **seulement si elle est ≤** une valeur. La
valeur est un nombre à virgule — un demi-degré compte pour une consigne de gel,
et « 5,5 » s'écrit comme on le dit.

Les deux usages sont symétriques et tous deux concrets :

- **Le matin, « seulement si ≥ 5 °C ».** En hiver, un volet fermé isole. L'ouvrir
  à 7 h par −3 °C fait perdre de la chaleur toute la matinée pour trois heures de
  lumière grise. En dessous du seuil, le plugin laisse les volets fermés.
- **La protection solaire, « seulement si ≥ 26 °C ».** Décrite ci-dessus.
- **Le soir, « seulement si ≤ 18 °C ».** Pour qui veut garder la fraîcheur du
  soir d'été le plus longtemps possible et ne fermer que lorsqu'elle est passée.

La sonde est une commande d'information de votre installation : une sonde
extérieure, la température d'une station météo, celle d'un thermostat. Elle se
choisit dans l'onglet *Volets* du groupe, dans une liste que le plugin remplit
tout seul, et la mesure du moment s'affiche à côté pour que vous voyiez
immédiatement si vous avez désigné la bonne. Laisser la liste sur son option
vide veut dire « celle du plugin », réglée une fois pour toutes dans la
configuration.

### Une sonde muette ne bloque rien

**Si la sonde est absente, en panne, ou rend une valeur illisible, le moment est
joué quand même.** C'est la décision la plus importante du plugin, et elle est
délibérée.

Une condition de température est un *raffinement* : le comportement normal est
de bouger. Le jour où une pile de sonde meurt, la maison doit continuer à ouvrir
ses volets le matin — pas rester dans le noir pendant trois semaines en attendant
que quelqu'un comprenne pourquoi. Le doute profite au mouvement, et le plugin
écrit dans son journal qu'il a bougé sans avoir pu vérifier.

La panne se voit tout de même : la page **Santé** compte les groupes qui posent
une condition de température sans sonde lisible, et l'aperçu du moment le
signale sous le réglage.

### La décision se prend une seule fois

La condition est évaluée à l'heure dite, une fois, et le moment est marqué joué
qu'il ait bougé ou non. Il ne se rejoue pas pendant le rattrapage.

C'est voulu : sans cela, un matin sauté à 07:00 pour 4 °C repartirait tout seul à
07:12 parce que le soleil a chauffé la sonde. Un volet qui part un quart d'heure
plus tard, sans que rien ne l'ait demandé, est exactement ce qu'on ne veut pas
d'un automatisme. La décision se prend à l'heure prévue ; ce qui n'a pas été fait
ce jour-là ne sera pas fait.

Quand un moment est sauté, le journal et la commande **Dernier changement** le
disent avec les chiffres : « Matin sauté : 1,5 °C, seuil 5 °C ». Vous n'avez
jamais à deviner pourquoi les volets n'ont pas bougé.

## Les commandes créées

| Commande | Type | Rôle |
|---|---|---|
| **Prochain changement** | info | « Fermeture aujourd'hui 21:14 », ou « Suspendu ». Visible sur le tableau de bord. |
| **Ouvrir** / **Fermer** / **Stop** | action | Agit sur tout le groupe, à la main ou depuis un scénario. |
| **Position** | action curseur | Place tout le groupe à un pourcentage. 0 % = fermé, 100 % = ouvert. |
| **État** | info numérique | La position du groupe, moyenne de celles que ses volets publient. Historisée. |
| **Température retenue** | info numérique | La mesure sur laquelle les conditions ont été évaluées. Historisée : c'est elle qui explique, trois jours plus tard, pourquoi le matin a été sauté. |
| **Dernier changement** | info | « Ouvert aujourd'hui 07:12 (programmation) », ou « Matin sauté : 1,5 °C, seuil 5 °C ». Répond seule à « est-ce que ça a marché ce matin ? ». |
| **Programmation active** | info binaire | 0 quand le groupe est suspendu. Historisée. |
| **Suspendre** / **Reprendre** | action | Le mode vacances, pilotable en scénario. |
| **Prochain matin** / **Prochaine protection** / **Prochain soir** | info | Les trois rendez-vous séparément. |
| **Lever du soleil** / **Coucher du soleil** | info | Les heures du jour, utiles en scénario. |

Seules les quatre premières sont visibles à la création ; les autres sont créées
masquées, à réafficher si vous en avez l'usage.

**L'« État » est celui des volets.** C'est la moyenne des positions que publient
les volets du groupe, rafraîchie chaque minute, et elle suit ce qui se passe
réellement — y compris quand quelqu'un appuie sur l'interrupteur mural. Les
volets qui ne publient pas leur position — beaucoup de modules 433 MHz, par
exemple — ne peuvent rien dire ; si aucun volet du groupe n'en publie, l'« État »
garde le souvenir du dernier ordre envoyé, faute de mieux. Ce que le plugin a
demandé, lui, est toujours dans « Dernier changement ».

Si vous renommez une commande ou changez sa visibilité, le plugin ne vous
contredira pas : il repose le type et le type générique à chaque enregistrement,
jamais le nom ni la visibilité. Ils sont à vous dès que vous y avez touché.

## Ce qui se passe quand un volet ne sait pas faire

Les volets d'un même groupe n'ont pas tous les mêmes talents, et le plugin
s'arrange avec ce qu'il trouve :

- **Ouvrir** sans commande « monter » : le curseur est envoyé à 100 %.
- **Fermer** sans commande « descendre » : le curseur est envoyé à 0 %.
- **Placer à 30 %** sans curseur : le volet est **fermé** (en dessous de 50 %) ou
  **ouvert** (à partir de 50 %), et le journal le dit. Un volet tout ou rien dans
  un groupe réglé à 30 % ne doit pas rester immobile sans explication.
- **Stop** sans commande d'arrêt : l'ordre est ignoré pour ce volet, avec un
  message explicite. Beaucoup de volets ne savent pas s'arrêter, et ce n'est pas
  une panne.

## Suspendre un groupe

Partir quinze jours, ou simplement vouloir dormir volets ouverts ce dimanche,
n'a rien à voir avec désactiver l'équipement : celui-ci sortirait du tableau de
bord, ses boutons ne répondraient plus et ses commandes disparaîtraient des
scénarios.

**Suspendre** arrête les trois moments, et rien d'autre. Le groupe reste entier,
on peut toujours l'ouvrir et le fermer à la main, et sa tuile affiche
« Suspendu » au lieu d'annoncer un rendez-vous qu'elle n'honorerait pas. Le
bouton est dans l'onglet *Volets*, et les commandes **Suspendre** et
**Reprendre** se pilotent depuis un scénario — c'est ainsi qu'on branche un mode
vacances, un détecteur d'absence, ou une alarme de vent qui remonte les stores et
interdit au plugin de les redescendre.

La suspension est enregistrée en base, pas en cache : elle survit à un
redémarrage, à une mise à jour et à un vidage du cache. Un groupe suspendu et
oublié étant la panne la plus discrète du plugin — tout fonctionne, et rien ne
bouge — la page Santé les compte, et la page d'accueil les marque en orange.

## Le rattrapage

Le cron passe chaque minute. Si la box était éteinte à l'heure dite, le moment
est encore joué pendant le **rattrapage** — 15 minutes par défaut, réglable dans
la configuration du plugin. Passé ce délai il est abandonné : ouvrir les volets
du matin à quatre heures de l'après-midi ne rend service à personne, et les
fermer à trois heures du matin encore moins.

Un moment n'est joué qu'une fois par jour, même si le cron repasse soixante fois
pendant le rattrapage.

## Questions fréquentes

**Un volet ne bouge plus.** Ouvrez le groupe : un équipement supprimé de Jeedom
porte l'étiquette « Équipement supprimé », un équipement désactivé porte la
sienne. Un échec d'ordre produit aussi un message au centre de messages de
Jeedom.

**Un volet part dans le mauvais sens.** Ouvrez le sélecteur, dépliez sa ligne
avec le bouton ⚙ et cochez **inversé**. Le bouton monter de la ligne permet de
vérifier immédiatement.

**J'ai renommé un volet, dois-je le re-choisir ?** Non. Les volets sont
enregistrés par leur identifiant ; le nom affiché est rafraîchi à chaque
ouverture de la page.

**Ma sonde de température est en panne, que se passe-t-il ?** Les moments sont
joués quand même, sans condition. C'est délibéré : un automatisme ne doit pas se
taire parce qu'un capteur s'est tu. La page Santé compte les groupes dans ce cas.

**Puis-je mettre le même volet dans deux groupes ?** Oui, mais les deux groupes
lui enverront leurs ordres : le dernier arrivé gagne. C'est utile pour une
protection solaire qui ne concerne qu'une partie d'un groupe plus large ; c'est
gênant si les deux groupes ferment à des heures différentes.

**Un groupe peut-il contenir un autre groupe ?** Non. Le sélecteur ne se propose
pas lui-même, un groupe qui se contiendrait s'appellerait sans fin.

**Et le vent, la pluie, la présence ?** Le plugin ne les regarde pas. Ces
conditions-là sont affaire de scénario, et le scénario a tout ce qu'il lui faut :
il suspend le groupe, ou il appelle **Ouvrir**, **Fermer** ou **Position**
directement. La règle de partage est simple : ce qui se répète tous les jours est
dans le plugin, ce qui dépend d'un événement est dans un scénario.

**Où est le journal ?** Analyse → Logs → `voletautobe`. Chaque ordre joué y
laisse une ligne avec l'heure prévue, le réglage qui l'a produite, et la
température retenue s'il y avait une condition.
