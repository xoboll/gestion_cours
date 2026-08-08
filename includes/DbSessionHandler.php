<?php
/**
 * Gestionnaire de sessions PHP stocké en base de données PostgreSQL.
 *
 * Pourquoi : Vercel exécute le PHP via des fonctions serverless sans état.
 * Le système de sessions par défaut de PHP (fichiers sur disque) ne survit pas
 * de façon fiable entre deux appels — chaque requête peut atterrir sur une
 * instance différente. En stockant la session dans la base (Supabase), la
 * connexion de l'utilisateur reste valide quelle que soit l'instance qui
 * traite la requête suivante.
 *
 * Activé uniquement si la variable d'environnement USE_DB_SESSIONS=1 est définie
 * (voir includes/config.php) — en local avec WampServer/PostgreSQL classique,
 * les sessions fichiers habituelles de PHP continuent d'être utilisées.
 */
class DbSessionHandler implements SessionHandlerInterface
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $stmt = $this->db->prepare("SELECT data FROM sessions WHERE id = ? AND expires_at > NOW()");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $row['data'] : '';
    }

    public function write(string $id, string $data): bool
    {
        $lifetime = (int) ini_get('session.gc_maxlifetime') ?: 1440;
        $expiresAt = date('Y-m-d H:i:s', time() + $lifetime);

        $stmt = $this->db->prepare("
            INSERT INTO sessions (id, data, expires_at)
            VALUES (?, ?, ?)
            ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, expires_at = EXCLUDED.expires_at
        ");
        return $stmt->execute([$id, $data, $expiresAt]);
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM sessions WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->db->prepare("DELETE FROM sessions WHERE expires_at < NOW()");
        $stmt->execute();
        return $stmt->rowCount();
    }
}
