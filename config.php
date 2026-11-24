
<?php

// Paramètres de connexion à MySQL
$host = "localhost";
$nomBase = "ecommerce";
$utilisateur = "root";
$motDePasse = "";

// Connexion à la base de données
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

} catch (PDOException $e) {

    die("Erreur de connexion à la base de données : " . $e->getMessage());

}


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// ============================================================
// NEXIA — Helpers ajoutés (paramètres modifiables + succursales)
// ============================================================

if (!function_exists('h')) {
    function h($valeur): string {
        return htmlspecialchars((string)($valeur ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/** Récupère un paramètre modifiable (cache par requête) */
if (!function_exists('parametre')) {
    function parametre(string $cle, $defaut = null) {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            try {
                global $pdo;
                foreach ($pdo->query('SELECT cle, valeur FROM parametres')->fetchAll() as $row) {
                    $cache[$row['cle']] = $row['valeur'];
                }
            } catch (Throwable $e) { /* table absente */ }
        }
        return $cache[$cle] ?? $defaut;
    }
}

/** Formate un montant selon la devise configurée */
if (!function_exists('formatMontant')) {
    function formatMontant($montant): string {
        return number_format((float)$montant, 2, ',', ' ') . ' ' . parametre('devise', 'USD');
    }
}

/** Succursale active en session (0 = stock général / centrale) */
if (!function_exists('succursaleCourante')) {
    function succursaleCourante(): int {
        return isset($_SESSION['succursale_id']) ? (int)$_SESSION['succursale_id'] : 0;
    }
}

if (!function_exists('utilisateurEstLimiteAuPointDeVente')) {
    function utilisateurEstLimiteAuPointDeVente(): bool {
        return in_array($_SESSION['role'] ?? '', ['Caissier', 'Vendeur'], true);
    }
}

if (!function_exists('succursaleUtilisateur')) {
    function succursaleUtilisateur(): int {
        if (empty($_SESSION['id_utilisateur'])) {
            return 0;
        }

        global $pdo;
        $stmt = $pdo->prepare('SELECT succursale_id FROM utilisateurs WHERE id = ?');
        $stmt->execute([(int)$_SESSION['id_utilisateur']]);
        $_SESSION['succursale_id'] = (int)($stmt->fetchColumn() ?: 0);

        return $_SESSION['succursale_id'];
    }
}

if (!function_exists('succursaleCentraleId')) {
    function succursaleCentraleId(): int {
        global $pdo;
        static $id = null;
        if ($id === null) {
            $id = (int)($pdo->query("SELECT id FROM succursales WHERE type = 'CENTRALE' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        }

        return $id;
    }
}

if (!function_exists('exigerSuccursaleUtilisateur')) {
    function exigerSuccursaleUtilisateur(): int {
        $succursaleId = succursaleUtilisateur();
        if ($succursaleId <= 0) {
            http_response_code(403);
            exit('Aucun point de vente n’est associé à ce compte. Contactez un administrateur.');
        }

        return $succursaleId;
    }
}

if (!function_exists('exigerAccesAdministration')) {
    function exigerAccesAdministration(): void {
        if (utilisateurEstLimiteAuPointDeVente()) {
            http_response_code(403);
            exit('Accès réservé à l’administration.');
        }
    }
}

/** Numéro de document unique */
if (!function_exists('numeroDocument')) {
    function numeroDocument(string $prefixe): string {
        return strtoupper($prefixe) . '-' . date('YmdHis') . '-' . random_int(100, 999);
    }
}