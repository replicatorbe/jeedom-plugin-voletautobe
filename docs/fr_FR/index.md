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
coucher du soleil sont fausses toute l'année, la position du soleil dans le ciel
l'est tout autant, et rien d'autre que ce plugin ne vous le dira.

C'est la même position que celle dont Jeedom se sert pour `#sunrise#` et
`#sunset#` dans les scénarios : les heures du plugin et celles de vos scénarios
ne peuvent donc pas diverger. C'est elle aussi qui permet de calculer où se
trouve le soleil à chaque instant, et donc sur quelle façade il tape.

Si vous comptez vous servir des conditions de température, désignez aussi une
sonde par défaut dans la configuration du plugin. Une maison a une température
extérieure, pas huit : la régler une fois évite de la choisir groupe par groupe,
et un groupe pourra toujours avoir la sienne s'il le faut — la chambre au nord,
une véranda.

Une sonde de luminosité — un luxmètre, une station météo, un capteur de
rayonnement — est tout à fait facultative. Elle ne sert qu'aux conditions de
luminosité, que personne n'est obligé de poser ; si vous en avez une, désignez-la
de la même façon, une fois pour toute la maison.

## Une convention à retenir : 0 % = fermé, 100 % = ouvert

Partout dans le plugin, dans l'interface comme dans les commandes, **0 % veut
dire fermé et 100 % veut dire ouvert**. C'est la convention du cœur de Jeedom
pour le type générique *Volet*, et c'est celle que le plugin affiche.

Tous les volets ne la respectent pas : certains modules publient 0 pour « grand
ouvert » et 100 pour « fermé ». Ceux-là se corrigent volet par volet, avec la
case **inversé** du sélecteur — voir plus bas.

## Créer un groupe

Un **groupe** rassemble les volets qu'on ouvre et qu'on ferme ensemble : la
façade sud, le rez-de-chaussée, les chambres. Un groupe a quatre moments — le
matin, la protection solaire, la fin de protection, le soir — et c'est tout ce
qu'il faut régler.

1. **Plugins → Automatisation → Volets Auto → Ajouter un groupe.** Donnez-lui le
   nom de ce qu'il commande.
2. **Choisir les volets.** Le bouton ouvre le sélecteur.
3. **Onglet Volets.** Dites vers où regarde la façade, et choisissez les sondes
   de température et de luminosité si vous comptez vous servir des conditions
   et que celles du plugin ne conviennent pas.
4. **Onglet Programmation.** Réglez le matin, réglez le soir, et la protection
   solaire si vous en voulez une.
5. **Sauvegarder.** Il n'y a rien d'autre à faire.

### Un groupe par façade

Ce n'est plus un conseil, c'est la façon dont le plugin est construit :
**l'orientation appartient au groupe.** Elle s'écrit une fois, dans l'onglet
*Volets*, et les quatre moments s'y réfèrent — celui qui se déclenche quand le
soleil arrive sur le mur comme celui qui vérifie qu'il y est bien.

Un groupe qui mélangerait la façade sud et la façade nord n'aurait donc aucune
orientation à déclarer : les deux murs ne prennent pas le soleil aux mêmes
heures, et une protection solaire réglée pour l'un serait fausse pour l'autre.
Découpez par façade — la baie du salon au sud-ouest, les chambres à l'est — et
tout le reste se règle tout seul. Un groupe par étage, lui, ne correspond à rien
de ce que fait le soleil.

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

## Les quatre moments

Les quatre moments se règlent exactement de la même façon, et se lisent dans
l'ordre de la journée :

| Moment | Ce qu'il fait | Quand, par défaut |
|---|---|---|
| **Le matin** | ouvrir | au lever du soleil, pas avant 07:00 — **activé** |
| **Protection solaire** | placer à 30 % | quand le soleil arrive sur la façade — désactivé |
| **Fin de protection** | ouvrir | quand le soleil quitte la façade — désactivé |
| **Le soir** | fermer | au coucher du soleil, pas avant 18:00 — **activé** |

Seuls le matin et le soir sont actifs à la création : ce sont les deux que tout
le monde veut. Les deux autres forment une paire, à activer ensemble le jour où
l'on s'occupe du soleil — l'un ferme, l'autre rouvre.

**Faire** — ouvrir, fermer, ou placer à un pourcentage. Le matin ouvre et le
soir ferme par défaut, mais rien n'oblige à s'y tenir : un matin réglé sur
« placer à 60 % » laisse entrer la lumière sans donner la chambre à voir depuis
la rue.

**Quand** — cinq possibilités :

- **À une heure fixe** : 07:00, toute l'année.
- **Par rapport au lever du soleil** : tant de minutes avant ou après.
- **Par rapport au coucher du soleil** : idem.
- **Quand le soleil arrive sur la façade** : au premier instant de la journée où
  le soleil éclaire ce mur, quelle que soit la saison.
- **Quand le soleil quitte la façade** : au premier instant où il cesse de
  l'éclairer — parce qu'il a tourné, parce qu'il est descendu trop bas, ou parce
  qu'il se couche.

Les deux derniers ne demandent aucun angle : la façade est déjà écrite dans
l'onglet *Volets* du groupe, et ils s'y réfèrent. Toute une section plus bas leur
est consacrée.

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
aucun jour coché, position de l'installation manquante, ou façade que le soleil
n'atteint jamais à cette saison.

**Essayer ce moment** — un bouton, sous chaque moment, qui exécute son action
sur-le-champ : son « faire », son pourcentage, ses volets. Il commande pour de
vrai, sans se soucier des conditions — un bouton d'essai qui ne ferait rien
parce qu'il fait 18 °C serait incompréhensible — **et il rend compte, dans la
même phrase, de ce que les conditions auraient dit à l'heure prévue** :
« Fermeture à 30 % envoyée à 4 volets. Au moment venu, ce moment aurait été
sauté : 18,2 °C, seuil 26 °C. »

C'est cette seconde moitié qui a le plus de valeur. Le premier bout vérifie la
chaîne complète, du réglage jusqu'au volet qui bouge — ce qu'aucun autre bouton
ne fait, ceux du groupe se contentant d'ouvrir, de fermer et d'arrêter. Le
second répond en plein mois de mars, et en une seconde, à la question qu'on ne
pouvait poser qu'au lendemain matin : *ce réglage-là aurait-il fait quelque
chose aujourd'hui, et sinon pourquoi ?*

L'essai ne marque pas le moment comme joué : il se jouera quand même, à son
heure, le jour même. Essayer un moment ne le consomme pas.

### La protection solaire, et sa fin

Le troisième moment est celui qui justifie à lui seul la sonde de température :
fermer les volets **aux trois quarts** pendant les heures chaudes, **les jours
chauds seulement**, et ne rien faire le reste de l'année.

Il est livré désactivé, réglé sur « placer à 30 % », « quand le soleil arrive sur
la façade », « seulement quand le soleil est sur la façade » — une condition sans
effet avec ce déclencheur-là, on verra plus bas pourquoi — et « seulement si la
température est ≥ 26 °C ». Ainsi réglé, il ne fait rien d'avril à juin, ferme aux
trois quarts pendant la canicule, et se tait de nouveau en septembre — sans que
vous ayez à l'activer ni à le désactiver au fil des saisons.

