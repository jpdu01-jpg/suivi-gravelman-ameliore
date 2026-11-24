# Suivi Gravelman amélioré (plugin WordPress BikeXplore)

Classement live d'UN parcours d'une épreuve Gravelman suivie sur DotVision, affiché sur
https://bikexplore.brocld.fr via le shortcode `[suivi_gravelman url="…?s=ID" parcours="500 Route"]`.
Le propriétaire corrige souvent depuis son iPhone, parfois pendant qu'il roule : réponses courtes,
en français, et toujours une correction vérifiée plutôt qu'une hypothèse.

## Structure
- `suivi-gravelman-ameliore/includes/class-sga-core.php` — toute la logique métier, sans WordPress
  (catégories, tracés GPX/DotVision, projection des positions sur le tracé, arrivées, raccourcis, classement).
- `suivi-gravelman-ameliore/suivi-gravelman-ameliore.php` — intégration WordPress (shortcode, AJAX, cron, cache).
- `suivi-gravelman-ameliore/includes/class-sga-updater.php` — mises à jour depuis les releases GitHub.
- `suivi-gravelman-ameliore/assets/suivi.js|.css` — interface (carte Leaflet, tableau, coureurs suivis).
- `tests/run.php` — rejoue une épreuve complète en ligne de commande avec la vraie logique.
- `tests/trace.php` — km par relevé pour un coureur (diagnostic d'un mauvais classement).
- `tests/wp-stub/` — mini-WordPress pour tester l'interface : `php -S localhost:8765 tests/wp-stub/index.php`.

## Vérifier une correction
```
php tests/run.php "https://mymotion.dotvision.com/play?s=ID" "500 Route"     # classement complet
php tests/trace.php "NOM" 500R 30                                             # (stage 256059 codé en dur : adapter $id)
```
PHP 7.4+ requis (`apt-get install -y php-cli php-xml` si absent). Les relevés DotVision sont mis en cache
dans `tests/.cache/` (ignoré par git : données personnelles, ne jamais committer).

## Publier une correction (déploiement)
1. Augmenter la version aux DEUX endroits de `suivi-gravelman-ameliore.php` : en-tête `Version:` et
   `const VERSION`. Correctif → 2.1.1, 2.1.2… ; nouvelle fonction → 2.2.0.
2. Message de commit en français, lisible par le propriétaire (il sert de note de version).
3. Pousser sur `main` (ou fusionner la PR) : GitHub Actions crée la release `vX.Y.Z` avec le zip.
4. Le site s'installe la mise à jour seul en ≤ 5 min si « mises à jour express » est activé,
   sinon « Mettre à jour » dans Extensions.
Revenir en arrière = `git revert` + nouvelle version supérieure (on ne republie jamais un ancien numéro).

## Règles métier à préserver
- Le km d'un coureur ne recule jamais ; premier passage « en avant » pour les allers-retours.
- Saut > 45 km/h + 5 km confirmé sur 2 relevés = raccourci (« Parcours écourté » au-delà de 10 km) ;
  retour durable sur la partie sautée = erreur de direction, saut annulé.
- Arrivée : ≤ 350 m de la fin du tracé après ≥ 97 % du parcours.
- L'organisation (tag « Équipe », > 2 parcours) est exclue.
- Aucun nom de coureur dans l'URL : le visiteur choisit dans la page (localStorage).
