<?php

namespace RedmineSummarizer\Database;

use PDO;
use PDOException;

class RedmineDatabase
{
    private PDO $connection;
    private bool $debug = false;

    public function __construct(string $host, int $port, string $dbname, string $user, string $password, bool $debug = false)
    {
        try {
            $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
            $this->connection = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->debug = $debug;
        } catch (PDOException $e) {
            throw new \Exception("データベース接続エラー: " . $e->getMessage());
        }
    }

    /**
     * デバッグモードを設定
     */
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    /**
     * 指定した日数分のアクティビティを取得
     * 
     * @param int $days 取得する日数
     * @param string|null $userLogin 特定ユーザーのアクティビティのみを取得する場合のログイン名
     * @return array アクティビティの配列
     */
    public function getActivities(int $days, ?string $userLogin = null): array
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
                --user_cond_j
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
                --user_cond_i
                WHERE i.created_on >= NOW() - INTERVAL '{$days} days'
                ORDER BY created_at DESC";

                        if ($userLogin !== null) {
                            $sql = str_replace('--user_cond_j', "AND u.login = :user_id", $sql);
                            $sql = str_replace('--user_cond_i', "AND u.login = :user_id", $sql);
                        }

        try {
            $stmt = $this->connection->prepare($sql);
            if ($userLogin !== null) {
                $stmt->bindParam(':user_id', $userLogin, PDO::PARAM_STR);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \Exception("アクティビティデータ取得エラー: " . $e->getMessage());
        }
    }

    /**
     * 期間を指定してアクティビティを取得
     * 
     * @param string $fromDate 開始日（YYYY-MM-DD形式）
     * @param string $toDate 終了日（YYYY-MM-DD形式）
     * @param string $userLogin 対象となるユーザーID
     * @return array アクティビティの配列
     */
    public function getActivitiesByDateRange(string $fromDate, string $toDate, ?string $userLogin = null): array
    {
        // 入力形式の検証（YYYY-MM-DD形式であること）
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            throw new \Exception("日付形式が無効です。YYYY-MM-DD形式で指定してください。");
        }

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
                --user_cond
                WHERE j.created_on >= :from_date AND j.created_on <= (:to_date || ' 23:59:59')::timestamp
                AND j.journalized_type = 'Issue'
                UNION ALL
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
                --user_cond
                JOIN projects p ON i.project_id = p.id
                WHERE i.created_on >= :from_date AND i.created_on <= (:to_date || ' 23:59:59')::timestamp
                -- changesets
                UNION ALL
                 SELECT
                     ci.issue_id,
                     SPLIT_PART(comments, E'\n', 1) as issue_subject,
                     comments as issue_description,
                     p.name as project_name,
                     COALESCE(u.login, c.committer) as author,
                     LEFT(c.revision, 10) as comment,
                     committed_on as created_at,
                     'changeset'::text as activity_type
                FROM changesets c
                JOIN repositories r ON c.repository_id = r.id
                JOIN projects p ON r.project_id = p.id
                JOIN users u ON c.user_id = u.id
                --user_cond
                LEFT JOIN changesets_issues ci ON c.id = ci.changeset_id
               WHERE c.committed_on >= :from_date AND c.committed_on <= (:to_date || ' 23:59:59')::timestamp
                ORDER BY created_at DESC";

        if ($userLogin !== null) {
            $sql = str_replace('--user_cond', "AND u.login = :user_id", $sql);
        }

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->bindParam(':from_date', $fromDate, PDO::PARAM_STR);
            $stmt->bindParam(':to_date', $toDate, PDO::PARAM_STR);
            if ($userLogin !== null) {
                $stmt->bindParam(':user_id', $userLogin, PDO::PARAM_STR);
            }
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
     * @param string|null $userLogin 特定ユーザーのアクティビティのみを取得する場合のログイン名
     * @return array アクティビティの配列
     */
    public function getProjectActivities(int $projectId, int $days, ?string $userLogin = null): array
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
                --user_cond_j
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
                --user_cond_i
                WHERE i.created_on >= NOW() - INTERVAL '{$days} days'
                AND p.id = :project_id
                ORDER BY created_at DESC";