30 % plutôt que 0 % n'est pas un caprice : un volet complètement fermé fait une
pièce noire à midi. Aux trois quarts, la chaleur est arrêtée et il reste de quoi
vivre sans allumer.

Le quatrième moment, **Fin de protection**, est son complément obligé : il rouvre
les volets quand le soleil quitte la façade. Sans lui, la pièce resterait à 30 %
jusqu'au soir, longtemps après que le soleil est passé ailleurs. Les deux moments
se règlent sur la façade du groupe, et la section suivante explique comment.

Ils forment une paire jusque dans leur exécution : la fin de protection ne
rouvre que les jours où la protection solaire a réellement fermé. Un jour trop
frais pour qu'elle ferme est un jour où la fin de protection n'a rien à rouvrir,
et elle se tait. La section qui lui est consacrée dit pourquoi, et dans quel cas
elle joue malgré tout toute seule.

### Une protection écartée se réessaie

La protection solaire est le seul moment qui ne se décide pas une fois pour
toutes. Écartée par ses conditions à l'arrivée du soleil — 24 °C à 11 h pour un
seuil de 26 °C —, elle n'est pas perdue pour la journée : c'est précisément pour
l'après-midi à 29 °C qu'on l'a réglée. Elle est donc **réévaluée chaque minute**,
et part la première fois que ses conditions sont réunies.

Les nouveaux essais durent :

- jusqu'à la **fin de protection** du jour, si ce moment est coché — fermer après
  la réouverture laisserait la pièce à 30 % jusqu'au soir ;
- sinon jusqu'à ce que **le soleil quitte la façade** ;
- sinon jusqu'au **coucher du soleil** ;

à chaque fois **moins une marge de 30 minutes** : fermer à 17 h 41 pour rouvrir à
17 h 42, ce sont deux trajets de moteur pour rien.

Le journal et la commande **Dernier changement** suivent l'attente sans la
répéter chaque minute. Le premier refus s'écrit avec l'heure limite —
« Protection solaire en attente : 24,1 °C, seuil 26 °C — nouvel essai jusqu'à
17:30 » —, puis vient soit l'ordre, marqué « après attente des conditions », soit
« Protection solaire abandonnée pour aujourd'hui : conditions jamais réunies ».
Le bouton **Essayer ce moment** le rappelle aussi : quand il répond que la
protection aurait été sautée, il précise qu'elle se serait réessayée.

Le premier essai, lui, obéit à la règle commune : il doit avoir lieu à l'heure
dite ou pendant le rattrapage. Une box redémarrée à 15 h ne commence pas à
attendre une protection qui était due à 11 h.

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

### Une sonde figée compte comme muette

Une sonde dont la pile meurt ne dit pas qu'elle s'est tue : Jeedom garde sa
dernière valeur, indéfiniment. Un 27 °C relevé un après-midi de juillet ferait
alors fermer la protection solaire toute la semaine suivante, pluie comprise, et
un matin d'octobre jugerait l'hiver entier sur cette seule mesure.

Le plugin regarde donc l'âge de la dernière mesure. **Au-delà de 3 heures sans
nouvelle mesure**, la sonde est traitée comme muette : sa valeur est ignorée, la
condition ne filtre plus rien, et le moment est joué quand même. Le délai se
règle dans la configuration du plugin, champ **Sonde muette après**, de 0 à
168 heures ; **0 désactive le contrôle**. Trois heures laissent passer une
station météo qui ne publie qu'une fois l'heure. Une sonde qui publie la même
valeur tous les quarts d'heure est bien vivante : c'est la date de collecte qui
compte, pas le changement de valeur.

Le contrôle vaut pour la température comme pour la luminosité, et il se voit
partout où il faut :

- le journal dit « sonde de température figée depuis 5 h, ordre envoyé quand
  même » plutôt que « muette » — l'un demande de changer une pile, l'autre de
  vérifier un réglage ;
- l'onglet *Volets* du groupe affiche « Sonde figée depuis 5 h » en orange à la
  place de la mesure ;
- la page **Santé** compte les groupes concernés sur une ligne « Sondes figées ».

### La décision se prend une seule fois

La condition est évaluée à l'heure dite, une fois, et le moment est marqué joué
qu'il ait bougé ou non. Il ne se rejoue pas pendant le rattrapage. La protection
solaire fait seule exception, pour les raisons dites plus haut : écartée, elle
se réessaie tant que le soleil est sur la façade.

C'est voulu : sans cela, un matin sauté à 07:00 pour 4 °C repartirait tout seul à
07:12 parce que le soleil a chauffé la sonde. Un volet qui part un quart d'heure
plus tard, sans que rien ne l'ait demandé, est exactement ce qu'on ne veut pas
d'un automatisme. La décision se prend à l'heure prévue ; ce qui n'a pas été fait
ce jour-là ne sera pas fait.

Quand un moment est sauté, le journal et la commande **Dernier changement** le
disent avec les chiffres : « Matin sauté : 1,5 °C, seuil 5 °C ». Vous n'avez
jamais à deviner pourquoi les volets n'ont pas bougé.

## La condition de luminosité

Le plugin sait **où** est le soleil, pas **s'il brille**. Un jour couvert à
27 °C, le soleil est sur la façade — par le calcul — et il fait chaud, mais rien
ne tape sur la vitre : fermer aux trois quarts plonge la pièce dans la pénombre
pour rien. Seule une mesure de lumière sait qu'il y a des nuages.

Chaque moment peut donc recevoir une **condition de luminosité**, bâtie
exactement comme celle de température : **sans condition**, **seulement si la
luminosité atteint au moins** une valeur, ou **seulement si elle ne dépasse
pas** une valeur. Elle est **facultative partout**, protection solaire comprise :
aucun moment n'en pose à la création, et un groupe enregistré avant cette
version continue de faire exactement ce qu'il faisait.

L'usage type : la protection solaire, « seulement si la luminosité atteint au
moins 20 000 lx ». Le jour de grand soleil, elle ferme ; le jour gris, elle se
tait — et, comme elle se réessaie chaque minute, elle ferme quand même si le ciel
se dégage dans l'après-midi.

**La sonde.** Un luxmètre, la luminosité d'une station météo, un capteur de
rayonnement solaire en W/m², ou un indice UV : tous répondent aussi bien à la
seule question posée — soleil ou nuages ? — et beaucoup de stations météo ne
publient que les deux derniers. Comme pour la température, elle se désigne une
fois dans la configuration du plugin, bloc **Luminosité**, et un groupe peut
choisir la sienne dans son onglet *Volets* ; l'option vide veut dire « celle du
plugin ». La mesure s'affiche à côté, avec son unité. La liste ne propose pas le
niveau de variation des lampes, qui n'a rien à dire du ciel, ni la « Luminosité
retenue » des groupes du plugin.

**Le seuil s'écrit dans l'unité de la sonde.** Le plugin ne convertit rien : un
seuil de 20 000 a du sens en lux, pas en W/m², où l'on écrira plutôt 300 ou 400.
L'unité affichée à côté du champ est celle de la sonde retenue par le groupe, et
les comptes rendus la reprennent. « 20 000 » peut se taper avec l'espace des
milliers.

Toutes les règles de la température valent ici :

- **une sonde muette ou figée ne bloque rien** : le moment est joué quand même,
  le journal le dit, l'aperçu du moment l'écrit en rouge sous le réglage, et la
  page **Santé** compte les groupes concernés sur une ligne « Sonde de
  luminosité » ;
