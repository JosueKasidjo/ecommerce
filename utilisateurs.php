<?php
require_once __DIR__ . '/config.php';
if (!isset($_SESSION['id_utilisateur'])) { header('Location: login.php'); exit; }
exigerAccesAdministration();

$nomUtilisateur  = $_SESSION['nom'] ?? 'Administrateur';
$roleUtilisateur = $_SESSION['role'] ?? 'Administrateur';
$nomEntreprise   = parametre('nom_entreprise', 'NEXIA');

$erreur = ''; $message = ''; $edition = null;
$recherche = trim($_GET['q'] ?? '');
$filtreSucc = (int)($_GET['succursale'] ?? 0);

// --- Liste des succursales ---
$succursales = $pdo->query("
    SELECT id, nom, code, type
    FROM succursales
    WHERE statut = 'Active'
    ORDER BY type = 'CENTRALE' DESC, nom
")->fetchAll();

// ============================================================
// TRAITEMENT DES FORMULAIRES
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Ajouter / Modifier ---
    if ($action === 'ajouter' || $action === 'modifier') {
        $id           = (int)($_POST['id'] ?? 0);
        $nom          = trim($_POST['nom'] ?? '');
        $email        = strtolower(trim($_POST['email'] ?? ''));
        $motDePasse   = $_POST['mot_de_passe'] ?? '';
        $role         = $_POST['role'] ?? 'Vendeur';
        $succursaleId = (int)($_POST['succursale_id'] ?? 0);
        $statut       = $_POST['statut'] ?? 'Actif';

        $rolesValides = ['Administrateur', 'Gestionnaire', 'Caissier', 'Vendeur'];

        if ($nom === '') {
            $erreur = "Le nom est obligatoire.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreur = "Adresse e-mail invalide.";
        } elseif (!in_array($role, $rolesValides, true)) {
            $erreur = "Rôle invalide.";
        } elseif ($succursaleId <= 0) {
            $erreur = "Veuillez sélectionner un point de vente.";
        } elseif ($action === 'ajouter' && strlen($motDePasse) < 6) {
            $erreur = "Le mot de passe doit contenir au moins 6 caractères.";
        } else {
            try {
                // Vérifier l'unicité de l'e-mail
                $stmtCheck = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ? AND id != ? LIMIT 1");
                $stmtCheck->execute([$email, $id]);
                if ($stmtCheck->fetch()) {
                    throw new RuntimeException("Cet e-mail est déjà utilisé par un autre utilisateur.");
                }

                if ($action === 'ajouter') {
                    $hash = password_hash($motDePasse, PASSWORD_DEFAULT);
                    $pdo->prepare("
                        INSERT INTO utilisateurs (nom, email, mot_de_passe, role, succursale_id, statut, date_creation)
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ")->execute([$nom, $email, $hash, $role, $succursaleId, $statut]);
                    $message = "Utilisateur ajouté avec succès.";
                } else {
                    if ($id <= 0) throw new RuntimeException("Utilisateur invalide.");
                    if ($motDePasse !== '') {
                        // Changement de mot de passe
                        if (strlen($motDePasse) < 6) throw new RuntimeException("Le mot de passe doit contenir au moins 6 caractères.");
                        $hash = password_hash($motDePasse, PASSWORD_DEFAULT);
                        $pdo->prepare("
                            UPDATE utilisateurs
                            SET nom=?, email=?, mot_de_passe=?, role=?, succursale_id=?, statut=?
                            WHERE id=?
                        ")->execute([$nom, $email, $hash, $role, $succursaleId, $statut, $id]);
                    } else {
                        $pdo->prepare("
                            UPDATE utilisateurs
                            SET nom=?, email=?, role=?, succursale_id=?, statut=?
                            WHERE id=?
                        ")->execute([$nom, $email, $role, $succursaleId, $statut, $id]);
                    }
                    $message = "Utilisateur modifié avec succès.";
                }
            } catch (PDOException $e) {
                $erreur = ($e->getCode() === '23000') ? "Cet e-mail est déjà utilisé." : "Erreur : " . $e->getMessage();
            } catch (RuntimeException $e) {
                $erreur = $e->getMessage();
            }
        }
    }

    // --- Changer statut ---
    elseif ($action === 'changer_statut') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE utilisateurs SET statut = IF(statut='Actif','Inactif','Actif') WHERE id = ?")
                ->execute([$id]);
            $message = "Statut de l'utilisateur mis à jour.";
        }
    }

    // --- Supprimer ---
    elseif ($action === 'supprimer') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$_SESSION['id_utilisateur']) {
            $erreur = "Vous ne pouvez pas supprimer votre propre compte.";
        } elseif ($id > 0) {
            $pdo->prepare("DELETE FROM utilisateurs WHERE id = ?")->execute([$id]);
            $message = "Utilisateur supprimé.";
        }
    }
}

