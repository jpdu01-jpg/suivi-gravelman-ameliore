=== BikeXplore – Suivi Gravelman amélioré ===
Version : 2.1.1

Classement live d'UN parcours d'une épreuve Gravelman (ou de toute épreuve suivie sur DotVision),
même quand tous les parcours sont mélangés sur le suivi officiel.

== Installation ==
1. Extensions > Ajouter > Téléverser : suivi-gravelman-ameliore.zip, puis Activer.
2. Désactiver l'ancienne extension « Gravelman Angers 350G Live » si elle n'est plus utilisée.
3. Dans une page, ajouter un bloc « Code court » avec :

   [suivi_gravelman url="https://mymotion.dotvision.com/play?s=256059" parcours="500 Route"]

== Paramètres ==
url       (obligatoire) Lien du suivi officiel DotVision (celui qui contient ?s=…).
parcours  (obligatoire) Parcours à afficher : « 500 Route », « 500R », « 350 Gravel », « 350G »…
          Les différentes écritures sont reconnues. Si le parcours n'existe pas, un message
          (visible des seuls éditeurs) indique les parcours disponibles.
gpx       (facultatif) Tracé de référence :
            - lien OpenRunner (le parcours doit être PUBLIC), ex. https://www.openrunner.com/route-details/25472820
            - ou lien vers un fichier .gpx (par exemple téléversé dans la Médiathèque).
          Sans gpx, le tracé officiel du parcours est récupéré automatiquement chez DotVision.
coureur   (facultatif, inutile en général) Coureur(s) mis en avant par défaut, séparés par « | ».
          Sans ce paramètre, la page affiche tout le parcours et chaque visiteur choisit
          lui-même, dans la page, qui suivre.
exclure   (facultatif) Noms à retirer du classement, séparés par des virgules.
          L'organisation (tag « Équipe », photographe, vidéaste…) est déjà retirée automatiquement.
titre     (facultatif) Titre affiché (par défaut « Classement live 500R »).
club      (facultatif) Badge affiché à côté des coureurs suivis (par défaut « BikeXplore »).

== Coureurs suivis ==
- Champ « Suivre un coureur » : saisir un nom (même partiel) ou un dossard, puis « Suivre ».
- Ou cliquer sur ☆ dans le classement. Plusieurs coureurs peuvent être suivis.
- Case « Coureurs suivis uniquement » : filtre le classement sur ces coureurs.
- Le choix est mémorisé sur l'appareil du visiteur (rien n'est ajouté à l'adresse de la page).
  Retirer un coureur : × sur sa fiche ou ★ dans le classement.
- Pour chaque coureur suivi : rang, km parcourus/restants, moyenne, écart avec le coureur
  devant, derrière et le leader, heure de la dernière position, bouton « Voir sur la carte ».

== Fonctionnement ==
- Toutes les minutes (WP-Cron et/ou visites), l'extension lit chez DotVision les dernières
  positions GPS des coureurs du parcours et les projette sur le tracé de référence.
- Le km de chaque coureur ne recule jamais ; les allers-retours et boucles sont gérés.
- Arrivée : coureur à moins de 350 m de l'arrivée après avoir couvert le parcours.
- « Signal perdu » : pas de position depuis 45 min.
- « Parcours écourté » : saut de plus de 10 km sur le tracé (raccourci), confirmé sur deux relevés.
  Une erreur de direction suivie d'un demi-tour est reconnue et n'est pas pénalisée.
- Si la page est créée en cours ou après l'épreuve, l'historique est reconstitué automatiquement
  (quelques minutes, la progression s'affiche en en-tête).
- Le suivi DotVision n'est plus interrogé une fois l'épreuve terminée depuis 12 h.

Plusieurs pages (épreuves ou parcours différents) peuvent coexister ; chacune a ses propres données.
Changer l'un des paramètres url / parcours / gpx / exclure repart d'un suivi neuf.

== Mises à jour ==
Les nouvelles versions sont publiées sur https://github.com/jpdu01-jpg/suivi-gravelman-ameliore
et apparaissent dans Extensions comme pour toute extension (« Mettre à jour »).
Lien « Activer les mises à jour express » (page Extensions, sous le nom du plugin) :
chaque nouvelle version est alors installée automatiquement dans les 5 minutes. Recommandé
pendant une épreuve, pour qu'une correction arrive sur le site sans intervention.