- **un moment sauté donne ses chiffres** : « Protection solaire sautée :
  luminosité 8 000 lx, seuil 20 000 lx ».

Quand plusieurs conditions sont posées, la luminosité est regardée en dernier,
après le soleil et la température : c'est un raffinement du raffinement.

## La position du soleil

Une heure fixe n'est qu'une approximation de ce qu'on cherche vraiment. Le 13:00
qui convient en juin laisse le soleil taper une heure de trop en août, et ne
correspond plus à rien en octobre. La vraie question n'est pas l'heure qu'il
est, c'est **où est le soleil** : une façade sud-ouest prend le soleil quand
celui-ci arrive en face d'elle, vers 200°, et ce n'est pas à la même heure d'un
mois à l'autre.

Le plugin sait calculer la position du soleil au-dessus de votre maison à chaque
instant. Vous n'avez qu'une chose à lui apprendre : **vers où regarde la façade
de ce groupe**. Elle s'écrit une fois, et tout le reste s'y réfère — les moments
qui se déclenchent dessus comme ceux qui s'y conditionnent.

### La façade se déclare une fois, dans le groupe

L'onglet *Volets* porte un bloc **Façade**, à côté de la sonde de température.
Trois nombres, qui décrivent le même mur vu de trois côtés :

| Champ | Ce qu'il dit | Livré à |
|---|---|---|
| **de** | l'azimut auquel le soleil **arrive** sur la façade | 135° (sud-est) |
| **à** | l'azimut auquel il la **quitte** | 315° (nord-ouest) |
| **au-dessus de** | la **hauteur minimale** en dessous de laquelle il ne tape pas vraiment | 15° |

Ces trois nombres ne sont pas trois réglages indépendants : **ensemble, ils
définissent une seule chose — le moment de la journée où le soleil éclaire cette
façade.** Le soleil est sur la façade quand son azimut est dans la fenêtre
*de … à* **et** que sa hauteur dépasse le minimum ; dès que l'une des deux cesse
d'être vraie, il n'y est plus. La hauteur minimale n'est donc pas une condition
posée par-dessus l'azimut : c'est la moitié de la définition, et la section
« Arriver sur la façade, la quitter » montre que c'est le plus souvent elle qui
décide.

Sous ces trois champs, le bloc affiche en permanence la position du soleil
**maintenant** : c'est l'outil qui permet de les remplir sans rien mesurer, et le
paragraphe suivant en donne le mode d'emploi. Dès que vous touchez à l'un des
trois nombres, une seconde phrase s'y ajoute et dit ce que cette façade donne
réellement sur une année à votre position — c'est l'objet de la section « Le
plugin vous dit si le soleil atteint votre façade ».

Ces trois nombres sont écrits **une seule fois pour tout le groupe**, et aucun
moment ne les redemande. Un moment déclenché sur la façade y renvoie ; une
condition de soleil y renvoie aussi. Vous ne pouvez donc pas écrire
l'orientation de votre maison à deux endroits et vous demander ensuite lequel
des deux fait foi. Le jour où vous corrigez un de ces nombres, les quatre
moments du groupe suivent sans que vous ayez à y retoucher.

Les valeurs livrées — **135° à 315°, au-dessus de 15°** — couvrent la moitié sud
du ciel, du sud-est au nord-ouest. C'est une façade sud au sens large, et un
point de départ raisonnable tant que vous n'avez pas relevé la vôtre.

### L'azimut, la hauteur, et la direction en clair

L'**azimut** est la direction d'où vient le soleil, comptée en degrés depuis le
nord et dans le sens des aiguilles d'une montre — exactement comme sur une
boussole :

| Azimut | Direction |
|---|---|
| **0°** (ou 360°) | nord |
| **90°** | est |
| **180°** | sud |
| **270°** | ouest |

Le soleil se lève à l'est, passe au sud en milieu de journée et se couche à
l'ouest : sous nos latitudes, son azimut ne fait que croître du lever au
coucher. À 50,5° de latitude nord — la Belgique, le nord de la France — il part
de 50° au lever du 21 juin pour finir à **310,1°** au coucher ; le 21 décembre,
il ne va que de 127,5° à **232,5°**, ce qui est une autre façon de dire que le
soleil d'hiver ne visite jamais les façades plein est et plein ouest.

Retenez ce **310,1°** : c'est le plus loin que le soleil aille dans l'année chez
nous, au soir du solstice d'été. Jamais au-delà. Une façade qui se termine à
315° — celle que le plugin livre — n'est donc **jamais quittée par la rotation du
soleil** : il se couche avant d'y arriver. Ce seul chiffre commande la suite de
cette section, et il est contre-intuitif : sous nos latitudes, le soleil quitte
une façade ouest en descendant, pas en tournant.

La **hauteur** est l'angle du soleil au-dessus de l'horizon. Elle vaut 0° au
lever et au coucher, elle est négative la nuit, et elle culmine à 62,6° au midi
du 21 juin à Bruxelles contre 15,7° seulement au midi du 21 décembre. C'est la
même hauteur qui fait la différence entre un soleil qui chauffe une pièce et un
soleil qui éclaire le mur d'en face.

Partout où le plugin affiche un azimut, il en donne aussi le nom : « 200°
(sud-sud-ouest) ». Un chiffre ne se vérifie pas de mémoire ; une direction, si.

### Relever l'orientation de sa façade sans boussole

Vous n'avez besoin ni de boussole, ni de chercher votre maison sur une carte.
L'onglet *Volets* d'un groupe affiche en permanence la position du soleil
**maintenant**, juste sous le bloc Façade : « Soleil au 217° (sud-ouest), 31° de
hauteur ».

C'est l'outil de réglage du plugin, et la manœuvre tient en trois gestes :

1. **Attendez le moment où le soleil tape sur la façade** — celui qui vous gêne,
   celui où la pièce chauffe, celui où vous baisseriez le volet à la main.
2. **Ouvrez la page du groupe** et lisez l'azimut affiché. C'est la direction du
   soleil à cet instant, donc à peu près l'orientation de votre façade.
3. **Recopiez ce chiffre dans le champ *de*.**

Recommencez en fin d'après-midi, quand le soleil quitte la façade et que la
pièce cesse de chauffer : ce second relevé va dans le champ *à*. Deux coups
d'œil par la fenêtre, et votre façade est déclarée.

C'est le moins critique des deux relevés : sous nos latitudes, ce qui fait sortir
le soleil d'une façade ouest, c'est presque toujours sa descente sous la hauteur
minimale, pas son azimut de fin. Un *à* approximatif, pris un peu large, ne fait
aucun mal.

Un relevé fait un seul jour vaut pour toute l'année. L'azimut auquel le soleil
arrive en face de votre mur ne dépend pas de la saison : seule change l'heure à
laquelle il y arrive, et c'est précisément ce dont le plugin se charge.

Si vous préférez raisonner sur le plan de la maison : une façade plein sud
regarde 180°, une façade sud-ouest 225°, une façade ouest 270°, une façade
sud-est 135°. Prenez alors le *de* et le *à* de part et d'autre — une façade
sud-ouest prend le soleil grosso modo de 180° à 270°.

### Le plugin vous dit si le soleil atteint votre façade