                        if ($userLogin !== null) {
                            $sql = str_replace('--user_cond_j', "AND u.login = :user_id", $sql);
                            $sql = str_replace('--user_cond_i', "AND u.login = :user_id", $sql);
                        }

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->bindParam(':project_id', $projectId, PDO::PARAM_INT);
            if ($userLogin !== null) {
                $stmt->bindParam(':user_id', $userLogin, PDO::PARAM_STR);
            }
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \Exception("プロジェクトアクティビティ取得エラー: " . $e->getMessage());
        }
    }

    /**
     * アクティビティデータをJSONファイルにエクスポート
     * 
     * @param int $days 取得する日数
     * @param string|null $outputPath 出力先ファイルパス（nullの場合はデフォルトパス）
     * @return string 出力されたファイルパス
     */
    public function exportActivitiesToJson(int $days, ?string $outputPath = null): string
    {
        try {
            // アクティビティデータを取得
            $activities = $this->getActivities($days);

            if (empty($activities)) {
                throw new \Exception("過去{$days}日間のアクティビティはありません。");
            }

            // 出力ファイルパスを設定
            if ($outputPath === null) {
                $tempDir = __DIR__ . '/../../temp';
                if (!is_dir($tempDir)) {
                    mkdir($tempDir, 0755, true);
                }
                $date = date('Y-m-d_H-i-s');
                $outputPath = "{$tempDir}/activities_{$days}days_{$date}.json";
            }

            // JSONとしてエンコード（読みやすく整形）
            $jsonData = json_encode($activities, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("JSONエンコードエラー: " . json_last_error_msg());
            }

            // ファイルに書き込み
            if (file_put_contents($outputPath, $jsonData) === false) {
                throw new \Exception("ファイル書き込みエラー: {$outputPath}");
            }

            if ($this->debug) {
                echo "アクティビティデータをJSONファイルに出力しました: {$outputPath}\n";
                echo "取得レコード数: " . count($activities) . "\n";
            }

            return $outputPath;

        } catch (\Exception $e) {
            throw new \Exception("データエクスポートエラー: " . $e->getMessage());
        }
    }

    /**
     * 特定プロジェクトの期間を指定してアクティビティを取得
     * 
     * @param int $projectId プロジェクトID
     * @param string $fromDate 開始日（YYYY-MM-DD形式）
     * @param string $toDate 終了日（YYYY-MM-DD形式）
     * @param string|null $userLogin 特定ユーザーのアクティビティのみを取得する場合のログイン名
     * @return array アクティビティの配列
     */
    public function getProjectActivitiesByDateRange(int $projectId, string $fromDate, string $toDate, ?string $userLogin = null): array
    {
        // 入力形式の検証（YYYY-MM-DD形式であること）
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            throw new \Exception("日付形式が無効です。YYYY-MM-DD形式で指定してください。");
        }

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
                WHERE j.created_on >= :from_date AND j.created_on <= (:to_date || ' 23:59:59')::timestamp
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
                WHERE i.created_on >= :from_date AND i.created_on <= (:to_date || ' 23:59:59')::timestamp
                AND p.id = :project_id
                ORDER BY created_at DESC";

        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->bindParam(':project_id', $projectId, PDO::PARAM_INT);
            $stmt->bindParam(':from_date', $fromDate, PDO::PARAM_STR);
            $stmt->bindParam(':to_date', $toDate, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \Exception("プロジェクトアクティビティ取得エラー: " . $e->getMessage());
        }
    }

    /**
     * 特定期間のアクティビティデータをJSONファイルにエクスポート
     * 
     * @param string $fromDate 開始日（YYYY-MM-DD形式）
     * @param string $toDate 終了日（YYYY-MM-DD形式）
     * @param string|null $outputPath 出力先ファイルパス（nullの場合はデフォルトパス）
     * @param string|null $userLogin エクスポートするユーザーID
     * @return string 出力されたファイルパス
     */
    public function exportActivitiesByDateRangeToJson(string $fromDate, string $toDate, ?string $outputPath = null, ?string $userLogin = null): string
    {
        try {
            // アクティビティデータを取得
            $activities = $this->getActivitiesByDateRange($fromDate, $toDate, $userLogin);;

            if (empty($activities)) {
                throw new \Exception("{$fromDate}から{$toDate}までのアクティビティはありません。");
            }

            // 出力ファイルパスを設定
            if ($outputPath === null) {
                $tempDir = __DIR__ . '/../../temp';
                if (!is_dir($tempDir)) {
                    mkdir($tempDir, 0755, true);
                }
                $date = date('Y-m-d_H-i-s');
                $outputPath = "{$tempDir}/activities_{$fromDate}_to_{$toDate}_{$date}.json";
            }

            // JSONとしてエンコード（読みやすく整形）
            $jsonData = json_encode($activities, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("JSONエンコードエラー: " . json_last_error_msg());
            }

            // ファイルに書き込み
            if (file_put_contents($outputPath, $jsonData) === false) {
                throw new \Exception("ファイル書き込みエラー: {$outputPath}");
            }

            if ($this->debug) {
                echo "{$fromDate}から{$toDate}までのアクティビティデータをJSONファイルに出力しました: {$outputPath}\n";
                echo "取得レコード数: " . count($activities) . "\n";
            }

            return $outputPath;

        } catch (\Exception $e) {
            throw new \Exception("データエクスポートエラー: " . $e->getMessage());
        }
    }

    /**
     * 特定プロジェクトの期間指定アクティビティデータをJSONファイルにエクスポート
     * 
     * @param int $projectId プロジェクトID
     * @param string $fromDate 開始日（YYYY-MM-DD形式）
     * @param string $toDate 終了日（YYYY-MM-DD形式）
     * @param string|null $outputPath 出力先ファイルパス（nullの場合はデフォルトパス）
     * @return string 出力されたファイルパス
     */
    public function exportProjectActivitiesByDateRangeToJson(int $projectId, string $fromDate, string $toDate, ?string $outputPath = null): string
    {
        try {
            // プロジェクトのアクティビティデータを取得
            $activities = $this->getProjectActivitiesByDateRange($projectId, $fromDate, $toDate);

            if (empty($activities)) {
                throw new \Exception("プロジェクトID {$projectId} の{$fromDate}から{$toDate}までのアクティビティはありません。");
            }

            // 出力ファイルパスを設定
            if ($outputPath === null) {
                $tempDir = __DIR__ . '/../../temp';
                if (!is_dir($tempDir)) {
                    mkdir($tempDir, 0755, true);
                }
                $date = date('Y-m-d_H-i-s');
                $outputPath = "{$tempDir}/project_{$projectId}_activities_{$fromDate}_to_{$toDate}_{$date}.json";
            }

            // JSONとしてエンコード（読みやすく整形）
            $jsonData = json_encode($activities, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("JSONエンコードエラー: " . json_last_error_msg());
            }

            // ファイルに書き込み
            if (file_put_contents($outputPath, $jsonData) === false) {
                throw new \Exception("ファイル書き込みエラー: {$outputPath}");
            }

            if ($this->debug) {
                echo "プロジェクト{$projectId}の{$fromDate}から{$toDate}までのアクティビティデータをJSONファイルに出力しました: {$outputPath}\n";
                echo "取得レコード数: " . count($activities) . "\n";
            }

            return $outputPath;

        } catch (\Exception $e) {
            throw new \Exception("データエクスポートエラー: " . $e->getMessage());
        }
    }

    /**
     * 特定プロジェクトのアクティビティデータをJSONファイルにエクスポート
     * 
     * @param int $projectId プロジェクトID
     * @param int $days 取得する日数
     * @param string|null $outputPath 出力先ファイルパス（nullの場合はデフォルトパス）
     * @return string 出力されたファイルパス
     */
    public function exportProjectActivitiesToJson(int $projectId, int $days, ?string $outputPath = null): string
    {
        try {
            // プロジェクトのアクティビティデータを取得
            $activities = $this->getProjectActivities($projectId, $days);

            if (empty($activities)) {
                throw new \Exception("プロジェクトID {$projectId} の過去{$days}日間のアクティビティはありません。");
            }

            // 出力ファイルパスを設定
            if ($outputPath === null) {
                $tempDir = __DIR__ . '/../../temp';
                if (!is_dir($tempDir)) {
                    mkdir($tempDir, 0755, true);
                }
                $date = date('Y-m-d_H-i-s');
                $outputPath = "{$tempDir}/project_{$projectId}_activities_{$days}days_{$date}.json";
            }

            // JSONとしてエンコード（読みやすく整形）
            $jsonData = json_encode($activities, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("JSONエンコードエラー: " . json_last_error_msg());
            }

            // ファイルに書き込み
            if (file_put_contents($outputPath, $jsonData) === false) {
                throw new \Exception("ファイル書き込みエラー: {$outputPath}");
            }

            if ($this->debug) {
                echo "プロジェクト{$projectId}のアクティビティデータをJSONファイルに出力しました: {$outputPath}\n";
                echo "取得レコード数: " . count($activities) . "\n";
            }

            return $outputPath;

        } catch (\Exception $e) {
            throw new \Exception("データエクスポートエラー: " . $e->getMessage());
        }
    }
}