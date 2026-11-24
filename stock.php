<?php
require_once __DIR__ . '/config.php';
if (!isset($_SESSION['id_utilisateur'])) { header('Location: login.php'); exit; }

$nomUtilisateur  = $_SESSION['nom'] ?? 'Administrateur';
$roleUtilisateur = $_SESSION['role'] ?? 'Administrateur';
$accesRestreint = utilisateurEstLimiteAuPointDeVente();
$succursaleUtilisateurId = $accesRestreint ? exigerSuccursaleUtilisateur() : 0;
$succursaleStockId = $accesRestreint && $succursaleUtilisateurId === succursaleCentraleId()
    ? 0
    : $succursaleUtilisateurId;
$nomEntreprise   = parametre('nom_entreprise', 'NEXIA');
$seuilDefaut     = (int)parametre('seuil_alerte_defaut', 5);

$erreur = ''; $message = '';
$recherche = trim($_GET['q'] ?? '');
$filtreSucc = (int)($_GET['succursale'] ?? 0);
if ($accesRestreint) {
    $filtreSucc = $succursaleStockId;
}
$page = max(1, (int)($_GET['page'] ?? 1));
$parPage = 8;
$decalage = ($page - 1) * $parPage;

// --- Mise à jour du seuil d'alerte ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'maj_seuil') {
    $produitId = (int)($_POST['produit_id'] ?? 0);
    $succId    = $accesRestreint ? $succursaleStockId : (int)($_POST['succursale_id'] ?? 0);
    $seuil     = max(0, (int)($_POST['seuil'] ?? $seuilDefaut));
    try {
        if ($succId > 0) {
            $pdo->prepare("UPDATE stocks_succursales SET seuil_alerte = ? WHERE produit_id = ? AND succursale_id = ?")
                ->execute([$seuil, $produitId, $succId]);
        } else {
            $pdo->prepare("UPDATE stocks SET seuil_alerte = ? WHERE produit_id = ?")->execute([$seuil, $produitId]);
        }
        $message = "Seuil d'alerte mis à jour.";
    } catch (PDOException $e) {
        $erreur = "Erreur : " . $e->getMessage();
    }
}

// --- Ajustement manuel du stock (entrée/sortie) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajuster_stock') {
    $produitId = (int)($_POST['produit_id'] ?? 0);
    $succId    = $accesRestreint ? $succursaleStockId : (int)($_POST['succursale_id'] ?? 0);
    $type      = $_POST['type_ajust'] ?? 'Entrée';
    $quantite  = max(1, (int)($_POST['quantite'] ?? 0));
    $motif     = trim($_POST['motif'] ?? '');

    if ($produitId <= 0 || $quantite <= 0) {
        $erreur = "Veuillez sélectionner un produit et une quantité valide.";
    } else {
        try {
            $pdo->beginTransaction();
            $signe = ($type === 'Entrée') ? '+' : '-';

            if ($succId > 0) {
                // Vérifier le stock de la succursale
                $cur = $pdo->prepare("SELECT quantite FROM stocks_succursales WHERE produit_id=? AND succursale_id=? FOR UPDATE");
                $cur->execute([$produitId, $succId]);
                $stock = (int)($cur->fetchColumn() ?: 0);
                if ($type === 'Sortie' && $stock < $quantite) {
                    throw new RuntimeException("Stock insuffisant dans cette succursale (disponible : $stock).");
                }
                $pdo->prepare("
                    INSERT INTO stocks_succursales (succursale_id, produit_id, quantite)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantite = quantite $signe VALUES(quantite), date_maj = NOW()
                ")->execute([$succId, $produitId, $quantite]);
            } else {
                // Stock général
                $cur = $pdo->prepare("SELECT quantite FROM stocks WHERE produit_id=? FOR UPDATE");
                $cur->execute([$produitId]);
                $stock = (int)($cur->fetchColumn() ?: 0);
                if ($type === 'Sortie' && $stock < $quantite) {
                    throw new RuntimeException("Stock général insuffisant (disponible : $stock).");
                }
                $pdo->prepare("
                    INSERT INTO stocks (produit_id, quantite, seuil_alerte)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantite = quantite $signe VALUES(quantite), derniere_modification = NOW()
                ")->execute([$produitId, $quantite, $seuilDefaut]);
            }

            // Mouvement
            $pdo->prepare("
                INSERT INTO mouvements_stock (produit_id, succursale_id, utilisateur_id, type_mouvement, quantite, motif)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$produitId, $succId ?: null, $_SESSION['id_utilisateur'] ?? null,
                         $type === 'Entrée' ? 'Entree' : 'Ajustement', $quantite, $motif]);

            $pdo->commit();
            $message = "Stock ajusté : $signe$quantite unité(s).";
        } catch (Throwable $e) {
            $pdo->rollBack();
            $erreur = $e->getMessage();
        }
    }
}