Trois nombres parfaitement cohérents à l'écran peuvent décrire une façade que le
soleil n'atteint jamais, ou qu'il ne quitte jamais. Rien ne le signalait à la
saisie : le réglage était accepté, l'aperçu des prochaines fois restait vide, et
l'on mettait des mois à comprendre — quand on comprenait.

Le plugin a pourtant tout ce qu'il faut pour le dire : votre latitude, votre
longitude, et la course du soleil sur une année. Il la parcourt donc, un jour
sur cinq, et écrit sous les champs de la façade ce qu'elle donne vraiment. La
phrase se rafraîchit une demi-seconde après votre dernière frappe, pendant que
vous réglez, et non des mois plus tard.

Trois réponses sont possibles :

- **« Cette façade est éclairée 340 jours sur 365, jusqu'à 8 h 00 par jour. »**
  Le cas normal : les trois nombres décrivent un mur que le soleil visite pour
  de bon, et les deux déclencheurs de façade tomberont.
- **« Le soleil ne dépasse jamais 310° à votre latitude : l'exposition ne se
  termine jamais sur l'azimut de fin, mais sur la hauteur minimale ou au
  coucher. »** Ce n'est pas un défaut, et c'est justement le cas de la façade
  livrée, qui va jusqu'à 315° : à 50,5° de latitude nord, le soleil ne va pas
  au-delà de **310,1°**, même au soir du 21 juin. Une façade déclarée jusqu'à
  340° ne se terminera donc jamais sur son azimut de fin. Mieux vaut le savoir
  que le découvrir : c'est la hauteur minimale qui fera sortir le soleil de
  cette façade, et c'est elle qu'il faudra corriger si la fin de protection
  tombe trop tôt ou trop tard.
- **« À votre position, le soleil n'éclaire jamais cette façade. Vérifiez les
  deux azimuts et la hauteur minimale ; le soleil ne monte jamais au-dessus de
  62,6° ici. »** Là, il y a quelque chose à reprendre : une façade plein nord
  trop étroite, un *de* et un *à* intervertis, ou une hauteur minimale de 70°
  sous une latitude où le soleil culmine à 62,6°. Aucun des deux déclencheurs de
  façade ne tomberait un seul jour de l'année.

Sans position d'installation, le plugin ne calcule rien et le dit : il n'y a pas
de course du soleil à parcourir tant que Jeedom ne sait pas où vous êtes.

### Déclencher : les cinq façons de fixer un moment

La liste *Quand* d'un moment compte cinq entrées, dont deux se servent de la
façade du groupe :

| Quand | Le moment part… |
|---|---|
| **À une heure fixe** | à l'heure donnée, toute l'année. |
| **Par rapport au lever du soleil** | tant de minutes avant ou après le lever. |
| **Par rapport au coucher du soleil** | tant de minutes avant ou après le coucher. |
| **Quand le soleil arrive sur la façade** | au premier instant de la journée où le soleil est sur la façade. |
| **Quand le soleil quitte la façade** | au premier instant où il n'y est plus. |

Les deux modes de façade **n'ouvrent aucun champ d'angle** : l'angle est déjà
écrit dans l'onglet *Volets*. Ils n'offrent que le décalage en minutes et son
sens, comme les modes lever et coucher — et il sert vraiment : « 20 minutes après
que le soleil arrive sur la façade » laisse au mur le temps de chauffer avant
qu'on ferme. Les **garde-fous**, les **jours** et le **décalage aléatoire**
s'appliquent ensuite, sans rien de particulier.

Ces deux modes suivent la saison sans que vous ayez rien à retoucher : le soleil
arrive sur une façade sud-ouest plus tôt dans l'après-midi en décembre et plus
tard en juin, et le moment se déplace avec lui. C'est le même principe que les
modes lever et coucher, appliqué à un mur plutôt qu'à l'horizon.

### Arriver sur la façade, la quitter

Le soleil est **sur la façade** quand son azimut est dans la fenêtre *de … à*
**et** que sa hauteur dépasse le minimum. Les deux déclencheurs ne font rien
d'autre que guetter le premier instant où cette phrase devient vraie, puis le
premier instant où elle cesse de l'être.

**Le soleil arrive sur la façade** de trois façons :

- il **franchit l'azimut de début** — le cas ordinaire, celui du début
  d'après-midi sur une façade sud-ouest ;
- il **se lève déjà en face**, quand la hauteur minimale est basse ou nulle :
  une façade plein est, réglée de 45° à 135° au-dessus de 0°, prend le soleil dès
  qu'il paraît au 50°, le 21 juin ;
- il **passe au-dessus de la hauteur minimale** alors qu'il est déjà en face —
  une façade sud-est qui commence à 120° : le 21 décembre le soleil se lève au
  127,5°, donc en plein dedans, mais il rase l'horizon un bon moment avant de
  dépasser les 15° de hauteur, et c'est ce franchissement-là qui est son arrivée.

**Le soleil quitte la façade** de trois façons symétriques, et c'est le premier
des trois qui compte :

- il **franchit l'azimut de fin** ;
- il **descend sous la hauteur minimale** ;
- il **se couche** — ce dernier cas est celui d'une hauteur minimale nulle ou
  négative ; avec 15°, le soleil est déjà passé sous la barre un moment avant de
  disparaître.

Les deux dernières façons de partir ne sont pas des cas d'école, ce sont les
plus fréquentes.
Rappelez-vous le **310,1°** : sous nos latitudes, le soleil ne tourne jamais plus
loin. Une façade livrée de 135° à 315° n'est donc **jamais** quittée par la
rotation du soleil, aucun jour de l'année — 232,5° au coucher du 21 décembre,
310,1° à celui du 21 juin, et rien entre les deux qui atteigne 315°. Si le plugin
ne regardait que l'azimut, « Fin de protection » ne se déclencherait pas une
seule fois de l'année, les volets resteraient à 30 % jusqu'au soir, et la panne
serait parfaitement invisible. C'est bien la descente sous les 15°, ou le
coucher, qui rouvre les volets.

Les jours où le soleil n'atteint jamais la façade, **aucun des deux moments ne se
déclenche**, et l'aperçu des prochaines fois le dit. C'est le cas normal d'une
façade plein est en décembre, quand le soleil se lève déjà au 127,5° : il ne
passera jamais par l'est. C'est aussi celui d'une façade dont la hauteur minimale
est portée à 20° : les jours où le soleil ne monte pas si haut, il n'y a ni
arrivée ni départ. Le plugin ne se replie alors ni sur minuit ni sur le lever, ce
qui serait la pire des réponses : rien n'apparaît dans les prochaines fois, et
rien ne bouge.

### Conditionner : seulement quand le soleil est sur la façade

Le déclenchement dit **quand**, la condition dit **si**. Ce sont deux questions
différentes, et c'est pour cela qu'il y a deux réglages — mais elles ne se posent
pas ensemble : la condition est faite pour les moments déclenchés **autrement**
que sur la façade, à une heure fixe, au lever ou au coucher.

Au même titre que la température, chaque moment peut être soumis à une
**condition de soleil**, qui n'a que deux positions :

- **Aucune** — le moment ne regarde pas le soleil.
- **Seulement quand le soleil est sur la façade** — le moment ne bouge que si le
  soleil est entre les deux azimuts du groupe **et** au-dessus de la hauteur
  minimale, c'est-à-dire s'il est sur la façade au sens exact de la section
  précédente.

