<?php
require_once __DIR__ . '/config.php';
if (!isset($_SESSION['id_utilisateur'])) { header('Location: login.php'); exit; }

$nomUtilisateur  = $_SESSION['nom'] ?? 'Administrateur';
$roleUtilisateur = $_SESSION['role'] ?? 'Administrateur';
$erreur = ''; $message = ''; $edition = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = $_POST['action'] ?? '';
    $nom         = trim($_POST['nom'] ?? '');
    $code        = strtoupper(trim($_POST['code'] ?? ''));
    $type        = $_POST['type'] ?? 'POINT_DE_VENTE';
    $adresse     = trim($_POST['adresse'] ?? '');
    $ville       = trim($_POST['ville'] ?? '');
    $telephone   = trim($_POST['telephone'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $responsable = trim($_POST['responsable'] ?? '');
    $statut      = $_POST['statut'] ?? 'Active';

    try {
        if ($action === 'ajouter' || $action === 'modifier') {
            if ($nom === '' || $code === '') {
                $erreur = 'Le nom et le code sont obligatoires.';
            } else {
                if ($action === 'ajouter') {
                    $pdo->prepare("INSERT INTO succursales (nom, code, type, adresse, ville, telephone, email, responsable, statut) VALUES (?,?,?,?,?,?,?,?,?)")
                        ->execute([$nom,$code,$type,$adresse,$ville,$telephone,$email,$responsable,$statut]);
                    $message = 'Succursale ajoutée avec succès.';
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    $pdo->prepare("UPDATE succursales SET nom=?, code=?, type=?, adresse=?, ville=?, telephone=?, email=?, responsable=?, statut=? WHERE id=?")
                        ->execute([$nom,$code,$type,$adresse,$ville,$telephone,$email,$responsable,$statut,$id]);
                    $message = 'Succursale modifiée avec succès.';
                }
            }
        } elseif ($action === 'changer_statut') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE succursales SET statut = IF(statut='Active','Inactive','Active') WHERE id=?")->execute([$id]);
            $message = 'Statut de la succursale mis à jour.';
        } elseif ($action === 'supprimer') {
            $id = (int)($_POST['id'] ?? 0);
            $c = (int)$pdo->query("SELECT COUNT(*) FROM stocks_succursales WHERE succursale_id = $id AND quantite > 0")->fetchColumn();
            if ($c > 0) $erreur = "Impossible : cette succursale possède $c produit(s) en stock.";
            else {
                $pdo->prepare("DELETE FROM succursales WHERE id=?")->execute([$id]);
                $message = 'Succursale supprimée.';
            }
        }
    } catch (PDOException $e) {
        $erreur = ($e->getCode() === '23000') ? 'Ce code succursale existe déjà.' : 'Erreur : ' . $e->getMessage();
    }
}

if (isset($_GET['modifier'])) {
    $stmt = $pdo->prepare("SELECT * FROM succursales WHERE id = ?");
    $stmt->execute([(int)$_GET['modifier']]);
    $edition = $stmt->fetch() ?: null;
}