// --- Liste des succursales pour le filtre ---
if ($accesRestreint) {
    $stmtSuccursales = $pdo->prepare("SELECT id, nom, code, type FROM succursales WHERE id = ? AND statut = 'Active'");
    $stmtSuccursales->execute([$succursaleUtilisateurId]);
    $succursales = $stmtSuccursales->fetchAll();
} else {
    $succursales = $pdo->query("SELECT id, nom, code, type FROM succursales WHERE statut='Active' ORDER BY type='CENTRALE' DESC, nom")->fetchAll();
}

// --- Construction de la requête stock ---
$where = ["1=1"]; $params = [];
if ($recherche !== '') {
    $where[] = "p.nom_produit LIKE :q";
    $params[':q'] = "%$recherche%";
}

if ($filtreSucc > 0) {
    $sqlBase = "
        FROM produits p
        LEFT JOIN stocks_succursales ss ON ss.produit_id = p.id AND ss.succursale_id = ?
        WHERE " . implode(' AND ', $where);

    $sqlCount = "SELECT COUNT(*) " . $sqlBase;
    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->execute(array_merge([$filtreSucc], $params));

    $sql = "
        SELECT p.id AS produit_id, p.nom_produit,
               COALESCE(ss.quantite, 0) AS quantite,
               COALESCE(ss.seuil_alerte, $seuilDefaut) AS seuil_alerte,
               ? AS succursale_id
        $sqlBase
        ORDER BY p.nom_produit
        LIMIT $parPage OFFSET $decalage
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$filtreSucc, $filtreSucc], $params));
} else {
    $sqlBase = "
        FROM produits p
        LEFT JOIN stocks s ON s.produit_id = p.id
        WHERE " . implode(' AND ', $where);

    $sqlCount = "SELECT COUNT(*) " . $sqlBase;
    $stmtCount = $pdo->prepare($sqlCount);
    $stmtCount->execute($params);

    $sql = "
        SELECT p.id AS produit_id, p.nom_produit,
               COALESCE(s.quantite, 0) AS quantite,
               COALESCE(s.seuil_alerte, $seuilDefaut) AS seuil_alerte,
               0 AS succursale_id
        $sqlBase
        ORDER BY p.nom_produit
        LIMIT $parPage OFFSET $decalage
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}
$totalProduits = (int)$stmtCount->fetchColumn();
$nombrePages = max(1, (int)ceil($totalProduits / $parPage));
if ($page > $nombrePages) {
    $page = $nombrePages;
    $decalage = ($page - 1) * $parPage;
    $sql = preg_replace('/OFFSET \d+$/', 'OFFSET ' . $decalage, $sql);
    $stmt = $pdo->prepare($sql);
    $executeParams = $filtreSucc > 0 ? array_merge([$filtreSucc, $filtreSucc], $params) : $params;
    $stmt->execute($executeParams);
}
$lignes = $stmt->fetchAll();