Il n'y a rien d'autre à saisir : la condition se sert de la façade du groupe,
celle-là même qui est écrite dans l'onglet *Volets*, et une ligne d'aide la
rappelle sous le réglage pour que vous n'ayez pas à changer d'onglet.

Comme la condition de température, elle est évaluée à l'heure dite, une seule
fois, et le moment est marqué joué qu'il ait bougé ou non.

Quand les deux conditions sont posées, le plugin regarde **le soleil d'abord**.
Une protection solaire qui ne part pas un jour couvert à 27 °C ne doit pas être
annoncée comme « sautée : trop chaud » alors que la vraie raison est que le
soleil n'était pas sur cette façade — et c'est de loin la plus fréquente des
deux.

### Avec un déclencheur de façade, la condition n'a rien à filtrer

C'est la question qu'on se pose en voyant les deux réglages côte à côte, et elle
mérite une réponse nette plutôt qu'un silence.

Un moment déclenché **quand le soleil arrive sur la façade** part, par
définition, au premier instant où le soleil est sur la façade — azimut dans la
fenêtre **et** hauteur au-dessus du minimum, les deux à la fois, puisque c'est
cela qu'« être sur la façade » veut dire. Lui demander en plus « seulement si le
soleil est sur la façade » ne filtre donc **rien du tout**, pas même la hauteur :
la réponse est oui par construction. La page vous le dit sous le réglage, et
laisser la condition sur **Aucune** ne vous fait rien perdre.

Ce n'est pas qu'une économie. Retester ce que le déclencheur vient de poser au
degré près est dangereux : un arrondi de calcul peut placer le soleil un millième
de degré **en dehors** de la façade, et le moment serait alors sauté tous les
jours, pour une raison parfaitement invisible.

Avec **quitte la façade**, c'est pire, et dans l'autre sens : à cet instant-là le
soleil vient justement de ne plus être sur la façade. Une condition de soleil y
serait fausse tous les jours, la réouverture ne se ferait jamais, et les volets
resteraient à 30 %. C'est pour cela que « Fin de protection » est livrée sans
condition de soleil.

La condition garde en revanche tout son sens avec les **autres** déclencheurs :
« à 13:00, mais seulement si le soleil est vraiment sur cette façade » est un
réglage complet, où la fenêtre d'azimut et la hauteur minimale servent l'une et
l'autre pleinement. C'est là qu'il faut la poser.

### Pourquoi une hauteur minimale

Parce que l'azimut seul ne dit pas tout, et c'est bien pour cela que la hauteur
fait partie de la définition de la façade au lieu d'être une condition posée
par-dessus. Un soleil sous l'horizon a un azimut parfaitement défini et
parfaitement hors sujet, et un soleil qui rase les toits à 8° de hauteur ne
chauffe rien : ses rayons traversent beaucoup plus d'atmosphère, et ils sont le
plus souvent arrêtés par une haie, un arbre ou la maison d'en face. Sans hauteur
minimale, une matinée de décembre déclencherait une protection solaire dont
personne n'a besoin, et laisserait le salon dans le noir le jour le plus sombre
de l'année.

C'est elle, aussi, qui fait que le soleil **quitte** une façade ouest en fin de
journée. Il n'ira jamais au-delà de 310,1° d'azimut, mais il descend sous les 15°
tous les soirs de l'année : sur une façade qui se termine à 315°, c'est la
hauteur minimale, et elle seule, qui met fin à la protection solaire.

La hauteur minimale fait en même temps office de garde-fou de saison. À
Bruxelles, le soleil culmine à 15,7° le 21 décembre : avec un minimum de 15°, il
n'est sur la façade que quelques dizaines de minutes autour de midi ce jour-là,
et il n'y est pas du tout si vous demandez 20°. C'est bien ce qu'on veut d'une
protection solaire — qu'elle se taise en hiver sans qu'on ait à la désactiver.

Quand un moment est sauté parce que le soleil n'était pas sur la façade, le
journal et la commande **Dernier changement** nomment le vrai motif, avec les
chiffres :

- « Protection solaire sautée : soleil à 8,4°, minimum 15° »
- « Protection solaire sautée : soleil au 112° (est-sud-est), façade 135°–315° »

La hauteur est regardée avant l'azimut : quand le soleil est trop bas, « trop
bas » est la raison utile à afficher. Ces deux lignes ne peuvent apparaître que
sur un moment déclenché autrement que par la façade — une heure fixe, un lever,
un coucher. Un déclencheur de façade, lui, ne tombe que lorsque les deux sont
déjà vraies.

### La façade peut passer par le nord

Une façade nord-ouest prend le soleil en fin de journée d'été, une façade
nord-est au petit matin. Une façade **de 300° à 30°** est donc parfaitement
légitime : elle part du nord-ouest, passe par le nord et s'arrête au
nord-nord-est. Le plugin la lit dans cet ordre, en franchissant le 0°, et un
soleil au 350° est bien dessus. Il n'y a rien de plus à cocher : il suffit que le
**de** soit plus grand que le **à**.

La seule écriture sans intérêt est une façade dont le début et la fin sont
égaux : elle ne contient rien du tout, le soleil n'y arrive donc jamais, les deux
déclencheurs de façade ne tombent aucun jour et une condition de soleil posée
dessus ne serait jamais remplie. Pour « tout le tour du ciel », c'est la condition
**Aucune** qu'il faut choisir.

### Le quatrième moment : la fin de protection

La protection solaire ferme aux trois quarts. Sans rien d'autre, les volets
restent à 30 % **jusqu'au soir** : le soleil est passé à l'ouest depuis deux
heures, il n'y a plus rien à protéger, et la pièce reste dans la pénombre pour
rien. C'est ce qu'on remarque au bout de trois jours de canicule, et c'est
exactement ce que le quatrième moment corrige.

**Fin de protection** rouvre les volets **quand le soleil quitte la façade**. Il
est livré désactivé, réglé sur « ouvrir », et son déclencheur est déjà le bon :
il suffit de l'activer en même temps que la protection solaire. Les deux vont
par paire — l'un ferme à l'arrivée du soleil, l'autre rouvre à son départ — et
activer le premier sans le second est la meilleure façon de passer ses
après-midi d'été dans le noir.

Et « quitter la façade » veut bien dire les trois choses vues plus haut. C'est
ici que cela compte le plus : avec la façade livrée, de 135° à 315°, l'azimut du
soleil ne dépasse jamais 310,1°, et ce moment ne se déclencherait jamais si le
plugin s'en tenait à l'azimut. Ce qui rouvre les volets, c'est la descente du
soleil sous la hauteur minimale — ou son coucher, si vous avez mis cette hauteur
à zéro.

**Elle ne défait que ce que la protection a fait.** C'est la règle la plus
importante de ce moment, et elle ne va pas de soi, parce qu'elle a été ajoutée
après coup : son absence se payait trop cher.

Les deux moments étaient indépendants l'un de l'autre. Un jour à 19 °C, la
protection solaire était sautée — seuil 26 °C, il ne faisait tout simplement pas
assez chaud — et à 18:03 la fin de protection **ouvrait quand même** les volets.
S'ils étaient fermés parce que quelqu'un faisait la sieste, ou pour ne pas être
vu de la rue, l'automatisme défaisait un geste que personne ne lui avait demandé
de défaire. C'est le pire reproche qu'on puisse faire à un plugin de ce genre,
et il ne se voit qu'après coup : le soir venu, les volets sont ouverts et rien
n'explique pourquoi.

