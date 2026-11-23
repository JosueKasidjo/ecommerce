<?php
require_once __DIR__ . '/config.php';
if (!isset($_SESSION['id_utilisateur'])) { header('Location: login.php'); exit; }

$nomUtilisateur  = $_SESSION['nom'] ?? 'Administrateur';
$roleUtilisateur = $_SESSION['role'] ?? 'Administrateur';
$message = ''; $erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'transferer') {
    $produitId = (int)($_POST['produit_id'] ?? 0);
    $succId    = (int)($_POST['succursale_id'] ?? 0);
    $quantite  = (int)($_POST['quantite'] ?? 0);
    $userId    = (int)($_SESSION['id_utilisateur'] ?? 0);

    if ($produitId <= 0 || $succId <= 0 || $quantite <= 0) {
        $erreur = 'Veuillez sélectionner un produit, une succursale et une quantité valide.';
    } else {
        try {
            $pdo->beginTransaction();

            // Vérifier le stock général
            $st = $pdo->prepare("SELECT quantite FROM stocks WHERE produit_id = ? FOR UPDATE");
            $st->execute([$produitId]);
            $stockGeneral = (int)$st->fetchColumn();

            if ($stockGeneral < $quantite) {
                throw new RuntimeException("Stock général insuffisant (disponible : $stockGeneral).");
            }

            // Décrémenter stock général
            $pdo->prepare("UPDATE stocks SET quantite = quantite - ? WHERE produit_id = ?")->execute([$quantite, $produitId]);

            // Ajouter au stock de la succursale
            $pdo->prepare("
                INSERT INTO stocks_succursales (succursale_id, produit_id, quantite)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE quantite = quantite + VALUES(quantite), date_maj = NOW()
            ")->execute([$succId, $produitId, $quantite]);

            // Enregistrer le transfert
            $numero = numeroDocument('TRF');
            $pdo->prepare("INSERT INTO transferts_stock (numero_transfert, succursale_dest_id, produit_id, quantite, utilisateur_id) VALUES (?,?,?,?,?)")
                ->execute([$numero, $succId, $produitId, $quantite, $userId]);

            $pdo->commit();
            $message = "Transfert $numero enregistré : $quantite unité(s) affectée(s).";
        } catch (Throwable $e) {
            $pdo->rollBack();
            $erreur = $e->getMessage();
        }
    }
}

$produits    = $pdo->query("SELECT p.id, p.nom_produit, COALESCE(s.quantite,0) AS qte FROM produits p LEFT JOIN stocks s ON s.produit_id=p.id ORDER BY p.nom_produit")->fetchAll();
$succursales = $pdo->query("SELECT id, nom, code FROM succursales WHERE statut='Active' AND type != 'CENTRALE' ORDER BY nom")->fetchAll();
$transferts  = $pdo->query("
    SELECT t.*, p.nom_produit, s.nom AS succursale_nom
    FROM transferts_stock t
    INNER JOIN produits p ON p.id = t.produit_id
    INNER JOIN succursales s ON s.id = t.succursale_dest_id
    ORDER BY t.date_transfert DESC LIMIT 50
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <link rel="stylesheet" href="scrollbar.css">
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Transferts de stock | NEXIA</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">
<div class="min-h-screen md:flex">
    <?php $pageCourante = 'transferts_stock.php'; include __DIR__ . '/_aside.php'; ?>
    <main class="min-w-0 flex-1 md:ml-64">
        <header class="sticky top-0 z-20 flex items-center justify-between border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur sm:px-8">
            <div>
                <p class="text-xs font-medium text-slate-400">Administration / Stock</p>
                <h1 class="mt-1 text-xl font-extrabold text-slate-900 sm:text-2xl">Transferts & Affectation</h1>
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
            <?php if ($erreur): ?><div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= h($erreur) ?></div><?php endif; ?>
            <?php if ($message): ?><div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?= h($message) ?></div><?php endif; ?>

            <section class="mb-7 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                <h2 class="font-bold text-slate-900 mb-4">Affecter du stock général vers une succursale</h2>
                <form method="POST" class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <input type="hidden" name="action" value="transferer">
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-sm font-semibold">Produit</label>
                        <select name="produit_id" required class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm">
                            <option value="">— Choisir —</option>
                            <?php foreach ($produits as $p): ?>
                                <option value="<?= (int)$p['id'] ?>"><?= h($p['nom_produit']) ?> (stock général : <?= (int)$p['qte'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold">Succursale destinataire</label>
                        <select name="succursale_id" required class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm">
                            <option value="">— Choisir —</option>
                            <?php foreach ($succursales as $s): ?>
                                <option value="<?= (int)$s['id'] ?>"><?= h($s['nom']) ?> (<?= h($s['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold">Quantité</label>
                        <input type="number" name="quantite" min="1" required class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm">
                    </div>
                    <div class="md:col-span-4 flex justify-end">
                        <button class="rounded-xl bg-green-700 px-6 py-3 text-sm font-bold text-white hover:bg-green-800">Affecter le stock</button>
                    </div>
                </form>
            </section>

            <section class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-4 flex items-center justify-between">
                    <div>
                        <h3 class="font-bold text-slate-900">Historique des transferts</h3>
                        <p class="mt-1 text-xs text-slate-400">50 derniers mouvements</p>
                    </div>
                    <span class="rounded-lg bg-green-50 px-3 py-1.5 text-xs font-semibold text-green-700"><?= count($transferts) ?> transfert(s)</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[800px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-3">Référence</th>
                                <th class="px-5 py-3">Produit</th>
                                <th class="px-5 py-3">Destination</th>
                                <th class="px-5 py-3">Quantité</th>
                                <th class="px-5 py-3">Date</th>
                                <th class="px-5 py-3">Statut</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                        <?php if (empty($transferts)): ?>
                            <tr><td colspan="6" class="px-5 py-12 text-center text-slate-400">Aucun transfert enregistré.</td></tr>
                        <?php else: foreach ($transferts as $t): ?>
                            <tr class="hover:bg-green-50/40">
                                <td class="px-5 py-4"><p class="font-bold text-green-700 text-xs"><?= h($t['numero_transfert']) ?></p></td>
                                <td class="px-5 py-4 font-semibold"><?= h($t['nom_produit']) ?></td>
                                <td class="px-5 py-4 text-slate-600"><?= h($t['succursale_nom']) ?></td>
                                <td class="px-5 py-4"><span class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-bold">+<?= (int)$t['quantite'] ?></span></td>
                                <td class="px-5 py-4 text-xs text-slate-500"><?= h(date('d/m/Y H:i', strtotime($t['date_transfert']))) ?></td>
                                <td class="px-5 py-4">
                                    <?php if ($t['statut'] === 'Valide'): ?>
                                        <span class="rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">Validé</span>
                                    <?php elseif ($t['statut'] === 'En attente'): ?>
                                        <span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">En attente</span>
                                    <?php else: ?>
                                        <span class="rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700">Annulé</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>