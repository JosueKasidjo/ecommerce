<?php
require_once __DIR__ . '/config.php';
if (!isset($_SESSION['id_utilisateur'])) { header('Location: login.php'); exit; }

$nomUtilisateur  = $_SESSION['nom'] ?? 'Administrateur';
$roleUtilisateur = $_SESSION['role'] ?? 'Administrateur';
$message = ''; $erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stmt = $pdo->prepare("UPDATE parametres SET valeur = :v WHERE cle = :c");
        foreach (($_POST['param'] ?? []) as $cle => $valeur) {
            $stmt->execute([':v' => trim((string)$valeur), ':c' => $cle]);
        }
        $message = 'Paramètres enregistrés avec succès.';
    } catch (PDOException $e) {
        $erreur = 'Erreur : ' . $e->getMessage();
    }
}

$params = [];
foreach ($pdo->query("SELECT * FROM parametres ORDER BY groupe, libelle")->fetchAll() as $p) {
    $params[$p['groupe']][] = $p;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <link rel="stylesheet" href="scrollbar.css">
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Paramètres | NEXIA</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">
<div class="min-h-screen md:flex">
    <!-- Même aside que succursales.php — copier/coller avec 'parametres.php' actif -->
    <?php $pageCourante = 'parametres.php'; include __DIR__ . '/_aside.php'; ?>

    <main class="min-w-0 flex-1 md:ml-64">
        <header class="sticky top-0 z-20 flex items-center justify-between border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur sm:px-8">
            <div>
                <p class="text-xs font-medium text-slate-400">Administration / Configuration</p>
                <h1 class="mt-1 text-xl font-extrabold text-slate-900 sm:text-2xl">Paramètres du logiciel</h1>
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

            <form method="POST" class="space-y-6">
                <?php foreach ($params as $groupe => $liste): ?>
                    <section class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                        <h2 class="font-bold text-slate-900 capitalize mb-4"><?= h(str_replace('_', ' ', $groupe)) ?></h2>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <?php foreach ($liste as $p): ?>
                                <div>
                                    <label class="mb-1 block text-sm font-semibold text-slate-700"><?= h($p['libelle']) ?></label>
                                    <?php if ($p['type'] === 'number'): ?>
                                        <input type="number" step="0.01" name="param[<?= h($p['cle']) ?>]" value="<?= h($p['valeur']) ?>" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm focus:border-green-500">
                                    <?php else: ?>
                                        <input type="text" name="param[<?= h($p['cle']) ?>]" value="<?= h($p['valeur']) ?>" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm focus:border-green-500">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
                <div class="flex justify-end">
                    <button class="rounded-xl bg-green-700 px-6 py-3 text-sm font-bold text-white hover:bg-green-800">Enregistrer les paramètres</button>
                </div>
            </form>
        </div>
    </main>
</div>
</body>
</html>