Désormais, la fin de protection ne rouvre que si la protection solaire **a
réellement bougé les volets** le jour même. Pas si elle a été évaluée : si elle
a envoyé ses ordres. Les jours où elle est sautée — trop frais, soleil absent,
jour de la semaine non coché —, la fin de protection est sautée aussi, et elle
le dit dans le journal comme dans la commande **Dernier changement** : « Fin de
protection sautée : la protection solaire n'a pas eu lieu aujourd'hui ». Le
moment est marqué joué malgré tout, comme pour les conditions : la décision se
prend une fois, à l'heure dite, et ne se rattrape pas un quart d'heure plus
tard.

**L'exception compte autant que la règle : quand la protection solaire est
désactivée, la fin de protection redevient autonome.** Le couplage existe pour
ne pas défaire une protection qui n'a pas eu lieu ; là où aucune protection
n'est configurée, il n'y a rien à ne pas défaire, et « ouvrir en fin
d'après-midi, quand le soleil quitte la façade » est un réglage légitime en soi.
C'est celui de qui veut de la lumière dans le salon dès que le mur cesse de
chauffer, sans jamais rien fermer à midi. Activez la protection solaire et les
deux moments forment de nouveau une paire ; laissez-la désactivée et la fin de
protection joue seule, comme le matin et le soir.

Il est livré **sans condition de soleil**, et ce n'est pas un oubli. Au moment où
le soleil quitte la façade, il n'y est par définition plus : poser la condition
sur ce moment-là la rendrait fausse à tous les coups, la réouverture serait
sautée chaque jour, et les volets resteraient à 30 % — précisément ce que le
moment doit éviter. C'est le piège de la section « Avec un déclencheur de
façade », vu de l'autre côté.

Si vous voulez rouvrir un peu plus tard que le départ du soleil, le décalage est
là pour ça : « 30 minutes après que le soleil quitte la façade » laisse au mur le
temps de cesser de rayonner. Et le garde-fou **pas après** ramène à 20:00 une
réouverture qui tomberait à 21:30 en juin, juste avant la fermeture du soir.

### Une façade sud-ouest, de bout en bout

Un salon dont la baie vitrée regarde le sud-ouest, avec une protection solaire
complète. Tout tient dans un seul groupe, et l'orientation n'y est écrite qu'une
fois.

**Dans l'onglet *Volets*, la façade :**

| Réglage | Valeur |
|---|---|
| **Façade, de** | **200°** (sud-sud-ouest) — le soleil arrive |
| **Façade, à** | **290°** (ouest-nord-ouest) — le soleil s'en va |
| **Hauteur minimale** | **15°** |

Les deux azimuts ont été relevés un jour de juin, à la fenêtre : une lecture au
moment où la pièce commence à chauffer, une autre au moment où elle cesse.

**Dans l'onglet *Programmation*, la protection solaire :**

| Réglage | Valeur |
|---|---|
| **Faire** | placer à 30 % |
| **Quand** | quand le soleil **arrive sur la façade** |
| **Pas avant** | **11:00** |
| **Condition de soleil** | aucune |
| **Condition de température** | seulement si ≥ **26 °C** |

**Et la fin de protection :**

| Réglage | Valeur |
|---|---|
| **Faire** | ouvrir |
| **Quand** | quand le soleil **quitte la façade**, 15 minutes après |
| **Condition de soleil** | aucune |
| **Condition de température** | aucune |

Cela se lit comme une phrase : *ferme aux trois quarts quand le soleil arrive en
face de la baie — donc quand il y est vraiment, et assez haut pour chauffer —,
jamais avant 11:00, et seulement s'il fait chaud ; rouvre un quart d'heure après
qu'il s'en est allé.*

Ce que cela donne au fil de l'année vaut d'être regardé de près, parce que c'est
la surprise du réglage : **le 290° ne sert jamais.** Au solstice d'été, le soleil
passe sous les 15° de hauteur à 288,8° d'azimut, à un cheveu des 290° mais avant
eux ; à l'équinoxe, il y passe dès 251°, une heure et demie avant de se coucher
au 270° ; en décembre il ne monte même pas à 15°. Autrement dit, sur cette baie,
c'est la hauteur minimale qui rouvre les volets **tous les jours de l'année**, et
un plugin qui n'aurait regardé que l'azimut ne les aurait jamais rouverts. Le
290° reste utile — il dit où s'arrête le mur — mais ce n'est pas lui qui
déclenche.

Chaque ligne a sa raison d'être :

- **200° et 290° sont l'orientation, et rien d'autre.** Ils sont écrits une fois,
  pour le groupe. Le jour où vous vous apercevez que la pièce chauffe déjà quand
  le soleil est au 190°, vous corrigez un seul nombre et les deux moments
  suivent.
- **Le déclencheur est le *quand*.** Le moment suit le soleil et pas l'horloge :
  il part en début d'après-midi toute l'année, jamais au même moment deux jours
  de suite, et il n'y a plus de 13:00 à ajuster deux fois par an.
- **11:00 est un filet.** La plupart des jours, il n'a rien à ramener : à
  Bruxelles le soleil n'atteint 200° qu'après le milieu de la journée, en toute
  saison. Il coûte une ligne et il borne les dégâts du jour où un chiffre est mal
  recopié — un 100° au lieu de 200° dans le champ *de* ferait fermer le salon en
  pleine matinée.
- **15° est la troisième borne de la façade**, pas un détail de réglage. C'est
  elle qui dit à partir de quand le soleil de cette baie chauffe vraiment, et
  c'est elle qui l'en fait sortir chaque soir, bien avant que l'azimut n'y
  arrive.
- **La condition de soleil n'a rien à faire ici, et c'est pour cela qu'elle est
  sur *aucune*.** Le déclencheur a déjà posé la façade entière, azimut et hauteur
  comprises : au moment où il tombe, la condition serait vraie par construction.
  Le plugin livre la protection solaire avec la condition posée ; la laisser ne
  change rien, la mettre sur *aucune* non plus. C'est avec un déclencheur à heure
  fixe — « à 13:00 » — qu'elle ferait son travail.
- **26 °C est le second *si*.** Un après-midi ensoleillé de mars n'a pas besoin
  d'une protection solaire, et 26 °C le dit mieux qu'une date.
- **Le quart d'heure de la réouverture** laisse au mur le temps de cesser de
  rayonner. Sans ce moment-là, le salon resterait à 30 % jusqu'à la fermeture du
  soir.
- **La réouverture est attachée à la fermeture.** Un 14 juillet à 19 °C, la
  protection solaire ne ferme pas — et la fin de protection ne rouvre pas
  davantage : elle n'a rien à défaire. Si les volets du salon étaient baissés ce
  jour-là, c'est que quelqu'un l'a voulu, et ils le restent.

### Sans position d'installation, le soleil est faux

Le calcul demande une latitude et une longitude. Si la position de
l'installation n'est pas renseignée, azimut et hauteur sont ceux du golfe de
Guinée, c'est-à-dire faux, et **le moment est joué quand même** : la condition
de soleil ne bloque rien, exactement comme une sonde de température muette ne
bloque rien. Une condition est un raffinement, le mouvement est le comportement
normal, et le doute profite au mouvement.

