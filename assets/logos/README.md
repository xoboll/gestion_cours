# 🖼️ Logos des universités

Déposez ici les fichiers logo de chaque université pour qu'ils remplacent
automatiquement le monogramme coloré affiché sur la page d'accueil — **aucune
modification de code n'est nécessaire**, il suffit de nommer le fichier correctement.

## Nom de fichier attendu (format PNG, fond transparent de préférence)

| Université | Sigle | Nom de fichier exact |
|---|---|---|
| Université Félix Houphouët-Boigny | UFHB | `ufhb.png` |
| Université Nangui Abrogoua | UNA | `una.png` |
| Université de Man | U-MAN | `u-man.png` |
| Université Internationale de Côte d'Ivoire | UICI | `uici.png` |
| Institut Universitaire d'Abidjan | UIA | `uia.png` |
| Institut International Polytechnique des Élites d'Abidjan | IIPEA | `iipea.png` |

## Comment ça marche

La page d'accueil (`index.php`) vérifie automatiquement si un fichier portant
le bon nom existe dans ce dossier :

- ✅ **Fichier présent** → le logo est affiché sur fond blanc, centré et redimensionné
- ➖ **Fichier absent** → un monogramme coloré (sigle sur fond dégradé) est affiché à la place

## Recommandations

- Format **PNG** avec fond transparent pour un rendu propre
- Taille conseillée : au moins **300×300 px**, carré ou proche du carré
- Utilisez les logos officiels de chaque université (téléchargeables sur leurs
  sites institutionnels ou pages officielles)
- Poids léger (< 200 Ko par fichier) pour ne pas ralentir le chargement de la page