// --- Répartition par succursale pour chaque produit (vue générale) ---
$repartition = [];
if ($filtreSucc === 0 && !$accesRestreint && $lignes) {
    $ids = array_column($lignes, 'produit_id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmtR = $pdo->prepare("
        SELECT ss.produit_id, s.nom AS succursale_nom, s.code, ss.quantite
        FROM stocks_succursales ss
        INNER JOIN succursales s ON s.id = ss.succursale_id
        WHERE ss.produit_id IN ($in) AND ss.quantite > 0
        ORDER BY s.nom
    ");
    $stmtR->execute($ids);
    foreach ($stmtR->fetchAll() as $r) {
        $repartition[$r['produit_id']][] = $r;
    }
}

// --- Totaux ---
$totalUnites = array_sum(array_column($lignes, 'quantite'));
$alertes     = count(array_filter($lignes, fn($l) => $l['quantite'] <= $l['seuil_alerte']));
$nomVue      = 'Stock général (centrale)';
if ($filtreSucc > 0) {
    foreach ($succursales as $s) {
        if ((int)$s['id'] === $filtreSucc) { $nomVue = 'Stock · ' . $s['nom']; break; }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <link rel="stylesheet" href="scrollbar.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion de stock | <?= h($nomEntreprise) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">
<div class="min-h-screen md:flex">

    <?php include __DIR__ . '/_aside.php'; ?>

    <main class="min-w-0 flex-1 md:ml-64">

        <!-- En-tête -->
        <header class="sticky top-0 z-20 flex items-center justify-between border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur sm:px-8">
            <div>
                <p class="text-xs font-medium text-slate-400">Administration / Stock</p>
                <h1 class="mt-1 text-xl font-extrabold text-slate-900 sm:text-2xl">Gestion de stock</h1>
            </div>
            <div class="flex items-center gap-3">
                <div class="hidden text-right sm:block">
                    <p class="text-sm font-bold text-slate-800"><?= h($nomUtilisateur) ?></p>
                    <p class="text-xs text-green-600"><?= h($roleUtilisateur) ?></p>
                </div>
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-green-100 font-bold text-green-700"><?= h(strtoupper(substr($nomUtilisateur,0,1))) ?></div>
            </div>
        </header>

        <div class="p-5 sm:p-8">

            <?php if ($erreur !== ''): ?>
                <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= h($erreur) ?></div>
            <?php endif; ?>
            <?php if ($message !== ''): ?>
                <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?= h($message) ?></div>
            <?php endif; ?>

            <!-- Cartes résumé -->
            <section class="mb-7 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-500">Unités en stock</span>
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-green-100 text-xl text-green-700">▣</span>
                    </div>
                    <p class="mt-4 text-3xl font-extrabold text-slate-900"><?= number_format($totalUnites, 0, ',', ' ') ?></p>
                    <p class="mt-1 text-xs text-slate-400"><?= h($nomVue) ?></p>
                </div>
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-500">Alertes</span>
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-100 text-xl text-amber-700">⚠</span>
                    </div>
                    <p class="mt-4 text-3xl font-extrabold text-amber-600"><?= $alertes ?></p>
                    <p class="mt-1 text-xs text-slate-400">Produits sous le seuil</p>
                </div>
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-500">Produits listés</span>
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-100 text-xl text-blue-700">□</span>
                    </div>
                    <p class="mt-4 text-3xl font-extrabold text-slate-900"><?= $totalProduits ?></p>
                    <p class="mt-1 text-xs text-slate-400"><?= $recherche ? 'Filtré par "' . h($recherche) . '"' : 'Tous les produits' ?></p>
                </div>
            </section>

            <!-- Barre de filtre -->
            <section class="mb-6 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                <form method="GET" class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                    <div class="sm:col-span-2">
                        <label class="mb-1 block text-xs font-semibold text-slate-500">Recherche</label>
                        <input type="text" name="q" value="<?= h($recherche) ?>" placeholder="Nom du produit..."
                               class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-green-500 focus:ring-2 focus:ring-green-100 outline-none">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-500">Vue</label>
                        <select name="succursale" onchange="this.form.submit()" <?= $accesRestreint ? 'disabled' : '' ?> class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm">
                            <?php if (!$accesRestreint || $succursaleStockId === 0): ?>
                                <option value="0" <?= $filtreSucc === 0 ? 'selected' : '' ?>>Stock général (centrale)</option>
                            <?php endif; ?>
                            <?php foreach ($succursales as $s): ?>
                                <option value="<?= (int)$s['id'] ?>" <?= $filtreSucc === (int)$s['id'] ? 'selected' : '' ?>>
                                    <?= h($s['nom']) ?> (<?= h($s['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <button class="flex-1 rounded-xl bg-green-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-green-800">Filtrer</button>
                        <a href="stock.php" class="rounded-xl bg-slate-100 px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-200">Reset</a>
                    </div>
                </form>
            </section>

            <!-- Tableau stock -->
            <section class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <div>
                        <h3 class="font-bold text-slate-900"><?= h($nomVue) ?></h3>
                        <p class="mt-1 text-xs text-slate-400">Ajustez les quantités et les seuils d'alerte</p>
                    </div>
                    <span class="rounded-lg bg-green-50 px-3 py-1.5 text-xs font-semibold text-green-700"><?= $totalProduits ?> produit(s)</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Produit</th>
                                <th class="px-5 py-3 font-semibold">Quantité</th>
                                <th class="px-5 py-3 font-semibold">Seuil</th>
                                <th class="px-5 py-3 font-semibold">État</th>
                                <?php if ($filtreSucc === 0 && !$accesRestreint): ?><th class="px-5 py-3 font-semibold">Répartition</th><?php endif; ?>
                                <th class="px-5 py-3 text-right font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                        <?php if (empty($lignes)): ?>
                            <tr>
                                <td colspan="<?= $filtreSucc === 0 && !$accesRestreint ? 6 : 5 ?>" class="px-5 py-12 text-center">
                                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-50 text-xl text-green-700">▣</div>
                                    <p class="mt-3 text-sm font-semibold text-slate-700">Aucun produit trouvé</p>
                                    <p class="mt-1 text-xs text-slate-400">Modifiez votre recherche ou changez de vue.</p>
                                </td>
                            </tr>
                        <?php else: foreach ($lignes as $l): ?>
                            <tr class="transition hover:bg-green-50/40">
                                <td class="px-5 py-4">
                                    <div>
                                        <p class="font-semibold text-slate-800"><?= h($l['nom_produit']) ?></p>
                                    </div>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="rounded-lg px-3 py-1.5 text-xs font-bold <?= $l['quantite'] <= $l['seuil_alerte'] ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-700' ?>">
                                        <?= (int)$l['quantite'] ?>
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <form method="POST" class="flex items-center gap-1">
                                        <input type="hidden" name="action" value="maj_seuil">
                                        <input type="hidden" name="produit_id" value="<?= (int)$l['produit_id'] ?>">
                                        <input type="hidden" name="succursale_id" value="<?= (int)$l['succursale_id'] ?>">
                                        <input type="number" name="seuil" value="<?= (int)$l['seuil_alerte'] ?>" min="0"
                                               class="w-16 rounded-lg border border-slate-200 px-2 py-1.5 text-xs">
                                        <button class="rounded-lg bg-green-50 px-2 py-1.5 text-xs font-bold text-green-700 hover:bg-green-100" title="Enregistrer le seuil">✓</button>
                                    </form>
                                </td>
                                <td class="px-5 py-4">
                                    <?php if ($l['quantite'] <= $l['seuil_alerte']): ?>
                                        <span class="rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700">⚠ Alerte</span>
                                    <?php else: ?>
                                        <span class="rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">OK</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($filtreSucc === 0 && !$accesRestreint): ?>
                                    <td class="px-5 py-4">
                                        <?php if (!empty($repartition[$l['produit_id']])): ?>
                                            <div class="flex flex-wrap gap-1">
                                                <?php foreach ($repartition[$l['produit_id']] as $r): ?>
                                                    <span class="rounded-md bg-blue-50 px-2 py-1 text-[10px] font-semibold text-blue-700" title="<?= h($r['succursale_nom']) ?>">
                                                        <?= h($r['code']) ?> · <?= (int)$r['quantite'] ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td class="px-5 py-4 text-right">
                                    <button type="button"
                                            onclick="ouvrirAjustement(<?= (int)$l['produit_id'] ?>, '<?= h(addslashes($l['nom_produit'])) ?>', <?= (int)$l['succursale_id'] ?>)"
                                            class="rounded-lg bg-green-700 px-3 py-2 text-xs font-semibold text-white hover:bg-green-800">
                                        Ajuster
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($nombrePages > 1): ?>
                    <?php
                    $paramsPage = ['q' => $recherche, 'succursale' => $filtreSucc];
                    $basePage = 'stock.php?' . http_build_query(array_filter($paramsPage, fn($v) => $v !== '' && $v !== 0));
                    ?>
                    <div class="flex items-center justify-between border-t border-slate-100 px-5 py-4">
                        <p class="text-xs text-slate-500">
                            Page <?= $page ?> sur <?= $nombrePages ?> · <?= $totalProduits ?> produit(s)
                        </p>
                        <div class="flex gap-2">
                            <?php if ($page > 1): ?>
                                <a href="<?= h($basePage . '&page=' . ($page - 1)) ?>" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50">Précédent</a>
                            <?php endif; ?>
                            <?php if ($page < $nombrePages): ?>
                                <a href="<?= h($basePage . '&page=' . ($page + 1)) ?>" class="rounded-lg bg-green-700 px-3 py-2 text-xs font-semibold text-white hover:bg-green-800">Suivant</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>

<!-- Modal ajustement de stock -->
<div id="modalAjust" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/60 p-4">
    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-slate-900">Ajuster le stock</h3>
            <button onclick="fermerAjust()" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100">✕</button>
        </div>
        <p class="mb-4 text-sm text-slate-500">Produit : <span id="nomProduitAjust" class="font-semibold text-slate-800"></span></p>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="ajuster_stock">
            <input type="hidden" name="produit_id" id="ajustProduitId">
            <input type="hidden" name="succursale_id" id="ajustSuccId">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="mb-1 block text-sm font-semibold">Type</label>
                    <select name="type_ajust" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                        <option value="Entrée">Entrée (+)</option>
                        <option value="Sortie">Sortie (−)</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold">Quantité</label>
                    <input type="number" name="quantite" min="1" required value="1"
                           class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
                </div>
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold">Motif (facultatif)</label>
                <input type="text" name="motif" placeholder="Ex. : inventaire, casse, réapprovisionnement"
                       class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm">
            </div>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="fermerAjust()" class="flex-1 rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50">Annuler</button>
                <button type="submit" class="flex-1 rounded-xl bg-green-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-green-800">Valider</button>
            </div>
        </form>
    </div>
</div>

<script>
function ouvrirAjustement(produitId, nom, succId) {
    document.getElementById('ajustProduitId').value = produitId;
    document.getElementById('ajustSuccId').value = succId;
    document.getElementById('nomProduitAjust').textContent = nom;
    const modal = document.getElementById('modalAjust');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function fermerAjust() {
    const modal = document.getElementById('modalAjust');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
</script>
</body>
</html>