<?php
require_once __DIR__ . '/config.php';
if (!isset($_SESSION['id_utilisateur'])) { header('Location: login.php'); exit; }

$nomUtilisateur  = $_SESSION['nom'] ?? 'Administrateur';
$roleUtilisateur = $_SESSION['role'] ?? 'Administrateur';
$nomEntreprise   = parametre('nom_entreprise', 'NEXIA');
$devise          = parametre('devise', 'USD');
$userId          = (int)($_SESSION['id_utilisateur'] ?? 0);

$erreur = ''; $message = ''; $edition = null;
$recherche = trim($_GET['q'] ?? '');
$filtreSucc = (int)($_GET['succursale'] ?? 0);
$filtreCat  = trim($_GET['categorie'] ?? '');
$filtreMois = $_GET['mois'] ?? date('Y-m');

$succursales = $pdo->query("SELECT id, nom, code FROM succursales WHERE statut='Active' ORDER BY nom")->fetchAll();

// Catégories prédéfinies
$categories = ['Fournitures', 'Loyer', 'Salaires', 'Transport', 'Électricité', 'Eau', 'Internet',
               'Entretien', 'Publicité', 'Impôts', 'Frais bancaires', 'Autres'];

// ============================================================
// ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'ajouter' || $action === 'modifier') {
        $id            = (int)($_POST['id'] ?? 0);
        $succId        = (int)($_POST['succursale_id'] ?? 0);
        $categorie     = trim($_POST['categorie'] ?? '');
        $libelle       = trim($_POST['libelle'] ?? '');
        $montant       = (float)($_POST['montant'] ?? 0);
        $moyen         = $_POST['moyen_paiement'] ?? 'Espece';
        $reference     = trim($_POST['reference'] ?? '');
        $beneficiaire  = trim($_POST['beneficiaire'] ?? '');
        $dateDepense   = $_POST['date_depense'] ?? date('Y-m-d');
        $note          = trim($_POST['note'] ?? '');

        if ($categorie === '' || $libelle === '' || $montant <= 0) {
            $erreur = "Catégorie, libellé et montant (> 0) sont obligatoires.";
        } elseif (!in_array($moyen, ['Espece','Mobile Money','Carte','Virement','Cheque'], true)) {
            $erreur = "Moyen de paiement invalide.";
        } else {
            try {
                if ($action === 'ajouter') {
                    $pdo->prepare("
                        INSERT INTO depenses (succursale_id, utilisateur_id, categorie, libelle, montant,
                                             moyen_paiement, reference, beneficiaire, date_depense, note)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $succId ?: null, $userId, $categorie, $libelle, $montant,
                        $moyen, $reference ?: null, $beneficiaire ?: null, $dateDepense, $note ?: null
                    ]);
                    $message = "Dépense enregistrée.";
                } else {
                    $pdo->prepare("
                        UPDATE depenses
                        SET succursale_id=?, categorie=?, libelle=?, montant=?, moyen_paiement=?,
                            reference=?, beneficiaire=?, date_depense=?, note=?
                        WHERE id=?
                    ")->execute([
                        $succId ?: null, $categorie, $libelle, $montant,
                        $moyen, $reference ?: null, $beneficiaire ?: null, $dateDepense, $note ?: null, $id
                    ]);
                    $message = "Dépense modifiée.";
                }
            } catch (PDOException $e) {
                $erreur = "Erreur : " . $e->getMessage();
            }
        }
    }

    if ($action === 'supprimer') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM depenses WHERE id = ?")->execute([$id]);
            $message = "Dépense supprimée.";
        }
    }
}

if (isset($_GET['modifier'])) {
    $stmt = $pdo->prepare("SELECT * FROM depenses WHERE id = ?");
    $stmt->execute([(int)$_GET['modifier']]);
    $edition = $stmt->fetch() ?: null;
}

// ============================================================
// LISTE FILTRÉE
// ============================================================
$where = ["1=1"]; $params = [];
if ($recherche !== '') {
    $where[] = "(d.libelle LIKE :q OR d.beneficiaire LIKE :q OR d.reference LIKE :q)";
    $params[':q'] = "%$recherche%";
}
if ($filtreSucc > 0) {
    $where[] = "d.succursale_id = :succ";
    $params[':succ'] = $filtreSucc;
}
if ($filtreCat !== '') {
    $where[] = "d.categorie = :cat";
    $params[':cat'] = $filtreCat;
}
if ($filtreMois !== '') {
    $where[] = "DATE_FORMAT(d.date_depense, '%Y-%m') = :mois";
    $params[':mois'] = $filtreMois;
}

$sql = "
    SELECT d.*, s.nom AS succursale_nom, u.nom AS utilisateur_nom
    FROM depenses d
    LEFT JOIN succursales s ON s.id = d.succursale_id
    LEFT JOIN utilisateurs u ON u.id = d.utilisateur_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY d.date_depense DESC, d.id DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$depenses = $stmt->fetchAll();