// ============================================================
// ÉDITION
// ============================================================
if (isset($_GET['modifier'])) {
    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id = ?");
    $stmt->execute([(int)$_GET['modifier']]);
    $edition = $stmt->fetch() ?: null;
}

// ============================================================
// LISTE FILTRÉE
// ============================================================
$where = ["1=1"]; $params = [];
if ($recherche !== '') {
    $where[] = "(u.nom LIKE :q OR u.email LIKE :q)";
    $params[':q'] = "%$recherche%";
}
if ($filtreSucc > 0) {
    $where[] = "u.succursale_id = :succ";
    $params[':succ'] = $filtreSucc;
}

$sql = "
    SELECT u.*, u.role AS utilisateur_role, s.nom AS succursale_nom, s.code AS succursale_code
    FROM utilisateurs u
    LEFT JOIN succursales s ON s.id = u.succursale_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY u.date_creation DESC, u.id DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$utilisateurs = $stmt->fetchAll();

// --- Statistiques ---
$totalUsers   = (int)$pdo->query("SELECT COUNT(*) FROM utilisateurs")->fetchColumn();
$totalActifs  = (int)$pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE statut = 'Actif'")->fetchColumn();
$parSucc = $pdo->query("
    SELECT s.nom, s.code, COUNT(u.id) AS nb
    FROM succursales s
    LEFT JOIN utilisateurs u ON u.succursale_id = s.id
    GROUP BY s.id
    ORDER BY nb DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <link rel="stylesheet" href="scrollbar.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Utilisateurs | <?= h($nomEntreprise) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">
<div class="min-h-screen md:flex">

    <?php include __DIR__ . '/_aside.php'; ?>

    <main class="min-w-0 flex-1 md:ml-64">

        <!-- En-tête -->
        <header class="sticky top-0 z-20 flex items-center justify-between border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur sm:px-8">
            <div>
                <p class="text-xs font-medium text-slate-400">Administration / Réseau</p>
                <h1 class="mt-1 text-xl font-extrabold text-slate-900 sm:text-2xl">Utilisateurs & Personnel</h1>
            </div>
            <div class="flex items-center gap-3">
                <div class="min-w-0 text-right">
                    <p class="hidden text-sm font-bold text-slate-800 sm:block"><?= h($nomUtilisateur) ?></p>
                    <p class="max-w-[35vw] truncate text-xs text-green-600 sm:max-w-none"><?= h($roleUtilisateur) ?></p>
                </div>
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-green-100 font-bold text-green-700"><?= h(strtoupper(substr($nomUtilisateur,0,1))) ?></div>
            </div>
        </header>

        <div class="p-5 sm:p-8">

            <?php if ($erreur): ?>
                <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= h($erreur) ?></div>
            <?php endif; ?>
            <?php if ($message): ?>
                <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?= h($message) ?></div>
            <?php endif; ?>

            <!-- Statistiques -->
            <section class="mb-7 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-500">Total utilisateurs</span>
                       
                    </div>
                    <p class="mt-4 text-3xl font-extrabold text-slate-900"><?= number_format($totalUsers, 0, ',', ' ') ?></p>
                    <p class="mt-1 text-xs text-slate-400">Comptes enregistrés</p>
                </div>
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-500">Comptes actifs</span>
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-100 text-xl text-emerald-700">✓</span>
                    </div>
                    <p class="mt-4 text-3xl font-extrabold text-green-700"><?= number_format($totalActifs, 0, ',', ' ') ?></p>
                    <p class="mt-1 text-xs text-slate-400">Peuvent se connecter</p>
                </div>
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-500">Points de vente</span>
                        
                    </div>
                    <p class="mt-4 text-3xl font-extrabold text-slate-900"><?= count($succursales) ?></p>
                    <p class="mt-1 text-xs text-slate-400">Succursales actives</p>
                </div>
            </section>

            <!-- Formulaire + Liste -->
            <section class="grid grid-cols-1 gap-6 xl:grid-cols-3">

                <!-- Formulaire -->
                <div class="h-fit rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div class="mb-5 border-b border-slate-100 pb-4">
                        <h3 class="font-bold text-slate-900"><?= $edition ? 'Modifier un utilisateur' : 'Ajouter un utilisateur' ?></h3>
                        <p class="mt-1 text-xs text-slate-400">Le personnel est rattaché à un point de vente</p>
                    </div>

                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="action" value="<?= $edition ? 'modifier' : 'ajouter' ?>">
                        <?php if ($edition): ?><input type="hidden" name="id" value="<?= (int)$edition['id'] ?>"><?php endif; ?>

                        <div>
                            <label class="mb-1 block text-sm font-semibold">Nom complet *</label>
                            <input type="text" name="nom" required maxlength="150" value="<?= h($edition['nom'] ?? '') ?>"
                                   placeholder="Ex. : Jean Kabila"
                                   class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-green-500 focus:ring-2 focus:ring-green-100 outline-none">
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold">Adresse e-mail *</label>
                            <input type="email" name="email" required maxlength="150" value="<?= h($edition['email'] ?? '') ?>"
                                   placeholder="utilisateur@nexia.cd"
                                   class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-green-500 focus:ring-2 focus:ring-green-100 outline-none">
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold">
                                Mot de passe <?= $edition ? '(laisser vide pour ne pas changer)' : '*' ?>
                            </label>
                            <input type="password" name="mot_de_passe" <?= $edition ? '' : 'required' ?> minlength="6"
                                   placeholder="••••••••"
                                   class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-green-500 focus:ring-2 focus:ring-green-100 outline-none">
                            <p class="mt-1 text-xs text-slate-400">6 caractères minimum</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold">Point de vente *</label>
                            <select name="succursale_id" required
                                    class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-green-500 outline-none">
                                <option value="">— Sélectionner —</option>
                                <?php foreach ($succursales as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>" <?= (int)($edition['succursale_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
                                        <?= h($s['nom']) ?> (<?= h($s['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold">Rôle *</label>
                            <select name="role" required
                                    class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-green-500 outline-none">
                                <?php foreach (['Administrateur','Gestionnaire','Caissier','Vendeur'] as $r): ?>
                                    <option value="<?= $r ?>" <?= ($edition['role'] ?? '') === $r ? 'selected' : '' ?>><?= $r ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold">Statut</label>
                            <select name="statut"
                                    class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm">
                                <option value="Actif" <?= ($edition['statut'] ?? 'Actif') === 'Actif' ? 'selected' : '' ?>>Active</option>
                                <option value="Inactif" <?= ($edition['statut'] ?? '') === 'Inactif' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>

                        <div class="flex flex-col gap-2 pt-2">
                            <button class="rounded-xl bg-green-700 px-5 py-2.5 text-sm font-bold text-white hover:bg-green-800">
                                <?= $edition ? 'Enregistrer les modifications' : 'Ajouter l\'utilisateur' ?>
                            </button>
                            <?php if ($edition): ?>
                                <a href="utilisateurs.php" class="rounded-xl border border-slate-200 px-5 py-2.5 text-center text-sm font-semibold text-slate-600 hover:bg-slate-50">Annuler la modification</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Liste -->
                <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm xl:col-span-2">

                    <div class="border-b border-slate-100 px-5 py-4">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h3 class="font-bold text-slate-900">Liste du personnel</h3>
                                <p class="mt-1 text-xs text-slate-400"><?= count($utilisateurs) ?> utilisateur(s) trouvé(s)</p>
                            </div>
                            <form method="GET" class="grid w-full grid-cols-1 gap-2 sm:w-auto sm:grid-cols-[minmax(12rem,1fr)_auto_auto]">
                                <input type="search" name="q" value="<?= h($recherche) ?>" placeholder="Nom ou e-mail..."
                                       class="min-w-0 rounded-xl border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                                <select name="succursale" onchange="this.form.submit()"
                                        class="min-w-0 rounded-xl border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-green-500 focus:ring-2 focus:ring-green-100">
                                    <option value="0">Toutes succursales</option>
                                    <?php foreach ($succursales as $s): ?>
                                        <option value="<?= (int)$s['id'] ?>" <?= $filtreSucc === (int)$s['id'] ? 'selected' : '' ?>>
                                            <?= h($s['nom']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="rounded-xl bg-green-700 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-green-800">Chercher</button>
                            </form>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[800px] text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-5 py-3 font-semibold">Utilisateur</th>
                                    <th class="px-5 py-3 font-semibold">Point de vente</th>
                                    <th class="px-5 py-3 font-semibold">Rôle</th>
                                    <th class="px-5 py-3 font-semibold">Statut</th>
                                    <th class="px-5 py-3 font-semibold">Date</th>
                                    <th class="px-5 py-3 text-right font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                            <?php if (empty($utilisateurs)): ?>
                                <tr>
                                    <td colspan="6" class="px-5 py-12 text-center">
                                      
                                        <p class="mt-3 text-sm font-semibold text-slate-700">Aucun utilisateur trouvé</p>
                                        <p class="mt-1 text-xs text-slate-400">Utilisez le formulaire pour ajouter du personnel.</p>
                                    </td>
                                </tr>
                            <?php else: foreach ($utilisateurs as $u): ?>
                                <tr class="hover:bg-green-50/40">
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-green-100 text-sm font-bold text-green-700">
                                                <?= h(strtoupper(substr($u['nom'], 0, 1))) ?>
                                            </div>
                                            <div>
                                                <p class="font-semibold text-slate-800"><?= h($u['nom']) ?></p>
                                                <p class="text-xs text-slate-400"><?= h($u['email']) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <?php if ($u['succursale_nom']): ?>
                                            <p class="font-semibold text-slate-700"><?= h($u['succursale_nom']) ?></p>
                                            <p class="text-xs text-slate-400"><?= h($u['succursale_code']) ?></p>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400">— Non affecté —</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="whitespace-nowrap rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700"><?= h(trim((string)($u['utilisateur_role'] ?? '')) ?: 'Non défini') ?></span>
                                    </td>
                                    <td class="px-5 py-4">
                                        <?php if ($u['statut'] === 'Actif'): ?>
                                            <span class="rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">Active</span>
                                        <?php else: ?>
                                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-xs text-slate-500">
                                        <?= !empty($u['date_creation']) ? h(date('d/m/Y', strtotime($u['date_creation']))) : '—' ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center justify-end gap-2">
                                            <a href="utilisateurs.php?modifier=<?= (int)$u['id'] ?>"
                                               class="rounded-lg bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-100">Modifier</a>
                                            <form method="POST" onsubmit="return confirm('Changer le statut ?')">
                                                <input type="hidden" name="action" value="changer_statut">
                                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                                <button class="rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-100">Statut</button>
                                            </form>
                                            <form method="POST" onsubmit="return confirm('Supprimer cet utilisateur ?')">
                                                <input type="hidden" name="action" value="supprimer">
                                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
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
            </section>

            <!-- Répartition par succursale -->
            <section class="mt-7 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                <h3 class="font-bold text-slate-900 mb-4">Répartition du personnel par point de vente</h3>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <?php foreach ($parSucc as $ps): ?>
                        <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 flex items-center justify-between">
                            <div>
                                <p class="font-semibold text-slate-800 text-sm"><?= h($ps['nom']) ?></p>
                                <p class="text-xs text-slate-400"><?= h($ps['code']) ?></p>
                            </div>
                            <span class="rounded-lg bg-green-100 px-3 py-1.5 text-xs font-bold text-green-700"><?= (int)$ps['nb'] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

        </div>
    </main>
</div>
</body>
</html>