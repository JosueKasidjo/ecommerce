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
$idUtilisateur = (int) $_SESSION["id_utilisateur"];

$host = "localhost";
$nomBase = "ecommerce";
$utilisateur = "root";
$motDePasse = "";

$erreur = "";
$message = "";
$produits = [];
$categories = [];
$edition = null;
$totalProduits = 0;
$totalDisponibles = 0;
$totalIndisponibles = 0;
$totalStock = 0;

function h($valeur) {
    return htmlspecialchars((string) $valeur, ENT_QUOTES, "UTF-8");
}

function montant($valeur) {
    return number_format((float) $valeur, 2, ",", " ") . " $";
}

/**
 * Enregistre une image de produit dans uploads/produits.
 * Retourne le chemin relatif ou null si aucune image n'a été envoyée.
 */
function enregistrerPhoto($fichier) {
    if (!isset($fichier) || $fichier["error"] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($fichier["error"] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Le téléchargement de la photo a échoué.");
    }

    if ($fichier["size"] > 5 * 1024 * 1024) {
        throw new RuntimeException("La photo ne doit pas dépasser 5 Mo.");
    }

    $typesAutorises = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp",
        "image/gif" => "gif"
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $typeMime = $finfo->file($fichier["tmp_name"]);

    if (!isset($typesAutorises[$typeMime])) {
        throw new RuntimeException("Format non autorisé. Utilisez JPG, PNG, WEBP ou GIF.");
    }

    $dossier = __DIR__ . "/uploads/produits/";
    if (!is_dir($dossier) && !mkdir($dossier, 0755, true) && !is_dir($dossier)) {
        throw new RuntimeException("Impossible de créer le dossier uploads/produits.");
    }

    $nomFichier = bin2hex(random_bytes(16)) . "." . $typesAutorises[$typeMime];
    $destination = $dossier . $nomFichier;

    if (!move_uploaded_file($fichier["tmp_name"], $destination)) {
        throw new RuntimeException("Impossible d'enregistrer la photo sur le serveur.");
    }

    return "uploads/produits/" . $nomFichier;
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
            $nomProduit = trim($_POST["nom_produit"] ?? "");
            $categorieId = (int) ($_POST["categorie_id"] ?? 0);
            $description = trim($_POST["description"] ?? "");
            $prixAchat = str_replace(",", ".", trim($_POST["prix_achat"] ?? "0"));
            $prixVente = str_replace(",", ".", trim($_POST["prix_vente"] ?? "0"));
            $quantite = filter_var($_POST["quantite"] ?? 0, FILTER_VALIDATE_INT);
            $seuilAlerte = filter_var($_POST["seuil_alerte"] ?? 5, FILTER_VALIDATE_INT);
            $afficherAccueil = isset($_POST["afficher_accueil"]) ? 1 : 0;
            $statut = $_POST["statut"] ?? "Disponible";

            if ($nomProduit === "") {
                $erreur = "Veuillez saisir le nom du produit.";
            } elseif ($categorieId <= 0) {
                $erreur = "Veuillez sélectionner une catégorie.";
            } elseif (!is_numeric($prixAchat) || !is_numeric($prixVente) || (float) $prixAchat < 0 || (float) $prixVente < 0) {
                $erreur = "Les prix doivent être des nombres positifs ou égaux à zéro.";
            } elseif ($quantite === false || $quantite < 0 || $seuilAlerte === false || $seuilAlerte < 0) {
                $erreur = "La quantité et le seuil d'alerte doivent être des nombres entiers positifs.";
            } elseif (!in_array($statut, ["Disponible", "Indisponible"], true)) {
                $erreur = "Le statut sélectionné est invalide.";
            } else {
                $stmtCategorie = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE id = :id");
                $stmtCategorie->execute([":id" => $categorieId]);

                if ((int) $stmtCategorie->fetchColumn() === 0) {
                    $erreur = "La catégorie sélectionnée n'existe pas.";
                } else {
                    $anciennePhoto = null;
                    if ($action === "modifier") {
                        $id = (int) ($_POST["id"] ?? 0);
                        $stmtAncien = $pdo->prepare("SELECT photo FROM produits WHERE id = :id");
                        $stmtAncien->execute([":id" => $id]);
                        $ancienProduit = $stmtAncien->fetch();

                        if (!$ancienProduit) {
                            throw new RuntimeException("Le produit à modifier est introuvable.");
                        }
                        $anciennePhoto = $ancienProduit["photo"];
                    }

                    $nouvellePhoto = enregistrerPhoto($_FILES["photo"] ?? null);
                    $photo = $nouvellePhoto ?? $anciennePhoto;

                    if ($action === "ajouter") {
                        $pdo->beginTransaction();

                        $stmt = $pdo->prepare(
                            "INSERT INTO produits
                                (categorie_id, nom_produit, description, photo, prix_achat, prix_vente, afficher_accueil, statut, date_creation)
                             VALUES
                                (:categorie_id, :nom, :description, :photo, :prix_achat, :prix_vente, :accueil, :statut, NOW())"
                        );
                        $stmt->execute([
                            ":categorie_id" => $categorieId,
                            ":nom" => $nomProduit,
                            ":description" => $description,
                            ":photo" => $photo,
                            ":prix_achat" => $prixAchat,
                            ":prix_vente" => $prixVente,
                            ":accueil" => $afficherAccueil,
                            ":statut" => $statut
                        ]);

                        $nouveauProduitId = (int) $pdo->lastInsertId();

                        $stmtStock = $pdo->prepare(
                            "INSERT INTO stocks (produit_id, quantite, seuil_alerte, derniere_modification)
                             VALUES (:produit_id, :quantite, :seuil, NOW())"
                        );
                        $stmtStock->execute([
                            ":produit_id" => $nouveauProduitId,
                            ":quantite" => $quantite,
                            ":seuil" => $seuilAlerte
                        ]);

                        if ($quantite > 0) {
                            $stmtMouvement = $pdo->prepare(
                                "INSERT INTO mouvements_stock
                                    (produit_id, utilisateur_id, type_mouvement, quantite, motif, date_mouvement)
                                 VALUES (:produit_id, :utilisateur_id, 'Entree', :quantite, :motif, NOW())"
                            );
                            $stmtMouvement->execute([
                                ":produit_id" => $nouveauProduitId,
                                ":utilisateur_id" => $idUtilisateur,
                                ":quantite" => $quantite,
                                ":motif" => "Stock initial à la création du produit"
                            ]);
                        }

                        $pdo->commit();
                        $message = "Le produit a été ajouté avec succès.";
                    } else {
                        $pdo->beginTransaction();

                        $stmt = $pdo->prepare(
                            "UPDATE produits
                             SET categorie_id = :categorie_id,
                                 nom_produit = :nom,
                                 description = :description,
                                 photo = :photo,
                                 prix_achat = :prix_achat,
                                 prix_vente = :prix_vente,
                                 afficher_accueil = :accueil,
                                 statut = :statut
                             WHERE id = :id"
                        );
                        $stmt->execute([
                            ":categorie_id" => $categorieId,
                            ":nom" => $nomProduit,
                            ":description" => $description,
                            ":photo" => $photo,
                            ":prix_achat" => $prixAchat,
                            ":prix_vente" => $prixVente,
                            ":accueil" => $afficherAccueil,
                            ":statut" => $statut,
                            ":id" => $id
                        ]);

                        $stmtStockAvant = $pdo->prepare("SELECT quantite FROM stocks WHERE produit_id = :id");
                        $stmtStockAvant->execute([":id" => $id]);
                        $stockAvant = $stmtStockAvant->fetchColumn();

                        if ($stockAvant === false) {
                            $stmtStock = $pdo->prepare(
                                "INSERT INTO stocks (produit_id, quantite, seuil_alerte, derniere_modification)
                                 VALUES (:id, :quantite, :seuil, NOW())"
                            );
                            $stmtStock->execute([
                                ":id" => $id,
                                ":quantite" => $quantite,
                                ":seuil" => $seuilAlerte
                            ]);
                            $ancienneQuantite = 0;
                        } else {
                            $ancienneQuantite = (int) $stockAvant;
                            $stmtStock = $pdo->prepare(
                                "UPDATE stocks
                                 SET quantite = :quantite, seuil_alerte = :seuil, derniere_modification = NOW()
                                 WHERE produit_id = :id"
                            );
                            $stmtStock->execute([
                                ":quantite" => $quantite,
                                ":seuil" => $seuilAlerte,
                                ":id" => $id
                            ]);
                        }

                        if ($ancienneQuantite !== $quantite) {
                            $stmtMouvement = $pdo->prepare(
                                "INSERT INTO mouvements_stock
                                    (produit_id, utilisateur_id, type_mouvement, quantite, motif, date_mouvement)
                                 VALUES (:produit_id, :utilisateur_id, 'Ajustement', :quantite, :motif, NOW())"
                            );
                            $stmtMouvement->execute([
                                ":produit_id" => $id,
                                ":utilisateur_id" => $idUtilisateur,
                                ":quantite" => abs($quantite - $ancienneQuantite),
                                ":motif" => "Ajustement de stock depuis la fiche produit"
                            ]);
                        }

                        $pdo->commit();
                        $message = "Le produit a été modifié avec succès.";

                        if ($nouvellePhoto && $anciennePhoto && is_file(__DIR__ . "/" . $anciennePhoto)) {
                            @unlink(__DIR__ . "/" . $anciennePhoto);
                        }
                    }

                    $_SESSION["produits_message"] = $message;
                    header("Location: produits.php");
                    exit;
                }
            }
        } elseif ($action === "supprimer") {
            $id = (int) ($_POST["id"] ?? 0);

            $stmtDetails = $pdo->prepare("SELECT COUNT(*) FROM details_commande WHERE produit_id = :id");
            $stmtDetails->execute([":id" => $id]);
            $dansCommandes = (int) $stmtDetails->fetchColumn();

            $stmtMouvements = $pdo->prepare("SELECT COUNT(*) FROM mouvements_stock WHERE produit_id = :id");
            $stmtMouvements->execute([":id" => $id]);
            $avecMouvements = (int) $stmtMouvements->fetchColumn();

            if ($dansCommandes > 0 || $avecMouvements > 0) {
                $erreur = "Ce produit est déjà lié à des commandes ou à des mouvements de stock. Pour conserver l'historique, passez-le plutôt en statut Indisponible.";
            } else {
                $stmtPhoto = $pdo->prepare("SELECT photo FROM produits WHERE id = :id");
                $stmtPhoto->execute([":id" => $id]);
                $photoASupprimer = $stmtPhoto->fetchColumn();

                $pdo->beginTransaction();
                $stmtStock = $pdo->prepare("DELETE FROM stocks WHERE produit_id = :id");
                $stmtStock->execute([":id" => $id]);
                $stmtProduit = $pdo->prepare("DELETE FROM produits WHERE id = :id");
                $stmtProduit->execute([":id" => $id]);
                $pdo->commit();

                if ($photoASupprimer && is_file(__DIR__ . "/" . $photoASupprimer)) {
                    @unlink(__DIR__ . "/" . $photoASupprimer);
                }

                $message = "Le produit a été supprimé.";
                $_SESSION["produits_message"] = $message;
                header("Location: produits.php");
                exit;
            }
        } elseif ($action === "changer_statut") {
            $id = (int) ($_POST["id"] ?? 0);
            $stmt = $pdo->prepare(
                "UPDATE produits
                 SET statut = IF(statut = 'Disponible', 'Indisponible', 'Disponible')
                 WHERE id = :id"
            );
            $stmt->execute([":id" => $id]);
            $_SESSION["produits_message"] = "Le statut du produit a été mis à jour.";
            header("Location: produits.php");
            exit;
        }
    }

    if (isset($_GET["modifier"])) {
        $idEdition = (int) $_GET["modifier"];
        $stmt = $pdo->prepare(
            "SELECT p.*, COALESCE(s.quantite, 0) AS quantite,
                    COALESCE(s.seuil_alerte, 5) AS seuil_alerte
             FROM produits p
             LEFT JOIN stocks s ON s.produit_id = p.id
             WHERE p.id = :id"
        );
        $stmt->execute([":id" => $idEdition]);
        $edition = $stmt->fetch() ?: null;
    }

    $categories = $pdo->query(
        "SELECT id, nom_categorie FROM categories WHERE statut = 'Active' ORDER BY nom_categorie ASC"
    )->fetchAll();

    $totalProduits = (int) $pdo->query("SELECT COUNT(*) FROM produits")->fetchColumn();
    $totalDisponibles = (int) $pdo->query("SELECT COUNT(*) FROM produits WHERE statut = 'Disponible'")->fetchColumn();
    $totalIndisponibles = (int) $pdo->query("SELECT COUNT(*) FROM produits WHERE statut = 'Indisponible'")->fetchColumn();
    $totalStock = (int) $pdo->query("SELECT COALESCE(SUM(quantite), 0) FROM stocks")->fetchColumn();

    $stmtProduits = $pdo->query(
        "SELECT p.id, p.nom_produit, p.description, p.photo, p.prix_achat, p.prix_vente,
                p.afficher_accueil, p.statut, p.date_creation, c.nom_categorie,
                COALESCE(s.quantite, 0) AS quantite,
                COALESCE(s.seuil_alerte, 0) AS seuil_alerte
         FROM produits p
         INNER JOIN categories c ON c.id = p.categorie_id
         LEFT JOIN stocks s ON s.produit_id = p.id
         ORDER BY p.id DESC"
    );
    $produits = $stmtProduits->fetchAll();

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $erreur = "Une erreur est survenue : " . $e->getMessage();
}