$succursales = $pdo->query("
    SELECT s.*, COALESCE(SUM(ss.quantite),0) AS total_produits
    FROM succursales s
    LEFT JOIN stocks_succursales ss ON ss.succursale_id = s.id
    GROUP BY s.id
    ORDER BY s.type='CENTRALE' DESC, s.nom
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <link rel="stylesheet" href="scrollbar.css">
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Succursales | NEXIA</title>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{colors:{boutique:{50:'#f0fdf4',100:'#dcfce7',500:'#22c55e',600:'#16a34a',700:'#15803d',800:'#166534',900:'#14532d'}}}}}</script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">
<div class="min-h-screen md:flex">

    <!-- Barre latérale IDENTIQUE (avec ajout Succursales + Paramètres) -->
    <?php include __DIR__ . '/_aside.php'; ?>

    <main class="min-w-0 flex-1 md:ml-64">
        <header class="sticky top-0 z-20 flex items-center justify-between border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur sm:px-8">
            <div>
                <p class="text-xs font-medium text-slate-400">Administration / Réseau</p>
                <h1 class="mt-1 text-xl font-extrabold text-slate-900 sm:text-2xl">Succursales & Points de vente</h1>
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

            <section class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <!-- Formulaire -->
                <div class="h-fit rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div class="mb-5 border-b border-slate-100 pb-4">
                        <h3 class="font-bold text-slate-900"><?= $edition ? 'Modifier la succursale' : 'Ajouter une succursale' ?></h3>
                        <p class="mt-1 text-xs text-slate-400">Remplissez les informations ci-dessous</p>
                    </div>
                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="action" value="<?= $edition ? 'modifier' : 'ajouter' ?>">
                        <?php if ($edition): ?><input type="hidden" name="id" value="<?= (int)$edition['id'] ?>"><?php endif; ?>
                        <div><label class="mb-1 block text-sm font-semibold">Nom *</label><input name="nom" required value="<?= h($edition['nom'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-green-500"></div>
                        <div><label class="mb-1 block text-sm font-semibold">Code *</label><input name="code" required value="<?= h($edition['code'] ?? '') ?>" placeholder="NEXIA-KIN" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-green-500"></div>
                        <div><label class="mb-1 block text-sm font-semibold">Type</label>
                            <select name="type" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm">
                                <?php foreach (['CENTRALE','SUCCURSALE','POINT_DE_VENTE'] as $t): ?>
                                    <option value="<?= $t ?>" <?= ($edition['type'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label class="mb-1 block text-sm font-semibold">Ville</label><input name="ville" value="<?= h($edition['ville'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm"></div>
                        <div><label class="mb-1 block text-sm font-semibold">Adresse</label><input name="adresse" value="<?= h($edition['adresse'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm"></div>
                        <div><label class="mb-1 block text-sm font-semibold">Téléphone</label><input name="telephone" value="<?= h($edition['telephone'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm"></div>
                        <div><label class="mb-1 block text-sm font-semibold">E-mail</label><input type="email" name="email" value="<?= h($edition['email'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm"></div>
                        <div><label class="mb-1 block text-sm font-semibold">Responsable</label><input name="responsable" value="<?= h($edition['responsable'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm"></div>
                        <div><label class="mb-1 block text-sm font-semibold">Statut</label>
                            <select name="statut" class="w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm">
                                <option value="Active" <?= ($edition['statut'] ?? 'Active') === 'Active' ? 'selected' : '' ?>>Active</option>
                                <option value="Inactive" <?= ($edition['statut'] ?? '') === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="flex flex-col gap-2 pt-2">
                            <button class="rounded-xl bg-green-700 px-5 py-2.5 text-sm font-bold text-white hover:bg-green-800"><?= $edition ? 'Enregistrer les modifications' : 'Ajouter la succursale' ?></button>
                            <?php if ($edition): ?><a href="succursales.php" class="rounded-xl border border-slate-200 px-5 py-2.5 text-center text-sm font-semibold text-slate-600">Annuler</a><?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Liste -->
                <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm xl:col-span-2">
                    <div class="border-b border-slate-100 px-5 py-4 flex items-center justify-between">
                        <div>
                            <h3 class="font-bold text-slate-900">Réseau NEXIA</h3>
                            <p class="mt-1 text-xs text-slate-400">Toutes les succursales et points de vente</p>
                        </div>
                        <span class="rounded-lg bg-green-50 px-3 py-1.5 text-xs font-semibold text-green-700"><?= count($succursales) ?> établissement(s)</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[750px] text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-5 py-3">Établissement</th>
                                    <th class="px-5 py-3">Type</th>
                                    <th class="px-5 py-3">Stock</th>
                                    <th class="px-5 py-3">Statut</th>
                                    <th class="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                            <?php if (empty($succursales)): ?>
                                <tr><td colspan="5" class="px-5 py-12 text-center text-slate-400">Aucune succursale enregistrée.</td></tr>
                            <?php else: foreach ($succursales as $s): ?>
                                <tr class="transition hover:bg-green-50/40">
                                    <td class="px-5 py-4">
                                        <p class="font-semibold text-slate-800"><?= h($s['nom']) ?></p>
                                        <p class="mt-1 text-xs text-slate-400"><?= h($s['code']) ?><?= $s['ville'] ? ' · ' . h($s['ville']) : '' ?></p>
                                    </td>
                                    <td class="px-5 py-4"><span class="rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700"><?= h(str_replace('_',' ', $s['type'])) ?></span></td>
                                    <td class="px-5 py-4"><span class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-700"><?= (int)$s['total_produits'] ?> unités</span></td>
                                    <td class="px-5 py-4">
                                        <?php if ($s['statut'] === 'Active'): ?>
                                            <span class="rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">Active</span>
                                        <?php else: ?>
                                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center justify-end gap-2">
                                            <a href="succursales.php?modifier=<?= (int)$s['id'] ?>" class="rounded-lg bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-100">Modifier</a>
                                            <form method="POST" data-confirm="Voulez-vous vraiment changer le statut de cette succursale ?" data-confirm-title="Modifier le statut"><input type="hidden" name="action" value="changer_statut"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-100">Statut</button></form>
                                            <form method="POST" data-confirm="Supprimer cette succursale ?" data-confirm-title="Supprimer cette succursale" data-confirm-label="Supprimer" data-confirm-variant="danger"><input type="hidden" name="action" value="supprimer"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="rounded-lg bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-100">Supprimer</button></form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>
<?php include __DIR__ . '/_notifications.php'; ?>
</body>
</html>