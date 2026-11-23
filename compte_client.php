
<?php
require_once __DIR__ . "/config.php";



$message = "";
$type_message = "error";

if (!isset($_SESSION["csrf_client"])) {
    $_SESSION["csrf_client"] = bin2hex(random_bytes(32));
}

$mode = $_GET["mode"] ?? "connexion";

if ($mode !== "inscription") {
    $mode = "connexion";
}

// Vérifier que le client n'est pas déjà connecté
if (isset($_SESSION["id_client"])) {
    header("Location: panier.php");
    exit;
}

// Traitement des formulaires
if ($_SERVER["REQUEST_METHOD"] === "POST") {

        $jeton_session = $_SESSION["csrf_client"] ?? "";
        $jeton_formulaire = $_POST["csrf_token"] ?? "";

        if (
            empty($jeton_session) ||
            empty($jeton_formulaire) ||
            !hash_equals($jeton_session, $jeton_formulaire)
        ) {
            $message = "Session expirée. Actualisez la page et réessayez.";
        } else {

        $action = $_POST["action"] ?? "";
        $email = strtolower(trim($_POST["email"] ?? ""));
        $mot_de_passe = $_POST["mot_de_passe"] ?? "";

        try {

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Adresse e-mail invalide.");
            }

            // INSCRIPTION
            if ($action === "inscription") {

                $nom = trim($_POST["nom"] ?? "");
                $postnom = trim($_POST["postnom"] ?? "");
                $prenom = trim($_POST["prenom"] ?? "");
                $telephone = trim($_POST["telephone"] ?? "");
                $adresse = trim($_POST["adresse"] ?? "");
                $confirmation = $_POST["confirmation"] ?? "";

                if (
                    $nom === "" ||
                    $prenom === "" ||
                    $telephone === "" ||
                    $adresse === ""
                ) {
                    throw new Exception("Veuillez remplir tous les champs obligatoires.");
                }

                if (strlen($mot_de_passe) < 8) {
                    throw new Exception("Le mot de passe doit contenir au moins 8 caractères.");
                }

                if ($mot_de_passe !== $confirmation) {
                    throw new Exception("Les mots de passe ne correspondent pas.");
                }

                // Vérifier si l'e-mail existe déjà
                $verifier = $pdo->prepare(
                    "SELECT id FROM clients WHERE email = ? LIMIT 1"
                );
                $verifier->execute([$email]);

                if ($verifier->fetch()) {
                    throw new Exception("Cette adresse e-mail est déjà utilisée.");
                }

                // Sécuriser le mot de passe
                $hash = password_hash($mot_de_passe, PASSWORD_DEFAULT);

                // Enregistrer le client
                $insert = $pdo->prepare("
                    INSERT INTO clients
                    (nom, postnom, prenom, telephone, email,
                     mot_de_passe, adresse, date_creation)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");

                $insert->execute([
                    $nom,
                    $postnom,
                    $prenom,
                    $telephone,
                    $email,
                    $hash,
                    $adresse
                ]);

                session_regenerate_id(true);

                $_SESSION["id_client"] = (int)$pdo->lastInsertId();
                $_SESSION["nom_client"] = $prenom . " " . $nom;
                $_SESSION["email_client"] = $email;

                header("Location: panier.php");
                exit;
            }

            // CONNEXION
            if ($action === "connexion") {

                if ($mot_de_passe === "") {
                    throw new Exception("Veuillez saisir votre mot de passe.");
                }

                $requete = $pdo->prepare("
                    SELECT id, nom, prenom, email, mot_de_passe
                    FROM clients
                    WHERE email = ?
                    LIMIT 1
                ");

                $requete->execute([$email]);
                $client = $requete->fetch();

                if (
                    !$client ||
                    empty($client["mot_de_passe"]) ||
                    !password_verify(
                        $mot_de_passe,
                        $client["mot_de_passe"]
                    )
                ) {
                    throw new Exception("E-mail ou mot de passe incorrect.");
                }

                session_regenerate_id(true);

                $_SESSION["id_client"] = (int)$client["id"];
                $_SESSION["nom_client"] =
                    $client["prenom"] . " " . $client["nom"];
                $_SESSION["email_client"] = $client["email"];

                header("Location: panier.php");
                exit;
            }

        } catch (Exception $e) {
            $message = $e->getMessage();
        } catch (PDOException $e) {
            $message = "Une erreur est survenue. Vérifiez la structure de votre table clients.";
        }
    }

    $mode = ($_POST["action"] ?? "") === "inscription"
        ? "inscription"
        : "connexion";
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Compte client - Ma Boutique</title>

    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 min-h-screen">

<header class="bg-white shadow-sm">
    <div class="max-w-6xl mx-auto px-5 py-4 flex justify-between items-center">

        <a href="index.php"
           class="text-2xl font-bold text-green-700">
            MaBoutique
        </a>

        <a href="panier.php"
           class="text-gray-600 hover:text-green-700 font-medium">
            Mon panier
        </a>

    </div>
</header>

<main class="max-w-5xl mx-auto px-4 py-12">

    <div class="grid md:grid-cols-2 gap-8 items-center">

        <div class="bg-green-700 text-white rounded-3xl p-10">

            <h1 class="text-3xl font-bold mb-5">
                Bienvenue chez nous !
            </h1>

            <p class="text-green-50 leading-7">
                Créez votre compte client pour profiter
                de nos produits et effectuer vos achats
                facilement et en toute sécurité.
            </p>

            <div class="mt-8 space-y-4 text-sm">
                <p class="flex items-center gap-2"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>Inscription gratuite</p>
                <p class="flex items-center gap-2"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>Connexion sécurisée</p>
                <p class="flex items-center gap-2"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>Accès à votre espace client</p>
                <p class="flex items-center gap-2"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>Commandes simplifiées</p>
            </div>

            <a href="index.php"
               class="inline-block mt-10 bg-white text-green-700
                      px-6 py-3 rounded-xl font-bold">
                Retour à la boutique
            </a>

        </div>

        <div class="bg-white rounded-3xl shadow-sm border p-7">

            <div class="grid grid-cols-2 bg-gray-100 rounded-xl p-1 mb-7">

                <a href="compte_client.php?mode=connexion"
                   class="text-center py-3 rounded-lg font-bold
                   <?= $mode === "connexion"
                       ? "bg-white text-green-700 shadow"
                       : "text-gray-500" ?>">
                    Connexion
                </a>

                <a href="compte_client.php?mode=inscription"
                   class="text-center py-3 rounded-lg font-bold
                   <?= $mode === "inscription"
                       ? "bg-white text-green-700 shadow"
                       : "text-gray-500" ?>">
                    Inscription
                </a>

            </div>

            <?php if ($message !== ""): ?>
                <div class="bg-red-50 border border-red-200
                            text-red-700 p-4 rounded-xl mb-5">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if ($mode === "inscription"): ?>

                <h2 class="text-2xl font-bold mb-2">
                    Créer un compte
                </h2>

                <p class="text-gray-500 text-sm mb-6">
                    Remplissez vos informations personnelles.
                </p>

                <form method="POST"
                      action="compte_client.php?mode=inscription"
                      class="space-y-4">

                    <input type="hidden" name="action"
                           value="inscription">

                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($_SESSION["csrf_client"]) ?>">

                    <div class="grid grid-cols-2 gap-3">

                        <input type="text" name="nom"
                               required placeholder="Nom"
                               class="border rounded-xl p-3 w-full">

                        <input type="text" name="postnom"
                               placeholder="Postnom"
                               class="border rounded-xl p-3 w-full">

                        <input type="text" name="prenom"
                               required placeholder="Prénom"
                               class="border rounded-xl p-3 w-full">

                        <input type="tel" name="telephone"
                               required placeholder="Téléphone"
                               class="border rounded-xl p-3 w-full">

                    </div>

                    <input type="email" name="email"
                           required placeholder="Adresse e-mail"
                           class="border rounded-xl p-3 w-full">

                    <input type="text" name="adresse"
                           required placeholder="Adresse physique"
                           class="border rounded-xl p-3 w-full">

                    <input type="password" name="mot_de_passe"
                           required minlength="8"
                           placeholder="Mot de passe (8 caractères minimum)"
                           class="border rounded-xl p-3 w-full">

                    <input type="password" name="confirmation"
                           required minlength="8"
                           placeholder="Confirmer le mot de passe"
                           class="border rounded-xl p-3 w-full">

                    <button type="submit"
                            class="w-full bg-green-700 hover:bg-green-800
                                   text-white font-bold py-3 rounded-xl">
                        Créer mon compte
                    </button>

                </form>

                <p class="text-center text-sm mt-5 text-gray-500">
                    Vous avez déjà un compte ?
                    <a href="compte_client.php?mode=connexion"
                       class="text-green-700 font-bold">
                        Connectez-vous
                    </a>
                </p>

            <?php else: ?>

                <h2 class="text-2xl font-bold mb-2">
                    Connexion client
                </h2>

                <p class="text-gray-500 text-sm mb-6">
                    Connectez-vous à votre compte.
                </p>

                <form method="POST"
                      action="compte_client.php?mode=connexion"
                      class="space-y-5">

                    <input type="hidden" name="action"
                           value="connexion">

                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($_SESSION["csrf_client"]) ?>">

                    <div>
                        <label class="block font-semibold mb-2">
                            Adresse e-mail
                        </label>

                        <input type="email" name="email"
                               required autocomplete="email"
                               placeholder="exemple@email.com"
                               class="border rounded-xl p-3 w-full">
                    </div>

                    <div>
                        <label class="block font-semibold mb-2">
                            Mot de passe
                        </label>

                        <input type="password" name="mot_de_passe"
                               required autocomplete="current-password"
                               placeholder="Votre mot de passe"
                               class="border rounded-xl p-3 w-full">
                    </div>

                    <button type="submit"
                            class="w-full bg-green-700 hover:bg-green-800
                                   text-white font-bold py-3 rounded-xl">
                        Se connecter
                    </button>

                </form>

                <p class="text-center text-sm mt-5 text-gray-500">
                    Vous n'avez pas encore de compte ?
                    <a href="compte_client.php?mode=inscription"
                       class="text-green-700 font-bold">
                        Créez-en un
                    </a>
                </p>

            <?php endif; ?>

        </div>

    </div>

</main>

</body>
</html>