Un moment déclenché sur la façade, lui, partira à un instant qui ne correspond à
rien — le soleil calculé n'est pas celui qui éclaire votre mur.

La panne se voit tout de même : la page **Santé** compte, sur une ligne
« Fenêtre de soleil », les groupes qui se servent du soleil sans position
d'installation, et l'aperçu du moment le signale sous le réglage. Le remède
tient en deux champs, dans **Réglages → Système → Configuration → Général**.

## Les commandes créées

| Commande | Type | Rôle |
|---|---|---|
| **Prochain changement** | info | « Fermeture aujourd'hui 21:14 », ou « Suspendu ». Visible sur le tableau de bord. |
| **Ouvrir** / **Fermer** / **Stop** | action | Agit sur tout le groupe, à la main ou depuis un scénario. |
| **Position** | action curseur | Place tout le groupe à un pourcentage. 0 % = fermé, 100 % = ouvert. |
| **État** | info numérique | La position du groupe, moyenne de celles que ses volets publient. Historisée. |
| **Température retenue** | info numérique | La mesure sur laquelle les conditions ont été évaluées. Historisée : c'est elle qui explique, trois jours plus tard, pourquoi le matin a été sauté. |
| **Luminosité retenue** | info numérique | La mesure de la sonde de luminosité, dans son unité. Historisée, pour la même raison ; vide tant qu'aucune sonde n'est choisie. |
| **Dernier changement** | info | « Ouvert aujourd'hui 07:12 (programmation) », ou « Matin sauté : 1,5 °C, seuil 5 °C ». Répond seule à « est-ce que ça a marché ce matin ? ». |
| **Programmation active** | info binaire | 0 quand le groupe est suspendu. Historisée. |
| **Suspendre** / **Reprendre** | action | Le mode vacances, pilotable en scénario. |
| **Prochain matin** / **Prochaine protection** / **Prochaine fin de protection** / **Prochain soir** | info | Les quatre rendez-vous séparément. |
| **Lever du soleil** / **Coucher du soleil** | info | Les heures du jour, utiles en scénario. |
| **Azimut du soleil** / **Hauteur du soleil** | info numérique | Où est le soleil en ce moment, en degrés. Non historisées : la position du soleil est un calcul et non une mesure, l'archiver reviendrait à stocker une table d'éphémérides qu'on sait refaire à la demande. |

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

**Suspendre** arrête les quatre moments, et rien d'autre. Le groupe reste entier,
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

## Le délai entre deux ordres

Un groupe de huit volets, ce sont huit ordres. Envoyés à la file sans attendre,
ce sont huit trames radio dans la même milliseconde — et en 433 MHz, une ou deux
se perdent. Un volet ne bouge pas, et **rien ne le signale** : la commande a bien
été jouée, aucune erreur n'est levée, le journal est propre et le centre de
messages est vide. C'est la panne la plus désagréable qui soit, parce qu'elle est
intermittente autant qu'invisible : ce n'est jamais le même volet, et le
lendemain tout va bien.

Le plugin attend donc **400 millisecondes entre deux volets**, et rien après le
dernier — attendre après le dernier ordre ne rend service à personne. Le réglage
est dans la configuration du plugin, de 0 à 5000 millisecondes. Zéro rétablit
l'ancien comportement, ce qui convient très bien à une installation entièrement
Zigbee ou Z-Wave, où les ordres sont acquittés ; montez à 800 ou 1000 ms si
votre passerelle 433 MHz reste capricieuse. Huit volets à 400 ms, cela fait
2,8 secondes pour tout le groupe : c'est invisible sur des volets qui mettent
vingt secondes à descendre.

**L'attente vaut entre deux groupes aussi.** Elle se compte depuis le dernier
ordre envoyé, tous groupes confondus, et non depuis le début de chaque groupe. Le
soir, tous les groupes calés sur le coucher du soleil partent à la même minute ;
si le compteur repartait de zéro à chaque groupe, le dernier volet du salon et le
premier de la chambre partiraient dans la même milliseconde — exactement la
trame perdue que le délai existe pour éviter. La passerelle radio ne sait pas à
quel groupe appartient une trame. À l'inverse, un groupe commandé seul, longtemps
après le précédent, n'attend rien pour son premier volet.

L'attente totale d'un groupe est plafonnée à **30 secondes**. Un groupe de cent
volets réglé à 500 ms mettrait cinquante secondes à tout envoyer ; au-delà du
plafond, le délai effectif est réduit d'autant et le journal le note en niveau
debug — c'est un détail d'exécution, pas un événement.

### Les ordres programmés partent en arrière-plan

Le cron de chaque plugin tourne dans le même processus que celui de tous les
autres, l'un après l'autre. Attendre 400 ms entre vingt volets au coucher du
soleil, c'était retenir huit secondes le thermostat, l'alarme et les scénarios
programmés de toute l'installation.

La **décision** reste prise dans le cron, à la minute dite : conditions évaluées,
moment marqué joué. Seul l'**envoi** part dans une tâche de fond du cœur — **une
seule pour toute la minute**, et non une par groupe, sans quoi deux groupes
enverraient de nouveau leurs trames en même temps. Les ordres y partent l'un
après l'autre, avec l'attente décrite plus haut.

L'envoi se fait sur place, comme avant, quand il n'y a rien à attendre — un seul
volet à commander, ou un délai réglé à 0 —, et aussi quand la tâche de fond ne
peut pas être lancée : le journal le signale alors en avertissement (« Envoi en
arrière-plan impossible, ordres envoyés sur place »). Une tâche que le cœur ne
démarrerait que trop tard, au-delà du délai de rattrapage — box surchargée,
redémarrage —, n'envoie rien et le dit : une fermeture de 21 h ne part pas à 3 h
du matin.

Les boutons du groupe, les commandes **Ouvrir**, **Fermer** et **Position**
appelées depuis un scénario, et le bouton **Essayer ce moment** envoient toujours
sur place : il n'y a là aucun cron à ne pas retenir.

## La page Santé

La page **Santé** de Jeedom répond d'un coup d'œil à « est-ce que tout va
bien ? ». Le plugin n'y compte que des choses qui ne se voient pas autrement :

| Ligne | Ce qu'elle regarde |
|---|---|
| **Position de l'installation** | latitude et longitude renseignées : sans elles, tout ce qui touche au soleil est faux. |
| **Groupes actifs** | le nombre de groupes. |
| **Groupes suspendus** | la panne la plus discrète du plugin : tout fonctionne, et rien ne bouge. |
| **Volets programmés** | le nombre de volets que le plugin commande. |
| **Sonde de température** | les groupes qui posent une condition de température sans sonde lisible : elle ne filtre plus rien. |
| **Sonde de luminosité** | la même chose pour la luminosité. « Lisible ou inutilisée » : un groupe sans condition de luminosité n'a pas besoin de sonde, et ne compte pas. |
| **Sondes figées** | les groupes dont une sonde, de température ou de luminosité, n'a rien publié depuis plus longtemps que le délai réglé dans la configuration : sa dernière valeur est ignorée. Vérifiez la pile ou le plugin de la sonde. |
| **Fenêtre de soleil** | les groupes qui se servent du soleil sans position d'installation. |
| **Volets introuvables** | les volets dont l'équipement n'existe plus dans Jeedom. |

