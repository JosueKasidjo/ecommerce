<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION["id_utilisateur"])) {
    header("Location: login.php");
    exit;
}

$nomUtilisateur = $_SESSION["nom"] ?? "Administrateur";
$roleUtilisateur = $_SESSION["role"] ?? "Administrateur";

$host = "localhost";
$nomBase = "ecommerce";
$utilisateur = "root";
$motDePasse = "";

$erreur = "";
$message = "";
$categories = [];
$edition = null;
$totalCategories = 0;
$totalActives = 0;
$totalInactives = 0;

function h($valeur) {
    return htmlspecialchars((string) $valeur, ENT_QUOTES, "UTF-8");
}

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$nomBase;charset=utf8mb4",
        $utilisateur,
        $motDePasse,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    // Traitement des formulaires
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $action = $_POST["action"] ?? "";

        if ($action === "ajouter" || $action === "modifier") {
            $nomCategorie = trim($_POST["nom_categorie"] ?? "");
            $description = trim($_POST["description"] ?? "");
            $statut = $_POST["statut"] ?? "Active";

            if ($nomCategorie === "") {
                $erreur = "Veuillez saisir le nom de la catégorie.";
            } elseif (!in_array($statut, ["Active", "Inactive"], true)) {
                $erreur = "Le statut sélectionné est invalide.";
            } else {
                if ($action === "ajouter") {
                    $stmt = $pdo->prepare(
                        "INSERT INTO categories (nom_categorie, description, statut, date_creation)
                         VALUES (:nom, :description, :statut, NOW())"
                    );
                    $stmt->execute([
                        ":nom" => $nomCategorie,
                        ":description" => $description,
                        ":statut" => $statut
                    ]);
                    $message = "La catégorie a été ajoutée avec succès.";
                } else {
                    $id = (int) ($_POST["id"] ?? 0);
                    $stmt = $pdo->prepare(
                        "UPDATE categories
                         SET nom_categorie = :nom, description = :description, statut = :statut
                         WHERE id = :id"
                    );
                    $stmt->execute([
                        ":nom" => $nomCategorie,
                        ":description" => $description,
                        ":statut" => $statut,
                        ":id" => $id
                    ]);
                    $message = "La catégorie a été modifiée avec succès.";
                }
            }
        } elseif ($action === "changer_statut") {
            $id = (int) ($_POST["id"] ?? 0);
            $stmt = $pdo->prepare(
                "UPDATE categories
                 SET statut = IF(statut = 'Active', 'Inactive', 'Active')
                 WHERE id = :id"
            );
            $stmt->execute([":id" => $id]);
            $message = "Le statut de la catégorie a été mis à jour.";
        } elseif ($action === "supprimer") {
            $id = (int) ($_POST["id"] ?? 0);

            $stmtProduits = $pdo->prepare(
                "SELECT COUNT(*) FROM produits WHERE categorie_id = :id"
            );
            $stmtProduits->execute([":id" => $id]);
            $nombreProduits = (int) $stmtProduits->fetchColumn();

            if ($nombreProduits > 0) {
                $erreur = "Impossible de supprimer cette catégorie : elle contient encore $nombreProduits produit(s).";
            } else {
                $stmt = $pdo->prepare("DELETE FROM categories WHERE id = :id");
                $stmt->execute([":id" => $id]);
                $message = "La catégorie a été supprimée.";
            }
        }

        // Évite de renvoyer le formulaire lors de l'actualisation.
        if ($message !== "" || ($erreur === "" && in_array($action, ["ajouter", "modifier", "changer_statut", "supprimer"], true))) {
            $_SESSION["categories_message"] = $message;
            $_SESSION["categories_erreur"] = $erreur;
            header("Location: categories.php");
            exit;
        }
    }

    if (isset($_GET["modifier"])) {
        $idEdition = (int) $_GET["modifier"];
        $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id");
        $stmt->execute([":id" => $idEdition]);
        $edition = $stmt->fetch() ?: null;
    }

    $totalCategories = (int) $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    $totalActives = (int) $pdo->query("SELECT COUNT(*) FROM categories WHERE statut = 'Active'")->fetchColumn();
    $totalInactives = (int) $pdo->query("SELECT COUNT(*) FROM categories WHERE statut = 'Inactive'")->fetchColumn();

    $stmtCategories = $pdo->query(
        "SELECT c.id, c.nom_categorie, c.description, c.statut, c.date_creation,
                COUNT(p.id) AS nombre_produits
         FROM categories c
         LEFT JOIN produits p ON p.categorie_id = c.id
         GROUP BY c.id, c.nom_categorie, c.description, c.statut, c.date_creation
         ORDER BY c.id DESC"
    );
    $categories = $stmtCategories->fetchAll();

} catch (PDOException $e) {
    $erreur = "Erreur de base de données : " . $e->getMessage();
}