if (isset($_SESSION["produits_message"])) {
    $message = $_SESSION["produits_message"];
    unset($_SESSION["produits_message"]);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Produits | E-commerce</title>
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

    <main class="min-w-0 flex-1 md:ml-64">
        <header class="sticky top-0 z-20 flex items-center justify-between border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur sm:px-8">
            <div>
                <p class="text-xs font-medium text-slate-400">Administration / Catalogue</p>
                <h1 class="mt-1 text-xl font-extrabold text-slate-900 sm:text-2xl">Gestion des produits</h1>
            </div>
            <div class="flex items-center gap-3">
                <div class="hidden text-right sm:block">
                    <p class="text-sm font-bold text-slate-800"><?= h($nomUtilisateur) ?></p>
                    <p class="text-xs text-green-600"><?= h($roleUtilisateur) ?></p>
                </div>
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-green-100 font-bold text-green-700"><?= h(strtoupper(substr($nomUtilisateur, 0, 1))) ?></div>
            </div>
        </header>

        <div class="p-5 sm:p-8">
            <?php if ($erreur !== ""): ?>
                <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= h($erreur) ?></div>
            <?php endif; ?>
            <?php if ($message !== ""): ?>
                <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?= h($message) ?></div>
            <?php endif; ?>

            <section class="mb-7 overflow-hidden rounded-2xl bg-gradient-to-r from-green-700 to-emerald-500 p-6 text-white shadow-lg shadow-green-900/10 sm:p-8">
                <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-center">
                    <div>
                        <p class="text-sm font-medium text-green-100">Catalogue de votre boutique</p>
                        <h2 class="mt-2 text-2xl font-extrabold sm:text-3xl">Bonjour, <?= h($nomUtilisateur) ?> !</h2>
                        <p class="mt-2 max-w-xl text-sm leading-6 text-green-50">Ajoutez vos articles, gérez leurs prix, leurs photos et les quantités disponibles en stock.</p>
                    </div>
                    <a href="#formulaire-produit" class="inline-flex w-fit items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-bold text-green-700 shadow transition hover:-translate-y-0.5 hover:bg-green-50">+ Nouveau produit</a>
                </div>
            </section>

            <section class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                    <div class="flex items-center justify-between"><span class="text-sm font-medium text-slate-500">Tous les produits</span><span class="flex h-11 w-11 items-center justify-center rounded-xl bg-green-100 text-green-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4.5 7.8 7.5 4.3 7.5-4.3M12 12v9"/></svg></span></div>
                    <p class="mt-4 text-3xl font-extrabold text-slate-900"><?= number_format($totalProduits, 0, ",", " ") ?></p>
                    <p class="mt-1 text-xs text-slate-400">Produits enregistrés</p>
                </div>
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                    <div class="flex items-center justify-between"><span class="text-sm font-medium text-slate-500">Disponibles</span><span class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg></span></div>
                    <p class="mt-4 text-3xl font-extrabold text-green-700"><?= number_format($totalDisponibles, 0, ",", " ") ?></p>
                    <p class="mt-1 text-xs text-slate-400">Produits disponibles à la vente</p>
                </div>
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                    <div class="flex items-center justify-between"><span class="text-sm font-medium text-slate-500">Indisponibles</span><span class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-100 text-amber-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M8 5v14m8-14v14"/></svg></span></div>
                    <p class="mt-4 text-3xl font-extrabold text-slate-900"><?= number_format($totalIndisponibles, 0, ",", " ") ?></p>
                    <p class="mt-1 text-xs text-slate-400">Produits masqués de la vente</p>
                </div>
                <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-md">
                    <div class="flex items-center justify-between"><span class="text-sm font-medium text-slate-500">Stock total</span><span class="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-100 text-blue-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5v-9Z"/><path d="m3.5 7.8 8.5 4.4 8.5-4.4M12 12.2V21"/></svg></span></div>
                    <p class="mt-4 text-3xl font-extrabold text-slate-900"><?= number_format($totalStock, 0, ",", " ") ?></p>
                    <p class="mt-1 text-xs text-slate-400">Unités en inventaire</p>
                </div>
            </section>

            <section class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <!-- Formulaire produit -->
                <div id="formulaire-produit" class="h-fit rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                    <div class="mb-5 border-b border-slate-100 pb-4">
                        <h3 class="font-bold text-slate-900"><?= $edition ? "Modifier le produit" : "Ajouter un produit" ?></h3>
                        <p class="mt-1 text-xs text-slate-400">Les champs marqués * sont obligatoires</p>
                    </div>

                    <?php if (empty($categories)): ?>
                        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                            Aucune catégorie active. <a href="categories.php" class="font-bold underline">Créez d'abord une catégorie</a>.
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="produits.php" enctype="multipart/form-data" class="space-y-4">
                        <input type="hidden" name="action" value="<?= $edition ? "modifier" : "ajouter" ?>">
                        <?php if ($edition): ?><input type="hidden" name="id" value="<?= (int) $edition["id"] ?>"><?php endif; ?>

                        <div>
                            <label for="nom_produit" class="mb-2 block text-sm font-semibold text-slate-700">Nom du produit *</label>
                            <input type="text" id="nom_produit" name="nom_produit" required maxlength="180" value="<?= h($edition["nom_produit"] ?? "") ?>" placeholder="Ex. : Téléphone Samsung" class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-green-500 focus:ring-4 focus:ring-green-100">
                        </div>

                        <div>
                            <label for="categorie_id" class="mb-2 block text-sm font-semibold text-slate-700">Catégorie *</label>
                            <select id="categorie_id" name="categorie_id" required class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-green-500 focus:ring-4 focus:ring-green-100">
                                <option value="">-- Choisir une catégorie --</option>
                                <?php foreach ($categories as $categorie): ?>
                                    <option value="<?= (int) $categorie["id"] ?>" <?= ((int) ($edition["categorie_id"] ?? 0) === (int) $categorie["id"]) ? "selected" : "" ?>><?= h($categorie["nom_categorie"]) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="description" class="mb-2 block text-sm font-semibold text-slate-700">Description</label>
                            <textarea id="description" name="description" rows="3" placeholder="Description du produit..." class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none transition focus:border-green-500 focus:ring-4 focus:ring-green-100"><?= h($edition["description"] ?? "") ?></textarea>
                        </div>

                        <div>
                            <label for="photo" class="mb-2 block text-sm font-semibold text-slate-700">Photo du produit</label>
                            <input type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp,image/gif" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-green-50 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-green-700">
                            <p class="mt-1 text-xs text-slate-400">JPG, PNG, WEBP ou GIF — 5 Mo maximum.</p>
                            <?php if (!empty($edition["photo"])): ?>
                                <div class="mt-3 flex items-center gap-3">
                                    <img src="<?= h($edition["photo"]) ?>" alt="Photo actuelle" class="h-16 w-16 rounded-xl border border-slate-200 object-cover">
                                    <span class="text-xs text-slate-500">Photo actuelle. Choisissez une nouvelle image pour la remplacer.</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="prix_achat" class="mb-2 block text-sm font-semibold text-slate-700">Prix d'achat *</label>
                                <input type="number" id="prix_achat" name="prix_achat" required min="0" step="0.01" value="<?= h($edition["prix_achat"] ?? "0") ?>" class="w-full rounded-xl border border-slate-200 px-3 py-3 text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-100">
                            </div>
                            <div>
                                <label for="prix_vente" class="mb-2 block text-sm font-semibold text-slate-700">Prix de vente *</label>
                                <input type="number" id="prix_vente" name="prix_vente" required min="0" step="0.01" value="<?= h($edition["prix_vente"] ?? "0") ?>" class="w-full rounded-xl border border-slate-200 px-3 py-3 text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-100">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="quantite" class="mb-2 block text-sm font-semibold text-slate-700">Quantité *</label>
                                <input type="number" id="quantite" name="quantite" required min="0" step="1" value="<?= h($edition["quantite"] ?? "0") ?>" class="w-full rounded-xl border border-slate-200 px-3 py-3 text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-100">
                            </div>
                            <div>
                                <label for="seuil_alerte" class="mb-2 block text-sm font-semibold text-slate-700">Seuil d'alerte</label>
                                <input type="number" id="seuil_alerte" name="seuil_alerte" min="0" step="1" value="<?= h($edition["seuil_alerte"] ?? "5") ?>" class="w-full rounded-xl border border-slate-200 px-3 py-3 text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-100">
                            </div>
                        </div>

                        <div>
                            <label for="statut" class="mb-2 block text-sm font-semibold text-slate-700">Statut du produit</label>
                            <select id="statut" name="statut" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-green-500 focus:ring-4 focus:ring-green-100">
                                <option value="Disponible" <?= (($edition["statut"] ?? "Disponible") === "Disponible") ? "selected" : "" ?>>Disponible</option>
                                <option value="Indisponible" <?= (($edition["statut"] ?? "") === "Indisponible") ? "selected" : "" ?>>Indisponible</option>
                            </select>
                        </div>

                        <label class="flex items-center gap-3 rounded-xl border border-slate-200 p-3">
                            <input type="checkbox" name="afficher_accueil" value="1" <?= !empty($edition["afficher_accueil"]) || !$edition ? "checked" : "" ?> class="h-4 w-4 rounded border-slate-300 text-green-600 focus:ring-green-500">
                            <span><span class="block text-sm font-semibold text-slate-700">Afficher sur la page d'accueil</span><span class="block text-xs text-slate-400">Le produit pourra apparaître dans la boutique publique.</span></span>
                        </label>

                        <div class="flex flex-col gap-2 pt-2">
                            <button type="submit" <?= empty($categories) ? "disabled" : "" ?> class="w-full rounded-xl bg-green-700 px-5 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-green-800 disabled:cursor-not-allowed disabled:opacity-50">
                                <?= $edition ? "Enregistrer les modifications" : "Ajouter le produit" ?>
                            </button>
                            <?php if ($edition): ?>
                                <a href="produits.php" class="w-full rounded-xl border border-slate-200 px-5 py-3 text-center text-sm font-semibold text-slate-600 transition hover:bg-slate-50">Annuler la modification</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Liste des produits -->
                <div class="overflow-hidden rounded-2xl border border-slate-100 bg-white shadow-sm xl:col-span-2">
                    <div class="flex flex-col justify-between gap-2 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center">
                        <div>
                            <h3 class="font-bold text-slate-900">Liste des produits</h3>
                            <p class="mt-1 text-xs text-slate-400">Produits enregistrés dans votre boutique</p>
                        </div>
                        <span class="w-fit rounded-lg bg-green-50 px-3 py-1.5 text-xs font-semibold text-green-700"><?= count($produits) ?> produit(s)</span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[950px] text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-5 py-3 font-semibold">Produit</th>
                                    <th class="px-5 py-3 font-semibold">Catégorie</th>
                                    <th class="px-5 py-3 font-semibold">Prix vente</th>
                                    <th class="px-5 py-3 font-semibold">Stock</th>
                                    <th class="px-5 py-3 font-semibold">Accueil</th>
                                    <th class="px-5 py-3 font-semibold">Statut</th>
                                    <th class="px-5 py-3 text-right font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (empty($produits)): ?>
                                    <tr><td colspan="7" class="px-5 py-12 text-center">
                                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-50 text-green-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4.5 7.8 7.5 4.3 7.5-4.3M12 12v9"/></svg></div>
                                        <p class="mt-3 text-sm font-semibold text-slate-700">Aucun produit enregistré</p>
                                        <p class="mt-1 text-xs text-slate-400">Utilisez le formulaire pour ajouter votre premier produit.</p>
                                    </td></tr>
                                <?php else: ?>
                                    <?php foreach ($produits as $produit): ?>
                                        <tr class="transition hover:bg-green-50/40">
                                            <td class="px-5 py-4">
                                                <div class="flex items-center gap-3">
                                                    <?php if (!empty($produit["photo"])): ?>
                                                        <img src="<?= h($produit["photo"]) ?>" alt="<?= h($produit["nom_produit"]) ?>" class="h-12 w-12 rounded-xl border border-slate-100 object-cover">
                                                    <?php else: ?>
                                                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-green-50 text-green-700"><svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4.5 7.8 7.5 4.3 7.5-4.3M12 12v9"/></svg></div>
                                                    <?php endif; ?>
                                                    <div class="min-w-0">
                                                        <p class="max-w-[190px] truncate font-semibold text-slate-800"><?= h($produit["nom_produit"]) ?></p>
                                                        <p class="mt-1 text-xs text-slate-400">Achat : <?= h(montant($produit["prix_achat"])) ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-5 py-4 text-slate-600"><?= h($produit["nom_categorie"]) ?></td>
                                            <td class="px-5 py-4 font-semibold text-slate-800"><?= h(montant($produit["prix_vente"])) ?></td>
                                            <td class="px-5 py-4">
                                                <span class="rounded-lg px-3 py-1.5 text-xs font-bold <?= (int) $produit["quantite"] <= (int) $produit["seuil_alerte"] ? "bg-red-50 text-red-700" : "bg-green-50 text-green-700" ?>">
                                                    <?= (int) $produit["quantite"] ?>
                                                </span>
                                            </td>
                                            <td class="px-5 py-4">
                                                <?= !empty($produit["afficher_accueil"]) ? '<span class="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">Oui</span>' : '<span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-500">Non</span>' ?>
                                            </td>
                                            <td class="px-5 py-4">
                                                <?php if ($produit["statut"] === "Disponible"): ?>
                                                    <span class="rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">Disponible</span>
                                                <?php else: ?>
                                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">Indisponible</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="flex items-center justify-end gap-2">
                                                    <a href="produits.php?modifier=<?= (int) $produit["id"] ?>#formulaire-produit" class="rounded-lg bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 transition hover:bg-blue-100">Modifier</a>
                                                    <form method="POST" action="produits.php" onsubmit="return confirm('Voulez-vous changer le statut de ce produit ?');">
                                                        <input type="hidden" name="action" value="changer_statut">
                                                        <input type="hidden" name="id" value="<?= (int) $produit["id"] ?>">
                                                        <button type="submit" class="rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700 transition hover:bg-amber-100">Statut</button>
                                                    </form>
                                                    <form method="POST" action="produits.php" onsubmit="return confirm('Confirmer la suppression de ce produit ?');">
                                                        <input type="hidden" name="action" value="supprimer">
                                                        <input type="hidden" name="id" value="<?= (int) $produit["id"] ?>">
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