La dernière ligne est la plus concrète. Un équipement supprimé de Jeedom reste
dans la configuration du groupe, où il échoue à chaque ordre : tant qu'il y est,
**le groupe commande moins de volets qu'il n'en affiche**, et c'est précisément
ce qu'on ne remarque pas, puisque les sept autres, eux, bougent. Ouvrez le
groupe concerné : ils portent l'étiquette « Équipement supprimé », et il suffit
de les décocher dans le sélecteur.

## Questions fréquentes

**Un volet ne bouge plus.** Ouvrez le groupe : un équipement supprimé de Jeedom
porte l'étiquette « Équipement supprimé », un équipement désactivé porte la
sienne. Un échec d'ordre produit aussi un message au centre de messages de
Jeedom, et la page Santé compte ces volets sur une ligne « Volets introuvables ».

**Un volet sur huit ne bouge pas, de temps en temps, et le journal ne dit
rien.** C'est la signature d'une trame radio perdue : huit ordres envoyés dans
la même milliseconde, en 433 MHz, c'est une ou deux trames qui n'arrivent pas, et
rien ne le signale puisque la commande a bien été jouée. Le plugin attend 400 ms
entre deux volets pour cette raison ; si le symptôme persiste, montez ce délai
dans la configuration du plugin — voir « Le délai entre deux ordres ».

**Il n'a pas fait chaud aujourd'hui : la fin de protection va-t-elle quand même
ouvrir mes volets ce soir ?** Non. Elle ne rouvre que si la protection solaire a
réellement fermé le jour même. Un jour à 19 °C, la protection est sautée et la
fin de protection l'est aussi, avec son motif : « la protection solaire n'a pas
eu lieu aujourd'hui ». Elle ne défait donc plus des volets que vous aviez baissés
à la main. Une seule exception : si vous laissez la protection solaire désactivée
et n'activez que la fin de protection, celle-ci joue seule — « ouvrir quand le
soleil quitte la façade » est un réglage légitime en soi, et le plugin ne vous en
prive pas.

**Comment vérifier ma protection solaire en plein mois de mars ?** Avec le
bouton **Essayer ce moment**, sous chaque moment de l'onglet *Programmation*. Il
envoie l'action pour de vrai — c'est la seule façon de vérifier toute la chaîne
jusqu'au volet qui bouge — et il vous dit dans la foulée ce que les conditions
auraient répondu à l'heure prévue : « Fermeture à 30 % envoyée à 4 volets. Au
moment venu, ce moment aurait été sauté : 18,2 °C, seuil 26 °C. » L'essai ne
consomme pas le moment : il se jouera quand même à son heure.

**J'ai déclaré ma façade et aucune prochaine fois n'apparaît.** Lisez la phrase
que le plugin écrit sous les champs de la façade : elle dit combien de jours par
an le soleil éclaire vraiment ce mur à votre position. Si elle répond qu'il ne
l'éclaire jamais, ce sont les trois nombres qu'il faut reprendre — le plus
souvent un *de* et un *à* intervertis, ou une hauteur minimale plus haute que le
soleil ne monte chez vous.

**Un volet part dans le mauvais sens.** Ouvrez le sélecteur, dépliez sa ligne
avec le bouton ⚙ et cochez **inversé**. Le bouton monter de la ligne permet de
vérifier immédiatement.

**J'ai renommé un volet, dois-je le re-choisir ?** Non. Les volets sont
enregistrés par leur identifiant ; le nom affiché est rafraîchi à chaque
ouverture de la page.

**Ma sonde de température est en panne, que se passe-t-il ?** Les moments sont
joués quand même, sans condition. C'est délibéré : un automatisme ne doit pas se
taire parce qu'un capteur s'est tu. La page Santé compte les groupes dans ce cas.

**Ma sonde affiche une valeur, mais le groupe dit « Sonde figée ».** Sa dernière
mesure date de plus longtemps que le délai de la configuration du plugin — 3 h
par défaut. Jeedom continue d'afficher cette valeur partout, mais le plugin ne
s'y fie plus. Changez la pile, ou vérifiez le plugin qui la publie ; si c'est une
sonde qui ne publie que rarement, allongez le délai **Sonde muette après**, ou
mettez-le à 0 pour désactiver le contrôle.

**Ma protection solaire a été sautée le matin, puis a fermé à 14 h. Est-ce
normal ?** Oui. Écartée à l'arrivée du soleil, elle se réessaie chaque minute
jusqu'à la fin de protection, au départ du soleil ou au coucher, moins une demi-
heure, et part dès que ses conditions sont réunies. Le journal dit « en attente
… nouvel essai jusqu'à … » puis « après attente des conditions ».

**Comment éviter de fermer la protection solaire un jour couvert ?** Avec une
condition de luminosité — « seulement si la luminosité atteint au moins
20 000 lx » — et une sonde de luminosité désignée dans la configuration du plugin
ou dans le groupe. Le seuil s'écrit dans l'unité de la sonde.

**Comment savoir dans quelle direction regarde ma façade ?** Ouvrez le groupe au
moment où le soleil tape dessus et lisez la position du soleil affichée sous le
bloc Façade, dans l'onglet *Volets* : « Soleil au 217° (sud-ouest), 31° de
hauteur ». Recopiez ce chiffre dans le champ *de*, recommencez quand le soleil
s'en va pour le champ *à*, et c'est fini. Ces azimuts ne changent pas avec la
saison.

**Ma condition de soleil fait-elle doublon avec mon déclencheur de façade ?**
Oui, entièrement. Un moment déclenché quand le soleil arrive sur la façade ne
tombe qu'à l'instant où le soleil y est — azimut **et** hauteur : la condition ne
peut qu'être vraie, elle ne filtre rien, et vous pouvez la laisser sur *aucune*.
Avec *quitte la façade*, elle serait même fausse à tous les coups et ferait
sauter la réouverture chaque jour. La condition est faite pour les autres
déclencheurs : « à 13:00, seulement si le soleil est sur la façade ».

**Ma façade va jusqu'à 315° et le soleil n'y arrive jamais : la fin de protection
se déclenche-t-elle quand même ?** Oui. Sous nos latitudes, l'azimut du soleil ne
dépasse pas 310,1°, même au soir du 21 juin : il ne sort jamais d'une telle
façade par la rotation. Il en sort en descendant — sous la hauteur minimale,
puis sous l'horizon — et c'est à ce moment-là que les volets se rouvrent. C'est
la même chose pour toute façade orientée à l'ouest : le soleil la quitte en
descendant bien plus souvent qu'en tournant.

**Mes volets restent à 30 % tout l'après-midi, pourquoi ?** Parce que la
protection solaire est activée et que la **fin de protection** ne l'est pas. Les
deux vont par paire : le premier ferme quand le soleil arrive sur la façade, le
second rouvre quand il s'en va. Activez-le dans l'onglet *Programmation*.

**Je n'ai pas renseigné la position de mon installation, qu'est-ce que ça change
pour le soleil ?** L'azimut et la hauteur sont calculés pour le golfe de Guinée,
donc faux. La condition de soleil ne bloque pas pour autant : **le moment est
joué quand même**, sans condition, comme avec une sonde de température muette.
Un moment déclenché sur la façade partira, lui, à un instant qui ne correspond à
rien. La page Santé compte les groupes concernés sur une ligne « Fenêtre de
soleil ».

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
