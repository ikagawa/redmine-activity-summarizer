<?php

namespace RedmineSummarizer\Database;

use PDO;
use PDOException;

class RedmineDatabase
{
    private PDO $connection;

    public function __construct(string $host, int $port, string $dbname, string $user, string $password)
    {
        try {
            $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
            $this->connection = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            throw new \Exception("データベース接続エラー: " . $e->getMessage());
        }
    }

    /**
     * 指定した日数分のアクティビティを取得
     * 
     * @param int $days 取得する日数
     * @return array アクティビティの配列
     */
    public function getActivities(int $days): array
    {
        $sql = "SELECT 
                    i.id as issue_id,
                    i.subject as issue_subject,
                    i.description as issue_description,
                    p.name as project_name,
                    u.login as author,
                    j.notes as comment,
                    j.created_on as created_at,
                    'issue_edit' as activity_type
                FROM journals j
                JOIN issues i ON j.journalized_id = i.id
                JOIN projects p ON i.project_id = p.id
                JOIN users u ON j.user_id = u.id
                WHERE j.created_on >= NOW() - INTERVAL '{$days} days'
                AND j.journalized_type = 'Issue'
                UNION
                SELECT 
                    i.id as issue_id,
                    i.subject as issue_subject,
                    i.description as issue_description,
                    p.name as project_name,
                    u.login as author,
                    '' as comment,
                    i.created_on as created_at,
                    'issue_add' as activity_type
                FROM issues i
                JOIN users u ON i.author_id = u.id
                JOIN projects p ON i.project_id = p.id
                WHERE i.created_on >= NOW() - INTERVAL '{$days} days'
                ORDER BY created_at DESC";

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \Exception("アクティビティデータ取得エラー: " . $e->getMessage());
        }
    }

    /**
     * 指定したプロジェクトのアクティビティを取得
     * 
     * @param int $projectId プロジェクトID
     * @param int $days 取得する日数
     * @return array アクティビティの配列
     */
    public function getProjectActivities(int $projectId, int $days): array
    {
        $sql = "SELECT 
                    i.id as issue_id,
                    i.subject as issue_subject,
                    i.description as issue_description,
                    p.name as project_name,
                    u.login as author,
                    j.notes as comment,
                    j.created_on as created_at,
                    'issue_edit' as activity_type
                FROM journals j
                JOIN issues i ON j.journalized_id = i.id
                JOIN projects p ON i.project_id = p.id
                JOIN users u ON j.user_id = u.id
                WHERE j.created_on >= NOW() - INTERVAL '{$days} days'
                AND j.journalized_type = 'Issue'
                AND p.id = :project_id
                UNION
                SELECT 
                    i.id as issue_id,
                    i.subject as issue_subject,
                    i.description as issue_description,
                    p.name as project_name,
                    u.login as author,
                    '' as comment,
                    i.created_on as created_at,
                    'issue_add' as activity_type
                FROM issues i
                JOIN users u ON i.author_id = u.id
                JOIN projects p ON i.project_id = p.id
                WHERE i.created_on >= NOW() - INTERVAL '{$days} days'
                AND p.id = :project_id
                ORDER BY created_at DESC";

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->bindParam(':project_id', $projectId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \Exception("プロジェクトアクティビティ取得エラー: " . $e->getMessage());
        }
    }
}