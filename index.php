<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

// Vérifier que config.php fournit bien une connexion PDO nommée $pdo.
if (!isset($pdo) || !($pdo instanceof PDO)) {
    die("Erreur : la connexion PDO \$pdo est introuvable. Vérifiez votre fichier config.php.");
}

if (!function_exists('e_index')) {
    function e_index($value): string {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (empty($_SESSION['csrf_boutique'])) {
    $_SESSION['csrf_boutique'] = bin2hex(random_bytes(32));
}

$succursales = $pdo->query("
    SELECT id, nom
    FROM succursales
    WHERE statut = 'Active'
    ORDER BY nom
")->fetchAll(PDO::FETCH_ASSOC);
$idsSuccursalesActives = array_map('intval', array_column($succursales, 'id'));
$succursaleParDefaut = (int)parametre('succursale_par_defaut', 1);
$succursaleDemandee = isset($_GET['succursale'])
    ? (int)$_GET['succursale']
    : (int)($_SESSION['succursale_vente'] ?? $succursaleParDefaut);

if (in_array($succursaleDemandee, $idsSuccursalesActives, true)) {
    $succursaleId = $succursaleDemandee;
} elseif (in_array($succursaleParDefaut, $idsSuccursalesActives, true)) {
    $succursaleId = $succursaleParDefaut;
} else {
    $succursaleId = $idsSuccursalesActives[0] ?? $succursaleParDefaut;
}
$_SESSION['succursale_vente'] = $succursaleId;

// Ajouter un produit au panier sans obliger le visiteur à créer un compte.
$messagePanier = '';
$typeMessage = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajouter_panier'])) {
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_boutique'], $token)) {
        $messagePanier = "Votre session a expiré. Actualisez la page et réessayez.";
        $typeMessage = 'error';
    } else {
        $produitId = filter_input(INPUT_POST, 'produit_id', FILTER_VALIDATE_INT);
        $quantite = filter_input(INPUT_POST, 'quantite', FILTER_VALIDATE_INT);
        $quantite = ($quantite && $quantite > 0) ? $quantite : 1;

        if (!$produitId) {
            $messagePanier = "Produit invalide.";
            $typeMessage = 'error';
        } else {
            $jointureStock = $succursaleId === 1
                ? "LEFT JOIN stocks s ON s.produit_id = p.id"
                : "LEFT JOIN stocks_succursales s ON s.produit_id = p.id AND s.succursale_id = :succursale";
            $stmt = $pdo->prepare("
                SELECT p.id, p.nom_produit, p.prix_vente, p.statut,
                       COALESCE(s.quantite, 0) AS stock_disponible
                FROM produits p
                $jointureStock
                WHERE p.id = :id
                  AND p.statut = 'Disponible'
                  AND p.afficher_accueil = 1
                LIMIT 1
            ");
            $parametresStock = ['id' => $produitId];
            if ($succursaleId !== 1) {
                $parametresStock['succursale'] = $succursaleId;
            }
            $stmt->execute($parametresStock);
            $produitAjoute = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$produitAjoute) {
                $messagePanier = "Ce produit n'est plus disponible.";
                $typeMessage = 'error';
            } elseif ((int)$produitAjoute['stock_disponible'] <= 0) {
                $messagePanier = "Ce produit est actuellement en rupture de stock.";
                $typeMessage = 'error';
            } else {
                if (!isset($_SESSION['panier']) || !is_array($_SESSION['panier'])) {
                    $_SESSION['panier'] = [];
                }

                $ancienneQuantite = (int)($_SESSION['panier'][$produitId] ?? 0);
                $nouvelleQuantite = min(
                    $ancienneQuantite + $quantite,
                    (int)$produitAjoute['stock_disponible']
                );

                $_SESSION['panier'][$produitId] = $nouvelleQuantite;
                $messagePanier = $produitAjoute['nom_produit'] . " a été ajouté au panier.";
            }
        }
    }
}

// Nombre total d'articles dans le panier.
$nombreArticlesPanier = 0;
if (!empty($_SESSION['panier']) && is_array($_SESSION['panier'])) {
    foreach ($_SESSION['panier'] as $qte) {
        $nombreArticlesPanier += (int)$qte;
    }
}

// Filtres du catalogue.
$recherche = trim($_GET['recherche'] ?? '');
$categorieId = filter_input(INPUT_GET, 'categorie', FILTER_VALIDATE_INT);
$categorieId = $categorieId ?: null;

// Catégories actives.
$stmtCategories = $pdo->query("
    SELECT id, nom_categorie
    FROM categories
    WHERE statut = 'Active'
    ORDER BY nom_categorie ASC
");
$categories = $stmtCategories->fetchAll(PDO::FETCH_ASSOC);

// Produits visibles sur la page d'accueil.
$sql = "
    SELECT p.id, p.nom_produit, p.description, p.photo, p.prix_vente,
           p.categorie_id, c.nom_categorie,
           COALESCE(s.quantite, 0) AS stock_disponible
    FROM produits p
    LEFT JOIN categories c ON c.id = p.categorie_id
";

if ($succursaleId === 1) {
    $sql .= " LEFT JOIN stocks s ON s.produit_id = p.id";
} else {
    $sql .= " LEFT JOIN stocks_succursales s ON s.produit_id = p.id AND s.succursale_id = :succursale";
}

$sql .= "
    WHERE p.statut = 'Disponible'
      AND p.afficher_accueil = 1
";
$params = $succursaleId === 1 ? [] : ['succursale' => $succursaleId];

if ($recherche !== '') {
    $sql .= " AND (p.nom_produit LIKE :recherche OR p.description LIKE :recherche)";
    $params['recherche'] = '%' . $recherche . '%';
}

if ($categorieId) {
    $sql .= " AND p.categorie_id = :categorie";
    $params['categorie'] = $categorieId;
}

$sql .= " ORDER BY p.date_creation DESC, p.id DESC";

$stmtProduits = $pdo->prepare($sql);
$stmtProduits->execute($params);
$produits = $stmtProduits->fetchAll(PDO::FETCH_ASSOC);

// Nom de la boutique : modifiable ici.
$nomBoutique = 'NEXIA';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e_index($nomBoutique) ?> - Accueil</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        boutique: {
                            50: '#f0fdf4',
                            100: '#dcfce7',
                            600: '#16a34a',
                            700: '#15803d',
                            800: '#166534'
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen bg-gray-50 text-gray-800">

    <!-- Barre de navigation -->
    <header class="sticky top-0 z-40 border-b border-gray-200 bg-white/95 backdrop-blur">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
            <a href="index.php" class="flex items-center gap-2">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-green-600 text-xl font-bold text-white">B</span>
                <span class="text-xl font-extrabold tracking-tight text-gray-900"><?= e_index($nomBoutique) ?></span>
            </a>

            <nav class="hidden items-center gap-7 md:flex">
                <a href="index.php" class="font-semibold text-green-700">Accueil</a>
                <a href="#catalogue" class="font-medium text-gray-600 transition hover:text-green-700">Nos produits</a>
                <a href="#apropos" class="font-medium text-gray-600 transition hover:text-green-700">À propos</a>
            </nav>

            <div class="flex items-center gap-2">
                <select aria-label="Succursale de vente" onchange="location='index.php?succursale='+this.value" class="max-w-40 rounded-xl border border-gray-200 bg-white px-2 py-2 text-xs font-medium text-gray-700 sm:px-3 sm:text-sm">
                    <?php foreach ($succursales as $succursale): ?>
                        <option value="<?= (int)$succursale['id'] ?>" <?= $succursaleId === (int)$succursale['id'] ? 'selected' : '' ?>>
                            <?= e_index($succursale['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <a href="panier.php" class="relative inline-flex items-center gap-2 rounded-xl border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-700 transition hover:border-green-600 hover:text-green-700">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l2.2 11.2a2 2 0 0 0 2 1.6h8.9a2 2 0 0 0 2-1.6L21 8H6"/>
                        <circle cx="10" cy="20" r="1"/>
                        <circle cx="18" cy="20" r="1"/>
                    </svg>
                    <span class="hidden sm:inline">Panier</span>
                    <span class="flex h-5 min-w-5 items-center justify-center rounded-full bg-green-600 px-1 text-xs font-bold text-white"><?= $nombreArticlesPanier ?></span>
                </a>
                <a href="connexion_client.php" class="hidden rounded-xl bg-green-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-green-700 sm:inline-flex">
                    Mon compte
                </a>
            </div>
        </div>
    </header>

    <main>
        <!-- Bannière principale -->
        <section class="bg-gradient-to-br from-green-800 via-green-700 to-emerald-600 text-white">
            <div class="mx-auto grid max-w-7xl items-center gap-10 px-4 py-16 sm:px-6 md:grid-cols-2 md:py-24 lg:px-8">
                <div>
                    <span class="inline-flex rounded-full border border-white/30 bg-white/10 px-4 py-1.5 text-sm font-medium">Bienvenue dans notre boutique</span>
                    <h1 class="mt-5 max-w-2xl text-4xl font-extrabold leading-tight sm:text-5xl">
                        Découvrez nos produits et faites vos achats en toute simplicité.
                    </h1>
                    <p class="mt-5 max-w-xl text-base leading-7 text-green-50 sm:text-lg">
                        Parcourez notre catalogue, choisissez vos articles et ajoutez-les au panier.
                        Vous pourrez créer votre compte au moment de valider votre commande.
                    </p>
                    <a href="#catalogue" class="mt-8 inline-flex items-center rounded-xl bg-white px-6 py-3 font-bold text-green-800 shadow-lg transition hover:bg-green-50">
                        Découvrir les produits
                        <svg class="ml-2 h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6"/>
                        </svg>
                    </a>
                </div>
                <div class="hidden md:flex md:justify-end">
                    <div class="flex h-72 w-72 items-center justify-center rounded-full border border-white/20 bg-white/10 shadow-2xl lg:h-96 lg:w-96">
                        <div class="flex h-56 w-56 items-center justify-center rounded-full bg-white/10 lg:h-72 lg:w-72">
                            <svg class="h-28 w-28 text-white lg:h-36 lg:w-36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" aria-hidden="true"><path d="M5 8h14l1 13H4L5 8Z"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/></svg>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Avantages -->
        <section class="border-b border-gray-100 bg-white">
            <div class="mx-auto grid max-w-7xl gap-6 px-4 py-7 sm:grid-cols-3 sm:px-6 lg:px-8">
                <div class="flex items-center gap-3">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-green-50 text-xl"></span>
                    <div><p class="font-bold text-gray-900">Commande simple</p><p class="text-sm text-gray-500">Quelques étapes pour acheter</p></div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-green-50 text-xl"></span>
                    <div><p class="font-bold text-gray-900">Compte sécurisé</p><p class="text-sm text-gray-500">Compte demandé à la commande</p></div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-green-50 text-xl"></span>
                    <div><p class="font-bold text-gray-900">Suivi des achats</p><p class="text-sm text-gray-500">Retrouvez vos commandes</p></div>
                </div>
            </div>
        </section>

        <!-- Catalogue -->
        <section id="catalogue" class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
            <div class="mb-8 flex flex-col justify-between gap-4 md:flex-row md:items-end">
                <div>
                    <p class="text-sm font-bold uppercase tracking-widest text-green-700">Notre sélection</p>
                    <h2 class="mt-2 text-3xl font-extrabold text-gray-900">Nos produits</h2>
                    <p class="mt-2 text-gray-500">Choisissez les articles qui vous intéressent.</p>
                </div>

                <form method="GET" action="index.php" class="grid w-full gap-2 sm:grid-cols-[1fr_190px_auto] md:max-w-2xl">
                    <input type="hidden" name="succursale" value="<?= $succursaleId ?>">
                    <input type="search" name="recherche" value="<?= e_index($recherche) ?>" placeholder="Rechercher un produit..." class="w-full rounded-xl border border-gray-300 bg-white px-4 py-3 text-sm outline-none transition focus:border-green-600 focus:ring-2 focus:ring-green-100">
                    <select name="categorie" class="w-full rounded-xl border border-gray-300 bg-white px-3 py-3 text-sm outline-none focus:border-green-600 focus:ring-2 focus:ring-green-100">
                        <option value="">Toutes les catégories</option>
                        <?php foreach ($categories as $categorie): ?>
                            <option value="<?= (int)$categorie['id'] ?>" <?= $categorieId === (int)$categorie['id'] ? 'selected' : '' ?>>
                                <?= e_index($categorie['nom_categorie']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="rounded-xl bg-green-600 px-5 py-3 text-sm font-bold text-white transition hover:bg-green-700">Rechercher</button>
                </form>
            </div>

            <?php if ($messagePanier !== ''): ?>
                <div class="mb-6 rounded-xl border px-4 py-3 text-sm <?= $typeMessage === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-green-200 bg-green-50 text-green-800' ?>">
                    <?= e_index($messagePanier) ?>
                    <?php if ($typeMessage === 'success'): ?>
                        <a href="panier.php" class="ml-2 font-bold underline">Voir le panier</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (count($produits) > 0): ?>
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    <?php foreach ($produits as $produit): ?>
                        <?php
                            $photo = trim((string)($produit['photo'] ?? ''));
                            $photoExiste = $photo !== '';
                            $stockDisponible = (int)$produit['stock_disponible'];
                        ?>
                        <article class="group overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-xl">
                            <a href="produit.php?id=<?= (int)$produit['id'] ?>" class="relative block aspect-[4/3] overflow-hidden bg-gray-100">
                                <?php if ($photoExiste): ?>
                                    <img src="<?= e_index($photo) ?>" alt="<?= e_index($produit['nom_produit']) ?>" class="h-full w-full object-cover transition duration-500 group-hover:scale-105" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="hidden h-full w-full items-center justify-center text-gray-300"><svg class="h-14 w-14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4.5 7.8 7.5 4.3 7.5-4.3M12 12v9"/></svg></div>
                                <?php else: ?>
                                    <div class="flex h-full w-full items-center justify-center text-gray-300"><svg class="h-16 w-16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4.5 7.8 7.5 4.3 7.5-4.3M12 12v9"/></svg></div>
                                <?php endif; ?>
                                <?php if ($stockDisponible <= 0): ?>
                                    <span class="absolute left-3 top-3 rounded-full bg-red-600 px-3 py-1 text-xs font-bold text-white">Rupture de stock</span>
                                <?php else: ?>
                                    <span class="absolute left-3 top-3 rounded-full bg-green-600 px-3 py-1 text-xs font-bold text-white">Disponible</span>
                                <?php endif; ?>
                            </a>

                            <div class="p-5">
                                <p class="text-xs font-semibold uppercase tracking-wide text-green-700"><?= e_index($produit['nom_categorie'] ?: 'Autre catégorie') ?></p>
                                <a href="produit.php?id=<?= (int)$produit['id'] ?>" class="mt-2 block text-lg font-bold text-gray-900 transition hover:text-green-700">
                                    <?= e_index($produit['nom_produit']) ?>
                                </a>
                                <p class="mt-2 min-h-10 text-sm leading-5 text-gray-500">
                                    <?= e_index(mb_strimwidth(strip_tags((string)($produit['description'] ?? '')), 0, 85, '...')) ?>
                                </p>

                                <div class="mt-4 flex items-center justify-between gap-3">
                                    <div>
                                        <p class="text-xs text-gray-500">Prix de vente</p>
                                        <p class="text-xl font-extrabold text-green-700"><?= number_format((float)$produit['prix_vente'], 0, ',', ' ') ?> <span class="text-sm">$</span></p>
                                    </div>
                                    <?php if ($stockDisponible > 0): ?>
                                        <span class="text-xs text-gray-500"><?= $stockDisponible ?> en stock</span>
                                    <?php endif; ?>
                                </div>

                                <form method="POST" action="index.php<?= $recherche !== '' || $categorieId ? '?' . http_build_query(array_filter(['recherche' => $recherche, 'categorie' => $categorieId])) : '' ?>" class="mt-5">
                                    <input type="hidden" name="csrf_token" value="<?= e_index($_SESSION['csrf_boutique']) ?>">
                                    <input type="hidden" name="produit_id" value="<?= (int)$produit['id'] ?>">
                                    <input type="hidden" name="quantite" value="1">
                                    <button type="submit" name="ajouter_panier" value="1" <?= $stockDisponible <= 0 ? 'disabled' : '' ?> class="flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-bold text-white transition <?= $stockDisponible <= 0 ? 'cursor-not-allowed bg-gray-300' : 'bg-green-600 hover:bg-green-700' ?>">
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l2.2 11.2a2 2 0 0 0 2 1.6h8.9a2 2 0 0 0 2-1.6L21 8H6"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M10 10v6m4-6v6"/>
                                        </svg>
                                        <?= $stockDisponible <= 0 ? 'Indisponible' : 'Ajouter au panier' ?>
                                    </button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-16 text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 4.5 4.5"/></svg>
                    <h3 class="mt-4 text-xl font-bold text-gray-900">Aucun produit trouvé</h3>
                    <p class="mt-2 text-gray-500">Essayez une autre recherche ou choisissez une autre catégorie.</p>
                    <a href="index.php" class="mt-5 inline-flex rounded-xl bg-green-600 px-5 py-3 text-sm font-bold text-white hover:bg-green-700">Afficher tous les produits</a>
                </div>
            <?php endif; ?>
        </section>

        <!-- À propos -->
        <section id="apropos" class="bg-white">
            <div class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
                <div class="rounded-3xl bg-green-50 px-6 py-10 text-center sm:px-12">
                    <h2 class="text-2xl font-extrabold text-gray-900">Achetez en toute simplicité</h2>
                    <p class="mx-auto mt-3 max-w-2xl leading-7 text-gray-600">
                        La consultation de notre catalogue est libre. Ajoutez vos produits au panier,
                        puis connectez-vous ou créez votre compte client lorsque vous serez prêt à commander.
                    </p>
                    <a href="#catalogue" class="mt-6 inline-flex rounded-xl bg-green-600 px-6 py-3 font-bold text-white transition hover:bg-green-700">Voir le catalogue</a>
                </div>
            </div>
        </section>
    </main>

    <footer class="bg-gray-900 text-gray-300">
        <div class="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-8 text-sm sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
            <p>&copy; <?= date('Y') ?> <?= e_index($nomBoutique) ?>. Tous droits réservés.</p>
            <div class="flex gap-5">
                <a href="connexion_client.php" class="transition hover:text-white">Mon compte</a>
                <a href="panier.php" class="transition hover:text-white">Mon panier</a>
                 <a href="dashboard.php" class="transition hover:text-white">dashboard</a>
                 <a href="mes_commandes.php"> Mes commandes</a>
            </div>
        </div>
    </footer>

</body>
</html>