// --- Statistiques ---
$totalMois   = (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM depenses WHERE DATE_FORMAT(date_depense,'%Y-%m') = '" . date('Y-m') . "'")->fetchColumn();
$totalGlobal = (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM depenses")->fetchColumn();
$nbDepenses  = (int)$pdo->query("SELECT COUNT(*) FROM depenses")->fetchColumn();

$parCategorie = $pdo->query("
    SELECT categorie, SUM(montant) AS total, COUNT(*) AS nb
    FROM depenses
    GROUP BY categorie
    ORDER BY total DESC
    LIMIT 6
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dépenses | <?= h($nomEntreprise) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">
<div class="min-h-screen md:flex">

    <!-- Aside (identique à caisse.php avec 'depenses.php' actif) -->
    <aside class="w-full bg-green-950 text-white md:fixed md:inset-y-0 md:left-0 md:flex md:w-64 md:flex-col">
        <div class="flex items-center gap-3 border-b border-white/10 px-6 py-5">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-green-500 text-xl font-black"><?= h(strtoupper(substr($nomEntreprise,0,1))) ?></div>
            <div>
                <p class="text-lg font-extrabold tracking-wide"><?= h($nomEntreprise) ?></p>
                <p class="text-xs text-green-200">Administration</p>
            </div>
        </div>
         <?php include __DIR__ . '/_aside.php'; ?>
    </aside>

    <main class="min-w-0 flex-1 md:ml-64">
        <header class="sticky top-0 z-20 flex items-center justify-between border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur sm:px-8">
            <div>
                <p class="text-xs font-medium text-slate-400">Administration / Finance</p>
                <h1 class="mt-1 text-xl font-extrabold text-slate-900 sm:text-2xl">Dépenses</h1>
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

            <!-- Statistiques -->
            <section class="mb-7 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <p class="text-sm text-slate-500">Dépenses du mois</p>
                    <p class="mt-3 text-2xl font-extrabold text-red-600"><?= h(formatMontant($totalMois)) ?></p>
                    <p class="mt-1 text-xs text-slate-400"><?= h(date('F Y')) ?></p>
                </div>
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <p class="text-sm text-slate-500">Total cumulé</p>
                    <p class="mt-3 text-2xl font-extrabold text-slate-900"><?= h(formatMontant($totalGlobal)) ?></p>
                    <p class="mt-1 text-xs text-slate-400">Toutes périodes</p>
                </div>
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <p class="text-sm text-slate-500">Nombre d'enregistrements</p>
                    <p class="mt-3 text-2xl font-extrabold text-slate-900"><?= number_format($nbDepenses, 0, ',', ' ') ?></p>
                    <p class="mt-1 text-xs text-slate-400">Lignes de dépense</p>
                </div>
            </section>

            <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">

                <!-- Formulaire -->
                <div class="h-fit rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div class="mb-4 border-b border-slate-100 pb-4">
                        <h3 class="font-bold text-slate-900"><?= $edition ? 'Modifier la dépense' : 'Nouvelle dépense' ?></h3>
                        <p class="mt-1 text-xs text-slate-400">Enregistrer une sortie d'argent</p>
                    </div>
                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="action" value="<?= $edition ? 'modifier' : 'ajouter' ?>">
                        <?php if ($edition): ?><input type="hidden" name="id" value="<?= (int)$edition['id'] ?>"><?php endif; ?>

                        <div>
                            <label class="mb-1 block text-sm font-semibold">Point de vente</label>
                            <select name="succursale_id" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm">
                                <option value="0">— Siège / Général —</option>
                                <?php foreach ($succursales as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>" <?= (int)($edition['succursale_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
                                        <?= h($s['nom']) ?> (<?= h($s['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold">Catégorie *</label>
                            <select name="categorie" required class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm">
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?= h($c) ?>" <?= ($edition['categorie'] ?? '') === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold">Libellé *</label>
                            <input type="text" name="libelle" required maxlength="255" value="<?= h($edition['libelle'] ?? '') ?>"
                                   placeholder="Ex. : Achat de fournitures"
                                   class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-green-500 outline-none">
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold">Montant (<?= h($devise) ?>) *</label>
                            <input type="number" name="montant" step="0.01" min="0.01" required value="<?= h($edition['montant'] ?? '') ?>"
                                   class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-green-500 outline-none">
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold">Moyen de paiement</label>
                            <select name="moyen_paiement" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm">
                                <?php foreach (['Espece','Mobile Money','Carte','Virement','Cheque'] as $mp): ?>
                                    <option value="<?= $mp ?>" <?= ($edition['moyen_paiement'] ?? 'Espece') === $mp ? 'selected' : '' ?>><?= $mp ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold">Bénéficiaire (facultatif)</label>
                            <input type="text" name="beneficiaire" maxlength="150" value="<?= h($edition['beneficiaire'] ?? '') ?>"
                                   class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm">
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold">Référence (facultatif)</label>
                            <input type="text" name="reference" maxlength="100" value="<?= h($edition['reference'] ?? '') ?>"
                                   class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm">
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold">Date *</label>
                            <input type="date" name="date_depense" required value="<?= h($edition['date_depense'] ?? date('Y-m-d')) ?>"
                                   class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm">
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold">Note (facultatif)</label>
                            <textarea name="note" rows="2" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm"><?= h($edition['note'] ?? '') ?></textarea>
                        </div>

                        <div class="flex flex-col gap-2">
                            <button class="rounded-xl bg-green-700 px-5 py-2.5 text-sm font-bold text-white hover:bg-green-800">
                                <?= $edition ? 'Enregistrer les modifications' : 'Ajouter la dépense' ?>
                            </button>
                            <?php if ($edition): ?>
                                <a href="depenses.php" class="rounded-xl border border-slate-200 px-5 py-2.5 text-center text-sm font-semibold text-slate-600 hover:bg-slate-50">Annuler</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Liste -->
                <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm xl:col-span-2">
                    <div class="border-b border-slate-100 px-5 py-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="font-bold text-slate-900">Liste des dépenses</h3>
                                <p class="mt-1 text-xs text-slate-400"><?= count($depenses) ?> résultat(s)</p>
                            </div>
                            <form method="GET" class="flex flex-wrap gap-2">
                                <input type="text" name="q" value="<?= h($recherche) ?>" placeholder="Rechercher..."
                                       class="rounded-xl border border-slate-200 px-3 py-2 text-xs focus:border-green-500 outline-none">
                                <select name="categorie" onchange="this.form.submit()" class="rounded-xl border border-slate-200 px-3 py-2 text-xs">
                                    <option value="">Toutes catégories</option>
                                    <?php foreach ($categories as $c): ?>
                                        <option value="<?= h($c) ?>" <?= $filtreCat === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="succursale" onchange="this.form.submit()" class="rounded-xl border border-slate-200 px-3 py-2 text-xs">
                                    <option value="0">Tous points de vente</option>
                                    <?php foreach ($succursales as $s): ?>
                                        <option value="<?= (int)$s['id'] ?>" <?= $filtreSucc === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['nom']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="month" name="mois" value="<?= h($filtreMois) ?>" onchange="this.form.submit()"
                                       class="rounded-xl border border-slate-200 px-3 py-2 text-xs">
                                <button class="rounded-xl bg-slate-900 px-3 py-2 text-xs font-bold text-white hover:bg-slate-700">OK</button>
                            </form>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[900px] text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-5 py-3">Date</th>
                                    <th class="px-5 py-3">Catégorie</th>
                                    <th class="px-5 py-3">Libellé</th>
                                    <th class="px-5 py-3">Point de vente</th>
                                    <th class="px-5 py-3">Moyen</th>
                                    <th class="px-5 py-3 text-right">Montant</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                            <?php if (empty($depenses)): ?>
                                <tr><td colspan="7" class="px-5 py-12 text-center text-sm text-slate-400">Aucune dépense enregistrée.</td></tr>
                            <?php else: foreach ($depenses as $d): ?>
                                <tr class="hover:bg-green-50/40">
                                    <td class="px-5 py-3 text-xs text-slate-500"><?= h(date('d/m/Y', strtotime($d['date_depense']))) ?></td>
                                    <td class="px-5 py-3"><span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700"><?= h($d['categorie']) ?></span></td>
                                    <td class="px-5 py-3">
                                        <p class="font-semibold text-slate-800"><?= h($d['libelle']) ?></p>
                                        <?php if ($d['beneficiaire']): ?><p class="text-xs text-slate-400"><?= h($d['beneficiaire']) ?></p><?php endif; ?>
                                    </td>
                                    <td class="px-5 py-3 text-xs"><?= h($d['succursale_nom'] ?? '— Siège —') ?></td>
                                    <td class="px-5 py-3 text-xs text-slate-500"><?= h($d['moyen_paiement']) ?></td>
                                    <td class="px-5 py-3 text-right font-bold text-red-600">−<?= h(formatMontant($d['montant'])) ?></td>
                                    <td class="px-5 py-3">
                                        <div class="flex justify-end gap-2">
                                            <a href="depenses.php?modifier=<?= (int)$d['id'] ?>" class="rounded-lg bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-100">Modifier</a>
                                            <form method="POST" onsubmit="return confirm('Supprimer cette dépense ?')">
                                                <input type="hidden" name="action" value="supprimer">
                                                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                                <button class="rounded-lg bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-100">Suppr.</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Top catégories -->
            <section class="mt-7 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                <h3 class="font-bold text-slate-900 mb-4">Top 6 des catégories</h3>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <?php if (empty($parCategorie)): ?>
                        <p class="text-sm text-slate-400">Aucune donnée.</p>
                    <?php else: foreach ($parCategorie as $pc): ?>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4">
                            <p class="font-semibold text-slate-800 text-sm"><?= h($pc['categorie']) ?></p>
                            <p class="text-lg font-extrabold text-red-600 mt-1"><?= h(formatMontant($pc['total'])) ?></p>
                            <p class="text-xs text-slate-400 mt-1"><?= (int)$pc['nb'] ?> dépense(s)</p>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </section>

        </div>
    </main>
</div>
</body>
</html>