if (isset($_SESSION["categories_message"])) {
    $message = $_SESSION["categories_message"];
    unset($_SESSION["categories_message"]);
}
if (isset($_SESSION["categories_erreur"])) {
    $erreur = $_SESSION["categories_erreur"];
    unset($_SESSION["categories_erreur"]);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catégories | E-commerce</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        boutique: {
                            50: '#f0fdf4',
                            100: '#dcfce7',
                            500: '#22c55e',
                            600: '#16a34a',
                            700: '#15803d',
                            800: '#166534',
                            900: '#14532d'
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800">
<div class="min-h-screen md:flex">

    <!-- Barre latérale identique au dashboard -->
    <?php include __DIR__ . '/_aside.php'; ?>

    <!-- Contenu principal -->
    <main class="min-w-0 flex-1 md:ml-64">
        <header class="sticky top-0 z-20 flex items-center justify-between border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur sm:px-8">
            <div>
                <p class="text-xs font-medium text-slate-400">Administration / Catalogue</p>
                <h1 class="mt-1 text-xl font-extrabold text-slate-900 sm:text-2xl">Gestion des catégories</h1>
            </div>
            <div class="flex items-center gap-3">
                <div class="hidden text-right sm:block">
                    <p class="text-sm font-bold text-slate-800"><?= h($nomUtilisateur) ?></p>
                    <p class="text-xs text-green-600"><?= h($roleUtilisateur) ?></p>
                </div>
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-green-100 font-bold text-green-700">
                    <?= h(strtoupper(substr($nomUtilisateur, 0, 1))) ?>
                </div>
            </div>
        </header>

        <div class="p-5 sm:p-8">
            <?php if ($erreur !== ""): ?>
                <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    <?= h($erreur) ?>
                </div>
            <?php endif; ?>

            <?php if ($message !== ""): ?>
                <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                    <?= h($message) ?>
                </div>
            <?php endif; ?>

            <section class="mb-7 overflow-hidden rounded-2xl bg-gradient-to-r from-green-700 to-emerald-500 p-6 text-white shadow-lg shadow-green-900/10 sm:p-8">
                <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-center">
                    <div>
                        <p class="text-sm font-medium text-green-100">Catalogue de votre boutique</p>
                        <h2 class="mt-2 text-2xl font-extrabold sm:text-3xl">Bonjour, <?= h($nomUtilisateur) ?> !</h2>
                        <p class="mt-2 max-w-xl text-sm leading-6 text-green-50">
                            Créez et organisez les catégories pour classer facilement les produits de votre boutique.
                        </p>
                    </div>
                    <a href="#formulaire-categorie" class="inline-flex w-fit items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-bold text-green-700 shadow transition hover:-translate-y-0.5 hover:bg-green-50">
                        + Nouvelle catégorie
                    </a>
                </div>
            </section>

            <!-- Statistiques -->
            <section class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-500">Toutes les catégories</span>
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-green-100 text-green-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 5h16M4 12h16M4 19h16"/><circle cx="8" cy="5" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="10" cy="19" r="1"/></svg></span>
                    </div>
                    <p class="mt-4 text-3xl font-extrabold text-slate-900"><?= number_format($totalCategories, 0, ",", " ") ?></p>
                    <p class="mt-1 text-xs text-slate-400">Catégories enregistrées</p>
                </div>
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-500">Catégories actives</span>
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg></span>
                    </div>
                    <p class="mt-4 text-3xl font-extrabold text-green-700"><?= number_format($totalActives, 0, ",", " ") ?></p>
                    <p class="mt-1 text-xs text-slate-400">Disponibles pour le catalogue</p>
                </div>
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-500">Catégories inactives</span>
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-100 text-amber-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 8l8 8m0-8-8 8"/></svg></span>
                    </div>
                    <p class="mt-4 text-3xl font-extrabold text-slate-900"><?= number_format($totalInactives, 0, ",", " ") ?></p>
                    <p class="mt-1 text-xs text-slate-400">Temporairement désactivées</p>
                </div>
            </section>

            <section class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <!-- Formulaire -->
                <div id="formulaire-categorie" class="h-fit rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div class="mb-5 border-b border-slate-100 pb-4">
                        <h3 class="font-bold text-slate-900"><?= $edition ? "Modifier la catégorie" : "Ajouter une catégorie" ?></h3>
                        <p class="mt-1 text-xs text-slate-400">Remplissez les informations ci-dessous</p>
                    </div>

                    <form method="POST" action="categories.php" class="space-y-4">
                        <input type="hidden" name="action" value="<?= $edition ? "modifier" : "ajouter" ?>">
                        <?php if ($edition): ?>
                            <input type="hidden" name="id" value="<?= (int) $edition["id"] ?>">
                        <?php endif; ?>

                        <div>
                            <label for="nom_categorie" class="mb-2 block text-sm font-semibold text-slate-700">Nom de la catégorie *</label>
                            <input
                                type="text"
                                id="nom_categorie"
                                name="nom_categorie"
                                required
                                maxlength="150"
                                value="<?= h($edition["nom_categorie"] ?? "") ?>"
                                placeholder="Ex. : Téléphones, Informatique..."
                                class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-green-500 focus:ring-4 focus:ring-green-100"
                            >
                        </div>

                        <div>
                            <label for="description" class="mb-2 block text-sm font-semibold text-slate-700">Description</label>
                            <textarea
                                id="description"
                                name="description"
                                rows="4"
                                placeholder="Décrivez brièvement cette catégorie..."
                                class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-green-500 focus:ring-4 focus:ring-green-100"
                            ><?= h($edition["description"] ?? "") ?></textarea>
                        </div>

                        <div>
                            <label for="statut" class="mb-2 block text-sm font-semibold text-slate-700">Statut</label>
                            <select id="statut" name="statut" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-green-500 focus:ring-4 focus:ring-green-100">
                                <option value="Active" <?= (($edition["statut"] ?? "Active") === "Active") ? "selected" : "" ?>>Active</option>
                                <option value="Inactive" <?= (($edition["statut"] ?? "") === "Inactive") ? "selected" : "" ?>>Inactive</option>
                            </select>
                        </div>

                        <div class="flex flex-col gap-2 pt-2">
                            <button type="submit" class="w-full rounded-xl bg-green-700 px-5 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-green-800">
                                <?= $edition ? "Enregistrer les modifications" : "Ajouter la catégorie" ?>
                            </button>
                            <?php if ($edition): ?>
                                <a href="categories.php" class="w-full rounded-xl border border-slate-200 px-5 py-3 text-center text-sm font-semibold text-slate-600 transition hover:bg-slate-50">Annuler la modification</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Liste -->
                <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm xl:col-span-2">
                    <div class="flex flex-col justify-between gap-2 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center">
                        <div>
                            <h3 class="font-bold text-slate-900">Liste des catégories</h3>
                            <p class="mt-1 text-xs text-slate-400">Toutes les catégories de votre boutique</p>
                        </div>
                        <span class="w-fit rounded-lg bg-green-50 px-3 py-1.5 text-xs font-semibold text-green-700">
                            <?= count($categories) ?> catégorie(s)
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[700px] text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-5 py-3 font-semibold">Catégorie</th>
                                    <th class="px-5 py-3 font-semibold">Produits</th>
                                    <th class="px-5 py-3 font-semibold">Statut</th>
                                    <th class="px-5 py-3 font-semibold">Date</th>
                                    <th class="px-5 py-3 text-right font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (empty($categories)): ?>
                                    <tr>
                                        <td colspan="5" class="px-5 py-12 text-center">
                                            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-50 text-green-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 5h16M4 12h16M4 19h16"/><circle cx="8" cy="5" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="10" cy="19" r="1"/></svg></div>
                                            <p class="mt-3 text-sm font-semibold text-slate-700">Aucune catégorie enregistrée</p>
                                            <p class="mt-1 text-xs text-slate-400">Utilisez le formulaire pour ajouter votre première catégorie.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($categories as $categorie): ?>
                                        <tr class="transition hover:bg-green-50/40">
                                            <td class="px-5 py-4">
                                                <p class="font-semibold text-slate-800"><?= h($categorie["nom_categorie"]) ?></p>
                                                <p class="mt-1 max-w-xs truncate text-xs text-slate-400"><?= h($categorie["description"] ?: "Aucune description") ?></p>
                                            </td>
                                            <td class="px-5 py-4">
                                                <span class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-700"><?= (int) $categorie["nombre_produits"] ?></span>
                                            </td>
                                            <td class="px-5 py-4">
                                                <?php if ($categorie["statut"] === "Active"): ?>
                                                    <span class="rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">Active</span>
                                                <?php else: ?>
                                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-5 py-4 text-xs text-slate-500">
                                                <?= !empty($categorie["date_creation"]) ? h(date("d/m/Y", strtotime($categorie["date_creation"]))) : "—" ?>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="flex items-center justify-end gap-2">
                                                    <a href="categories.php?modifier=<?= (int) $categorie["id"] ?>" class="rounded-lg bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 transition hover:bg-blue-100">Modifier</a>
                                                    <form method="POST" action="categories.php" onsubmit="return confirm('Voulez-vous vraiment changer le statut de cette catégorie ?');">
                                                        <input type="hidden" name="action" value="changer_statut">
                                                        <input type="hidden" name="id" value="<?= (int) $categorie["id"] ?>">
                                                        <button type="submit" class="rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700 transition hover:bg-amber-100">Statut</button>
                                                    </form>
                                                    <form method="POST" action="categories.php" onsubmit="return confirm('Confirmer la suppression de cette catégorie ?');">
                                                        <input type="hidden" name="action" value="supprimer">
                                                        <input type="hidden" name="id" value="<?= (int) $categorie["id"] ?>">
                                                        <button type="submit" class="rounded-lg bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 transition hover:bg-red-100">Supprimer</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
